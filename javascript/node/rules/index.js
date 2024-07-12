/**
 * @module rules
 * @author Jim McGowen
 */
'use strict'

const { Engine, Fact } = require('json-rules-engine')
const { getDoc, queryDoc, queryDocs, getDocsByIds } = require('../firestore')
const { checkFileExistsSync, replaceJsonProps, getSettings, hasProp } = require('../utils')
const { admin, functions, db, _, dayjs } = require('../admin')
const { isTimeMatches } = require('@datasert/cronjs-matcher')

// Global rules config from the /rules/config document (so it's only loaded once)
let rulesConfig = undefined

/** @typedef {module: json-rules-engine} Almanac */

/**
 * Evaluates all active 'doc' type rule sets filtered by collection.
 *
 * All doc type rule sets must define `filters.collection`.
 *
 * See https://solardrive.atlassian.net/wiki/spaces/S/pages/26902529/Solardrive+Rules+Engine
 * for a description of the Rules Engine and the rule document structure.
 *
 * @async
 * @param {object} doc
 * @param {string} trigger 'create' | 'update' | 'delete'
 * @param {object} docBefore - When the trigger is 'update', this is the doc before the update.
 */
const runDocRules = async (doc, trigger, docBefore = undefined) => {
  await loadRulesConfig()

  if (rulesConfig.debug) {
    console.log('Running doc rules for: ', doc.ref.path)
  }

  // Retrieve rules from db
  const query = db
    .collection('rulesets')
    .where('active', '==', true)
    .where('ruleType', '==', 'doc')
    // All doc rules MUST have a collection filter
    .where('filters.collection', '==', doc.ref.parent.path)

  const ruleSets = await queryDocs(query)
  if (!ruleSets) {
    if (rulesConfig.debug) {
      console.log('No rules match filters.')
      console.log(` trigger: ${trigger}`)
      console.log(` source doc "${doc.ref.path}"`)
    }
    return
  }

  ruleSets.docs.map(async (ruleSetSnap) => {
    const ruleSet = ruleSetSnap.data()
    const { title, rules, additionalFacts } = ruleSet
    const engine = new Engine()

    /**
     * Define a 'hasProp' custom operator, for use in later rules
     */
    engine.addOperator('hasProp', (factValue, jsonValue) => {
      return hasProp(factValue, jsonValue)
    })

    checkForDryrun(ruleSet)

    // Add the built-in facts that all doc type rules get
    engine.addFact('srcDoc', { id: doc.id, ...doc.data() })
    if (docBefore && docBefore.exists) {
      engine.addFact('docBefore', { id: doc.id, ...docBefore.data() })
    }
    engine.addFact('collection', doc.ref.parent.path)
    engine.addFact('trigger', trigger)

    addNowFact(engine)

    // TODO: I dont think we need to add the additional facts here since they will be added in then handleBulkRuleSet function
    addAdditionalFacts(engine, additionalFacts)

    const bulkRule = await handleBulkRuleSet(ruleSet, engine)
    if (!bulkRule) {
      // Add each rule
      rules.forEach((rule) => {
        if (rulesConfig.debug) {
          console.log(`Adding rule "${title}" - "${rule.name} (${rule.event.type})"`)
        }

        addRule(engine, rule)
      })

      // Start the rules engine
      await runRules(engine)
    }
  })
}

/**
 * TODO: DOES NOT WORK. For future implementation.
 *
 *
 * Evaluates the ruleset for every doc in the data array.
 *
 * See https://solardrive.atlassian.net/wiki/spaces/S/pages/26902529/Solardrive+Rules+Engine
 * for a description of the Rules Engine and the rule document structure.
 *
 * @async
 * @param {string} callableRuleTitle - The title of the rule to run
 *
 * TODO: data is an array of doc ids?
 * @param {Array} data - The array of objects to run the rule against
 */
