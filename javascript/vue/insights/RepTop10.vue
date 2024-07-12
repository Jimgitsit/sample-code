<template>
  <Card>
    <div class="w-full flex flex-col">
      <DTTopBar title="Top 10 Reps">
        <FormKit
          type="dropdown"
          name="rep_top_10_period"
          :options="{ years: 'YTD', quarters: 'QTD', months: 'MTD', weeks: 'WTD' }"
          select-icon="down"
          v-model="activePeriod"
        />
        <FormKit
          type="dropdown"
          name="rep_top_10_unit"
          placeholder="Unit"
          :options="{ volume: 'Volume', watts: 'KW' }"
          select-icon="down"
          v-model="activeUnit"
        />
      </DTTopBar>
      <DTTable>
        <DTHead>
          <DTRow>
            <DTCell header first>Rank</DTCell>
            <DTCell header>Name</DTCell>
            <DTCell
              header
              @click="activeUnit === 'watts' ? (activeOrder = 'net') : null"
              :class="{ 'cursor-pointer': activeUnit === 'watts' }"
            >
              <div class="relative inline-flex items-center">
                <span>{{ activeUnit === 'volume' ? 'Net Sales' : 'Net KW' }}</span>
              </div>
            </DTCell>
            <DTCell
              header
              @click="activeUnit === 'watts' ? (activeOrder = 'gross') : null"
              :class="{ 'cursor-pointer': activeUnit === 'watts' }"
            >
              <div class="relative inline-flex items-center">
                <span>{{ activeUnit === 'volume' ? 'Gross Sales' : 'Gross KW' }}</span>
              </div>
            </DTCell>
            <DTCell header>
              <div class="relative inline-flex items-center">
                <span>Net Installs</span>
              </div>
            </DTCell>
          </DTRow>
        </DTHead>
        <DTBody>
          <DTRow v-for="(item, index) in top10Report" :key="index">
            <DTCell first>{{ item.rank }}</DTCell>
            <DTCell>{{ item.name }}</DTCell>
            <DTCell :class="{ 'bg-highlight': activeUnit === 'watts' && activeOrder === 'net' }">
              {{
                activeUnit === 'volume'
                  ? formatCurrency(item.net_sales)
                  : formatNumber(item.net_watts)
              }}
            </DTCell>
            <DTCell :class="{ 'bg-highlight': activeUnit === 'watts' && activeOrder === 'gross' }">
              {{
                activeUnit === 'volume'
                  ? formatCurrency(item.gross_sales)
                  : formatNumber(item.gross_watts)
              }}
            </DTCell>
            <DTCell :class="{ 'bg-highlight': activeUnit === 'volume' }">
              {{ item.installs }}
            </DTCell>
          </DTRow>
        </DTBody>
      </DTTable>
    </div>
  </Card>
</template>

<script setup>
import { formatNumber, formatCurrency } from '../../composables/useSDFormatters'
import { getTop10ReportQuery, normalizeTop10Data } from './staticReports'
import { useDatabase, useDatabaseObject } from 'vuefire'
import { ref } from 'vue'
import _ from 'lodash'

const top10Report = ref(null)
const activeUnit = ref('watts')
const activePeriod = ref('years')
const activeOrder = ref('net')

// When switching to volume, we need to make sure we are ordered by net
// also remember the previous selection
let activeOrderSave = 'net'
watch(activeUnit, () => {
  if (activeUnit.value === 'volume') {
    activeOrderSave = activeOrder.value
    activeOrder.value = 'net'
  } else {
    activeOrder.value = activeOrderSave
  }
})

/**
 * Modified version of node_modules/.../vuefire/dist/index.mjs::createRecordFromDatabaseSnapshot
 * that doesn't use snapshot.val() when snapshot is multiple objects because it messes up the order.
 * Also reverses the order of the objects so they are descending.
 *
 * @param snapshot
 * @returns {{}|null}
 */
const customSerializer = (snapshot) => {
  if (!snapshot.exists()) return null

  const value = snapshot.val()
  if (snapshot.key === 'reps') {
    // Multiple objects.
    // Return our own array to maintain the order, (snapshot.val() reorders it)
    let objects = []
    snapshot.forEach((childSnapshot) => {
      objects.push({
        ...childSnapshot.val(),
        repId: childSnapshot.key
      })
    })
    // When we get all the reps at once they are in ascending order so we need to reverse it
    return _.reverse(objects)
  } else {
    // Single object
    // Seems that when we get each rep individually they are in descending order already. Why, I don't know.
    return {
      ...value,
      repId: snapshot.key
    }
  }
}

const rtdb = useDatabase()

// Reactive db query
const top10QueryRef = computed(() => {
  return getTop10ReportQuery(activePeriod.value, activeOrder.value, activeUnit.value, 'reps')
})

// Bind the query to the report data
const top10ReportData = useDatabaseObject(top10QueryRef, { serialize: customSerializer })

// Watch for changes to the report data or options and normalize it for the UI
watch(top10ReportData, (newData) => {
  top10Report.value = normalizeTop10Data(
    newData,
    activePeriod.value,
    activeOrder.value,
    activeUnit.value
  )
})
</script>
