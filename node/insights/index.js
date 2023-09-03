/**
 * @module Sales/stats
 * @author Jim McGowen <jim@solardrive.io>
 */
'use strict'
const { functions } = require('../admin')
const { onStatCreate, onStatUpdate, onStatDelete, reAggregate } = require('./aggregator')

const statDoc = functions.firestore.document(`stats/{statDocId}`)

/** Create trigger for the stats collection. */
const statOnCreate = statDoc.onCreate(async (snap, context) => {
  await onStatCreate(context.params.statDocId, snap.data())
})

/** Update trigger for the stats collection. */
const statOnUpdate = statDoc.onUpdate(async (snap, context) => {
  await onStatUpdate(context.params.statDocId, snap.before.data(), snap.after.data())
})

/** Delete trigger for the stats collection. */
const statOnDelete = statDoc.onDelete(async (snap, context) => {
  await onStatDelete(context.params.statDocId, snap.data())
})

/**
 * This function will create or recreate aggregated stats for a given entity.
 *
 * data should be in this format:
 *   {
 *     "entityType": entity/segment name 'teams' | 'members' | etc... | 'company' for all
 *     "entityId": doc id or null for 'company'
 *   }
 *
 * For example, to create stats for a single team:
 *   createAggregatedStats(
 *     {
 *       entityType: "teams",
 *       entityId: "0jNobDpGyFKrwBlq29Cy"
 *     }
 *   )
 *
 * or to create/recreate organizational stats (from ALL stat records):
 *   {
 *     "entityType": "company"
 *   }
 */
const reAggregateStats = functions.https.onCall(async (data) => {
  console.log('Creating aggregated stats...')

  const { entityType, entityId } = data
  if (entityType !== 'org' && (!entityType || !entityId)) {
    console.error('reAggregateStats: Missing entityType and/or entityId')
    throw 'reAggregateStats: Missing entityType and/or entityId'
  }

  await reAggregate(entityType, entityId)
})

/**
 * Helper to run the reAggregateStats callable for testing.
 *
 * URL: http://<host>(:<port>)/<project-url>/us-central1/insights-reAggregateStatsNow
 * Method: POST
 * Data:
 *  entityType: entity/segment name 'teams' | 'members' | etc... | 'company' for all
 *  entityId: doc id or null for 'company'
 */
const reAggregateStatsNow = functions.runWith({
  timeoutSeconds: 300,
  memory: '1GB'
}).https.onRequest(async (req, resp) => {
  let { entityType, entityId } = req.body
  entityType || console.log('Missing entityType in request body')
  entityId || console.log('Missing entityId in request body')

  if ((entityType && entityId) || entityType === 'company') {
    try {
      await reAggregate(entityType, entityId)
      resp.status(200).send('OK').end()
    } catch (err) {
      console.error(err)
      resp.status(500).send(err.message).end()
    }
  } else {
    resp.status(400).send('Bad Argument(s). Check log.').end()
  }
})

module.exports = {
  statOnCreate,
  statOnUpdate,
  statOnDelete,
  reAggregateStats,
  reAggregateStatsNow
}
