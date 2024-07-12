/**
 * @module rules
 * @author Jim McGowen
 */

/**
 * Example of a action for the rules engine.
 *
 * @param {any} params
 */
const exampleAction = (params) => {
  console.log('This is an example action.')
  console.log('  params: ', params)
}

module.exports = {
  exampleAction
}
