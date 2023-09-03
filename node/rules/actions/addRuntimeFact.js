/**
 * @module rules
 * @author Jim McGowen <jim@solardrive.io>
 */

/**
 * Adds a runtime fact to the rules engine for a chained rule.
 * 
 * See https://github.com/CacheControl/json-rules-engine
 * See https://solardrive.atlassian.net/wiki/spaces/S/pages/26902529/Solardrive+Rules+Engine
 * 
 * @param {Array} params Dynamic params passed in from the rules json. Also always includes the almanac.
 */
const addRuntimeFact = async (params) => {
  // sanity check
  if (params.factName === undefined || params.value === undefined) {
    console.error('Missing "factName" and/or "value" in prams sent to addRuntimeFace.')
  }

  params.almanac.addRuntimeFact(params.factName, params.value)
}

module.exports = {
  addRuntimeFact
}
