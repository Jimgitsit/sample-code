/**
 * @module rules
 * @author Jim McGowen
 */
'use strict'
const { addDoc, convertTimestamps } = require('../../firestore')
const { mapFieldsIncoming } = require('../../api/api')

/**
 * Action to add a doc in the database.
 * 
 * Example params:
 * {
 *   "id": "theDocId",
 *   "merge": false,
 *   "data": {
 *     ... <= the doc data
 *     "created": "timestamp:now", <= current timestamp
 *     "birthday": "timestamp:30070800" <= explicit time in epoch seconds
 *   }
 * }
 *
 * Timestamp values can be Firestore Timestamp objects or strings preceded by "timestamp:"
 * and can be either "now" or the number of milliseconds since the Unix epoch.
 * 
 * @param {object} params Required 'data' property. 'id' is optional and defaults 
 *                        to auto. 'merge' is optional and defaults to true.
 */
module.exports.addDoc = async (params) => {
  // If the rule does not pass the id into the action then we won't get it here.
  // So whether or not a 3rd party is allowed to make up their own IDs can be controlled with the rule.
  const id = params.id ? params.id : null

  if (!params.collection || !params.data) {
    console.error('Error: Missing "collection" or "data" in addDoc action.')
    return
  }

  // If this is called from an api ruleset then check the request method and content-type
  if (params['facts']['request']) {
    if (params['facts']['request'].value.method !== 'POST') {
      throw "Error: Only POST requests are accepted for update methods."
    }

    if (params['facts']['request'].value.headers['content-type'] !== 'application/json') {
      throw "Error: content-type must be application/json."
    }
  }

  let data = {}
  if (params['facts']['fieldMaps']) {
    data = mapFieldsIncoming(params.collection, params.data, params['facts']['fieldMaps'].value)
  } else {
    data = params.data
  }

  data = convertTimestamps(data)

  const docId = await addDoc(params.collection, data, id)

  return {
    success: !!docId,
    docId: docId
  }
}
