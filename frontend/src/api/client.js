// Client HTTP centralisé.
// - Appelle des chemins relatifs (proxifiés par Vite vers le back), pas de CORS.
// - Ajoute automatiquement le Bearer JWT et l'en-tête X-Etablissement (établissement actif).
// - 401 => on notifie l'app pour repasser sur l'écran de connexion.

const TOKEN_KEY = 'billetterie.token'
const ETAB_KEY = 'billetterie.etablissement'

let onUnauthorized = null
export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn
}

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (t) => localStorage.setItem(TOKEN_KEY, t),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

export const etablissementStore = {
  get: () => localStorage.getItem(ETAB_KEY),
  set: (id) => localStorage.setItem(ETAB_KEY, id),
  clear: () => localStorage.removeItem(ETAB_KEY),
}

// Extrait un message d'erreur lisible d'une réponse API (auth, API Platform, opérations custom).
function messageFromPayload(payload, status) {
  if (!payload) return `Erreur ${status}`
  return (
    payload.message ||
    payload['hydra:description'] ||
    payload.detail ||
    payload.description ||
    payload.title ||
    `Erreur ${status}`
  )
}

export class ApiError extends Error {
  constructor(message, status, payload) {
    super(message)
    this.status = status
    this.payload = payload
  }
}

async function request(path, { method = 'GET', body, ld = false, auth = true } = {}) {
  const headers = {}
  if (body !== undefined) {
    headers['Content-Type'] = ld ? 'application/ld+json' : 'application/json'
  }
  if (auth) {
    const token = tokenStore.get()
    if (token) headers['Authorization'] = `Bearer ${token}`
    const etab = etablissementStore.get()
    if (etab) headers['X-Etablissement'] = etab
  }

  let res
  try {
    res = await fetch(path, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch (e) {
    throw new ApiError(
      "Impossible de joindre l'API. Vérifiez que le back tourne sur http://localhost:8080.",
      0,
      null,
    )
  }

  if (res.status === 401 && auth) {
    tokenStore.clear()
    if (onUnauthorized) onUnauthorized()
  }

  const text = await res.text()
  let payload = null
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = { message: text }
    }
  }

  if (!res.ok) {
    throw new ApiError(messageFromPayload(payload, res.status), res.status, payload)
  }
  return payload
}

// Déballe une collection Hydra ({ member: [...] }) ou renvoie un tableau brut.
export function membres(collection) {
  if (!collection) return []
  if (Array.isArray(collection)) return collection
  return collection.member || collection['hydra:member'] || []
}

export const api = {
  // Auth : hors /api, sans X-Etablissement.
  login: (email, motDePasse) =>
    request('/auth', { method: 'POST', body: { email, motDePasse }, auth: false }),
  me: () => request('/me'),

  etablissements: () => request('/api/etablissements'),
  produits: () => request('/api/produits'),
  typeProduits: () => request('/api/type_produits'),
  creerProduit: (corps) =>
    request('/api/produits', { method: 'POST', body: corps, ld: true }),

  pointDeVentes: () => request('/api/point_de_ventes'),
  caisses: () => request('/api/caisses'),
  sessionsCaisse: () => request('/api/session_caisses'),
  ouvrirSession: (corps) =>
    request('/api/sessions-caisse/ouvrir', { method: 'POST', body: corps }),

  creerVente: (corps) => request('/api/ventes', { method: 'POST', body: corps }),
  ajouterLigne: (venteId, corps) =>
    request(`/api/ventes/${venteId}/lignes`, { method: 'POST', body: corps }),
  payer: (venteId, corps) =>
    request(`/api/ventes/${venteId}/paiements`, { method: 'POST', body: corps }),
  valider: (venteId) =>
    request(`/api/ventes/${venteId}/valider`, { method: 'POST', body: {} }),
  ticket: (venteId, mode = 'imprimer') =>
    request(`/api/ventes/${venteId}/ticket`, { method: 'POST', body: { mode } }),
}
