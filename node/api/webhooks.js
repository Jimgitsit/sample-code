/**
 * @module api
 * @author Jim McGowen
 */
'use strict'

const axios = require("axios")
const { addDoc, getAllDocs } = require('../firestore')
const { getSettings } = require('../utils')
const { mapFieldsOutgoing } = require('./api')

/**
 * Broadcasts to all webhooks that match the collection and event trigger
 * for allowed collections. Called for all doc changes (see triggers.js).
 *
 * @param {object} snap - QueryDocumentSnapshot
 * @param {string} trigger - 'create', 'update', or 'delete'
 * @returns {Promise<void>}
 */
const runWebhooks = async (snap, trigger) => {
  const webhooks = await getAllDocs('webhooks')
  const apiSettings = await getSettings('api')

  if (apiSettings.debug) {
    console.log('Running webhooks...')
  }

  webhooks.forEach((webhook) => {
    const { allowedCollections } = apiSettings
    if (Array.isArray(allowedCollections) && allowedCollections.includes(snap.ref.parent.id)
      && (webhook.collections === undefined
        || webhook.collections === []
        || webhook.collections.includes(snap.ref.parent.id))
      && webhook.events.includes(trigger))
    {
      if (apiSettings.debug) {
        console.log('Running webhook for: ', snap.ref.path)
      }

      callWebhook(webhook, snap.data(), trigger, snap.ref.parent.id)
    }
  })
}

/**
 * Sends a POST request to the url with the data.
 *
 * The token is passed in the payload header as X-Token and can be used by the
 * client to verify the authenticity of the request.
 *
 * @param {object} webhook - The webhook object (webhook document from the webhooks collection)
 * @param {string} data - The raw data to send
 * @param {string} trigger - The event that triggered the webhook (create, update, or delete)
 * @param {string} collection - The collection name from which the webhook was triggered
 */
const callWebhook = async (webhook, data, trigger, collection) => {
  const payload = await getPayload(webhook, data, trigger, collection)

  const config = {
    method: 'POST',
    url: webhook.url,
    data: payload,
    headers: {
      accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Token': webhook.token
    },
    timeout: 10000
  }

  // Make the POST request
  try {
    const resp = await axios(config)
    await addLog(payload, resp)
  } catch (error) {
    if (error.response) {
      await addLog(payload, error.response)
    } else {
      await addLog(payload, {
        status: error.errno,
        statusText: error.message
      })

      // TODO: Needs to retry on failure
    }
  }
}

/**
 * Add a webhook.
 *
 * See https://solardrive.atlassian.net/l/cp/fUr7x89W and https://solardrive.atlassian.net/l/cp/tyu83DoU
 * for a description of the webhook object expected in the webhook parameter.
 *
 * Will return false on any exception and any missing required fields.
 *
 * @param {object} webhook
 * @returns {Promise<object>|boolean} Returns the webhook object with ID and token.
 */
const addWebhook = async (webhook) => {
  try {
    const newWebhook = {
      "collection": webhook.collection,
      "events": webhook.events,
      "fields": webhook.fields ? webhook.fields : [],
      "maxRetries": webhook.maxRetries ? webhook.maxRetries : 0,
      "retryIntervalMinutes": webhook.retryIntervalMinutes ? webhook.retryIntervalMinutes : 5,
      "title": webhook.title,
      "token": "",
      "url": webhook.url
    }

    // Generate a random token
    newWebhook.token = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15)

    return await addDoc('webhooks', newWebhook)
  } catch (error) {
    console.error("Error adding webhook: ", error)
    return false
  }
}

/**
 * Returns the payload object to send to the client.
 *
 * @param {object} webhook - The webhook object (webhook document from the webhooks collection)
 * @param {string} data - The raw data to send
 * @param {string} trigger - The event that triggered the webhook (create, update, or delete)
 * @param {string} collection - The collection name from which the webhook was triggered
 * @param {number} retryCount
 */
const getPayload = async (webhook, data, trigger, collection, retryCount = 0) => {
  const settings = await getSettings('api')

  const payload = {
    "version": settings.version,
    "retries": retryCount,
    "timestamp": new Date().toISOString(),
    "event": trigger
  }

  // Get data with mapped fields
  const { fieldMaps } = webhook
  if (fieldMaps) {
    payload.object = {
      original: data,
      mapped: mapFieldsOutgoing(collection, data, fieldMaps)
    }
  } else {
    payload.object = data
  }

  return payload
}

/**
 * Adds a document to the webhookLog collection.
 *
 * @param {object} payload
 * @param {object} resp The response from the client
 */
const addLog = async (payload, resp) => {
  const log = {
    retryCount: 0,
    payload,
    respCode: resp.status,
    respText: resp.statusText,
    success: resp.status === 200
  }

  await addDoc('webhookLog', log)
}

module.exports = {
  addWebhook,
  runWebhooks
}
