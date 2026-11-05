// The bridge end to end: browser-side HTTP <-> bridge <-> SIMULATED Caisse-AP terminal over TCP.
import assert from 'node:assert/strict'
import crypto from 'node:crypto'
import fs from 'node:fs'
import http from 'node:http'
import net from 'node:net'
import os from 'node:os'
import path from 'node:path'
import { after, before, beforeEach, test } from 'node:test'
import { createDrawer, pulseBytes } from '../src/drawer.js'
import { createFakeTerminal } from '../src/fake-terminal.js'
import { createBridge, listen } from '../src/server.js'
import { publicKeyBase64 } from '../src/signer.js'
import { requestPayment } from '../src/terminal.js'

const ORIGIN = 'https://fluvia.example.test'
const { privateKey } = crypto.generateKeyPairSync('ed25519')
const fake = createFakeTerminal({ delayMs: 5 })
const printed = []
let printer
let bridge
let port
let terminalPort
let printerPort
let journal

function call(method, url, { body, origin = ORIGIN, host = `127.0.0.1:${port}`, type = 'application/json' } = {}) {
  return new Promise((resolve, reject) => {
    const headers = { Host: host }
    if (origin) headers.Origin = origin
    if (body !== undefined) headers['Content-Type'] = type
    const req = http.request({ host: '127.0.0.1', port, method, path: url, headers }, (res) => {
      let raw = ''
      res.setEncoding('utf8')
      res.on('data', (c) => { raw += c })
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, json: raw ? JSON.parse(raw) : null }))
    })
    req.on('error', reject)
    req.end(body === undefined ? undefined : JSON.stringify(body))
  })
}

const uuid = () => crypto.randomUUID()
const verify = ({ result, signature }) => crypto.verify(null, Buffer.from(result, 'utf8'), crypto.createPublicKey(privateKey), Buffer.from(signature, 'base64'))

before(async () => {
  terminalPort = await fake.listen()
  printer = net.createServer((s) => {
    const chunks = []
    s.on('data', (c) => chunks.push(c))
    s.on('end', () => printed.push(Buffer.concat(chunks)))
  })
  printerPort = await new Promise((r) => printer.listen(0, '127.0.0.1', () => r(printer.address().port)))
  journal = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'bridge-')), 'journal.jsonl')
  const config = {
    allowedOrigins: [ORIGIN],
    terminal: { host: '127.0.0.1', port: terminalPort, timeoutMs: 400, id: 'TPE-TEST' },
    journalPath: journal,
  }
  bridge = createBridge({ config, privateKey, drawer: createDrawer({ mode: 'tcp', host: '127.0.0.1', port: printerPort }, { minIntervalMs: 0 }) })
  port = (await listen(bridge, 0)).port
})

after(async () => {
  bridge.close()
  printer.close()
  await fake.close()
})

beforeEach(() => { fake.state.mode = 'accept' })

test('an accepted payment comes back signed, with the amount the terminal debited', async () => {
  const attempt = uuid()
  const r = await call('POST', '/v1/payments', { body: { attempt, amount: '45.00' } })
  assert.equal(r.status, 200)
  assert.ok(verify(r.json), 'the signature verifies with the bridge public key')
  const outcome = JSON.parse(r.json.result)
  assert.deepEqual(
    [outcome.attempt, outcome.requestedAmount, outcome.status, outcome.debitedAmount, outcome.authorization, outcome.terminal],
    [attempt, '45.00', 'accepte', '45.00', '123456', 'TPE-TEST'],
  )
  assert.equal(fake.state.requests.at(-1).CB, '4500', 'the terminal received the amount in cents')
  assert.ok(!JSON.stringify(outcome).includes('497010'), 'the card number never leaves the bridge')
})

test('A REFUSAL BY THE TERMINAL IS SIGNED AS A REFUSAL — never an acceptance', async () => {
  fake.state.mode = 'refuse'
  const r = await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '45.00' } })
  const outcome = JSON.parse(r.json.result)
  assert.deepEqual([outcome.status, outcome.debitedAmount, outcome.af], ['refuse', null, '04'])
  assert.ok(verify(r.json))
})

test('an abort on the terminal is a cancellation', async () => {
  fake.state.mode = 'abort'
  const r = await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '10.00' } })
  assert.equal(JSON.parse(r.json.result).status, 'annule')
})

test('a partial debit carries the debited amount', async () => {
  fake.state.mode = 'partial:3000'
  const r = await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '45.00' } })
  assert.deepEqual([JSON.parse(r.json.result).status, JSON.parse(r.json.result).debitedAmount], ['accepte', '30.00'])
})

test('the immediate acknowledgement (AE=11) is not taken for the outcome', async () => {
  fake.state.mode = 'ack-then-accept'
  const r = await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '5.00' } })
  assert.equal(JSON.parse(r.json.result).status, 'accepte')
})

