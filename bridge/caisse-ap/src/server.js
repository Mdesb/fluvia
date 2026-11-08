// The local HTTP interface the till's browser talks to. See README.md, « Interface locale ».
//
// Safety rules, all enforced here:
// 1. it listens on the loopback only — nothing on the shop network can reach it;
// 2. the Host header must be the loopback (blocks DNS rebinding: a web page that re-points its own
//    domain to 127.0.0.1 still sends its own name as Host);
// 3. the Origin must be one of `allowedOrigins` (the Fluvia address of this site) — any other web page
//    open in a browser on this PC gets a 403, and its preflight gets no CORS grant;
// 4. requests are JSON only (which forces the CORS preflight) and small;
// 5. an outcome is SIGNED with the bridge's private key: the browser relays it to Fluvia, but cannot
//    alter it — Fluvia checks the signature against the public key of the point of sale;
// 6. one attempt = one debit: the same attempt asked twice (page reloaded) returns the same signed
//    outcome, never a second debit; another attempt while the terminal is busy gets a 409.
import fs from 'node:fs'
import http from 'node:http'
import { signOutcome, publicKeyBase64 } from './signer.js'
import { requestPayment } from './terminal.js'

export const VERSION = '1.0.0'
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/
const LOOPBACK = new Set(['127.0.0.1', '::1', 'localhost'])
const MAX_BODY = 4096

