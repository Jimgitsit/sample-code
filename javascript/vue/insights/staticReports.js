/**
 * @module stores/insights
 * @author Jim McGowen
 */
'use strict'

import dayjs from 'dayjs'
import quarterOfYear from 'dayjs/plugin/quarterOfYear'
import weekOfYear from 'dayjs/plugin/weekOfYear'
import _ from 'lodash'

dayjs.extend(quarterOfYear)
dayjs.extend(weekOfYear)

import {
  getDatabase, ref as rtdbRef, query as rtdbQuery,
  orderByChild, limitToFirst, limitToLast, onValue, startAt
} from 'firebase/database'

/**
 * Returns normalized data for the top 10 reports
 *
 * @param {string} period - 'all', 'weeks', 'months', 'quarters', 'years'
 * @param {string} orderBy - 'gross', 'net', 'installs'
 * @param {string} role - 'reps', 'leadSetters', 'teams'
 * @param {string} unit - 'watts', 'volume'
 * @param {number} limit - Optional, Number of rows to return. Default is 10.
 * @param {function} callback - Will be passed the normalized data
 * @return {Promise<object>} - The report data
 */
export const getTop10Report = async (period, orderBy, unit, role, limit = 10, callback = undefined) => {
  const queryRef = getTop10ReportQuery(period, orderBy, unit, role, limit)

  return new Promise((resolve, reject) => {
    onValue(queryRef, (snap) => {
      const top10 = snap.val()
      if (top10 === null) {
        // No data
        callback([])
        reject('No data for top 10 report.')
      }

      const report = normalizeTop10Data(top10, period, orderBy, unit)

      resolve(report)
    }, { onlyOnce: true })
  })
}

/**
 * Returns the query for a top 10 report based on the given parameters.
 *
 * @param {string} period - 'all', 'weeks', 'months', 'quarters', 'years'
 * @param {string} orderBy - 'gross', 'net', 'installs'
 * @param {string} role - 'reps', 'leadSetters', 'teams'
 * @param {string} unit - 'watts', 'volume'
 * @param {number} limit - Optional, Number of rows to return. Default is 10.
 * @returns {Query}
 */
export const getTop10ReportQuery = (period, orderBy, unit, role, limit = 10) => {
  // Determine stat type to order by
  let orderType
  switch (orderBy) {
    case 'gross':
      orderType = 'sold'
      break
    case 'installs':
      orderType = 'installed'
      break
    case 'net':
      orderType = 'net'
      break
    default:
      console.log('Invalid value for orderBy')
  }

  let currentPeriod = null
  if (period !== 'all') {
    currentPeriod = dayjs()[period.slice(0, -1)]()
    if (period === 'months') {
      // Months are 0 based in dayjs
      currentPeriod++
    }
  }

  const currentYear = dayjs().year()
  let orderPath = `years/${currentYear}`

  if (period === 'all') {
    orderPath = `all/`
  } else if (period === 'years') {
    orderPath += `/all/`
  } else {
    orderPath += `/${period}/${currentPeriod}/`
  }

  orderPath += `${orderType}/${unit}`

  const rtdb = getDatabase()

  return rtdbQuery(rtdbRef(rtdb, `stats/${role}`), orderByChild(orderPath), startAt(0), limitToLast(limit))
}

/**
 * Takes raw data obtained from the db and normalizes it for the report UI component.
 * Assumes data is already ordered by the desired top 10 value. Hence this can be used for
 * any top 10 list.
 *
 * @param {object} data - The raw data from the db. This assumes the data is already ordered.
 * @param {string} period - 'all', 'weeks', 'months', 'quarters', 'years'
 * @param {string} orderBy - 'gross', 'net', 'installs'
 * @param {string} unit - 'watts', 'volume'
 * @returns {Array} - Normalized table data. 2-dimensional array representing rows and columns.
 */
