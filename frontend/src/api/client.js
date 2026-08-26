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
    // API Platform impose `application/merge-patch+json` sur les PATCH (sinon 415) ; les autres
    // écritures acceptent JSON simple, ou JSON-LD quand l'opération l'exige (`ld: true`).
    headers['Content-Type'] = method === 'PATCH'
      ? 'application/merge-patch+json'
      : ld
        ? 'application/ld+json'
        : 'application/json'
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
  // Creer et modifier un etablissement. Pas de suppression exposee : voir EtablissementsSection.
  creerEtablissement: (corps) => request('/api/etablissements', { method: 'POST', body: corps, ld: true }),
  majEtablissement: (id, corps) => request(`/api/etablissements/${id}`, { method: 'PATCH', body: corps }),
  produits: () => request('/api/produits'),
  // Le détail ajoute le groupe `produit:compta` (compte, TVA, règle PCA), absent de la collection.
  produit: (id) => request(`/api/produits/${id}`),
  majProduit: (id, corps) => request(`/api/produits/${id}`, { method: 'PATCH', body: corps }),
  typeProduits: () => request('/api/type_produits'),
  creerProduit: (corps) =>
    request('/api/produits', { method: 'POST', body: corps, ld: true }),

  // Cycle de vie d'un produit (Offre) : brouillon -> publié -> archivé, et la réactivation.
  // Côté serveur ces opérations sont déclarées `input: false` : elles n'ont pas de corps, seul
  // l'identifiant compte. Elles existent depuis des jours et n'étaient appelées de nulle part — un
  // produit créé depuis cet écran restait donc en brouillon à vie, invendable sur tous les canaux.
  publierProduit: (id) => request(`/api/produits/${id}/publier`, { method: 'POST' }),
  depublierProduit: (id) => request(`/api/produits/${id}/depublier`, { method: 'POST' }),
  archiverProduit: (id) => request(`/api/produits/${id}/archiver`, { method: 'POST' }),
  reactiverProduit: (id) => request(`/api/produits/${id}/reactiver`, { method: 'POST' }),

  pointDeVentes: () => request('/api/point_de_ventes'),
  creerPointDeVente: (corps) => request('/api/point_de_ventes', { method: 'POST', body: corps, ld: true }),
  majPointDeVente: (id, corps) => request(`/api/point_de_ventes/${id}`, { method: 'PATCH', body: corps }),
  caisses: () => request('/api/caisses'),
  moyensPaiement: () => request('/api/moyen_paiements'),
  // Moyens de paiement — écriture (source M6, sécurité `compta.gerer`).
  creerMoyenPaiement: (corps) =>
    request('/api/moyen_paiements', { method: 'POST', body: corps, ld: true }),
  majMoyenPaiement: (id, corps) =>
    request(`/api/moyen_paiements/${id}`, { method: 'PATCH', body: corps }),

  // Sessions de caisse (M2).
  sessionsCaisse: () => request('/api/session_caisses'),
  ouvrirSession: (corps) =>
    request('/api/sessions-caisse/ouvrir', { method: 'POST', body: corps }),
  cloturerSession: (id, corps) =>
    request(`/api/sessions-caisse/${id}/cloturer`, { method: 'POST', body: corps }),

  // Vente + encaissement.
  // Historique : la collection et le detail existaient et n'etaient appeles nulle part.
  ventes: (params) => request('/api/ventes', { query: params }),
  vente: (id) => request(`/api/ventes/${id}`),
  // `input: false` cote serveur, mais le processor lit bien un corps : motif, montant partiel
  // facultatif, et jeton de rejeu apres validation d'une escalade.
  rembourserVente: (id, corps) => request(`/api/ventes/${id}/rembourser`, { method: 'POST', body: corps }),
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

  // CRM.
  rechercheClients: (params) => request('/api/crm/clients/recherche', { query: params }),
  ficheClient: (id) => request(`/api/clients/${id}/fiche-360`),
  // La fiche 360 ne porte qu'un sous-ensemble des champs : pour modifier, il faut le client entier.
  client: (id) => request(`/api/clients/${id}`),
  majClient: (id, corps) => request(`/api/clients/${id}`, { method: 'PATCH', body: corps }),
  // Relevé de mouvements du porte-monnaie virtuel (US-L5-04). Renvoie { mouvements: [...] }.
  pmvMouvements: (id) => request(`/api/clients/${id}/pmv/mouvements`),
  // Création rapide d'une fiche client (US-L5-02). L'établissement de création / le groupe sont
  // fixés côté back depuis l'établissement actif (en-tête X-Etablissement).
  creerClient: (corps) => request('/api/clients', { method: 'POST', body: corps, ld: true }),
  // Rattache (ou crée) un client sur une vente ouverte (M2, CA-7). Corps : un de
  // { client: uuid } | { recherche: "..." } | { creer: { nom, prenom, email, telephone } }.
  rattacherClientVente: (venteId, corps) =>
    request(`/api/ventes/${venteId}/client`, { method: 'POST', body: corps, timeoutMs: 20000 }),

  // --- Options produits (App\OptionProduit) ---
  // Groupes d'options (choix unique/multiple) — référentiel réutilisable (RG-OPT-01).
  groupeOptions: () => request('/api/groupe_options', { query: { itemsPerPage: 200 } }),
  creerGroupeOption: (corps) => request('/api/groupe_options', { method: 'POST', body: corps, ld: true }),
  majGroupeOption: (id, corps) =>
    request(`/api/groupe_options/${id}`, { method: 'PATCH', body: corps }),
  // Valeurs d'un groupe (impact prix fixe/%). Filtrable par groupe (SearchFilter exact).
  valeurOptions: (groupeId) =>
    request('/api/valeur_options', {
      query: { itemsPerPage: 300, ...(groupeId ? { groupeOption: groupeId } : {}) },
    }),
  creerValeurOption: (corps) => request('/api/valeur_options', { method: 'POST', body: corps, ld: true }),
  majValeurOption: (id, corps) =>
    request(`/api/valeur_options/${id}`, { method: 'PATCH', body: corps }),
  // Rattachements groupe↔produit (pivot). Filtrable par produit (SearchFilter exact).
  optionProduits: (produitId) =>
    request('/api/option_produits', {
      query: { itemsPerPage: 300, ...(produitId ? { produit: produitId } : {}) },
    }),
  creerOptionProduit: (corps) => request('/api/option_produits', { method: 'POST', body: corps, ld: true }),
  majOptionProduit: (id, corps) =>
    request(`/api/option_produits/${id}`, { method: 'PATCH', body: corps }),
  supprimerOptionProduit: (id) =>
    request(`/api/option_produits/${id}`, { method: 'DELETE' }),
  // Options proposables à la vente pour un produit sur l'établissement actif (RG-OPT-07/08).
  optionsDisponibles: (produitId) => request(`/api/produits/${produitId}/options-disponibles`),

  // --- Tranche 3 ---

  // Réservation / Planning (M5).
  reservationRessources: () => request('/api/reservation_ressources', { query: { itemsPerPage: 100 } }),
  reservationCreneaux: () => request('/api/reservation_creneaus', { query: { itemsPerPage: 200 } }),
  reservationActivites: () => request('/api/reservation_activites', { query: { itemsPerPage: 100 } }),
  reservations: () => request('/api/reservations', { query: { itemsPerPage: 200 } }),
  // No-show (D27) : les deux operations existaient et n'etaient appelees de nulle part.
  facturationsNoShow: () => request('/api/reservation_facturation_no_shows', { query: { itemsPerPage: 100 } }),
  exonererNoShow: (id, corps) =>
    request(`/api/reservation/facturations-no-show/${id}/exonerer`, { method: 'POST', body: corps }),
  emettreVenteNoShow: (id, corps) =>
    request(`/api/reservation/facturations-no-show/${id}/emettre-vente`, { method: 'POST', body: corps }),
  beneficiaires: () => request('/api/beneficiaires', { query: { itemsPerPage: 100 } }),
  // Écriture : réserver un créneau (créneau + organisateur en IRI). L'opération API Platform
  // n'accepte que le format JSON-LD (application/ld+json) — sans `ld`, le back répond 415.
  // Coupe-circuit 25 s.
  reserverCreneau: (corps) =>
    request('/api/reservation/reservations', { method: 'POST', body: corps, ld: true, timeoutMs: 25000 }),

  // Supervision accès / FMI (M3).
  supervisionAcces: () => request('/api/acces/supervision'),
  // Verification d'un billet : le support par son numero imprime, puis son droit actif.
  // `Appairage` n'expose aucun filtre : on charge et on croise cote client, faute de mieux.
  supports: (params) => request('/api/supports', { query: params }),
  appairages: () => request('/api/appairages', { query: { itemsPerPage: 200 } }),
  jaugesFmi: () => request('/api/jauge_fmis', { query: { itemsPerPage: 100 } }),
  passages: () =>
    request('/api/passages', { query: { itemsPerPage: 20, 'order[horodatage]': 'desc' } }),

  // Reporting / Pilotage (M7). Route hors /api (proxifiée via /reporting).
  dashboardEtablissement: (id) => request(`/reporting/dashboards/etablissement/${id}`),
  // Référentiel des indicateurs (M7).
  indicateurs: () => request('/api/indicateurs', { query: { itemsPerPage: 100 } }),

  // --- Paramètres (référentiels, lecture) ---
  espaces: () => request('/api/espaces', { query: { itemsPerPage: 200 } }),
  regions: () => request('/api/regions', { query: { itemsPerPage: 100 } }),
  categories: () => request('/api/categories', { query: { itemsPerPage: 200 } }),
  creerCategorie: (corps) => request('/api/categories', { method: 'POST', body: corps, ld: true }),
  majCategorie: (id, corps) => request(`/api/categories/${id}`, { method: 'PATCH', body: corps }),
  supprimerCategorie: (id) => request(`/api/categories/${id}`, { method: 'DELETE' }),
  typeTarifs: () => request('/api/type_tarifs', { query: { itemsPerPage: 100 } }),
  // Référentiels modifiables : les opérations existaient côté serveur depuis le début, le front ne
  // les appelait simplement pas.
  creerTypeTarif: (corps) => request('/api/type_tarifs', { method: 'POST', body: corps, ld: true }),
  majTypeTarif: (id, corps) => request(`/api/type_tarifs/${id}`, { method: 'PATCH', body: corps }),
  supprimerTypeTarif: (id) => request(`/api/type_tarifs/${id}`, { method: 'DELETE' }),
  grilleTarifaires: () => request('/api/grille_tarifaires', { query: { itemsPerPage: 200 } }),
  // Post et Patch existaient depuis le debut, appeles de nulle part. Pas de Delete cote serveur :
  // un prix engage dans des ventes passees ne s'efface pas.
  creerGrilleTarifaire: (corps) => request('/api/grille_tarifaires', { method: 'POST', body: corps, ld: true }),
  majGrilleTarifaire: (id, corps) =>
    request(`/api/grille_tarifaires/${id}`, { method: 'PATCH', body: corps }),
  saisons: () => request('/api/saisons', { query: { itemsPerPage: 100 } }),
  creerSaison: (corps) => request('/api/saisons', { method: 'POST', body: corps, ld: true }),
  majSaison: (id, corps) => request(`/api/saisons/${id}`, { method: 'PATCH', body: corps }),
  supprimerSaison: (id) => request(`/api/saisons/${id}`, { method: 'DELETE' }),
  tauxTvas: () => request('/api/taux_tvas', { query: { itemsPerPage: 100 } }),
  creerTauxTva: (corps) => request('/api/taux_tvas', { method: 'POST', body: corps, ld: true }),
  majTauxTva: (id, corps) => request(`/api/taux_tvas/${id}`, { method: 'PATCH', body: corps }),

  // Comptes / rôles & droits (M8).
  utilisateurs: () => request('/api/utilisateurs', { query: { itemsPerPage: 100 } }),
  roles: () => request('/api/roles', { query: { itemsPerPage: 100 } }),
  // Creer, modifier, dupliquer, supprimer un role : quatre operations qui existaient sans bouton,
  // sur l'ecran qui s'appelle « Utilisateurs et droits ».
  creerRole: (corps) => request('/api/roles', { method: 'POST', body: corps, ld: true }),
  majRole: (id, corps) => request(`/api/roles/${id}`, { method: 'PATCH', body: corps }),
  supprimerRole: (id) => request(`/api/roles/${id}`, { method: 'DELETE' }),
  dupliquerRole: (id) => request(`/api/roles/${id}/dupliquer`, { method: 'POST' }),
  permissions: () => request('/api/permissions', { query: { itemsPerPage: 300 } }),
  affectations: () => request('/api/affectations', { query: { itemsPerPage: 200 } }),
  // Création de compte : sans mot de passe, le back génère un jeton d'invitation et passe le
  // compte en `statut = invite` (UtilisateurProcessor, RG-M8-01).
  creerUtilisateur: (corps) =>
    request('/api/utilisateurs', { method: 'POST', body: corps, ld: true }),
  // Cycle de vie (RG-M8-01). Le back refuse (422) toute opération laissant un établissement sans
  // administrateur (RG-M8-07) : l'erreur est remontée telle quelle.
  suspendreUtilisateur: (id) =>
    request(`/api/utilisateurs/${id}/suspendre`, { method: 'POST', body: {} }),
  reactiverUtilisateur: (id) =>
    request(`/api/utilisateurs/${id}/reactiver`, { method: 'POST', body: {} }),
  reinviterUtilisateur: (id) =>
    request(`/api/utilisateurs/${id}/reinviter`, { method: 'POST', body: {} }),
  // Affectation d'un rôle sur un établissement (utilisateur/role/etablissement en IRI).
  creerAffectation: (corps) =>
    request('/api/affectations', { method: 'POST', body: corps, ld: true }),
  // Aperçu des droits conférés par un rôle (matrice « vivante », US-L7-05).
  apercuDroitsRole: (id, etablissement) =>
    request(`/api/roles/${id}/apercu-droits`, { query: { etablissement } }),

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
  // Absences : declarer, accepter, refuser. Trois operations qui n'avaient aucun bouton.
  absences: () => request('/api/absences', { query: { itemsPerPage: 200 } }),
  declarerAbsence: (corps) => request('/api/personnel/absences', { method: 'POST', body: corps }),
  validerAbsence: (id) => request(`/api/personnel/absences/${id}/valider`, { method: 'POST' }),
  refuserAbsence: (id) => request(`/api/personnel/absences/${id}/refuser`, { method: 'POST' }),
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
  // Le parc par pointure : c'est lui qui dit ce qui est louable, pas la liste des locations.
  patinoireParc: () =>
    request('/api/patinoire_parc_patins', { query: { itemsPerPage: 200 } }),
  patinoireListeAttente: () =>
    request('/api/patinoire_liste_attente_pointures', { query: { itemsPerPage: 100 } }),
  patinoireRetenues: () =>
    request('/api/patinoire_retenue_cautions', { query: { itemsPerPage: 100 } }),
  // Opérations sur mesure (`uriTemplate`) : elles portent `input: false`, leur processor lit le corps
  // brut. Pas de `ld: true` — l'ajouter ici serait exactement la correction que `verifier-formats`
  // cherche à éviter.
  patinoireSortirPatins: (corps) =>
    request('/api/patinoire/locations', { method: 'POST', body: corps }),
  patinoireRetourPatins: (id, corps) =>
    request(`/api/patinoire/locations/${id}/retour`, { method: 'POST', body: corps }),
  patinoireDemarrerAffutage: (corps) =>
    request('/api/patinoire/affutages', { method: 'POST', body: corps }),
  patinoireTerminerAffutage: (id) =>
    request(`/api/patinoire/affutages/${id}/terminer`, { method: 'POST', body: {} }),
  patinoireInscrireListeAttente: (corps) =>
    request('/api/patinoire/liste-attente', { method: 'POST', body: corps }),
  patinoireAnnulerListeAttente: (id) =>
    request(`/api/patinoire/liste-attente/${id}/annuler`, { method: 'POST', body: {} }),
  patinoireValiderRetenue: (id, corps) =>
    request(`/api/patinoire/retenues/${id}/valider`, { method: 'POST', body: corps }),
  // Stock
  // `articleStock` ne porte AUCUNE quantite : le stock reel vit dans les lots, un article pouvant en
  // avoir plusieurs (dates d'entree et couts d'achat differents). C'est pour ca que les deux listes
  // sont chargees ensemble et agregees a l'ecran.
  stockArticles: () => request('/api/article_stocks', { query: { itemsPerPage: 200 } }),
  stockLots: () => request('/api/stock_lots', { query: { itemsPerPage: 500 } }),
  stockMouvements: () =>
    request('/api/stock_mouvements', { query: { itemsPerPage: 50, 'order[date]': 'desc' } }),
  stockParametrage: () => request('/api/stock_parametrages', { query: { itemsPerPage: 5 } }),
  stockAlertesReappro: () => request('/api/stock/alertes-reappro'),
  stockValorisation: () => request('/api/stock/valorisation'),
  creerArticleStock: (corps) => request('/api/article_stocks', { method: 'POST', body: corps, ld: true }),
  majArticleStock: (id, corps) => request(`/api/article_stocks/${id}`, { method: 'PATCH', body: corps }),
  // Operations sur mesure : `input: false`, le processor lit le corps brut. Pas de `ld: true`.
  stockAjuster: (corps) => request('/api/stock/mouvements/ajustement', { method: 'POST', body: corps }),
  // Rattacher un article a un produit vendu : c'est CE lien qui fait qu'une vente decremente le
  // stock. Sans lui, le produit se vend et rien ne bouge — volontairement, et silencieusement.
  stockRattacherProduit: (id, produit) =>
    request(`/api/stock/articles/${id}/rattacher-produit`, { method: 'POST', body: { produit } }),
  stockDetacherProduit: (id) =>
    request(`/api/stock/articles/${id}/detacher-produit`, { method: 'POST', body: {} }),

  // Padel
  padelTerrains: () => request('/api/padel/terrains', { query: { itemsPerPage: 100 } }),
  // Pas de collection listable pour les tournois (seulement des routes custom
  // /api/padel/tournois/{id}/...) : on renvoie un état vide propre.
  padelTournois: () => Promise.resolve({ 'hydra:member': [] }),
  // Musée
  museeExpositions: () => request('/api/musee_expositions', { query: { itemsPerPage: 100 } }),
  museeVisitesGuidees: () =>
    request('/api/musee_visite_guidees', { query: { itemsPerPage: 100 } }),

  // Administration de l'éditeur (ED-6). Le serveur répond 404 si la session n'est pas celle de
  // l'éditeur : le contrôle est une identité de tenant, pas une permission, et il n'est pas rejoué
  // ici (D39).
  editorSubscriptions: () => request('/api/editor/subscriptions'),

  // Catalogue d'offres, côté administration éditeur (ED-6). Ces routes rendent AUSSI ce que la
  // vitrine cache — formules retirées de la vente, formules incohérentes — parce que c'est le seul
  // écran où on peut les corriger.
  editorPlans: () => request('/api/editor/catalog/plans'),
  creerEditorPlan: (corps) => request('/api/editor/catalog/plans', { method: 'POST', body: corps }),
  majEditorPlan: (id, corps) => request(`/api/editor/catalog/plans/${id}`, { method: 'PATCH', body: corps }),
  supprimerEditorPlan: (id) => request(`/api/editor/catalog/plans/${id}`, { method: 'DELETE' }),

  editorOptions: () => request('/api/editor/catalog/options'),
  creerEditorOption: (corps) => request('/api/editor/catalog/options', { method: 'POST', body: corps }),
  majEditorOption: (id, corps) => request(`/api/editor/catalog/options/${id}`, { method: 'PATCH', body: corps }),
  supprimerEditorOption: (id) => request(`/api/editor/catalog/options/${id}`, { method: 'DELETE' }),

  // Fiche client de l'éditeur (ED-6). La collection ne rend QUE les clients du CRM de l'éditeur :
  // les clients finaux des exploitants vivent dans la même table et n'ont rien à faire ici.
  editorCustomers: () => request('/api/editor/customers'),
  editorCustomer: (id) => request(`/api/editor/customers/${id}`),

  // Facturation des abonnements (ED-7). La collection remonte en tête ce qui n'a PAS été facturé :
  // un abonnement actif qu'on oublie ne produit aucun signal, seulement de l'argent jamais prélevé.
  editorBilling: (mois) => request('/api/editor/billing', { query: mois ? { month: mois } : {} }),
  emettreFactureAbonnement: (corps) => request('/api/editor/billing', { method: 'POST', body: corps }),

  // Ce qui reste du a l'editeur (ED-8). Une facture soldee ne figure pas dans la reponse : le
  // serveur la retire, l'ecran n'a pas a decider ce qu'il montre.
  editorReceivables: () => request('/api/editor/receivables'),
}
