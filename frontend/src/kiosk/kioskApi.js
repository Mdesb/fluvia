// L'API DE L'APPAREIL DE BORNE (#341) — volontairement séparée de `api/client.js`.
//
// `client.js` porte le jeton du PERSONNEL et l'établissement actif, et un 401 y déconnecte l'agent.
// La borne n'a rien de tout cela : un jeton d'appareil, gardé sous sa propre clé, qui n'ouvre que
// `/api/kiosk/…`. Un 401 ici veut dire « borne désappairée » : l'écran revient à l'appairage.

const DEVICE_KEY = 'fluvia.kiosk.device'

export const deviceStore = {
  get() {
    try {
      return localStorage.getItem(DEVICE_KEY)
    } catch {
      return null
    }
  },
  set(token) {
    try {
      localStorage.setItem(DEVICE_KEY, token)
    } catch {
      // Stockage refusé : la borne redemandera son appairage au prochain démarrage.
    }
  },
  clear() {
    try {
      localStorage.removeItem(DEVICE_KEY)
    } catch {
      // idem
    }
  },
}

export class KioskError extends Error {
  constructor(message, status, code) {
    super(message)
    this.status = status
    this.code = code
  }
}

async function call(path, { method = 'GET', body } = {}) {
  const headers = { Accept: 'application/json' }
  const token = deviceStore.get()
  if (token) headers.Authorization = `Bearer ${token}`
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let res
  try {
    res = await fetch(`/api/kiosk${path}`, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined })
  } catch {
    throw new KioskError('La borne ne joint plus le serveur. Réessayez dans un instant.', 0, 'network')
  }
  const text = await res.text()
  let payload = {}
  try {
    payload = text ? JSON.parse(text) : {}
  } catch {
    payload = {}
  }
  if (res.status === 401) {
    deviceStore.clear()
    throw new KioskError(payload.message || 'Borne non appairée.', 401, 'kiosk_not_paired')
  }
  if (!res.ok && res.status !== 202) {
    throw new KioskError(payload.message || `Erreur ${res.status}`, res.status, payload.code || null)
  }
  return { status: res.status, ...payload }
}

/** Un UUID v4 pour la clé de paiement : une par essai, rejouée telle quelle si le réseau coupe. */
export function newKey() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID()
  const b = Array.from({ length: 16 }, () => Math.floor(Math.random() * 256))
  b[6] = (b[6] & 0x0f) | 0x40
  b[8] = (b[8] & 0x3f) | 0x80
  const h = b.map((x) => x.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

export const kioskApi = {
  pair: (code) => call('/pair', { method: 'POST', body: { code } }),
  config: () => call('/config'),
  open: (items, cardCode) => call('/orders', { method: 'POST', body: cardCode ? { items, cardCode } : { items } }),
  pay: (order, key) => call(`/orders/${order}/pay`, { method: 'POST', body: { key } }),
  payment: (order, attempt) => call(`/orders/${order}/payments/${attempt}`),
  cancelPayment: (order, attempt) => call(`/orders/${order}/payments/${attempt}/cancel`, { method: 'POST', body: {} }),
  complete: (order) => call(`/orders/${order}/complete`, { method: 'POST', body: {} }),
  abandon: (order) => call(`/orders/${order}/abandon`, { method: 'POST', body: {} }),
  unlock: (code) => call('/unlock', { method: 'POST', body: { code } }),
}
