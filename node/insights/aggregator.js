/**
 * @module Sales/stats
 * @author Jim McGowen <jim@solardrive.io>
 */
'use strict'

const { rtdb, db } = require('../admin')
const { getDoc } = require('../firestore')
const { getSettings, hasProp } = require('../utils')
const dayjs = require('dayjs')
const _ = require('lodash')

const STATS_COLLECTION_NAME = 'stats'
const STATS_RTDB_ROOT_NAME = 'stats'
const TRIGGER = { create: 'create', update: 'update', delete: 'delete' }

/**
 * Handler for new stat documents.
 * Increments all aggregated stats as needed.
 *
 * @param {string} statId ID of the stat document
 * @param {object} stat The stat document data.
 * @param {string} onlyEntityType (optional) Entity/segment name 'teams' | 'members' | etc...
 *                   If onlyEntityType is provided, only this entity type be aggregated despite
 *                   any other entities specified in the stat document. If onlyEntityType but
 *                   not onlyEntityId, then all ID for this entity type will be aggregated.
 * @param {string} onlyEntityId (optional) An ID. If this and onlyEntityType are provided, only this entity will be
 *                   aggregated despite any other entity IDs specified in the stat document.
 */
const onStatCreate = async (statId, stat, onlyEntityType = undefined, onlyEntityId = undefined) => {
  try {
    const { dateTime, entities } = stat

    // If dateTime is in the future, ignore it. Can happen with test or demo data.
    if (dayjs.unix(dateTime.seconds).isAfter(dayjs())) {
      return
    }

    // For each entity type
    for (const [entityType, entityIds] of Object.entries(entities)) {
      if (onlyEntityType === undefined || onlyEntityType === entityType) {
        // For each entity ID
        for (const entityId of entityIds) {
          if (onlyEntityId === undefined || onlyEntityId === entityId) {
            // Check to see if this stat has already been aggregated
            const exists = await statRefExists(entityType, entityId, statId)
            if (!exists) {
              const newAggregatedData = await getNewAggregatedStats(TRIGGER.create, stat, entityType, entityId)
              newAggregatedData.name = await getMemberName(entityId)
              await saveAggregatedStats(stat, statId, entityType, entityId, newAggregatedData)
            }
          }
        }
      }
    }

    // Add company stats
    const newAggregatedData = await getNewAggregatedStats(TRIGGER.create, stat, 'company', '')
    const companySettings = await getSettings('company')
    newAggregatedData.name = companySettings?.name || 'Unknown'
    await saveAggregatedStats(stat, statId, 'company', '', newAggregatedData)
  } catch (e) {
    console.error('Exception in aggregator.onStatCreate: ', e)
  }
}

/**
 * Handler for updated stat documents.
 * Simply calls delete on the before data and create on the after data.
 *
 * @param {string} statId ID of the project the stat came from.
 * @param {object} statBefore The stat document data before the update.
 * @param {object} statAfter The stat document data after the update.
 */
const onStatUpdate = async (statId, statBefore, statAfter) => {
  await onStatDelete(statId, statBefore)
  await onStatCreate(statId, statAfter)
}

/**
 * Handler for deleted stat documents.
 * Deletion of a stat doc is the opposite of creating one in that
 * it will decrement aggregated values.
 *
 * @param {string} statId ID of the stat document
 * @param {object} stat The stat document data.
 */
const onStatDelete = async (statId, stat) => {
  try {
    const { entities } = stat
    for (const [entityType, entityIds] of Object.entries(entities)) {
      for (const entityId of entityIds) {
        // Check to see if this stat has been aggregated
        const exists = await statRefExists(entityType, entityId, statId)
        if (exists) {
          const newAggregatedData = await getNewAggregatedStats(TRIGGER.delete, stat, entityType, entityId)
          await saveAggregatedStats(stat, statId, entityType, entityId, newAggregatedData, true)
        }
      }
    }
  } catch (e) {
    console.error('Exception in aggregator.onStatDelete: ', e)
  }
}

/**
 * Creates or merges stat data into aggregated stats.
 * Checks if the stat has been processed already and if so ignores it.
 * After writing the stats, runs and caches all static reports.
 *
 * @param {object} stat The stat data.
 * @param {string} statId The stat doc ID.
 * @param {string} entityType The entity type from the stat doc
 * @param {string} entityId The entity ID from the stat doc
 * @param {object} newAggregatedData
 * @param {boolean} isRemove
 */
const saveAggregatedStats = async (stat, statId, entityType, entityId, newAggregatedData, isRemove = false) => {
  await writeAggregatedStats(entityType, entityId, newAggregatedData)

  if (isRemove) {
    await removeStatRef(entityType, entityId, statId)
  } else {
    await writeStatRef(entityType, entityId, statId)
  }
}

