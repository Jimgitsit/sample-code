<template>
  <Card>
      <DTTopBar :title="title">
        <FormKit
          type="dropdown"
          name="dealer_stats_order"
          :options="{'volume': 'Volume', 'watts': 'KW'}"
          select-icon="down"
          v-model="activeUnit"
        />
      </DTTopBar>
      <DTTable>
        <DTHead>
          <DTRow>
            <DTCell header center>WTD</DTCell>
            <DTCell header center>MTD</DTCell>
            <DTCell header center>QTD</DTCell>
            <DTCell header center>YTD</DTCell>
          </DTRow>
        </DTHead>
        <DTBody>
          <DTRow>
            <DTCell center>
              {{ installsData && formatNumber(installsData.wtd) }}
            </DTCell>
            <DTCell center>
              {{ installsData && formatNumber(installsData.mtd) }}
            </DTCell>
            <DTCell center>
              {{ installsData && formatNumber(installsData.qtd) }}
            </DTCell>
            <DTCell center>
              {{ installsData && formatNumber(installsData.ytd) }}
            </DTCell>
          </DTRow>
        </DTBody>
      </DTTable>
  </Card>
</template>

<script setup>
import { formatNumber } from '../../composables/useSDFormatters'
import { getCompanyStatsQuery, normalizeInstallsReportData } from './staticReports'
import { useDatabase, useDatabaseObject } from 'vuefire'
import { ref } from 'vue'

const rtdb = useDatabase()

const installsData = ref(null)
const activeUnit = ref('volume')

const title = 'Installs'

// Reactive db query
const installsQueryRef = computed(() => {
  // TODO: Need to use activeUnit inside computed so it gets watched. Better way?
  const notUsed = activeUnit.value
  return getCompanyStatsQuery()
})

// Initialize the report data
const installsReportDb = useDatabaseObject(installsQueryRef)

// Watch for changes to the report data and normalize for the UI
watch(installsReportDb, (newData) => {
  installsData.value = normalizeInstallsReportData(newData, activeUnit.value)
})
</script>
