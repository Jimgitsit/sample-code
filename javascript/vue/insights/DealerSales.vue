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
            <DTCell header>&nbsp;</DTCell>
            <DTCell header center>WTD</DTCell>
            <DTCell header center>MTD</DTCell>
            <DTCell header center>QTD</DTCell>
            <DTCell header center>YTD</DTCell>
          </DTRow>
        </DTHead>
        <DTBody>
          <DTRow>
            <DTCell>
              <span class="font-bold">Net</span>
            </DTCell>
            <DTCell center>
              {{ salesData?.net && formatNumber(salesData.net.wtd) }}
            </DTCell>
            <DTCell center>
              {{ salesData?.net && formatNumber(salesData.net.mtd) }}
            </DTCell>
            <DTCell center>
              {{ salesData?.net && formatNumber(salesData.net.qtd) }}
            </DTCell>
            <DTCell center>
              {{ salesData?.net && formatNumber(salesData.net.ytd) }}
            </DTCell>
          </DTRow>
          <DTRow>
            <DTCell>
              <span class="font-bold">Gross</span>
            </DTCell>
            <DTCell center>
              {{ salesData?.gross && formatNumber(salesData.gross.wtd) }}
            </DTCell>
            <DTCell center>
              {{ salesData?.gross && formatNumber(salesData.gross.mtd) }}
            </DTCell>
            <DTCell center>
              {{ salesData?.gross && formatNumber(salesData.gross.qtd) }}
            </DTCell>
            <DTCell center>
              {{ salesData?.gross && formatNumber(salesData.gross.ytd) }}
            </DTCell>
          </DTRow>
        </DTBody>
      </DTTable>
  </Card>
</template>

<script setup>
import { formatNumber } from '../../composables/useSDFormatters'
import { getCompanyStatsQuery, normalizeSalesReportData } from './staticReports'
import { useDatabase, useDatabaseObject } from 'vuefire'
import { ref } from 'vue'

const rtdb = useDatabase()

const salesData = ref(null)
const activeUnit = ref('volume')

const title = 'Sales'

// Reactive db query
const salesQueryRef = computed(() => {
  // TODO: Need to use activeUnit inside computed so it gets watched. Better way?
  const notUsed = activeUnit.value
  return getCompanyStatsQuery()
})

// Initialize the report data
const salesReportDb = useDatabaseObject(salesQueryRef)

// Watch for changes to the report data and normalize for the UI
watch(salesReportDb, (newData) => {
  salesData.value = normalizeSalesReportData(newData, activeUnit.value)
})
</script>

<style lang="scss">
.formkit-inner select + span {
  display: none !important
}
</style>