/**
 * Returns aggregated stats from an individual stat record. Suitable for merging
 * into and existing aggregated_stats document. Makes use of the firestore
 * increment functionality.
 *
 * @param {TRIGGER} trigger The trigger that precipitated this aggregation.
 * @param {object} stat The stat data.
 * @param {string} entityType The entity type from the stat doc
 * @param {string} entityId The entity ID from the stat doc
 * @returns {object} The relevant portion of the aggregated_stats document.
 */
const getNewAggregatedStats = async (trigger, stat, entityType, entityId) => {
  try {
    // Get the existing stats if any
    const path = entityType === 'company'
      ? `${STATS_RTDB_ROOT_NAME}/${entityType}`
      : `${STATS_RTDB_ROOT_NAME}/${entityType}/${entityId}`
    const ref = rtdb.ref(path)
    const snap = await ref.once('value')
    const stats = snap.val() || {years: {}}

    // Extract the metadata
    const metaData = {}
    for (let [name, value] of Object.entries(stat.meta)) {
      // Ignore any meta value that's not a number
      if (typeof value === 'number') {
        metaData[name] = value
      }
    }

    // Explode the stat datetime
    const statDate = dayjs.unix(stat.dateTime.seconds)
    stat.year = statDate.year()
    stat.quarter = statDate.quarter()
    stat.month = statDate.month() + 1
    stat.week = statDate.week()

    // Add stats for each period
    const periods = ['weeks', 'months', 'quarters', 'all']
    for (const period of periods) {
      const statPeriod = period.slice(0, -1)

      // Set each meta value
      for (let [name, value] of Object.entries(metaData)) {
        // Invert the value to subtract if deleted
        if (trigger === TRIGGER.delete) {
          value *= -1
        }

        let newStat
        const existingStat = _.get(stats, `years.${stat.year}.${period}`)
        // Add new and existing values
        if (period === 'all') {
          newStat = { [stat.type]: { [name]: value } }
          newStat[stat.type][name] += existingStat?.[stat.type]?.[name] || 0
        } else {
          newStat = { [stat[statPeriod]]: { [stat.type]: { [name]: value } } }
          newStat[stat[statPeriod]][stat.type][name] += existingStat?.[stat[statPeriod]]?.[stat.type]?.[name] || 0
        }
        _.set(stats, `years.${stat.year}.${period}`, _.merge(existingStat, newStat))
      }

      // Set net Stats (sold minus cancelled)
      for (let [name, value] of Object.entries(metaData)) {
        // net = installed minus canceled, so we only care about those stat types
        if (stat.type !== 'cancelled' && stat.type !== 'sold') {
          continue
        }

        // Invert the value to subtract if canceled or deleted
        if (stat.type === 'cancelled' || trigger === TRIGGER.delete) {
          value *= -1
        }

        let newStat
        const existingStat = _.get(stats, `years.${stat.year}.${period}`)
        // Add new and existing values
        if (period === 'all') {
          newStat = { net: { [name]: value } }
          newStat.net[name] += existingStat?.net?.[name] || 0
        } else {
          newStat = { [stat[statPeriod]]: { net: { [name]: value } } }
          newStat[stat[statPeriod]].net[name] += existingStat?.[stat[statPeriod]]?.net?.[name] || 0
        }
        _.set(stats, `years.${stat.year}.${period}`, _.merge(existingStat, newStat))
      }
    }

    // Add company stats
    // Set each meta value
    for (let [name, value] of Object.entries(metaData)) {
      // Invert the value to subtract if deleted
      if (trigger === TRIGGER.delete) {
        value *= -1
      }

      const existingStat = _.get(stats, 'all')
      // Add new and existing values
      const newStat = { [stat.type]: { [name]: value } }
      newStat[stat.type][name] += existingStat?.[stat.type]?.[name] || 0
      _.set(stats, 'all', _.merge(existingStat, newStat))
    }

    // Set net Stats (sold minus cancelled)
    for (let [name, value] of Object.entries(metaData)) {
      // net = installed minus canceled, so we only care about those stat types
      if (stat.type !== 'cancelled' && stat.type !== 'sold') {
        continue
      }

      // Invert the value to subtract if canceled or deleted
      if (stat.type === 'cancelled' || trigger === TRIGGER.delete) {
        value *= -1
      }

      const existingStat = _.get(stats, 'all')
      // Add new and existing values
      const newStat = { net: { [name]: value } }
      newStat.net[name] += existingStat?.net?.[name] || 0
      _.set(stats, 'all', _.merge(existingStat, newStat))
    }

    return stats
  } catch (e) {
    console.error('Exception in getNewAggregatedStats: ', e)
    console.error('trigger: ', trigger, '\nstat:\n', stat)
    throw e
  }
}

/**
 * Updates or creates the aggregated data for the provided entity.
 * Always merges new data with existing data.
 *
 * @param {string} entityType The entity type (collection name)
 * @param {string} entityId The entity ID
 * @param {object} newData
 */
const writeAggregatedStats = async (entityType, entityId, newData) => {
  const path = entityType === 'company'
    ? `${STATS_RTDB_ROOT_NAME}/${entityType}`
    : `${STATS_RTDB_ROOT_NAME}/${entityType}/${entityId}`
  const ref = rtdb.ref(path)
  ref.update(newData)
}

