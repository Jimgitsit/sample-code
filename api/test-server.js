/**
 * @module api
 * @author Jim McGowen <jim@solardrive.io>
 */
const http = require('http')
const StringDecoder = require('string_decoder').StringDecoder
const qs = require('querystring')

const host = 'localhost'
const port = 8081

/**
 * This is a simple HTTP server for testing webhooks that writes
 * the request header and body to the console. Data can be a urlencoded
 * or JSON string, will error if not.
 *
 * Use in Fusiondrive by adding a webhook this url:
 *    http://localhost:8081
 *
 * Run this server in a terminal with (might need sudo):
 *    node test-server.js
 *
 * To test manually send a request with:
 *    curl -H 'X-Token: 123456789' -d '{foo: bar}' -X POST http://localhost:8081
 */

const requestListener = function (req, res) {
  const decoder = new StringDecoder('utf-8')

  console.log('Method: ', req.method)
  console.log('Headers: ', req.headers)

  // For security, we would normally compare the 'X-Token' header value
  // with the token generated when they added the webhook. This is optional
  // but recommended to authenticate the call came from Solardrive.

  let data = ''
  req.on('data', (chunk) => {
    data += decoder.write(chunk)
  })

  req.on('end', () => {
    try {
      data += decoder.end()

      if (req.headers['content-type'] === 'application/json') {
        const jsonData = JSON.parse(data)
        console.log('Data: ', jsonData, '\n')
      } else if (req.headers['content-type'] === 'application/x-www-form-urlencoded') {
        console.log('Data: ', qs.parse(data), '\n')
      }

      res.writeHead(200, 'OK', { 'Content-Type': 'text/plain'})
    } catch (e) {
      console.log('Could not parse data as JSON')
      console.log('Data: ', data, '\n')
      res.writeHead(500, 'Internal Server Error: ' + e.message, { 'Content-Type': 'text/plain'})
    }
  })

  res.end('Done')
}

const server = http.createServer(requestListener);
server.listen(port, host, () => {
  console.log(`Server is listening on http://${host}:${port}`)
})
