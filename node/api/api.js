/**
 * @module api
 * @author Jim McGowen
 */
'use strict'

const { addDoc, convertTimestamps } = require('../firestore')
const { getSettings } = require('../utils')
const { functions, _ } = require('../admin')
const { runApiRules } = require('../rules/index')
const express = require('express')
const cors = require('cors')

const app = express()
app.use(cors({ origin: true }))

/* eslint-disable no-unused-vars */
/**
 * TODO: Consider adding this function for the UI to call. Similar to addWebhook in webhook.js.
 */
const addEndpoint = () => {
  // TODO: Build the ruleset based on params?
}
/* eslint-enable no-unused-vars */

/**
 * This is the single entry point for all GET API requests.
 *
 * The URL for all API calls is
 *   http://[hostname][:port]/[project-id]/[location]/api/<endPoint>/<id>
 *
 * And the parameters are:
 *   <endPoint> is required and is the URL encoded version of the ruleset name.
 *   <id> is optional and is the document id when needed.
 */
app.get('/:endPoint/:id?', async (req, res) => {
  await handleRequest(req, res)
})

/**
 * This is the single entry point for all POST API requests.
 *
 * The URL for all API calls is
 *   http://[hostname][:port]/[project-id]/[location]/api/<endPoint>/<id>
 *
 * And the parameters are:
 *   <endPoint> is required and is the URL encoded version of the ruleset name.
 *   <id> is optional and is the document id when needed.
 *
 * The data passed in the body of the request must be in the format specified
 * by the Content-Type header.
 */
app.post('/:endPoint/:id?', async (req, res) => {
  await handleRequest(req, res)
})

/**
 * Request handler for all API requests.
 * Runs the rules engine for API rules.
 *
 * @param {express.Request} req
 * @param {express.Response} res
 * @returns {Promise<void>}
 */
const handleRequest = async (req, res) => {
  try {
    // Run api rules
    await runApiRules(req , (results) => handleResponse(results, req, res))
  } catch (error) {
    const apiSettings = await getSettings('api')
    const message = error.message ? error.message : error
    console.error(`Internal error: ${message}`)

    res.set('Content-Type', 'application/json')
    res.status(500).send({
      data: {},
      dt: new Date().toISOString(),
      apiVersion: apiSettings.version,
      error: `Internal error: ${message}`
    })
  }
}

/**
 * Handler for all API responses.
 * The response will contain the results of all the actions run
 * by the rules engine.
 *
 * @param {object} results
 * @param {express.Request} req
 * @param {express.Response} res
 * @returns {Promise<void>}
 */
const handleResponse = async (results, req, res) => {
  let code = 200
  let message = ""

  for (const [action, result] of Object.entries(results)) {
    if (result !== undefined && result.internalError) {
      code = result.internalError.code
      message = `Internal error (${action}): ` + result.internalError.message
      break;
    }
  }

  const { params, method, headers, body } = req
  const { action, id } = params

  const logData = {
    "endPoint": action,
    "id": id,
    "method": method,
    "requestHeaders": headers,
    "requestData": body,
    "url": req.url,
    "remoteIp": req.socket.remoteAddress ? req.socket.remoteAddress : '',
    "requestTime": "timestamp:now",
    "responseData": results,
    "responseTime": "timestamp:now",
    "code": code,
    "error": message,
  }
  await addDoc('apiLog', convertTimestamps(logData))

  const apiSettings = await getSettings('api')

  res.set('Content-Type', 'application/json')
  res.status(code).send({
    data: results,
    dt: new Date().toISOString(),
    apiVersion: apiSettings.version,
    error: message
  })
}

/**
 * Maps incoming fields to our document fields.
 * fieldMaps is an array of objects that must be like this example:
 *
 * {
 *   "ours": "title",
 *   "theirs": "name"
 * }
 *
 * Values can be paths to nested fields like "address.city"
 *
 * Any field in data that is not in fieldMaps will be ignored.
 *
 * @param {string} collection
 * @param {object} data
 * @param {Array<object>}fieldMaps
 * @returns {object} - The mapped data
 */
const mapFieldsIncoming = (collection, data, fieldMaps) => {
  const mappedData = {}

  for (const [mapCollection, maps] of Object.entries(fieldMaps)) {
    if (mapCollection === collection) {
      for (const map of maps) {
        if (_.get(data, map['theirs'])) {
          _.set(mappedData, map['ours'], _.get(data, map['theirs']))
        } else if (map['value']) {
          _.set(mappedData, map['ours'], map['value'])
        }
      }
    }
  }

  return mappedData
}

/**
 * Maps the fields in the document according to fieldMaps.
 * fieldMaps is an array of objects that must be like this example:
 *
 * {
 *   "ours": "title",
 *   "theirs": "name"
 * }
 *
 * Values can be paths to nested fields like "address.city"
 *
 * This function expects a document reference but returns the raw data.
 *
 * Any field in the doc that is not in fieldMaps will be left as is.
 *
 * @param {string} collection
 * @param {object} data
 * @param {Array<objects>} fieldMaps
 * @returns {object} - The mapped document data.
 */
const mapFieldsOutgoing = (collection, data, fieldMaps) => {
  const mappedDoc = _.cloneDeep(data)

  for (const [mapCollection, maps] of Object.entries(fieldMaps)) {
    if (mapCollection === collection) {
      for (const map of maps) {
        _.set(mappedDoc, map['theirs'], _.get(mappedDoc, map['ours']))
        _.unset(mappedDoc, map['ours'])
      }
    }
  }

  return mappedDoc
}

const baseAPI = functions.https.onRequest(app)

module.exports = {
  baseAPI,
  mapFieldsIncoming,
  mapFieldsOutgoing
}