export const normalizeTop10Data = (data, period, orderBy, unit) => {
  let prevValue = -1
  let rank = 0
  let report = []

  // Sanity check. As long as there is data for the current periods
  // this should never happen. But just in case.
  if (!data) {
    return report
  }

  let currentPeriod = null
  if (period !== 'all') {
    currentPeriod = dayjs()[period.slice(0, -1)]()
    if (period === 'months') {
      // Months are 0 based in dayjs, 1 based for us
      currentPeriod++
    }
  }

  const currentYear = dayjs().year()
  let pathPrefix = `years.${currentYear}`

  if (period === 'all') {
    pathPrefix = `all`
  } else if (period === 'years') {
    pathPrefix += `.all`
  } else {
    pathPrefix += `.${period}.${currentPeriod}`
  }

  for (const rowData of data) {
    const netData = _.get(rowData, `${pathPrefix}.net`, {})
    const grossData = _.get(rowData, `${pathPrefix}.sold`, {})
    const originalRank = rank

    const name = rowData?.name || 'Unknown'
    const grossSales = grossData?.contract || 0
    const netSales = netData?.contract || 0
    const installs = netData?.volume || 0
    const grossWatts = grossData?.watts || 0
    const netWatts = netData?.watts || 0

    switch (orderBy) {
      case 'gross': {
        if (prevValue !== grossSales) {
          rank === originalRank && rank++
          prevValue = grossSales
        }
        break
      }
      case 'net': {
        if (prevValue !== netSales) {
          rank === originalRank && rank++
          prevValue = netSales
        }
        break
      }
      case 'installs': {
        if (prevValue !== installs) {
          rank === originalRank && rank++
          prevValue = installs
        }
        break
      }
    }

    switch (unit) {
      case 'watts': {
        if (prevValue !== grossWatts) {
          rank === originalRank && rank++
          prevValue = grossWatts
        }
        break
      }
      case 'volume': {
        if (prevValue !== netWatts) {
          rank === originalRank && rank++
          prevValue = netWatts
        }
        break
      }
    }

    report.push({
      rank: rank,
      name: name,
      net_sales: netSales,
      gross_sales: grossSales,
      net_watts: netWatts,
      gross_watts: grossWatts,
      installs: installs
    })
  }

  return report
}

/**
 * Returns normalized data for the dealer sales report.
 *
 * @param {string} unit - 'watts', 'volume'
 */
export const getSalesReport = async (unit) => {
  const queryRef = getCompanyStatsQuery()

  return new Promise((resolve, reject) => {
    onValue(queryRef, (snap) => {
      const data = snap.val()
      const report = normalizeSalesReportData(data, unit)

      resolve(report)
    }, { onlyOnce: true })
  })
}

/**
 * Takes raw data obtained from the db and normalizes it for the report UI component.
 *
 * @param {object} data
 * @param {string} unit - 'watts', 'volume'
 * @returns {object} - Normalized table data.
 */
export const normalizeSalesReportData = (data, unit) => {
  const curYear = dayjs().year()
  const curQuarter = dayjs().quarter()
  const curMonth = dayjs().month() + 1
  const curWeek = dayjs().week()

  return {
    gross: {
      ytd: data?.years?.[curYear]?.all?.sold?.[unit] || 0,
      qtd: data?.years?.[curYear]?.quarters[curQuarter]?.sold?.[unit] || 0,
      mtd: data?.years?.[curYear]?.months[curMonth]?.sold?.[unit] || 0,
      wtd: data?.years?.[curYear]?.weeks[curWeek]?.sold?.[unit] || 0
    },
    net: {
      ytd: data?.years?.[curYear]?.all?.net?.[unit] || 0,
      qtd: data?.years?.[curYear]?.quarters[curQuarter]?.net?.[unit] || 0,
      mtd: data?.years?.[curYear]?.months[curMonth]?.net?.[unit] || 0,
      wtd: data?.years?.[curYear]?.weeks[curWeek]?.net?.[unit] || 0
    }
  }
}

/**
 * Returns raw data for an installs report.
 *
 * @param {string} unit - 'watts', 'volume'
 * @returns {Promise<object>}
 */
export const getInstallsReport = async (unit) => {
  const queryRef = getCompanyStatsQuery()

  return new Promise((resolve, reject) => {
    onValue(queryRef, (snap) => {
      const data = snap.val()
      const report = normalizeInstallsReportData(data, unit)

      resolve(report)
    }, { onlyOnce: true })
  })
}

/**
 * Takes raw data obtained from the db and normalizes it for the report UI component.
 *
 * @param data
 * @param {string} unit - 'watts', 'volume'
 * @returns {object} - Normalized table data.
 */
export const normalizeInstallsReportData = (data, unit) => {
  const curYear = dayjs().year()
  const curQuarter = dayjs().quarter()
  const curMonth = dayjs().month() + 1
  const curWeek = dayjs().week()

  return {
    ytd: data?.years?.[curYear]?.all?.installed?.[unit] || 0,
    qtd: data?.years?.[curYear]?.quarters?.[curQuarter]?.installed?.[unit] || 0,
    mtd: data?.years?.[curYear]?.months?.[curMonth]?.installed?.[unit] || 0,
    wtd: data?.years?.[curYear]?.weeks?.[curWeek]?.installed?.[unit] || 0
  }
}

/**
 * Returns the query to get ALL company stats.
 *
 * @returns {Query}
 */
export const getCompanyStatsQuery = () => {
  const rtdb = getDatabase()
  return rtdbQuery(rtdbRef(rtdb, 'stats/company'))
}