const runCallableRule = async (callableRuleTitle, data) => {
  await loadRulesConfig()
  // Retrieve ruleSet from db
  const query = { haystack: 'title', ops: '==', needle: callableRuleTitle }
  const ruleSet = await queryDoc('rulesets', query)

  if (!ruleSet) {
    console.error('No ruleset with title "' + callableRuleTitle + '" found.')
    return
  }

  if (rulesConfig.debug) {
    console.log(`Running callable rules for: ${callableRuleTitle}`)
  }

  // Create new engine
  const engine = new Engine()

  // Define the 'hasProp' custom operator
  engine.addOperator('hasProp', (factValue, jsonValue) => {
    return hasProp(factValue, jsonValue)
  })

  // Checks if this ruleSet is a dry run
  checkForDryrun(ruleSet)
  // Add "now" fact to engine for use in date/time conditions
  addNowFact(engine)
  // Add any additional facts to that might be in the ruleSet to the engine
  addAdditionalFacts(engine, ruleSet.additionalFacts)

  // Using reduce we group the entities by type
  // TODO: Looks like data is expecting a specific format. entity types and ids (collections and docids)?
  const groupedEntityIds = data.reduce((acc, entity) => {
    const { type } = entity
    if (!acc[type]) acc[type] = []

    acc[type].push(entity)
    return acc
  })
  const callableData = []
  for (const entityType of Object.keys(groupedEntityIds)) {
    // fetch all the entities from the db and push them to the entities array
    const docs = await getDocsByIds(entityType, groupedEntityIds[entityType])

    callableData.push({ [entityType]: docs })
  }

  // TODO: handleBulkRuleSet does not take callableData as a parameter
  //       Should probably create a new function for callable bulk data.
  //       Trying to reuse the existing one was problematic.
  await handleBulkRuleSet(ruleSet, engine, callableData)

  // Start the rules engine
  await runRules(engine)
}

/**
 * Evaluates all 'scheduled' type rules.
 *
 * See https://solardrive.atlassian.net/wiki/spaces/S/pages/26902529/Solardrive+Rules+Engine
 * for a description of the Rules Engine and the rule document structure.
 */
const runScheduledRules = async () => {
  await loadRulesConfig()

  if (rulesConfig.debug) {
    console.log('Running scheduled rules...')
  }

  // Get all active scheduled rules
  const query = db.collection('rulesets').where('active', '==', true).where('ruleType', '==', 'scheduled')

  // Check cron on each rule and if it's a match add the rule to the rules engine
  const ruleSets = await queryDocs(query)
  if (!ruleSets) {
    if (rulesConfig.debug) {
      console.log('No scheduled rules match filters.')
    }
    return
  }

  ruleSets.docs.map(async (ruleSetSnap) => {
    const ruleSet = ruleSetSnap.data()
    const { cron, title, rules, additionalFacts } = ruleSet

    // Drop the seconds in 'now' for comparison
    const now = new Date()
    const dt = now.toISOString().slice(0, now.toISOString().lastIndexOf(':'))
    if (isTimeMatches(cron, dt)) {
      const engine = new Engine()

      /**
       * Define a 'hasProp' custom operator, for use in later rules
       */
      engine.addOperator('hasProp', (factValue, jsonValue) => {
        return hasProp(factValue, jsonValue)
      })

      checkForDryrun(ruleSet)

      // Add the built-in 'now' fact and any additional facts
      addNowFact(engine)
      addAdditionalFacts(engine, additionalFacts)

      const bulkRule = await handleBulkRuleSet(ruleSet, engine)
      if (!bulkRule) {
        // **** Single document filter ****
        rules.forEach((rule) => {
          // Add each rule
          if (rulesConfig.debug) {
            console.log(`Adding rule "${title}" - "${rule.name} (${rule.event.type})"`)
          }

          addRule(engine, rule)
        })
      }

      // Start the rules engine
      await runRules(engine)
    }
  })
}

