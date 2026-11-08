// The TCP conversation with the payment terminal: one request, one answer, per connection.
import net from 'node:net'
import { decode, interpretAnswer, paymentRequest } from './protocol.js'

/**
 * Ask the terminal for a debit and wait for its final answer.
 *
 * Never throws: it always resolves to an outcome, because the cashier must always be told something.
 * - connection refused / unknown host BEFORE anything was sent: `annule` (nothing reached the terminal,
 *   the card cannot have been debited);
 * - anything that goes wrong AFTER the request left (socket closed early, unreadable answer, time-out):
 *   `timeout`, i.e. "unknown" — Fluvia keeps the sale held until the cashier declares what the
 *   terminal shows.
 */
export function requestPayment({ host, port, register = '01', amount, currency = 'EUR', timeoutMs = 180000, connectTimeoutMs = 5000, settleMs = 200 }) {
  return new Promise((resolve) => {
    let message
    try {
      message = paymentRequest({ amount, currency, register })
    } catch (e) {
      resolve({ status: 'annule', ae: null, af: null, reason: e.message, sent: false })
      return
    }

    let sent = false
    let buffer = ''
    let settled = false
    const socket = net.createConnection({ host, port })

    const finish = (outcome) => {
      if (settled) return
      settled = true
      clearTimeout(connectTimer)
      clearTimeout(answerTimer)
      clearTimeout(settleTimer)
      socket.destroy()
      resolve({ ...outcome, sent })
    }
    const unknown = (reason) => finish({ status: 'timeout', ae: null, af: null, reason })

    const connectTimer = setTimeout(() => {
      if (!sent) finish({ status: 'annule', ae: null, af: null, reason: 'Terminal injoignable : rien n\'a été envoyé.' })
    }, connectTimeoutMs)
    let answerTimer = null
    let settleTimer = null

    socket.setEncoding('ascii')
    socket.on('connect', () => {
      clearTimeout(connectTimer)
      socket.write(message, 'ascii')
      sent = true
      answerTimer = setTimeout(() => unknown('Pas de réponse du terminal dans le délai : lisez le terminal.'), timeoutMs)
    })
    socket.on('data', (chunk) => {
      buffer += chunk
      let parsed
      try {
        parsed = decode(buffer)
      } catch {
        unknown('Réponse du terminal illisible : lisez le terminal.')
        return
      }
      // An immediate acknowledgement (AE=11) is not the outcome: drop it and keep reading.
      if (parsed.complete && parsed.fields.AE === '11') {
        buffer = ''
        return
      }
      // The answer may arrive in several TCP segments, cut on a field boundary: wait a moment for
      // the rest (or for the terminal to close the connection) before reading it.
      clearTimeout(settleTimer)
      if (parsed.complete && parsed.fields.AE) {
        settleTimer = setTimeout(() => finish(interpretAnswer(parsed.fields, { amount, currency })), settleMs)
      }
    })
    socket.on('end', () => {
      if (!sent) return
      try {
        const parsed = decode(buffer)
        if (parsed.complete && parsed.fields.AE && parsed.fields.AE !== '11') {
          finish(interpretAnswer(parsed.fields, { amount, currency }))
          return
        }
      } catch { /* fall through */ }
      unknown('Le terminal a coupé la liaison sans issue : lisez le terminal.')
    })
    socket.on('error', (e) => {
      if (!sent) finish({ status: 'annule', ae: null, af: null, reason: `Terminal injoignable (${e.code || e.message}) : rien n'a été envoyé.` })
      else unknown(`Liaison perdue avec le terminal (${e.code || e.message}) : lisez le terminal.`)
    })
  })
}
