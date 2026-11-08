// A SIMULATED Caisse-AP terminal over IP, for the tests and for a demonstration without hardware.
// It answers like a terminal at the end of the transaction. Behaviour, per request, from `mode`:
//   accept            AE=10, the requested amount, an authorisation number
//   partial:<cents>   AE=10 with CB=<cents> (e.g. a prepaid card with not enough balance)
//   refuse            AE=01 AF=04 (refused by the card / the bank)
//   abort             AE=01 AF=11 (the cashier or the customer cancelled on the terminal)
//   ack-then-accept   AE=11 first (immediate acknowledgement), then AE=10
//   silent            never answers (the bridge's time-out applies)
//   hangup            closes the connection without an answer
import net from 'node:net'
import { decode, encode } from './protocol.js'

export function createFakeTerminal({ mode = 'accept', delayMs = 20 } = {}) {
  const state = { mode, requests: [] }
  const server = net.createServer((socket) => {
    let buffer = ''
    socket.setEncoding('ascii')
    socket.on('error', () => {})
    socket.on('data', (chunk) => {
      buffer += chunk
      let parsed
      try { parsed = decode(buffer) } catch { socket.destroy(); return }
      if (!parsed.complete || !parsed.fields.CB) return
      const req = parsed.fields
      state.requests.push(req)
      const current = typeof state.mode === 'function' ? state.mode(req) : state.mode
      const echo = [['CZ', req.CZ], ['CA', req.CA], ['CD', req.CD], ['CE', req.CE]]
      const reply = (fields) => setTimeout(() => socket.end(encode(fields), 'ascii'), delayMs)
      if (current === 'silent') return
      if (current === 'hangup') { setTimeout(() => socket.destroy(), delayMs); return }
      if (current === 'refuse') return reply([...echo, ['CB', req.CB], ['AE', '01'], ['AF', '04']])
      if (current === 'abort') return reply([...echo, ['CB', req.CB], ['AE', '01'], ['AF', '11']])
      const amount = current.startsWith('partial:') ? current.slice(8) : req.CB
      const accepted = [...echo, ['CB', amount], ['AE', '10'], ['AC', '123456'], ['CC', '00B'], ['CI', '2'], ['AA', '497010######1234']]
      if (current === 'ack-then-accept') {
        socket.write(encode([...echo, ['AE', '11']]), 'ascii')
        return reply(accepted)
      }
      return reply(accepted)
    })
  })
  return {
    state,
    listen: (port = 0, host = '127.0.0.1') => new Promise((resolve) => server.listen(port, host, () => resolve(server.address().port))),
    close: () => new Promise((resolve) => server.close(() => resolve())),
  }
}