/**
 * Evaluates the active 'api' type ruleset filtered by endPoint. There should only ever be one
 * ruleset per endPoint. If more than one is found, an error is thrown.
 *
 * The onFinished callback will get a single parameter which will be the return values (if any)
 * of the actions mapped by action name.
 *
 * @param {module: express} request
 * @param {Function} onFinished Callback function to run when all other actions are complete.
 * @returns {Promise<void>}
 */
const runApiRules = async (request, onFinished) => {
  await loadRulesConfig()

  if (rulesConfig.debug) {
    console.log('Running api rules...')
  }

  // Get all active api rules
  const { params } = request
  const { endPoint } = params
  const query = db.collection('rulesets').where('active', '==', true).where('ruleType', '==', 'api').where('method', '==', request.method).where('endPoint', '==', endPoint)

  const ruleSets = await queryDocs(query)
  if (!ruleSets) {
    if (rulesConfig.debug) {
      throw 'No api rules match filters.'
    }
    return
  }

  if (ruleSets.size > 1) {
    throw `Internal error: Multiple rulesets found for endpoint "${endPoint}". Only one ruleset per endpoint is allowed.`
  }

  ruleSets.docs.map(async (ruleSetSnap) => {
    const ruleSet = ruleSetSnap.data()
    const { title, rules, additionalFacts } = ruleSet
    const engine = new Engine()

    /**
     * Define a 'hasProp' custom operator, for use in later rules
     */
    engine.addOperator('hasProp', (factValue, jsonValue) => {
      return hasProp(factValue, jsonValue)
    })

    checkForDryrun(ruleSet)

    // Add the built-in facts that all api type rules get
    engine.addFact('request', request)
    addNowFact(engine)

    const { fieldMaps } = ruleSet
    if (fieldMaps) {
      engine.addFact('fieldMaps', fieldMaps)
    }

    // Add any additional facts from the ruleset
    addAdditionalFacts(engine, additionalFacts)

    const bulkRule = await handleBulkRuleSet(ruleSet, engine)
    if (!bulkRule) {
      // Add each rule
      rules.forEach((rule) => {
        if (rulesConfig.debug) {
          console.log(`Adding rule "${title}" - "${rule.name} (${rule.event.type})"`)
        }

        if (onFinished instanceof Function) {
          // Add the onFinished callback as an action. The results parameter of the onFinished function will be
          // the return values of all the previous actions in the rule. The same callback will be used for both
          // onSuccess and onFailure. Check the almanac in the callback for any errors.
          rule.onSuccess.actions = {
            ...rule.onSuccess.actions,
            onFinished: { function: onFinished }
          }
          rule.onFailure.actions = {
            ...rule.onFailure.actions,
            onFinished: { function: onFinished }
          }
        }

        addRule(engine, rule)
      })

      // Start the rules engine
      await runRules(engine)
    }
  })
}

/**
 * In the case where we have an additional fact that produces a number of documents we want to
 * add the rule(s) and any other additional facts for each document.
 *
 * This function will check if the rule set includes an additional fact for multiple documents and
 * returns false, doing nothing else, if it does not. Otherwise, all rules and other additional facts will
 * be added to the rules-engine passed in.
 *
 * @param {object} ruleSet The rule set document to examine and process if needed.
 * @param {Engine} engine The json-rules engine instance to add the rule to.
 * @returns {Promise<boolean>} True if this function found and dealt with bulk documents. False if not a bulk rule set.
 */
