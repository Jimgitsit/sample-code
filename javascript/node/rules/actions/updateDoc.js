/**
 * @module rules
 * @author Jim McGowen
 */
'use strict'
const { setDoc, convertTimestamps } = require('../../firestore')
const { mapFieldsIncoming } = require('../../api/api')

/**
 * Action to update an existing doc in the database.
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
module.exports.updateDoc = async (params) => {
  const id = params.id ? params.id : null
  const merge = params.merge ? params.merge : true

  if (!params.collection || !params.data) {
    console.error('Error: Missing "collection" or "data" in newDoc action.')
    return
  }

  // If this is called from an api ruleset then check the request method and content-type
  if (params['facts']['request']) {
    if (params['facts']['request'].value.method !== 'POST') {
      throw 'Error: Only POST requests are accepted for update methods.'
    }

    if (params['facts']['request'].value.headers['content-type'] !== 'application/json') {
      throw 'Error: content-type must be application/json.'
    }
  }

  let data = {}
  if (params['facts']['fieldMaps']) {
    data = mapFieldsIncoming(params.collection, params.data, params['facts']['fieldMaps'].value)
  } else {
    data = params.data
  }

  data = convertTimestamps(data)

  const success = await setDoc(params.collection, id, data, merge)

  return {
    success: success
  }
}
