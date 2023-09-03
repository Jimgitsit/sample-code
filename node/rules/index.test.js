// This is an example test using jest
const { expect, test } = require('@jest/globals')
const { runScheduledRules } = require('./index')

test('Scheduled rules', async () => {
  // Check to make sure the function runScheduledRules is defined
  expect(runScheduledRules).toBeDefined()
})