async function handleBulkRuleSet(ruleSet, engine) {
  let bulkRule = false
  const { title, rules, additionalFacts } = ruleSet

  if (additionalFacts) {
    for (const [name, fact] of Object.entries(additionalFacts)) {
      if (hasProp(fact, 'collection') && (hasProp(fact, 'filters') || hasProp(fact, 'query'))) {
        // **** Multiple document filter (bulk) ****
        bulkRule = true

        // Get all the documents in the filtered collection
        const factQuery = await getFactQuery(fact, undefined, engine)
        if (factQuery === false) {
          console.log(`Error: Invalid fact query for fact "${name}"`)
          return false
        }

        // Add the rules and additional facts for EVERY document
        const docs = await queryDocs(factQuery)
        docs.forEach((docSnap) => {
          rules.forEach((rule) => {
            if (rulesConfig.debug) {
              console.log(`Adding rule "${title}" - "${rule.name} (${rule.event.type})"`)
            }

            // Create a unique additional fact specific to this document.
            // The fact name will be appended with tha dash and the doc id.
            const addFactName = name + '-' + docSnap.id
            const addFact = {
              [addFactName]: {
                data: factData
              }
            }
            addAdditionalFacts(engine, addFact)

            // Add any other additional facts. First replace the fact name with the unique one.
            const additionalFactsCopy = _.cloneDeep(additionalFacts)
            replaceJsonProps(additionalFactsCopy, 'fact', name, addFactName)
            delete additionalFactsCopy[name]
            addAdditionalFacts(engine, additionalFactsCopy)

            // Crawl the rule and replace all references to the fact name with the unique one.
            const ruleCopy = _.cloneDeep(rule)
            replaceJsonProps(ruleCopy, 'fact', name, addFactName)

            // Add the rule
            addRule(engine, ruleCopy)
          })
        })
      }
    }
  }

  return bulkRule
}

/**
 * Check the ruleSet for the 'dryrun' property and if true replaces all the actions with
 * the exampleAction.
 *
 * Modifies the ruleSet object.
 *
 * @param {object} ruleSet
 */
const checkForDryrun = (ruleSet) => {
  if (ruleSet.dryRun) {
    console.log('*** Dry Run ***')
    for (const rule of ruleSet.rules) {
      if (rule.onSuccess && rule.onSuccess.actions) {
        for (const [key, value] of Object.entries(rule.onSuccess.actions)) {
          rule.onSuccess.actions['exampleAction'] = value
          delete rule.onSuccess.actions[key]
        }
      }

      if (rule.onDelete && rule.onDelete.actions) {
        for (const [key, value] of Object.entries(rule.onDelete.actions)) {
          rule.onDelete.actions['exampleAction'] = value
          delete rule.onDelete.actions[key]
        }
      }
    }
  }
}

/**
 * Add "now" fact which is the current unix timestamp (when the rule is processed). This time can be altered
 * with a calculation using "add" or "subtract" or any Date function can be called on it.
 *
 * Example use in a condition:
 *  {
 *    "fact": "task", // Task document
 *    "operator": "lessThan",
 *    "path": "$.created._seconds",
 *    // This will be in unix timestamp. Should always be UTC but depends on how it was saved to the db.
 *    "value": {
 *      "fact": "now",
 *      "params": {
 *        "subtract": "14 days"
 *        // The key can be either "add" or "subtract". The value is a string with an integer followed by the unit
 *        // as described in the dayjs docs here: https://day.js.org/docs/en/manipulate/add (units and can be plural).
 *        // Then the appropriate calculation performed on "now", i.e. "now - 14 days" or "now + 1 month" etc...
 *
 *        // Or any function of the date object can be called as long as it does not take parameters. For example:
 *        "function": "toJSON" // this will call the "toJSON" function of the Date object.
 *      }
 *    }
 *  }
 *
 * In the above example, if task.created._seconds is older than 2 weeks the condition will pass.
 *
 * @param {Engine} engine The json-rules engine instance to add the rule to
 */
