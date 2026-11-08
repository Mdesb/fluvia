// Opening the cash drawer: the drawer is plugged into the receipt printer (RJ11/RJ12 "DK" port), and
// the printer opens it when it receives the ESC/POS "generate pulse" command.
//
// ESC p m t1 t2 = 0x1B 0x70 m t1 t2 (Epson ESC/POS reference, « ESC p — Generate pulse »; same layout in
// the TM-T88 and Aures manuals, consulted 2026-11-05): m = 0 → drawer connector pin 2, m = 1 → pin 5;
// pulse ON for t1 × 2 ms, OFF for t2 × 2 ms. Default here: pin 2, 50 ms on, 500 ms off.
// Repeated pulses can overheat the drawer solenoid: one pulse per `minIntervalMs` at most.
import fs from 'node:fs'
import net from 'node:net'

export function pulseBytes({ pin = 2, onMs = 50, offMs = 500 } = {}) {
  const m = pin === 5 ? 1 : 0
  const t = (ms) => Math.max(1, Math.min(255, Math.round(ms / 2)))
  return Buffer.from([0x1b, 0x70, m, t(onMs), t(offMs)])
}

/**
 * config.drawer:
 *   { mode: "tcp",  host: "192.168.1.50", port: 9100 }      network receipt printer (raw port 9100)
 *   { mode: "file", path: "\\\\localhost\\TICKET" }         Windows shared printer (raw), COM port, /dev/usb/lp0
 *   { mode: "none" }                                         no drawer on this till
 */
export function createDrawer(config = {}, { minIntervalMs = 2000, now = () => Date.now() } = {}) {
  let last = -Infinity
  return {
    enabled: config.mode === 'tcp' || config.mode === 'file',
    async open() {
      if (config.mode !== 'tcp' && config.mode !== 'file') {
        const e = new Error('Aucun tiroir-caisse n\'est réglé sur ce poste (drawer.mode).')
        e.code = 'DRAWER_NOT_CONFIGURED'
        throw e
      }
      if (now() - last < minIntervalMs) return { opened: false, reason: 'already_opening' }
      last = now()
      const bytes = pulseBytes(config)
      if (config.mode === 'file') {
        await fs.promises.writeFile(config.path, bytes)
        return { opened: true }
      }
      await new Promise((resolve, reject) => {
        const socket = net.createConnection({ host: config.host, port: config.port ?? 9100 })
        socket.setTimeout(config.timeoutMs ?? 3000)
        socket.on('connect', () => socket.end(bytes))
        socket.on('close', (hadError) => (hadError ? null : resolve()))
        socket.on('timeout', () => { socket.destroy(); reject(new Error('Imprimante injoignable (délai dépassé).')) })
        socket.on('error', (e) => reject(new Error(`Imprimante injoignable (${e.code || e.message}).`)))
      })
      return { opened: true }
    },
  }
}
