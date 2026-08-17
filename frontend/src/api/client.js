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

// Construit une query string à partir d'un objet (ignore null/undefined/'').
function qs(params) {
  if (!params) return ''
  const usp = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined || v === '') continue
    usp.append(k, String(v))
  }
  const s = usp.toString()
  return s ? `?${s}` : ''
}

async function request(
  path,
  { method = 'GET', body, ld = false, auth = true, headers: extra = {}, query, timeoutMs } = {},
) {
  const headers = { ...extra }
  if (body !== undefined) {
    headers['Content-Type'] = ld ? 'application/ld+json' : 'application/json'
  }
  if (auth) {
    const token = tokenStore.get()
    if (token) headers['Authorization'] = `Bearer ${token}`
    const etab = etablissementStore.get()
    if (etab) headers['X-Etablissement'] = etab
  }
  if (query) path += qs(query)

  // Coupe-circuit optionnel : certaines opérations d'écriture peuvent traîner côté back ;
  // on préfère un message clair plutôt qu'un spinner infini.
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
      throw new ApiError(
        "Le serveur n'a pas répondu à temps (délai dépassé). Réessayez dans un instant.",
        0,
        null,
      )
    }
    throw new ApiError(
      "Impossible de joindre l'API. Vérifiez que le back tourne sur http://localhost:8080.",
      0,
      null,
    )
  } finally {
    if (timer) clearTimeout(timer)
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
  moyensPaiement: () => request('/api/moyen_paiements'),

  // Sessions de caisse (M2).
  sessionsCaisse: () => request('/api/session_caisses'),
  ouvrirSession: (corps) =>
    request('/api/sessions-caisse/ouvrir', { method: 'POST', body: corps }),
  cloturerSession: (id, corps) =>
    request(`/api/sessions-caisse/${id}/cloturer`, { method: 'POST', body: corps }),

  // Vente + encaissement.
  creerVente: (corps) => request('/api/ventes', { method: 'POST', body: corps, timeoutMs: 20000 }),
  ajouterLigne: (venteId, corps) =>
    request(`/api/ventes/${venteId}/lignes`, { method: 'POST', body: corps, timeoutMs: 20000 }),
  // Un règlement CB/chèque peut être simulé via l'en-tête X-Tpe-Simule (accepte|refuse|annule|timeout).
  // Le dialogue TPE peut prendre plusieurs secondes : coupe-circuit large (45 s) pour éviter le
  // spinner infini si le TPE ne répond pas, sans couper une transaction encore en cours.
  payer: (venteId, corps, headers) =>
    request(`/api/ventes/${venteId}/paiements`, { method: 'POST', body: corps, headers, timeoutMs: 45000 }),
  annulerVente: (venteId) =>
    request(`/api/ventes/${venteId}/annuler`, { method: 'POST', body: {}, timeoutMs: 20000 }),
  valider: (venteId) =>
    request(`/api/ventes/${venteId}/valider`, { method: 'POST', body: {}, timeoutMs: 30000 }),
  ticket: (venteId, mode = 'imprimer') =>
    request(`/api/ventes/${venteId}/ticket`, { method: 'POST', body: { mode } }),

  // CRM (lecture seule cette tranche).
  rechercheClients: (params) => request('/api/crm/clients/recherche', { query: params }),
  ficheClient: (id) => request(`/api/clients/${id}/fiche-360`),

  // --- Tranche 3 ---

  // Réservation / Planning (M5).
  reservationRessources: () => request('/api/reservation_ressources', { query: { itemsPerPage: 100 } }),
  reservationCreneaux: () => request('/api/reservation_creneaus', { query: { itemsPerPage: 200 } }),
  reservationActivites: () => request('/api/reservation_activites', { query: { itemsPerPage: 100 } }),
  reservations: () => request('/api/reservations', { query: { itemsPerPage: 200 } }),
  beneficiaires: () => request('/api/beneficiaires', { query: { itemsPerPage: 100 } }),
  // Écriture : réserver un créneau (créneau + organisateur en IRI). L'opération API Platform
  // n'accepte que le format JSON-LD (application/ld+json) — sans `ld`, le back répond 415.
  // Coupe-circuit 25 s.
  reserverCreneau: (corps) =>
    request('/api/reservation/reservations', { method: 'POST', body: corps, ld: true, timeoutMs: 25000 }),

  // Supervision accès / FMI (M3).
  supervisionAcces: () => request('/api/acces/supervision'),
  jaugesFmi: () => request('/api/jauge_fmis', { query: { itemsPerPage: 100 } }),
  passages: () =>
    request('/api/passages', { query: { itemsPerPage: 20, 'order[horodatage]': 'desc' } }),

  // Reporting / Pilotage (M7). Route hors /api (proxifiée via /reporting).
  dashboardEtablissement: (id) => request(`/reporting/dashboards/etablissement/${id}`),

  // --- Paramètres (référentiels, lecture) ---
  espaces: () => request('/api/espaces', { query: { itemsPerPage: 200 } }),
  regions: () => request('/api/regions', { query: { itemsPerPage: 100 } }),
  categories: () => request('/api/categories', { query: { itemsPerPage: 200 } }),
  typeTarifs: () => request('/api/type_tarifs', { query: { itemsPerPage: 100 } }),
  grilleTarifaires: () => request('/api/grille_tarifaires', { query: { itemsPerPage: 200 } }),
  saisons: () => request('/api/saisons', { query: { itemsPerPage: 100 } }),
  tauxTvas: () => request('/api/taux_tvas', { query: { itemsPerPage: 100 } }),

  // Comptes / rôles & droits (M8, lecture seule côté front).
  utilisateurs: () => request('/api/utilisateurs', { query: { itemsPerPage: 100 } }),
  roles: () => request('/api/roles', { query: { itemsPerPage: 100 } }),
  permissions: () => request('/api/permissions', { query: { itemsPerPage: 300 } }),
  affectations: () => request('/api/affectations', { query: { itemsPerPage: 200 } }),

  // Capacités activables (feature flags par établissement).
  catalogueCapacites: () => request('/api/fonctionnalites/catalogue'),
  fonctionnalitesEtablissement: (id) => request(`/api/etablissements/${id}/fonctionnalites`),

  // --- Comptabilité / Régie (M6) ---
  journaux: () => request('/api/journals', { query: { itemsPerPage: 100 } }),
  ecrituresComptables: () =>
    request('/api/ecriture_comptables', { query: { itemsPerPage: 100 } }),
  regieRecettes: () => request('/api/regie_recettes', { query: { itemsPerPage: 100 } }),
  ventesImpayeesRegie: () =>
    request('/api/vente_impayee_regies', { query: { itemsPerPage: 100 } }),
  bordereauxVersement: () =>
    request('/api/bordereau_versements', { query: { itemsPerPage: 100 } }),
  comptesComptables: () =>
    request('/api/compte_comptables', { query: { itemsPerPage: 200 } }),
  cautions: () => request('/api/cautions', { query: { itemsPerPage: 100 } }),

  // SEPA : remises de prélèvement (pain.008), mandats, rejets.
  remisesSepa: () => request('/api/remise_sepas', { query: { itemsPerPage: 100 } }),
  mandatsSepa: () => request('/api/mandat_sepas', { query: { itemsPerPage: 100 } }),
  rejetsSepa: () => request('/api/rejet_sepas', { query: { itemsPerPage: 100 } }),

  // Recouvrement / impayés.
  incidentsImpayes: () => request('/api/incident_impayes', { query: { itemsPerPage: 100 } }),

  // --- Boutique en ligne (M3, vue admin) ---
  // Les paniers en ligne ne sont pas listables (accès par id) : la vue admin s'appuie sur les
  // demandes de remboursement (listables) et les comptes clients boutique.
  demandesRemboursement: () =>
    request('/api/boutique/demandes-remboursement', { query: { itemsPerPage: 100 } }),
  comptesClientBoutique: () =>
    request('/api/compte_clients', { query: { itemsPerPage: 100 } }),
  vitrines: () => request('/api/boutique/vitrines', { query: { itemsPerPage: 100 } }),
  accepterRemboursement: (id) =>
    request(`/api/boutique/demandes-remboursement/${id}/accepter`, { method: 'POST', body: {}, ld: true }),
  refuserRemboursement: (id, motif) =>
    request(`/api/boutique/demandes-remboursement/${id}/refuser`, { method: 'POST', body: { motifRefus: motif }, ld: true }),

  // --- Personnel ---
  employes: () => request('/api/employes', { query: { itemsPerPage: 200 } }),
  roster: () => request('/api/personnel/roster'),
  badgeStaffs: () => request('/api/badge_staffs', { query: { itemsPerPage: 200 } }),
  revoquerBadgeStaff: (id, motif) =>
    request(`/api/personnel/badges/${id}/revoquer`, { method: 'POST', body: { motif }, ld: true }),

  // --- Verticales (routes explicites privilégiées) ---
  // Piscine
  bassins: () => request('/api/bassins', { query: { itemsPerPage: 100 } }),
  creneauxBassin: () => request('/api/creneau_bassins', { query: { itemsPerPage: 200 } }),
  jaugesGrandPublic: () =>
    request('/api/jauge_grand_public_calculees', { query: { itemsPerPage: 100 } }),
  // Patinoire
  patinoireConflits: () => request('/api/patinoire/conflits-glace'),
  patinoireLocations: () =>
    request('/api/patinoire_location_patins', { query: { itemsPerPage: 100 } }),
  patinoireAffutages: () =>
    request('/api/patinoire_affutages', { query: { itemsPerPage: 100 } }),
  // Padel
  padelTerrains: () => request('/api/padel/terrains', { query: { itemsPerPage: 100 } }),
  // Pas de collection listable pour les tournois (seulement des routes custom
  // /api/padel/tournois/{id}/...) : on renvoie un état vide propre.
  padelTournois: () => Promise.resolve({ 'hydra:member': [] }),
  // Musée
  museeExpositions: () => request('/api/musee_expositions', { query: { itemsPerPage: 100 } }),
  museeVisitesGuidees: () =>
    request('/api/musee_visite_guidees', { query: { itemsPerPage: 100 } }),
}