const addNowFact = (engine) => {
  const nowFact = new Fact('now', (params) => {
    const date = new Date()
    let value
    if (params.function && typeof date[params.function] === 'function') {
      return date[params.function]()
    } else if (params.add) {
      const day = dayjs(date)
      value = day.add(...params.add.split(' '))
    } else if (params.subtract) {
      const day = dayjs(date)
      value = day.subtract(...params.subtract.split(' '))
    } else {
      value = dayjs(date)
    }

    if (params.format) {
      if (params.format === 'unix') {
        return value.unix()
      } else if (params.format === 'firestoreTimestamp') {
        return admin.firestore.Timestamp.fromDate(value.toDate())
      }
    }
  })
  engine.addFact(nowFact)
}

/**
 * Adds a rule to the json-rules engine. Redefines the onSuccess and
 * onFailure functions.
 *
 * Modified the rule object.
 *
 * @param {Engine} engine The json-rules engine instance to add the rule to
 * @param {object} rule The rule (json object per json-rules documentation)
 */
const addRule = (engine, rule) => {
  // Redefine onSuccess
  const successDefs = rule.onSuccess
  rule.onSuccess = async (event, almanac) => {
    await onSuccess(event, almanac, successDefs)
  }

  // Redefine onFailure
  const failureDefs = rule.onFailure
  rule.onFailure = async (event, almanac) => {
    await onFailure(event, almanac, failureDefs)
  }

  // Add the rule
  engine.addRule(rule)
}

/**
 * Adds additional facts to the json-rules engine.
 *
 * At this time, this function assumes the fact will be a property of a document.
 * In the future it can support static values or other types of dynamic values if needed.
 *
 * The format for an additional fact definition is like so:
 * {
 *   <fact_name>: {
 *     collection: <collection_name>,
 *     id: <doc_id> | {
 *       fact: <fact_to_derive_id_from>,
 *       path: <id_property_of_the_fact>
 *     }
 *   }
 * }
 *
 * fact_name can be anything. This is what you will use when referencing this
 * fact elsewhere in the rule. The fact def must have 'collection' and 'id' fields.
 * The id field can be a static id or and fact-path combo from another fact.
 *
 * Note that there is also the case where an additional fact can be an array of docs
 * but that condition is not handled and they are ignored here. This function only
 * deals with additional facts that have 'collection' and 'id' properties.
 *
 * @param {Engine} engine The json-rules engine instance to add the rule to
 * @param {object} additionalFacts json fact definitions
 */
const addAdditionalFacts = (engine, additionalFacts) => {
  for (const [name, fact] of Object.entries(additionalFacts)) {
    // The data is already retrieved so just add it as a fact and return
    if (fact.data) {
      const factDef = new Fact(name, fact.data)
      engine.addFact(factDef)
      return
      // If we have a 'collection' and an 'id' we know we are going to be retrieving a doc
    } else if ((fact.collection && fact.id) || fact.query) {
      try {
        engine.addFact(name, async (params, almanac) => {
          try {
            if (fact.id instanceof Object) {
              // We expect a fact and a path which we will use to determine the doc id,
              // and we will return a single document
              const doc = await almanac.factValue(fact.id.fact)
              const id = _.get(doc, fact.id.path.replace('$.', ''))
              return await getDoc(fact.collection, id)
            } else if (fact.query) {
              // Return an array of document objects
              const factQuery = await getFactQuery(fact, almanac)
              if (factQuery) {
                return await queryDocs(factQuery, false)
              }
            } else {
              // We expect a literal doc id returning a single document
              return await getDoc(fact.collection, fact.id)
            }
          } catch (e) {
            console.error('An exception occurred evaluating an additionalFact: ', e)
          }
        })
      } catch (e) {
        console.error('An exception occurred adding an additionalFact: ', e)
      }
    } else {
      // TODO: Support for static values, string, number, array, object?
      console.log('skipping additional fact: ', name)
    }
  }
}

/**
 * Runs the rules in the json-rules-engine.
 *
 * @param {Engine} engine The json-rules engine instance
 * @param {object} facts Any additional facts to add before running
 */
