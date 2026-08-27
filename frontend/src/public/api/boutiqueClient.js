// Client HTTP PUBLIC de la boutique en ligne (front client final).
// Volontairement distinct de src/api/client.js (back-office staff) :
//  - PAS de JWT staff ni d'en-tête X-Etablissement (l'établissement est déduit de la vitrine côté back).
//  - Propriété du panier prouvée par l'en-tête applicatif `X-Panier-Token` (jeton rendu une seule fois
//    à l'ouverture du panier, cf. OuvrirPanierProcessor / PanierProprietaireGuard).
//  - Espace client (« Mes billets / commandes ») authentifié par un JWT client obtenu via /auth,
//    stocké sous une clé DIFFÉRENTE du token staff pour ne jamais les mélanger.
// Chemins relatifs proxifiés par Vite (/auth, /api) — même origine, pas de CORS.

const PANIER_TOKEN_KEY = 'boutique.panierToken'
const PANIER_ID_KEY = 'boutique.panierId'
const CLIENT_TOKEN_KEY = 'boutique.clientToken'
const VITRINE_KEY = 'boutique.vitrine'

export const panierStore = {
  getToken: () => localStorage.getItem(PANIER_TOKEN_KEY),
  getId: () => localStorage.getItem(PANIER_ID_KEY),
  set: (id, token) => {
    if (id) localStorage.setItem(PANIER_ID_KEY, id)
    if (token) localStorage.setItem(PANIER_TOKEN_KEY, token)
  },
  clear: () => {
    localStorage.removeItem(PANIER_ID_KEY)
    localStorage.removeItem(PANIER_TOKEN_KEY)
  },
}

export const clientTokenStore = {
  get: () => localStorage.getItem(CLIENT_TOKEN_KEY),
  set: (t) => localStorage.setItem(CLIENT_TOKEN_KEY, t),
  clear: () => localStorage.removeItem(CLIENT_TOKEN_KEY),
}

export const vitrineStore = {
  get: () => localStorage.getItem(VITRINE_KEY),
  set: (id) => localStorage.setItem(VITRINE_KEY, id),
  clear: () => localStorage.removeItem(VITRINE_KEY),
}

export class ApiError extends Error {
  constructor(message, status, payload) {
    super(message)
    this.status = status
    this.payload = payload
  }
}

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

async function request(path, { method = 'GET', body, auth = false, panierToken, timeoutMs } = {}) {
  const headers = {}
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  // Jeton de panier : passé explicitement ou repris du stockage local.
  const token = panierToken ?? panierStore.getToken()
  if (token) headers['X-Panier-Token'] = token
  // Espace client : JWT client (jamais le token staff).
  if (auth) {
    const jwt = clientTokenStore.get()
    if (jwt) headers['Authorization'] = `Bearer ${jwt}`
  }

  let abort
  let timer
  if (timeoutMs) {
    abort = new AbortController()
    timer = setTimeout(() => abort.abort(), timeoutMs)
  }

  let res
  try {
    res = await fetch(path, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: abort?.signal,
    })
  } catch (e) {
    if (abort?.signal.aborted) {
      throw new ApiError("Le serveur n'a pas répondu à temps. Réessayez dans un instant.", 0, null)
    }
    throw new ApiError("Impossible de joindre la boutique. Réessayez dans un instant.", 0, null)
  } finally {
    if (timer) clearTimeout(timer)
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

export const boutique = {
  // --- Vitrine / catalogue (public) ---
  // Liste publique des vitrines ouvertes (sans auth) : sert d'écran de choix quand aucune
  // vitrine n'est passée dans l'URL (?vitrine=<id>).
  vitrinesPubliques: () => request('/api/boutique/vitrines-publiques'),
  vitrine: (id) => request(`/api/boutique/vitrines/${id}`),
  catalogue: (id) => request(`/api/boutique/vitrines/${id}/catalogue`),
  creneaux: (produitId) => request(`/api/boutique/produits/${produitId}/creneaux`),

  // Mentions obligatoires, en acces PUBLIC et sans jeton.
  //
  // La LCEN exige des mentions << aisement accessibles >>. Les mettre derriere une authentification
  // les rendrait inaccessibles a exactement la personne qui en a besoin : le visiteur qui hesite a
  // acheter, et qui n'a pas encore de compte.
  documentsLegaux: (etablissementId) => request(`/api/legal/publics/${etablissementId}`),

  // --- Panier (invité, jeton applicatif) ---
  ouvrirPanier: (vitrineId, email) =>
    request('/api/boutique/paniers', { method: 'POST', body: { vitrine: vitrineId, email } }),
  // GET panier enrichi (total + prix par ligne). Le back exige désormais X-Panier-Token,
  // que request() ajoute automatiquement depuis panierStore.
  panier: (id) => request(`/api/boutique/paniers/${id}`),
  ajouterLigne: (id, corps) =>
    request(`/api/boutique/paniers/${id}/lignes`, { method: 'POST', body: corps, timeoutMs: 20000 }),
  retirerLigne: (id, ligneId) =>
    request(`/api/boutique/paniers/${id}/lignes/${ligneId}/retirer`, { method: 'POST', body: {} }),
  // Ajustement direct de la quantité d'une ligne (remplace le retrait + ré-ajout).
  ajusterQuantite: (id, ligneId, quantite) =>
    request(`/api/boutique/paniers/${id}/lignes/${ligneId}/quantite`, {
      method: 'POST',
      body: { quantite },
    }),
  viderPanier: (id) => request(`/api/boutique/paniers/${id}/vider`, { method: 'POST', body: {} }),
  // Billets à QR du panier, accessibles à l'invité via X-Panier-Token (pas besoin de compte).
  // Le jeton est passé explicitement car le panier peut déjà avoir été purgé du stockage local
  // après confirmation de la commande.
  panierBillets: (id, panierToken) =>
    request(`/api/boutique/paniers/${id}/billets`, { panierToken }),

  // --- Tunnel ---
  identifier: (id, corps) =>
    request(`/api/boutique/paniers/${id}/identifier`, { method: 'POST', body: corps }),
  beneficiaires: (id, lignes) =>
    request(`/api/boutique/paniers/${id}/beneficiaires`, { method: 'POST', body: { lignes } }),
  consentement: (id, corps) =>
    request(`/api/boutique/paniers/${id}/consentement`, { method: 'POST', body: corps }),
  payer: (id) =>
    request(`/api/boutique/paniers/${id}/payer`, { method: 'POST', body: {}, timeoutMs: 30000 }),
  retourPaiement: (id, corps) =>
    request(`/api/boutique/paniers/${id}/retour-paiement`, { method: 'POST', body: corps, timeoutMs: 30000 }),

  // --- Création de compte (rattache le panier en cours sans perte de contenu) ---
  creerCompte: (corps) =>
    request('/api/boutique/comptes', { method: 'POST', body: corps }),

  // --- Espace client (JWT client requis) ---
  login: (email, motDePasse) =>
    request('/auth', { method: 'POST', body: { email, motDePasse } }),
  moiCompte: () => request('/api/boutique/comptes/me', { auth: true }),
  mesCommandes: () => request('/api/boutique/comptes/me/commandes', { auth: true }),
  mesBillets: () => request('/api/boutique/comptes/me/billets', { auth: true }),
}
