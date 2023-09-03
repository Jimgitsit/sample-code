/**
 * @module rules
 * @author Jim McGowen <jim@solardrive.io>
 */
'use strict'
const { getDoc } = require('../../firestore')
const { mapFieldsOutgoing } = require('../../api/api')

/**
 * Action to get a document from the database.
 *
 * If fieldMaps are defined in the ruleset, then the returned document will have
 * the fields mapped accordingly
 * 
 * Example params:
 * {
 *   "collection": "projects",
 *   "id": "KnxWMvTY1veyVBHD8Qqb"
 * }
 * 
 * @param {object} params
 * @returns {Promise<object>} - The document data.
 */
module.exports.getDoc = async (params) => {
  const id = params.id ? params.id : null
  
  if (!params.collection || !params.id) {
    throw 'Error: Missing "collection" or "id" in getDoc action.'
  }

  // If this is called from an api ruleset then check the request method
  if (params['facts']['request']) {
    if (params['facts']['request'].value.method !== 'GET') {
      throw "Error: Only GET requests are accepted."
    }
  }

  let doc = await getDoc(params.collection, id, true)
  if (!doc) {
    throw "Error: Document not found."
  }

  if (params['facts']['fieldMaps']) {
    doc = mapFieldsOutgoing(doc.ref.parent.id, doc.data(), params['facts']['fieldMaps'].value)
  } else {
    doc = doc.data()
  }

  return doc
}