const runRules = async (engine, facts = {}) => {
  try {
    // Run the rules
    if (rulesConfig.debug) {
      console.log('Running rules...')
    }

    const { results, failureResults } = await engine.run(facts)

    if (rulesConfig.debug) {
      console.log('Finished running rules.\n\nHere are the results of the conditions:')

      // Log failed conditions
      for (const failure of failureResults) {
        // TODO: This doesn't work well with nested conditions. Needs a recursive function.
        if (failure.conditions.all) {
          for (const condition of failure.conditions.all) {
            if (!condition.result) {
              console.log(`Condition failed: Rule "${failure.name}": `, condition)
            } else {
              console.log(`Condition succeeded: Rule "${failure.name}": `, condition)
            }
          }
        }
      }

      // Log successful conditions
      for (const result of results) {
        if (result.conditions.all) {
          for (const condition of result.conditions.all) {
            if (!condition.result) {
              console.log(`Condition failed: Rule "${result.name}": `, condition)
            } else {
              console.log(`Condition succeeded: Rule "${result.name}": `, condition)
            }
          }
        }
      }
    }

    // TODO: return results for api rules? Result of action saved in almanac?
  } catch (e) {
    console.error('An error occurred running the rule.')
    console.error(e)
    console.trace()
  }
}

/**
 * Success call back for all rules. Executes all actions defined in the
 * rule doc onSuccess property. The definitions in onSuccess in the doc
 * are stored internally and the rule's onSuccess is replaced with this
 * function.
 *
 * actions in the rule doc should be in the following format:
 * "onSuccess": {
 *  "actions": {
 *    "actionName": { <= corresponds to a file in the "actions" directory
 *      "param1": "value1", <= parameters passed to the action
 *      "param2": "value2",
 *      ...
 *    },
 *    ...
 *  }
 *
 * parameters may be set at runtime using defined facts. For example:
 * "param3": {
 *    "fact": "user",
 *    "path": "$.email"
 *  }
 *
 * @param {object} event As defined in the rule json
 * @param {Almanac} almanac  <Almanac> The json-rules-engine almanac
 * @param {object} successDefs The value of onSuccess as it is in the rule doc
 *                             (where actions are defined)
 */
const onSuccess = async (event, almanac, successDefs) => {
  if (rulesConfig.debug) {
    console.log('Rule passed: ', event)
  }

  if (successDefs.actions) {
    for (const [action, params] of Object.entries(successDefs.actions)) {
      await executeAction(action, params, almanac)
    }
  }
}

/**
 * Failure call back for all rules. Executes all actions defined in the
 * rule doc onFailure property. The definitions in onFailure in the doc
 * are stored internally and the rule's onFailure is replaced with this
 * function.
 *
 * actions in the rule doc should be in the following format:
 * "onFailure": {
 *  "actions": {
 *    "actionName": { <= corresponds to a file in the "actions" directory
 *      "param1": "value1", <= parameters passed to the action
 *      "param2": "value2",
 *      ...
 *    },
 *    ...
 *  }
 *
 * parameters may be set at runtime using defined facts. For example:
 * "param3": {
 *    "fact": "user",
 *    "path": "$.email"
 *  }
 *
 * @param {object} event As defined in the rule json
 * @param {Almanac} almanac  <Almanac> The json-rules-engine almanac
 * @param {object} failureDefs The value of onFailure as it is in the rule doc
 *                             (where actions are defined)
 */
const onFailure = async (event, almanac, failureDefs) => {
  if (rulesConfig.debug) {
    console.log('Rule failed: ', event)
  }

  if (failureDefs.actions) {
    for (const [action, params] of Object.entries(failureDefs.actions)) {
      await executeAction(action, params, almanac)
    }
  }
}

/**
 * Executes a rule action. An Action is a function that is defined in the 'actions' directory.
 * All params will be passed to the action function. Params can use facts defined in the rule.
 *
 * @param {string} action Name of the action. Should correspond to the name of a js file in
 *                        the "actions" dir.
 * @param {object} params The parameters to send to the action function. These are defined
 *                        in the json and can be pretty much anything the function needs.
 * @param {Almanac} almanac The almanac from the rule success or failure callback.
 */