test('a silent terminal or a hang-up is UNKNOWN (timeout): the cashier will read the terminal', async () => {
  fake.state.mode = 'silent'
  assert.equal(JSON.parse((await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '5.00' } })).json.result).status, 'timeout')
  fake.state.mode = 'hangup'
  assert.equal(JSON.parse((await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '5.00' } })).json.result).status, 'timeout')
})

test('an unreachable terminal is a cancellation: nothing was sent', async () => {
  const closed = await new Promise((r) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)) }) })
  const outcome = await requestPayment({ host: '127.0.0.1', port: closed, amount: '5.00' })
  assert.deepEqual([outcome.status, outcome.sent], ['annule', false])
})

test('the same attempt asked twice is debited ONCE and returns the same signed outcome', async () => {
  const attempt = uuid()
  const before = fake.state.requests.length
  const [a, b] = await Promise.all([
    call('POST', '/v1/payments', { body: { attempt, amount: '20.00' } }),
    call('POST', '/v1/payments', { body: { attempt, amount: '20.00' } }),
  ])
  const c = await call('POST', '/v1/payments', { body: { attempt, amount: '20.00' } })
  assert.equal(fake.state.requests.length - before, 1)
  assert.equal(a.json.signature, b.json.signature)
  assert.equal(a.json.signature, c.json.signature)
  const other = await call('POST', '/v1/payments', { body: { attempt, amount: '25.00' } })
  assert.equal(other.status, 409)
  assert.ok(fs.readFileSync(journal, 'utf8').includes(attempt), 'kept in the journal (survives a restart)')
})

test('another attempt while the terminal is busy is refused, not queued', async () => {
  fake.state.mode = 'silent'
  const first = call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '5.00' } })
  await new Promise((r) => setTimeout(r, 50))
  const second = await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '5.00' } })
  assert.deepEqual([second.status, second.json.code], [409, 'terminal_busy'])
  await first
})

test('another web page, another Host, or a non-JSON body is refused before reaching the terminal', async () => {
  const before = fake.state.requests.length
  const body = { attempt: uuid(), amount: '5.00' }
  assert.equal((await call('POST', '/v1/payments', { body, origin: 'https://evil.example' })).status, 403)
  assert.equal((await call('POST', '/v1/payments', { body, origin: null })).status, 403)
  assert.equal((await call('POST', '/v1/payments', { body, host: 'evil.example:80' })).status, 403, 'DNS rebinding')
  assert.equal((await call('POST', '/v1/payments', { body, type: 'text/plain' })).status, 415)
  assert.equal((await call('POST', '/v1/drawer/open', { body: {}, origin: 'https://evil.example' })).status, 403)
  assert.equal(fake.state.requests.length, before)
})

test('the preflight grants CORS and Private Network Access to the allowed origin only', async () => {
  const ok = await call('OPTIONS', '/v1/payments')
  assert.equal(ok.status, 204)
  assert.equal(ok.headers['access-control-allow-origin'], ORIGIN)
  assert.equal(ok.headers['access-control-allow-private-network'], 'true')
  const ko = await call('OPTIONS', '/v1/payments', { origin: 'https://evil.example' })
  assert.equal(ko.status, 403)
  assert.equal(ko.headers['access-control-allow-origin'], undefined)
})

test('invalid requests are refused', async () => {
  assert.equal((await call('POST', '/v1/payments', { body: { attempt: 'x', amount: '5.00' } })).status, 422)
  assert.equal((await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '5' } })).status, 422)
  assert.equal((await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '0.00' } })).status, 422)
  assert.equal((await call('POST', '/v1/payments', { body: { attempt: uuid(), amount: '5.00', currency: 'USD' } })).status, 422)
})

test('status gives the public key Fluvia must know', async () => {
  const r = await call('GET', '/v1/status')
  assert.equal(r.json.publicKey, publicKeyBase64(privateKey))
  assert.equal(Buffer.from(r.json.publicKey, 'base64').length, 32)
})

test('the drawer receives exactly the ESC/POS pulse ESC p 0 25 250', async () => {
  const r = await call('POST', '/v1/drawer/open', { body: {} })
  assert.equal(r.status, 200)
  await new Promise((res) => setTimeout(res, 50))
  assert.deepEqual([...printed.at(-1)], [0x1b, 0x70, 0x00, 25, 250])
  assert.deepEqual([...pulseBytes({ pin: 5, onMs: 100, offMs: 1000 })], [0x1b, 0x70, 0x01, 50, 255])
})

test('a till without a drawer says so', async () => {
  await assert.rejects(createDrawer({ mode: 'none' }).open(), /Aucun tiroir/)
})

test('the drawer is not pulsed twice in a row (solenoid)', async () => {
  let t = 1000
  const writes = []
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'drawer-')), 'lp0')
  const d = createDrawer({ mode: 'file', path: file }, { now: () => t })
  assert.equal((await d.open()).opened, true)
  writes.push(fs.readFileSync(file).length)
  t += 500
  assert.equal((await d.open()).opened, false)
  t += 2000
  assert.equal((await d.open()).opened, true)
  assert.deepEqual(writes, [5])
})