/**
 * Write the stat ref to the aggregated stats doc.
 *
 * @param {string} entityType The entity type (collection name)
 * @param {string} entityId The entity ID
 * @param {string} statId The stat ID
 */
const writeStatRef = async (entityType, entityId, statId) => {
  const path = entityType === 'company'
    ? `${STATS_RTDB_ROOT_NAME}/${entityType}/refs`
    : `${STATS_RTDB_ROOT_NAME}/${entityType}/${entityId}/refs`
  const ref = rtdb.ref(path)
  const snap = await ref.once('value')
  /** @type {Array} */
  const statRefs = snap.exists() ? snap.val() : []

  statRefs.push(`${STATS_COLLECTION_NAME}/${statId}`)
  ref.set(statRefs)
}

/**
 * Remove the stat ref from the aggregated stats doc.
 *
 * @param {string} entityType The entity type (collection name)
 * @param {string} entityId The entity ID
 * @param {string} statId The stat ID
 */
const removeStatRef = async (entityType, entityId, statId) => {
  const path = entityType === 'company'
    ? `${STATS_RTDB_ROOT_NAME}/${entityType}/refs`
    : `${STATS_RTDB_ROOT_NAME}/${entityType}/${entityId}/refs`
  const ref = rtdb.ref(path)
  const snap = await ref.once('value')

  if (snap.exists()) {
    /** @type {Array} */
    const refs = snap.val()
    const newRefs = refs.filter(el => el !== `${STATS_COLLECTION_NAME}/${statId}`)
    ref.set(newRefs)
  }
}

/**
 * Helper function to check if the stat was aggregated by checking the stat refs.
 *
 * @param {string} entityType The entity type (collection name)
 * @param {string} entityId The entity ID
 * @param {string} statId The stat ID
 * @returns {Promise<boolean>} true if it exists, false if not
 */
const statRefExists = async (entityType, entityId, statId) => {
  const path = entityType === 'company'
    ? `${STATS_RTDB_ROOT_NAME}/${entityType}/refs`
    : `${STATS_RTDB_ROOT_NAME}/${entityType}/${entityId}/refs`
  const ref = rtdb.ref(path)
  const snap = await ref.once('value')

  if (snap.exists()) {
    /** @type {Array} */
    const refs = snap.val()
    return refs.includes(`${STATS_COLLECTION_NAME}/${statId}`)
  } else {
    return false
  }
}

/**
 * Re-aggregate all stats for the provided entity. If entityType is 'company', then ALL stats will be re-aggregated.
 *
 * @param {string} entityType
 * @param {string} entityId
 * @returns {Promise<void>}
 */
const reAggregate = async (entityType, entityId) => {
  // Delete existing stats
  const path = entityType === 'company'
    ? `${STATS_RTDB_ROOT_NAME}/${entityType}`
    : `${STATS_RTDB_ROOT_NAME}/${entityType}/${entityId}`
  const ref = rtdb.ref(path)
  await ref.set(null)

  const statsRef = db.collection('stats')
  let snapshot = null
  if (entityType === 'company') {
    // Delete all stats for the company
    const ref = rtdb.ref('stats/company')
    await ref.set(null)

    // Get ALL stats!
    snapshot = await statsRef.get()
  } else {
    // Delete all stats for the entity
    const ref = rtdb.ref(`stats/${entityType}/${entityId}`)
    await ref.set(null)

    // Get only stats that reference the entity
    const statsQuery = statsRef.where(`entities.${entityType}`, 'array-contains', entityId)
    snapshot = await statsQuery.get()
  }

  if (!snapshot) {
    console.log(`No stats found for ${entityType}/${entityId}. Can't re-aggregate.`)
    return
  }

  // Call onStatCreate for each stat
  for (const doc of snapshot.docs) {
    const stat = doc.data()
    if (entityType === 'company') {
      await onStatCreate(doc.ref.id, stat)
    } else {
      await onStatCreate(doc.ref.id, stat, entityType, entityId)
    }
  }
}

/**
 * Derives the name from a member entity. If no 'members' doc is found or it is missing
 * name properties, it will return 'Unknown'.
 *
 * @param {string} memberId
 * @returns {Promise<string>} The name of the entity
 */
const getMemberName = async (memberId) => {
  const entity = await getDoc('members', memberId)

  let name = 'Unknown'

  if (entity && hasProp(entity, 'name')) {
    const { full, first, last } = entity.name
    if (full) {
      name = full
    } else if (first && last) {
      name = `${first} ${last}`
    } else {
      console.error(`Member ${memberId} is missing "name" properties, "full", "first", and/or "last".`)
    }
  } else {
    console.error(`Member ${memberId} does not exist or is missing the "name" property..`)
    return 'Unknown'
  }

  return name
}

module.exports = {
  onStatCreate,
  onStatUpdate,
  onStatDelete,
  reAggregate
}