const executeAction = async (action, params, almanac) => {
  // If the action is a anonymous function definition, just execute it passing all
  // facts from the almanac to it in a single parameter.
  if (params.function) {
    // Parameters will be the action results from the almanac (formatted for easier use)
    const actionResults = almanac.factMap.get('actionResults')
    const newParams = actionResults ? actionResults.value : {}

    if (rulesConfig.debug) {
      console.log(`Calling action "${action}" with params: `, newParams)
    }

    params.function(newParams)
    return
  }

  if (rulesConfig.debug) {
    console.log(`Calling action "${action}" with params: `, params)
  }

  if (checkFileExistsSync(`./rules/actions/${action}.js`)) {
    try {
      const js = require(`./actions/${action}`)
      if (typeof js[action] === 'function') {
        // Check if the value is an object in the almanac fact map
        for (const [name, value] of Object.entries(params)) {
          if (value.fact) {
            const fact = await almanac.factValue(value.fact)
            if (_.isArray(fact)) {
              if (value.path) {
                // If we have a path with an array of objects then return an array of the values at that path
                params[name] = fact.map((item) => {
                  const itemValue = _.get(item, value.path.replace('$.', ''))
                  if (itemValue === undefined) {
                    console.error(`Path "${value.path}" not found in fact "${value.fact}"`)
                  }
                  return itemValue
                })
              } else {
                // If no path just return the array
                params[name] = fact
              }
            } else if (_.isObject(fact) && value.path) {
              // If it's an object and we have a path, try to get the value at the path
              const path = value.path.replace('$.', '')
              if (path === '') {
                // If the path is empty (ie. '$.'), just return the object
                params[name] = fact
              } else {
                params[name] = _.get(fact, path)
              }
            } else if (_.isObject(fact)) {
              // If it's an object and no path, just return the object
              params[name] = fact
            } else {
              throw `Could not get fact ${value.fact}. Bad path, query, or collection name?`
            }
          }
        }

        // Add all the facts as params to the action
        params['facts'] = {}
        Array.from(almanac.factMap.values()).map((fact) => {
          params['facts'][fact.id] = fact
        })

        // Execute the action call back
        let actionResult
        try {
          actionResult = await js[action](params)
        } catch (error) {
          console.log('Error executing action "' + action + '": ', error)
          console.log('  Params: ', params)

          actionResult = {
            internalError: {
              code: 500,
              message: error.message ? error.message : error
            }
          }
        }

        // Store any return value in the almanac
        let actionResults = {}
        try {
          actionResults = await almanac.factValue('actionResults')
        } catch (error) {
          // First time, ignore this exception
          if (error.code !== 'UNDEFINED_FACT') {
            console.log('Error reading from almanac: ', error)
          }
        }
        actionResults = { ...actionResults, [action]: actionResult }

        almanac.addRuntimeFact('actionResults', actionResults)
      } else {
        if (rulesConfig.debug) {
          console.error(`Function ${action} not defined in ${action}.js`)
        }
      }
    } catch (error) {
      console.error(error)
      console.error('__dirname: ', __dirname)
      console.error('working dir: ', process.cwd())
    }
  } else {
    if (rulesConfig.debug) {
      console.warn(`Warning, callback ${action} in rule doesn't exist`)
    }
  }
}