export function createBridge({ config, privateKey, drawer, pay = requestPayment, now = () => new Date(), log = () => {} }) {
  const allowed = new Set(config.allowedOrigins ?? [])
  const outcomes = new Map() // attempt -> { result, signature }
  const inFlight = new Map() // attempt -> Promise
  const journal = config.journalPath ?? null

  if (journal && fs.existsSync(journal)) {
    for (const line of fs.readFileSync(journal, 'utf8').split('\n')) {
      if (!line.trim()) continue
      try {
        const entry = JSON.parse(line)
        outcomes.set(JSON.parse(entry.result).attempt, entry)
      } catch { /* a torn last line is ignored */ }
    }
  }

  function keep(attempt, signed) {
    outcomes.set(attempt, signed)
    if (journal) fs.appendFileSync(journal, JSON.stringify(signed) + '\n')
    if (outcomes.size > 1000) outcomes.delete(outcomes.keys().next().value)
  }

  async function runPayment({ attempt, amount, currency }) {
    log(`paiement ${attempt} : ${amount} ${currency} envoyé au terminal`)
    const answer = await pay({
      host: config.terminal.host,
      port: config.terminal.port ?? 8888,
      register: config.terminal.register ?? '01',
      timeoutMs: config.terminal.timeoutMs ?? 180000,
      amount,
      currency,
    })
    const outcome = {
      v: 1,
      bridge: 'fluvia-caisse-ap',
      attempt,
      requestedAmount: amount,
      currency,
      status: answer.status,
      debitedAmount: answer.status === 'accepte' ? answer.debitedAmount : null,
      authorization: answer.authorization ?? null,
      network: answer.network ?? null,
      contactless: answer.contactless ?? null,
      ae: answer.ae ?? null,
      af: answer.af ?? null,
      reason: answer.reason ?? null,
      terminal: config.terminal.id ?? null,
      at: now().toISOString(),
    }
    const signed = signOutcome(privateKey, outcome)
    keep(attempt, signed)
    log(`paiement ${attempt} : ${answer.status}${answer.reason ? ` (${answer.reason})` : ''}`)
    return signed
  }

  function payment(body) {
    const attempt = typeof body.attempt === 'string' ? body.attempt.toLowerCase() : ''
    const amount = typeof body.amount === 'string' ? body.amount : ''
    const currency = body.currency ?? 'EUR'
    if (!UUID.test(attempt)) return [422, { code: 'invalid_attempt', message: 'attempt : l\'identifiant de la tentative (UUID).' }]
    if (!/^\d{1,8}\.\d{2}$/.test(amount) || Number(amount) <= 0) return [422, { code: 'invalid_amount', message: 'amount : un montant positif, deux décimales (« 12.50 »).' }]
    if (currency !== 'EUR') return [422, { code: 'invalid_currency', message: 'Seul l\'euro est pris en charge.' }]

    const done = outcomes.get(attempt)
    if (done) {
      if (JSON.parse(done.result).requestedAmount !== amount) return [409, { code: 'attempt_amount_mismatch', message: 'Cette tentative a déjà été envoyée avec un autre montant.' }]
      return [200, done]
    }
    if (inFlight.has(attempt)) return inFlight.get(attempt).then((s) => [200, s])
    if (inFlight.size > 0) return [409, { code: 'terminal_busy', message: 'Le terminal traite déjà un autre paiement.' }]

    const p = runPayment({ attempt, amount, currency }).finally(() => inFlight.delete(attempt))
    inFlight.set(attempt, p)
    return p.then((s) => [200, s])
  }

  async function route(req, body) {
    if (req.method === 'GET' && req.url === '/v1/status') {
      return [200, {
        bridge: 'fluvia-caisse-ap',
        version: VERSION,
        publicKey: publicKeyBase64(privateKey),
        terminal: { configured: Boolean(config.terminal?.host), busy: inFlight.size > 0 },
        drawer: { configured: Boolean(drawer?.enabled) },
      }]
    }
    if (req.method === 'POST' && req.url === '/v1/payments') return payment(body)
    if (req.method === 'GET' && req.url.startsWith('/v1/payments/')) {
      const done = outcomes.get(req.url.slice('/v1/payments/'.length).toLowerCase())
      return done ? [200, done] : [404, { code: 'unknown_attempt', message: inFlight.size ? 'En cours.' : 'Tentative inconnue de ce pont.' }]
    }
    if (req.method === 'POST' && req.url === '/v1/drawer/open') {
      try {
        const r = await drawer.open()
        log(`tiroir : ${r.opened ? 'ouvert' : 'impulsion ignorée (déjà en cours)'}`)
        return [200, r]
      } catch (e) {
        return [e.code === 'DRAWER_NOT_CONFIGURED' ? 409 : 502, { code: e.code === 'DRAWER_NOT_CONFIGURED' ? 'drawer_not_configured' : 'drawer_unreachable', message: e.message }]
      }
    }
    return [404, { code: 'not_found', message: 'Inconnu.' }]
  }

  const server = http.createServer((req, res) => {
    const origin = req.headers.origin
    const send = (status, payload, extra = {}) => {
      res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', Vary: 'Origin', ...extra })
      res.end(payload === null ? '' : JSON.stringify(payload))
    }
    const hostName = String(req.headers.host ?? '').replace(/:\d+$/, '').replace(/^\[|\]$/g, '')
    if (!LOOPBACK.has(hostName)) return send(403, { code: 'forbidden_host', message: 'Ce pont ne répond qu\'au poste lui-même.' })
    if (!origin || !allowed.has(origin)) return send(403, { code: 'forbidden_origin', message: 'Origine non autorisée : ajoutez l\'adresse de Fluvia dans allowedOrigins.' })
    const cors = { 'Access-Control-Allow-Origin': origin }
    if (req.method === 'OPTIONS') {
      return send(204, null, {
        ...cors,
        'Access-Control-Allow-Methods': 'GET, POST',
        'Access-Control-Allow-Headers': 'Content-Type',
        // Private Network Access: a public HTTPS page may then reach the loopback (Chrome, Edge).
        'Access-Control-Allow-Private-Network': 'true',
        'Access-Control-Max-Age': '600',
      })
    }
    if (req.method === 'POST' && !String(req.headers['content-type'] ?? '').startsWith('application/json')) {
      return send(415, { code: 'json_only', message: 'Corps JSON attendu.' }, cors)
    }
    let raw = ''
    let tooBig = false
    req.setEncoding('utf8')
    req.on('data', (c) => {
      raw += c
      if (raw.length > MAX_BODY) { tooBig = true; req.destroy() }
    })
    req.on('end', async () => {
      if (tooBig) return
      let body = {}
      if (raw) {
        try { body = JSON.parse(raw) } catch { return send(400, { code: 'invalid_json', message: 'JSON illisible.' }, cors) }
      }
      if (body === null || typeof body !== 'object') return send(400, { code: 'invalid_json', message: 'Objet JSON attendu.' }, cors)
      try {
        const [status, payload] = await route(req, body)
        send(status, payload, cors)
      } catch (e) {
        log(`erreur : ${e.message}`)
        send(500, { code: 'bridge_error', message: 'Erreur du pont.' }, cors)
      }
    })
  })
  // A card payment with PIN can take minutes: the browser waits for the answer.
  server.requestTimeout = 0
  server.headersTimeout = 10000
  return server
}

/** Listen on the loopback only, whatever the configuration says. */
export function listen(server, port, host = '127.0.0.1') {
  if (!LOOPBACK.has(host)) throw new Error(`listen.host doit être l'adresse de bouclage (127.0.0.1), pas ${host}`)
  return new Promise((resolve) => server.listen(port, host === 'localhost' ? '127.0.0.1' : host, () => resolve(server.address())))
}