/**
 * Returns a firebase query object for the given fact. It expects the following
 * properties in the fact:
 *   "collection" <= the name of the collection to query
 *   "query" <= and array of query properties. types can be "where", "orderBy", "limit". See example.
 *
 * Example:
    {
      "collection": "sales_workers",
      "query": [
        {
          "operator": "==",
          "path": "$.team.docId",
          "type": "where",
          "value": {
            "fact": "rep",
            "path": "$.team.docId"
          }
        },
        {
          "type": "limit",
          "value": 10
        },
        {
          "direction": "asc",
          "path": "$.created",
          "type": "order"
        }
      ]
    }
 * 
 * Note in the where query above, the value is an additional fact resolved at runtime.
 *
 * This function can be used before the rule is run or during. Any additional facts will be taken from the
 * almanac, durning runtime, or the engine, before runtime.
 *
 * @param {object} fact
 * @param {Almanac} almanac
 * @param {Engine} engine
 * @returns {Query} firebase query object
 */
const getFactQuery = async (fact, almanac = undefined, engine = undefined) => {
  const collectionRef = db.collection(fact.collection)
  let factQuery = false

  // Query params can be in .filters or .query
  // TODO: Should prob just drop filters and use query
  const queryParams = fact.filters ? fact.filters : fact.query ? fact.query : undefined
  for (const filter of queryParams) {
    switch (filter.type) {
      case 'where': {
        if (filter.path && filter.operator && filter.value) {
          let value = filter.value
          if (
            filter.value instanceof Object &&
            (almanac !== undefined || engine !== undefined) &&
            ((filter.value.fact && filter.value.path) || filter.value.fact === 'now' || filter.value.fact === 'firestore:now')
          ) {
            let fact
            if (almanac !== undefined) {
              fact = await almanac.factValue(filter.value.fact)
            } else if (engine !== undefined) {
              const factObj = engine.getFact(filter.value.fact)
              fact = factObj.calculationMethod(filter.value.params, undefined)
            }

            if (filter.value.path) {
              value = _.get(fact, filter.value.path.replace('$.', ''))
            } else {
              value = fact
            }
          }

          if (factQuery) {
            factQuery = factQuery.where(filter.path.replace('$.', ''), filter.operator, value)
          } else {
            factQuery = collectionRef.where(filter.path.replace('$.', ''), filter.operator, value)
          }
        } else {
          console.warn('Waring: Missing "path", "operator", or "value" in where filter.')
        }
        break
      }
      case 'limit': {
        if (filter.value) {
          if (factQuery) {
            factQuery = factQuery.limit(filter.value)
          } else {
            factQuery = collectionRef.limit(filter.value)
          }
        } else {
          console.warn('Waring: Missing "value" in limit filter.')
        }
        break
      }
      case 'order': {
        if (filter.path && filter.direction) {
          if (factQuery) {
            factQuery = factQuery.orderBy(filter.path.replace('$.', ''), filter.direction)
          } else {
            factQuery = collectionRef.orderBy(filter.path.replace('$.', ''), filter.direction)
          }
        } else {
          console.warn('Waring: Missing "path" or "direction" in order filter.')
        }
        break
      }
      // TODO: Add support for 'select'
      default: {
        console.error('Invalid fact filter type. Acceptable type: where, limit, order')
      }
    }
  }
  return factQuery
}

/**
 * Load the rules configuration from the db into the global rulesConfig variable.
 */
const loadRulesConfig = async () => {
  if (rulesConfig === undefined) {
    rulesConfig = await getSettings('rules')
  }
}

/**
 * Main scheduled rule runner. Executes every minute.
 * // TODO: Need the memory param to pubsub?
 */
const scheduledRulesRunner = functions
  .runWith({ memory: '2GB' })
  .pubsub.schedule('* * * * *')
  .timeZone('UTC')
  .onRun(async () => {
    await runScheduledRules()
    return true
  })

/**
 * Scheduled rules can also be immediately triggered with an http request.
 * http://<host>(:<port>)/<project-url>/us-central1/rules-runScheduledRules
 */
const runScheduledRulesNow = functions.https.onRequest(async (req, resp) => {
  await runScheduledRules()
  resp.status(200).end()
})

module.exports = {
  runDocRules,
  runScheduledRules,
  runApiRules,
  scheduledRulesRunner,
  runScheduledRulesNow
}
