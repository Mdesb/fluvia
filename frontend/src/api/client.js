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
//
// ⚠ UN TABLEAU DEVIENT `k[]=a&k[]=b`, ET CE N'EST PAS UNE PRÉFÉRENCE DE STYLE.
//
// La version précédente faisait `String(v)` sur tout, donc un tableau partait en `k=a,b` — une seule
// valeur contenant une virgule. Côté serveur, `$request->query->all('k')` attend des entrées
// répétées : il n'aurait rien trouvé, **sans lever**, et le filtre serait resté silencieusement vide.
//
// C'est la forme la plus courante du défaut de cette semaine : une requête qui part, une réponse qui
// arrive, un résultat plausible et faux. Ici il se serait traduit par « aucune option retenue » sur
// un panier où le caissier venait d'en cocher trois — et le client aurait payé le prix de base.
function qs(params) {
  if (!params) return ''
  const usp = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined || v === '') continue
    if (Array.isArray(v)) {
      for (const element of v) {
        if (element === null || element === undefined || element === '') continue
        usp.append(`${k}[]`, String(element))
      }
      continue
    }
    usp.append(k, String(v))
  }
  const s = usp.toString()
  return s ? `?${s}` : ''
}

async function request(
  path,
  { method = 'GET', body, formData, ld = false, auth = true, headers: extra = {}, query, timeoutMs } = {},
) {
  const headers = { ...extra }
  // ⚠ ON NE POSE PAS `Content-Type` SUR UN ENVOI MULTIPART, ET C'EST CONTRE-INTUITIF.
  //
  // Le navigateur doit le composer lui-même, parce qu'il y ajoute la *frontière* (`boundary`) qui
  // sépare les parties du corps. Un `Content-Type: multipart/form-data` écrit à la main arrive donc
  // SANS frontière : PHP reçoit un corps qu'il ne sait pas découper, `$request->files` est vide, et
  // le serveur répond « fichier manquant » sur une requête qui contenait le fichier.
  //
  // Le symptôme accuse l'appelant ; la cause est cet en-tête de trop.
  if (formData !== undefined) {
    // rien : le navigateur s'en charge
  } else if (body !== undefined) {
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
      body: formData !== undefined ? formData : (body !== undefined ? JSON.stringify(body) : undefined),
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

// Les routes des gestes d'une pièce commerciale (FAC-1). Aucune ne prend de corps : tout est dans
// la route, et le serveur les déclare `input: false`.
const GESTES_PIECE = {
  issue: (id) => request(`/api/billing/documents/${id}/issue`, { method: 'POST' }),
  accept: (id) => request(`/api/billing/documents/${id}/accept`, { method: 'POST' }),
  reject: (id) => request(`/api/billing/documents/${id}/reject`, { method: 'POST' }),
  derive: (id) => request(`/api/billing/documents/${id}/derive`, { method: 'POST' }),
  invoice: (id) => request(`/api/billing/documents/${id}/invoice`, { method: 'POST' }),
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

  // Pièces commerciales (FAC-1) : devis -> bon de commande -> bon de livraison -> facture.
  //
  // Aucun `ld: true` : les huit opérations sont déclarées `input: false` côté serveur et lisent le
  // corps brut, elles ne passent donc pas par la désérialisation d'API Platform. Les cinq gestes
  // n'ont carrément pas de corps — seul l'identifiant compte, tout est dans la route.
  piecesCommerciales: () => request('/api/billing/documents'),
  pieceCommerciale: (id) => request(`/api/billing/documents/${id}`),
  creerDevis: (corps) => request('/api/billing/documents', { method: 'POST', body: corps }),
  // Les cinq gestes sont écrits en toutes lettres, un par ligne, et non composés depuis une variable.
  //
  // Deux raisons, et la seconde n'est pas cosmétique. D'abord un geste mal orthographié échoue ici,
  // à l'appel, au lieu de partir en 404 sur une route qui n'existe pas. Ensuite `verifier-formats`
  // compare le chemin au `uriTemplate` déclaré côté serveur, et il normalise toute interpolation en
  // `{id}` : un chemin composé donnait `/billing/documents/{id}/{id}`, qui ne correspond à rien, et
  // le contrôle réclamait un `ld: true` dont ces routes n'ont que faire. Le contrôle avait raison de
  // ne pas savoir — c'est au code appelé d'être lisible.
  gestePiece: (id, geste) => GESTES_PIECE[geste](id),

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

  // CONTACTS D'UN CLIENT PROFESSIONNEL. `Beneficiaire` porte une semantique de FAMILLE
  // (payeur, beneficiaire) : elle ne sait pas dire << directrice >> ni << comptabilite >>.
  //
  // Charges entiers puis filtres a l'ecran : le `SearchFilter` sur `customer` est de la famille
  // D58 -- il rend soit tout, soit rien, sans jamais lever.
  contactsClient: () => request('/api/customer_contacts', { query: { itemsPerPage: 300 } }),
  creerContactClient: (corps) =>
    request('/api/customer_contacts', { method: 'POST', body: corps, ld: true }),
  majContactClient: (id, corps) =>
    request(`/api/customer_contacts/${id}`, { method: 'PATCH', body: corps }),
  supprimerContactClient: (id) =>
    request(`/api/customer_contacts/${id}`, { method: 'DELETE' }),

  // AFFAIRES EN COURS -- ce qui vit entre << un client appelle >> et << un devis part >>.
  //
  // `/crm/pipeline` rend les colonnes avec l'etape EFFECTIVE : des qu'un devis est rattache,
  // c'est lui qui dit ou en est l'affaire. Rien n'est recopie -- une copie prend du retard, et
  // une etape en retard est pire qu'absente parce qu'elle a l'air d'etre a jour.
  pipeline: () => request('/api/crm/pipeline'),

  // SEGMENTS -- une DEFINITION de clients, jamais une liste.
  //
  // Les membres se calculent a chaque lecture : c'est ce que veut dire << segment dynamique >>, et
  // c'est ce qu'exige son critere d'acceptation (<< immediatement disponible et a jour >>). Une
  // liste stockee serait juste le jour de sa creation et fausse le lendemain, avec exactement la
  // meme allure.
  segments: () => request('/api/segments', { query: { itemsPerPage: 200 } }),
  // Relu avant edition : la ligne du tableau date du dernier chargement, et un collegue a pu
  // changer les criteres entre-temps.
  segment: (id) => request(`/api/segments/${id}`),
  creerSegment: (corps) => request('/api/segments', { method: 'POST', body: corps, ld: true }),
  majSegment: (id, corps) => request(`/api/segments/${id}`, { method: 'PATCH', body: corps }),
  supprimerSegment: (id) => request(`/api/segments/${id}`, { method: 'DELETE' }),

  // L'EFFECTIF AVANT L'ENVOI. Sans ce chiffre, l'exploitant decouvre l'ampleur de son geste apres
  // l'avoir fait -- et un message parti ne se rattrape pas. Rend aussi un echantillon nominatif :
  // un compte ne se verifie pas, des noms se reconnaissent.
  apercuSegment: (id) => request(`/api/marketing/segments/${id}/apercu`),

  // CAMPAGNES -- un message, une audience, et la trace de ce qui s'est passe.
  campagnes: () => request('/api/campaigns', { query: { itemsPerPage: 200 } }),
  creerCampagne: (corps) => request('/api/campaigns', { method: 'POST', body: corps, ld: true }),
  majCampagne: (id, corps) => request(`/api/campaigns/${id}`, { method: 'PATCH', body: corps }),
  campagne: (id) => request(`/api/campaigns/${id}`),

  // L'ENVOI -- le geste qu'on ne rattrape pas. Rend le detail PAR MOTIF : << 310 exclus faute de
  // consentement >> dit qu'il faut travailler le recueil du consentement, << 930 envoyes >> ne dit
  // rien.
  envoyerCampagne: (id) =>
    request(`/api/marketing/campagnes/${id}/envoyer`, { method: 'POST', body: {} }),
  resultatCampagne: (id) => request(`/api/marketing/campagnes/${id}/resultat`),

  // L'ATTRIBUTION -- ce que la campagne a produit EN PLUS de ce qui serait arrive sans elle.
  // Droit distinct (campagne.lire_journal) : la reponse expose du chiffre d'affaires par groupe.
  attributionCampagne: (id) => request(`/api/marketing/campagnes/${id}/attribution`),

  // FIDELITE -- solde, palier et historique d'un client. Tout est calcule : aucun compteur n'est
  // stocke, donc une vente annulee retire ses points d'elle-meme.
  fidelite: (clientId) => request(`/api/marketing/fidelite/${clientId}`),
  mouvementFidelite: (corps) =>
    request('/api/marketing/fidelite/mouvements', { method: 'POST', body: corps, ld: true }),

  // PARRAINAGE -- le code est CREE au premier appel : demander son code est le geste qui l'attribue.
  codeParrainage: (clientId) => request(`/api/marketing/parrainage/code/${clientId}`),
  parrainages: (parrain) =>
    request('/api/marketing/parrainages', { query: parrain ? { parrain } : {} }),
  declarerParrainage: (corps) =>
    request('/api/marketing/parrainages', { method: 'POST', body: corps, ld: true }),
  // Le versement est EXPLICITE : une lecture qui verse verserait deux fois si on la rafraichit.
  recompenserParrainage: (id) =>
    request(`/api/marketing/parrainages/${id}/recompenser`, { method: 'POST', body: {} }),

  // PARAMETRAGE -- un bareme est DATE : il vaut a partir d'un jour et ne reecrit pas le passe.
  // C'est pour cela qu'on en AJOUTE un et qu'on n'en modifie jamais : corriger un bareme passe
  // changerait des soldes deja annonces aux clients.
  baremesFidelite: () => request('/api/loyalty_rules', { query: { itemsPerPage: 100 } }),
  creerBaremeFidelite: (corps) => request('/api/loyalty_rules', { method: 'POST', body: corps, ld: true }),

  paliersFidelite: () => request('/api/loyalty_tiers', { query: { itemsPerPage: 100 } }),
  creerPalierFidelite: (corps) => request('/api/loyalty_tiers', { method: 'POST', body: corps, ld: true }),
  supprimerPalierFidelite: (id) => request(`/api/loyalty_tiers/${id}`, { method: 'DELETE' }),

  programmesParrainage: () => request('/api/referral_programs', { query: { itemsPerPage: 100 } }),
  creerProgrammeParrainage: (corps) =>
    request('/api/referral_programs', { method: 'POST', body: corps, ld: true }),
  majProgrammeParrainage: (id, corps) =>
    request(`/api/referral_programs/${id}`, { method: 'PATCH', body: corps }),

  // ECHANGES COMMERCIAUX -- ce qui s'est passe avec un client, et le prochain geste.
  //
  // Distinct d'un ticket d'assistance : un ticket est SUBI et se ferme, un echange est DECIDE et la
  // relation continue. Les ranger ensemble forcerait un statut << ferme >> sur un client qu'on
  // rappellera dans six mois.
  //
  // /!\ La collection est chargee entiere puis filtree cote ecran. `CommercialActivity` porte un
  // SearchFilter sur `customer` -- famille D58, ou le filtre rend soit tout soit rien, sans jamais
  // lever. Un historique vide ressemble a un client qu'on n'a jamais appele : on ne l'emprunte pas.
  activitesCommerciales: () =>
    request('/api/commercial_activities', { query: { itemsPerPage: 500 } }),
  creerActivite: (corps) =>
    request('/api/commercial_activities', { method: 'POST', body: corps, ld: true }),

  // RELANCES EN ATTENTE -- deduites, jamais stockees.
  //
  // Une relance tient tant qu'aucun echange plus recent n'existe sur la meme cible : rappeler suffit
  // a la faire disparaitre. Il n'y a donc rien a cocher, et pas de seconde boite de taches a cote
  // du module Projets -- personne ne regarde les deux.
  relances: () => request('/api/crm/relances'),

  // PROJETS -- travail interne qui a une fin. A ne pas confondre avec un ticket d'assistance
  // (arrive de l'exterieur) ni avec une tache planifiee (machine, cron).
  //
  // `/projets/tableau` rend l'avancement COMPTE et le retard DEDUIT : rien de tout cela n'est
  // stocke. Un pourcentage recopie serait faux entre deux rafraichissements, et un pourcentage
  // faux est pire qu'absent parce qu'il rassure.
  // DOCUMENTS (App\Dms) -- 15 operations, aucun appelant jusqu'ici.
  //
  // Le televersement est en multipart : `request()` laisse alors le navigateur composer l'en-tete,
  // frontiere comprise. Le telechargement, lui, n'est pas une operation API Platform mais un
  // controleur qui diffuse le flux -- on ouvre donc l'URL, on ne la lit pas en JSON.
  documentsDms: (params) => request('/api/documents', { query: { itemsPerPage: 100, ...(params || {}) } }),
  televerserDocument: (formData) => request('/api/documents', { method: 'POST', formData }),
  majDocumentDms: (id, corps) => request(`/api/documents/${id}`, { method: 'PATCH', body: corps }),
  remplacerVersionDocument: (id, formData) =>
    request(`/api/documents/${id}/replace-version`, { method: 'POST', formData }),
  versionsDocument: () => request('/api/document_versions', { query: { itemsPerPage: 300 } }),
  urlTelechargementDocument: (id) => `/dms/documents/${id}/download`,

  // PUBLICATION SOCIALE -- onze operations, aucun appelant jusqu'ici.
  //
  // Les publications sont chargees SEPAREMENT des messages : c'est le detail par compte qui distingue
  // un envoi a moitie reussi d'un echec complet. Un statut global sur une action partielle est faux
  // dans les deux sens, et le lecteur ne peut pas savoir lequel.
  comptesSociaux: () => request('/api/social_accounts', { query: { itemsPerPage: 50 } }),
  messagesSociaux: () => request('/api/social_posts', { query: { itemsPerPage: 100 } }),
  publicationsSociales: () => request('/api/social_publications', { query: { itemsPerPage: 300 } }),
  creerMessageSocial: (corps) => request('/api/social_posts', { method: 'POST', body: corps, ld: true }),

  // SPORT & FITNESS -- et d'abord les alertes que personne n'entendait.
  //
  // `EvenementSOS` porte un statut << ouverte >> et une operation << traiter >>, sans aucun ecran :
  // une alarme qu'aucune interface ne montre cree la croyance qu'on serait prevenu.
  // ⚠ `/api/evenement_s_o_s` et non `evenement_sos` : API Platform transforme le nom court
  // `EvenementSOS` caractere par caractere -- chaque majuscule devient un segment. Devine, le
  // chemin rend 404, et l'ecran conclut << aucune alerte >> sur une salle qui en a.
  //
  // C'est la troisieme fois aujourd'hui qu'un chemin suppose se revele faux. On les releve
  // desormais dans `debug:router`, jamais par deduction depuis le nom de la classe.
  evenementsSOS: () => request('/api/evenement_s_o_s', { query: { itemsPerPage: 100 } }),
  traiterSOS: (id) => request(`/api/sport/sos/${id}/traiter`, { method: 'POST', body: {} }),
  alertesPresenceIsolee: () => request('/api/alerte_presence_isolees', { query: { itemsPerPage: 100 } }),
  abonnementsFitness: () => request('/api/abonnement_fitness', { query: { itemsPerPage: 200 } }),

  tableauProjets: () => request('/api/projets/tableau'),
  creerProjet: (corps) => request('/api/projects', { method: 'POST', body: corps, ld: true }),
  majProjet: (id, corps) => request(`/api/projects/${id}`, { method: 'PATCH', body: corps }),
  tachesProjet: () => request('/api/project_tasks', { query: { itemsPerPage: 500 } }),
  creerTacheProjet: (corps) => request('/api/project_tasks', { method: 'POST', body: corps, ld: true }),
  majTacheProjet: (id, corps) => request(`/api/project_tasks/${id}`, { method: 'PATCH', body: corps }),
  supprimerTacheProjet: (id) => request(`/api/project_tasks/${id}`, { method: 'DELETE' }),
  creerOpportunite: (corps) => request('/api/opportunities', { method: 'POST', body: corps, ld: true }),
  majOpportunite: (id, corps) => request(`/api/opportunities/${id}`, { method: 'PATCH', body: corps }),
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
  // ⚠ UN FILTRE DE RELATION PREND UNE IRI, ET UN IDENTIFIANT NU LE FAIT ABANDONNER.
  //
  // C'est le piège D58 sous sa **septième forme**, et la seule qui rende **plus** au lieu de moins.
  //
  // `SearchFilter::filterProperty` résout la valeur d'un filtre de relation par `getResourceFromIri`.
  // Un UUID nu n'est pas une IRI : la résolution lève, le repli teste la valeur contre le type Doctrine
  // de l'identifiant — ici le type personnalisé `uuid`, absent de la liste admise — et la déclare
  // invalide. Le filtre est alors **retiré de la requête** :
  //
  //     $this->logger->notice('Invalid filter ignored', …);
  //     return;   // ← aucune condition ajoutée
  //
  // La bibliothèque commente elle-même la ligne au-dessus : « Shouldn't this actually fail harder? »
  //
  // **La collection revient entière.** Les six formes connues de D58 rendent « rien » — ça ressemble à
  // une absence, et une absence intrigue. Celle-ci rend « tout » : ça ressemble à des données, et
  // personne n'interroge des données qui s'affichent. Sur la fiche produit, chacun des deux groupes
  // d'options listait les cinq valeurs des deux groupes.
  //
  // L'IRI supprime le repli : la résolution réussit, le filtre s'applique.
  valeurOptions: (groupeId) =>
    request('/api/valeur_options', {
      query: { itemsPerPage: 300, ...(groupeId ? { groupeOption: `/api/groupe_options/${groupeId}` } : {}) },
    }),
  creerValeurOption: (corps) => request('/api/valeur_options', { method: 'POST', body: corps, ld: true }),
  majValeurOption: (id, corps) =>
    request(`/api/valeur_options/${id}`, { method: 'PATCH', body: corps }),
  // Rattachements groupe↔produit (pivot). IRI et non identifiant nu — voir `valeurOptions` ci-dessus.
  //
  // Celui-ci était plus discret : sans filtre effectif, la fiche d'un produit montrait les groupes
  // rattachés à **tous** les produits. Un seul produit en démonstration le rendait invisible.
  optionProduits: (produitId) =>
    request('/api/option_produits', {
      query: { itemsPerPage: 300, ...(produitId ? { produit: `/api/produits/${produitId}` } : {}) },
    }),
  creerOptionProduit: (corps) => request('/api/option_produits', { method: 'POST', body: corps, ld: true }),
  majOptionProduit: (id, corps) =>
    request(`/api/option_produits/${id}`, { method: 'PATCH', body: corps }),
  supprimerOptionProduit: (id) =>
    request(`/api/option_produits/${id}`, { method: 'DELETE' }),
  // LE PRIX APPLICABLE, OPTIONS COMPRISES, RENDU PAR LE SERVEUR.
  //
  // `GET /produits/{id}/tarif` appelle **le même service que la composition d'une ligne de vente** :
  // ce n'est pas une estimation parallèle, c'est le calcul qui facturera. Il rend les groupes
  // d'options avec leur prix, **les indisponibles comprises et leur motif**, plus `totalUnitaire` et
  // `totalLigne`.
  //
  // L'écran n'additionne donc rien. La raison n'est pas que l'addition serait difficile : un plafond
  // sur le cumul, une remise « pack », une option qui en rend une autre gratuite — et une somme faite
  // ici deviendrait fausse **en continuant de rendre un nombre plausible**.
  //
  // `options` est une liste d'identifiants de valeurs retenues ; le serveur ignore celles qu'il
  // refuse et le dit dans sa réponse.
  tarifProduit: (produitId, { typeTarif, canal = 'guichet', date, qf, options = [], quantite = 1 } = {}) =>
    request(`/api/produits/${produitId}/tarif`, {
      query: {
        typeTarif,
        canal,
        ...(date ? { date } : {}),
        ...(qf !== undefined && qf !== null && qf !== '' ? { qf } : {}),
        ...(options.length ? { options } : {}),
        quantite,
      },
    }),

  // Options proposables à la vente pour un produit sur l'établissement actif (RG-OPT-07/08).
  optionsDisponibles: (produitId) => request(`/api/produits/${produitId}/options-disponibles`),

  // --- Tranche 3 ---

  // Réservation / Planning (M5).
  reservationRessources: () => request('/api/reservation_ressources', { query: { itemsPerPage: 100 } }),
  // LA JAUGE D'UNE RESSOURCE ETAIT AFFICHEE A DEUX ENDROITS ET MODIFIABLE NULLE PART.
  //
  // `capacitePropre` porte deja la jauge par ressource -- un terrain de padel a 4, un court de
  // tennis en simple a 2, un bassin en a cinquante. Le modele savait donc les distinguer depuis
  // le debut ; aucun ecran ne permettait de poser la valeur. C'etait la demande de Maxime
  // << jauge differente padel/tennis >>, et il ne manquait que ceci.
  majRessourceReservation: (id, corps) =>
    request(`/api/reservation_ressources/${id}`, { method: 'PATCH', body: corps }),
  reservationCreneaux: () => request('/api/reservation_creneaus', { query: { itemsPerPage: 200 } }),
  // HORAIRES ET ABSENCES D'UNE RESSOURCE -- exposes en CRUD complet depuis le debut, sans un
  // seul appelant. Un coiffeur ne pouvait pas declarer qu'il travaille le mardi.
  //
  // Chargees ENTIERES, sans le `SearchFilter` sur `ressource` : c'est la famille D58, ou le
  // filtre rend soit tout (parametre ignore) soit rien (identifiant lie sans type), sans jamais
  // lever. Le regroupement se fait a l'ecran, ou il est visible ; les volumes le permettent.
  reservationDisponibilites: () =>
    request('/api/reservation_disponibilites', { query: { itemsPerPage: 300 } }),
  creerDisponibilite: (corps) =>
    request('/api/reservation_disponibilites', { method: 'POST', body: corps, ld: true }),
  supprimerDisponibilite: (id) =>
    request(`/api/reservation_disponibilites/${id}`, { method: 'DELETE' }),
  reservationIndisponibilites: () =>
    request('/api/reservation_indisponibilites', { query: { itemsPerPage: 300 } }),
  creerIndisponibilite: (corps) =>
    request('/api/reservation_indisponibilites', { method: 'POST', body: corps, ld: true }),
  supprimerIndisponibilite: (id) =>
    request(`/api/reservation_indisponibilites/${id}`, { method: 'DELETE' }),
  reservationActivites: () => request('/api/reservation_activites', { query: { itemsPerPage: 100 } }),
  // PLACEMENT LIBRE : les debuts ou un rendez-vous TIENDRAIT, un jour donne.
  //
  // Sans `ressource`, on interroge tous les praticiens capables -- le mode << avec qui est
  // libre >>, qui est celui qui remplit un agenda. Ce point d'entree ne reserve rien : il
  // propose, et la reservation reste la creation d'un creneau puis d'une reservation.
  creneauxLibres: (params) => request('/api/reservation/creneaux-libres', { query: params }),
  creerCreneau: (corps) =>
    request('/api/reservation/creneaux', { method: 'POST', body: corps }),
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
  profilsExploitant: () => request('/api/profil_exploitants', { query: { itemsPerPage: 20 } }),
  periodesComptables: () =>
    request('/api/periode_comptables', { query: { itemsPerPage: 100, 'order[dateDebut]': 'desc' } }),
  exportsComptables: () => request('/api/export_comptables', { query: { itemsPerPage: 50 } }),
  // Operations sur mesure (`input: false`) : pas de `ld: true`.
  //
  // `generer` est idempotent en sequentiel — `ventesValideesNonComptabilisees` exclut ce qui a deja
  // une ecriture. Mais la liste est calculee avant la boucle et le flush n'a lieu qu'a la fin : deux
  // requetes qui se chevauchent voient le meme ensemble et generent toutes les deux. claude-D pose
  // le verrou serveur ; en attendant, le bouton est desactive du clic jusqu'a la reponse, ce qui
  // ferme le cas courant — celui de l'exploitant qui reclique parce que rien ne bouge.
  genererEcritures: (profilId) =>
    request('/api/compta/ecritures/generer', { method: 'POST', body: { profilExploitant: profilId } }),
  validerEcriture: (id) =>
    request(`/api/compta/ecritures/${id}/valider`, { method: 'POST', body: {} }),
  extournerEcriture: (id) =>
    request(`/api/compta/ecritures/${id}/extourne`, { method: 'POST', body: {} }),
  verifierChaineEcritures: (journalId) =>
    request('/api/compta/ecritures/verifier-chaine', { query: { journal: journalId } }),
  cloturerPeriode: (id) =>
    request(`/api/compta/periodes/${id}/cloturer`, { method: 'POST', body: {} }),
  telechargerExport: (id) => request(`/api/compta/exports/${id}/telecharger`),
  // --- Achats & tresorerie ---
  facturesFournisseur: () =>
    request('/api/supplier_invoices', { query: { itemsPerPage: 200 } }),
  // Le rapprochement a trois voies : facture contre commande contre reception. Charge AVANT
  // d'afficher le bouton d'approbation — approuver, c'est engager le paiement.
  rapprochementFactureFournisseur: (id) =>
    request(`/api/finance/supplier-invoices/${id}/reconciliation`),
  approuverFactureFournisseur: (id) =>
    request(`/api/finance/supplier-invoices/${id}/approve`, { method: 'POST', body: {} }),
  contesterFactureFournisseur: (id, corps) =>
    request(`/api/finance/supplier-invoices/${id}/dispute`, { method: 'POST', body: corps }),
  resoudreLitigeFactureFournisseur: (id, corps) =>
    request(`/api/finance/supplier-invoices/${id}/resolve-dispute`, { method: 'POST', body: corps }),
  annulerFactureFournisseur: (id) =>
    request(`/api/finance/supplier-invoices/${id}/cancel`, { method: 'POST', body: {} }),

  // Recouvrement : les deux gestes qui closent un impaye, et le compteur d'acces bloques.
  tableauBordRecouvrement: () => request('/api/recouvrement/tableau-bord'),
  // Ecarts de caisse.  est calcule par le serveur a la lecture — aucun drapeau stocke,
  // donc aucun drapeau a maintenir. C'est lui qui fait descendre la liste (D55).
  alertesEcartCaisse: () =>
    request('/api/alerte_ecart_caisses', { query: { itemsPerPage: 100 } }),
  corrigerReglement: (venteId, corps) =>
    request(`/api/ventes/${venteId}/corriger-reglement`, { method: 'POST', body: corps }),
  resoudreImpaye: (id) =>
    request(`/api/recouvrement/incidents/${id}/resoudre`, { method: 'POST', body: {} }),
  forcerReouvertureImpaye: (id, motif) =>
    request(`/api/recouvrement/incidents/${id}/forcer-reouverture`, { method: 'POST', body: { motif } }),
  // Operation STANDARD : elle deserialise.
  creerExportComptable: (corps) =>
    request('/api/export_comptables', { method: 'POST', body: corps, ld: true }),
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
  // Les REPRÉSENTATIONS bancaires et la POLITIQUE qui les gouverne : deux ressources exposées depuis
  // le début, sans appelant. Sans elles, l'écran montrait des accès bloqués sans jamais dire *quand*
  // la banque va réessayer, ni *quelle règle* a décidé de couper — donc rien de ce qui permet de
  // répondre à l'abonné qui appelle.
  representationsRecouvrement: () =>
    request('/api/representation_recouvrements', { query: { itemsPerPage: 100 } }),
  enregistrerResultatRepresentation: (id, resultat) =>
    request(`/api/recouvrement/representations/${id}/enregistrer-resultat`, {
      method: 'POST',
      body: { resultat },
    }),
  politiquesRecouvrement: () => request('/api/politique_recouvrements', { query: { itemsPerPage: 50 } }),

  // --- Boutique en ligne (M3, vue admin) ---
  // Les paniers en ligne ne sont pas listables (accès par id) : la vue admin s'appuie sur les
  // demandes de remboursement (listables) et les comptes clients boutique.
  demandesRemboursement: () =>
    request('/api/boutique/demandes-remboursement', { query: { itemsPerPage: 100 } }),
  comptesClientBoutique: () =>
    request('/api/compte_clients', { query: { itemsPerPage: 100 } }),
  vitrines: () => request('/api/boutique/vitrines', { query: { itemsPerPage: 100 } }),
  // Le nom d'URL de la boutique. PATCH partiel : on n'envoie que `slug`, pour ne pas
  // reecrire par megarde une couleur ou une langue qu'un autre onglet vient de changer.
  majVitrine: (id, corps) => request(`/api/boutique/vitrines/${id}`, { method: 'PATCH', body: corps }),
  // `montant` absent = remboursement total, c'est le defaut du serveur. On ne l'envoie donc que
  // lorsque l'utilisateur a explicitement choisi un remboursement partiel.
  accepterRemboursement: (id, montant) =>
    request(`/api/boutique/demandes-remboursement/${id}/accepter`, {
      method: 'POST',
      body: montant === undefined ? {} : { montant: String(montant) },
    }),
  refuserRemboursement: (id, motifRefus) =>
    request(`/api/boutique/demandes-remboursement/${id}/refuser`, { method: 'POST', body: { motifRefus } }),

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

  // LE CYCLE DE VIE COMPLET D'UN BADGE, ET NON LA SEULE LECTURE.
  //
  // Quatre operations existaient cote serveur -- emettre, suspendre, revoquer, reactiver -- et
  // l'ecran n'affichait qu'une liste. Un badge est une CLE PHYSIQUE : ne pas pouvoir le desactiver
  // n'est pas une gene d'interface, c'est un acces qui reste ouvert.
  //
  // Suspendre et revoquer ne sont pas la meme chose et ne se remplacent pas : on suspend un badge
  // egare qu'on retrouvera peut-etre, on revoque celui d'une personne qui est partie. Confondre les
  // deux, c'est soit rendre une carte a quelqu'un qui n'a plus rien a faire ici, soit re-emettre un
  // badge pour rien.
  suspendreBadgeStaff: (id, motif) =>
    request(`/api/personnel/badges/${id}/suspendre`, { method: 'POST', body: { motif }, ld: true }),
  reactiverBadgeStaff: (id) =>
    request(`/api/personnel/badges/${id}/reactiver`, { method: 'POST', body: {}, ld: true }),
  emettreBadgeStaff: (employeId) =>
    request(`/api/personnel/employes/${employeId}/badges`, { method: 'POST', body: {}, ld: true }),

  // --- Verticales (routes explicites privilégiées) ---
  // Piscine
  bassins: () => request('/api/bassins', { query: { itemsPerPage: 100 } }),
  creneauxBassin: () => request('/api/creneau_bassins', { query: { itemsPerPage: 200 } }),
  jaugesGrandPublic: () =>
    request('/api/jauge_grand_public_calculees', { query: { itemsPerPage: 100 } }),
  // Casiers : quatre gestes, dont un qui demande un droit plus fort que les autres.
  piscineCasiers: () => request('/api/casiers', { query: { itemsPerPage: 300 } }),
  piscineBracelets: () => request('/api/bracelet_etanches', { query: { itemsPerPage: 300 } }),
  piscineAttribuerCasier: (id, corps) =>
    request(`/api/piscine/casiers/${id}/attribuer`, { method: 'POST', body: corps }),
  piscineLibererCasier: (id) =>
    request(`/api/piscine/casiers/${id}/liberer`, { method: 'POST', body: {} }),
  piscineRelancerCasier: (id) =>
    request(`/api/piscine/casiers/${id}/relancer`, { method: 'POST', body: {} }),
  piscineForcerCasier: (id, motif) =>
    request(`/api/piscine/casiers/${id}/forcer`, { method: 'POST', body: { motif } }),
  // Le POSS est le plan de surveillance : son seuil est une limite reglementaire, pas un confort.
  piscinePoss: () => request('/api/posses', { query: { itemsPerPage: 20 } }),
  piscineEtatPoss: (id) => request(`/api/piscine/poss/${id}/etat`),
  piscineValiderCreneauBassin: (id) =>
    request(`/api/piscine/creneaux-bassin/${id}/valider`, { method: 'POST', body: {} }),
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
  // Valorisation : droit distinct (`stock.lire_valorisation`). Le total de l'etablissement n'accepte
  // PAS de date ; seule la valorisation par article la reconstruit.
  stockValorisation: () => request('/api/stock/valorisation'),
  stockValorisationArticle: (id, date) =>
    request(`/api/stock/articles/${id}/valorisation`, { query: date ? { date } : undefined }),
  // Les imputations ne sont pas lisibles depuis le mouvement : `MouvementStock` expose bien
  // `imputations` dans `mouvement:read`, mais aucune propriete d'`ImputationLotStock` ne porte ce
  // groupe — la collection sort en simples IRI. On la charge donc a part.
  stockImputations: () =>
    request('/api/stock_imputation_lots', { query: { itemsPerPage: 500 } }),
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

  // Inventaire.
  stockInventaires: () =>
    request('/api/stock_inventaires', { query: { itemsPerPage: 20, 'order[dateLancement]': 'desc' } }),
  stockLignesInventaire: () =>
    request('/api/stock_ligne_inventaires', { query: { itemsPerPage: 500 } }),
  // Operation STANDARD (pas d'`uriTemplate`) : elle deserialise, donc `ld: true`. Les trois
  // suivantes sont sur mesure et n'en ont pas besoin.
  stockLancerInventaire: (corps) =>
    request('/api/stock_inventaires', { method: 'POST', body: corps, ld: true }),
  stockSaisirComptage: (id, corps) =>
    request(`/api/stock/lignes-inventaire/${id}`, { method: 'PATCH', body: corps }),
  stockRegulariserLigne: (id) =>
    request(`/api/stock/lignes-inventaire/${id}/regulariser`, { method: 'POST', body: {} }),
  stockCloturerInventaire: (id) =>
    request(`/api/stock/inventaires/${id}/cloturer`, { method: 'POST', body: {} }),

  // Cycle d'achat : fournisseur -> commande -> envoi -> confirmation -> reception -> validation.
  stockFournisseurs: () =>
    request('/api/stock_fournisseurs', { query: { itemsPerPage: 200 } }),
  stockCommandesAchat: () =>
    request('/api/stock_commande_achats', { query: { itemsPerPage: 100 } }),
  stockLignesCommandeAchat: () =>
    request('/api/stock_ligne_commande_achats', { query: { itemsPerPage: 500 } }),
  stockReceptions: () =>
    request('/api/stock_reception_achats', { query: { itemsPerPage: 100 } }),
  // Operations STANDARD : elles deserialisent, donc `ld: true`.
  //
  // `etablissement` n'est JAMAIS envoye, bien que le modele l'accepte en ecriture : le serveur le
  // tient de la session (D3/D8), et un client qui le choisit est un client qui peut ecrire chez le
  // voisin. Le respecter ici evite de prendre l'habitude inverse sur un ecran.
  creerFournisseur: (corps) =>
    request('/api/stock_fournisseurs', { method: 'POST', body: corps, ld: true }),
  majFournisseur: (id, corps) =>
    request(`/api/stock_fournisseurs/${id}`, { method: 'PATCH', body: corps }),
  creerCommandeAchat: (corps) =>
    request('/api/stock_commande_achats', { method: 'POST', body: corps, ld: true }),
  creerLigneCommandeAchat: (corps) =>
    request('/api/stock_ligne_commande_achats', { method: 'POST', body: corps, ld: true }),
  creerReceptionAchat: (corps) =>
    request('/api/stock_reception_achats', { method: 'POST', body: corps, ld: true }),
  creerLigneReceptionAchat: (corps) =>
    request('/api/stock_ligne_reception_achats', { method: 'POST', body: corps, ld: true }),
  // Operations sur mesure : `input: false`, pas de `ld: true`.
  stockEnvoyerCommande: (id) =>
    request(`/api/stock/commandes-achat/${id}/envoyer`, { method: 'POST', body: {} }),
  stockConfirmerCommande: (id) =>
    request(`/api/stock/commandes-achat/${id}/confirmer`, { method: 'POST', body: {} }),
  stockAnnulerCommande: (id) =>
    request(`/api/stock/commandes-achat/${id}/annuler`, { method: 'POST', body: {} }),
  stockValiderReception: (id) =>
    request(`/api/stock/receptions-achat/${id}/valider`, { method: 'POST', body: {} }),

  // Padel
  padelTerrains: () => request('/api/padel/terrains', { query: { itemsPerPage: 100 } }),
  padelReservations: () =>
    request('/api/padel_reservations', { query: { itemsPerPage: 200 } }),
  padelLocationsMateriel: () =>
    request('/api/padel_location_materiels', { query: { itemsPerPage: 200 } }),
  // Operations sur mesure : `input: false`, pas de `ld: true`.
  padelReserverTerrain: (id, corps) =>
    request(`/api/padel/terrains/${id}/reservations`, { method: 'POST', body: corps }),
  padelRejoindrePartie: (id, corps) =>
    request(`/api/padel/parties-ouvertes/${id}/rejoindre`, { method: 'POST', body: corps }),
  padelRetournerMateriel: (id, corps) =>
    request(`/api/padel/locations/${id}/retour`, { method: 'POST', body: corps }),
  // `padel.acces_forcer` : passer outre l'automatisme d'eclairage. Motif obligatoire.
  padelEclairageManuel: (id, corps) =>
    request(`/api/padel/terrains/${id}/eclairage/repli-manuel`, { method: 'POST', body: corps }),
  // Pas de collection listable pour les tournois (seulement des routes custom
  // /api/padel/tournois/{id}/...) : on renvoie un état vide propre.
  padelTournois: () => Promise.resolve({ 'hydra:member': [] }),
  // Musée
  museeExpositions: () => request('/api/musee_expositions', { query: { itemsPerPage: 100 } }),
  museeVisitesGuidees: () =>
    request('/api/musee_visite_guidees', { query: { itemsPerPage: 100 } }),
  museeSalles: () => request('/api/musee_salles', { query: { itemsPerPage: 100 } }),
  museeGuides: () => request('/api/musee_guides', { query: { itemsPerPage: 100 } }),
  museeContingentsGratuite: () =>
    request('/api/musee_contingent_gratuites', { query: { itemsPerPage: 50 } }),
  museeDossiersGroupe: () =>
    request('/api/musee_dossier_groupe_scolaires', { query: { itemsPerPage: 100 } }),
  // L'etat d'une salle se lit salle par salle : il n'existe pas de vue d'ensemble cote serveur.
  museeEtatSalle: (id) => request(`/api/musee/salles/${id}/etat`),
  // Operations sur mesure : `input: false`, pas de `ld: true`.
  museeCreerVisite: (corps) =>
    request('/api/musee/visites-guidees', { method: 'POST', body: corps }),
  museeConfirmerVisite: (id) =>
    request(`/api/musee/visites-guidees/${id}/confirmer`, { method: 'POST', body: {} }),
  museeCreerDossierGroupe: (corps) =>
    request('/api/musee/dossiers-groupe', { method: 'POST', body: corps }),
  museeConfirmerDossierGroupe: (id, corps) =>
    request(`/api/musee/dossiers-groupe/${id}/confirmer`, { method: 'POST', body: corps }),

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

  // --- App\Support — ASSISTANCE ------------------------------------------------------------------
  //
  // Onze opérations de tickets et sept d'articles d'aide, **et pas un seul appelant** jusqu'au 27/08.
  // Le module était complet côté serveur — statuts, escalade N1→N2, réaffectation, notes internes,
  // base de connaissances versionnée et publiable — et l'entrée de menu portait `absent: true`.
  //
  // C'est la forme la plus coûteuse de travail perdu du dépôt : `CARTE-MODULES.md` compte **815
  // opérations sans porte** contre 234 atteignables. Ce qui manque n'est presque jamais la règle
  // métier ; c'est le chemin qui y mène.
  //
  // ⚠ Les actions sont des POST à `input: false` : API Platform ne désérialise pas le corps, et c'est
  // le processeur qui le lit directement dans la requête. Le corps part donc tel quel, sans IRI.
  supportTickets: (params) => request('/api/support/tickets', { query: { itemsPerPage: 100, ...(params || {}) } }),
  supportTicket: (id) => request(`/api/support/tickets/${id}`),
  ouvrirTicket: (corps) => request('/api/support/tickets', { method: 'POST', body: corps, ld: true }),
  prendreEnChargeTicket: (id) => request(`/api/support/tickets/${id}/prendre-en-charge`, { method: 'POST', body: {} }),
  changerStatutTicket: (id, statut, motifFermeture) =>
    request(`/api/support/tickets/${id}/statut`, {
      method: 'POST',
      body: { statut, ...(motifFermeture ? { motifFermeture } : {}) },
    }),
  rouvrirTicket: (id) => request(`/api/support/tickets/${id}/rouvrir`, { method: 'POST', body: {} }),
  escaladerTicket: (id, affecteA) =>
    request(`/api/support/tickets/${id}/escalader`, { method: 'POST', body: affecteA ? { affecteA } : {} }),
  reaffecterTicket: (id, affecteA) =>
    request(`/api/support/tickets/${id}/reaffecter`, { method: 'POST', body: { affecteA } }),
  lierArticleTicket: (id, articleId) =>
    request(`/api/support/tickets/${id}/lier-article`, { method: 'POST', body: { articleId } }),

  messagesTicket: (ticketId) => request(`/api/support/tickets/${ticketId}/messages`, { query: { itemsPerPage: 200 } }),
  repondreTicket: (ticketId, contenu, noteInterne = false) =>
    request(`/api/support/tickets/${ticketId}/messages`, {
      method: 'POST',
      body: { contenu, noteInterne },
      ld: true,
    }),

  // --- App\Autorisation — PLAFONDS ET ESCALADES ---------------------------------------------------
  //
  // Treize opérations, aucun appelant jusqu'au 27/08. Un module d'autorisation sans écran n'est pas
  // une fonctionnalité en attente : c'est un mécanisme qui refuse et que personne ne peut débloquer.
  // La demande d'escalade d'un caissier partait, n'arrivait nulle part, et expirait.
  demandesEscalade: (params) =>
    request('/api/demande_escalades', { query: { itemsPerPage: 100, ...(params || {}) } }),
  approuverEscalade: (id) => request(`/api/demandes-escalade/${id}/approuver`, { method: 'POST', body: {} }),
  rejeterEscalade: (id, motif) =>
    request(`/api/demandes-escalade/${id}/rejeter`, { method: 'POST', body: { motif } }),
  limitesAutorisation: () => request('/api/limite_autorisations', { query: { itemsPerPage: 200 } }),
  operationsSensibles: () => request('/api/operation_sensibles', { query: { itemsPerPage: 200 } }),

  // --- App\Legal — MENTIONS OBLIGATOIRES ---------------------------------------------------------
  //
  // Une fiche saisie une fois, six documents composes a partir d'elle. Le champ qui decide de tout
  // est `activities` : le droit de retractation n'est pas le meme pour un billet date (aucune
  // retractation) et pour une marchandise (quatorze jours, formulaire type obligatoire).
  identitesLegales: () => request('/api/legal_identities', { query: { itemsPerPage: 20 } }),
  creerIdentiteLegale: (corps) => request('/api/legal_identities', { method: 'POST', body: corps, ld: true }),
  majIdentiteLegale: (id, corps) => request(`/api/legal_identities/${id}`, { method: 'PATCH', body: corps }),
  genererDocumentsLegaux: (id, nomSite) =>
    request(`/api/legal/identites/${id}/generer`, { method: 'POST', body: nomSite ? { nomSite } : {} }),

  documentsLegaux: () => request('/api/legal_documents', { query: { itemsPerPage: 50 } }),
  majDocumentLegal: (id, corps) => request(`/api/legal_documents/${id}`, { method: 'PATCH', body: corps }),
  publierDocumentLegal: (id) => request(`/api/legal/documents/${id}/publier`, { method: 'POST', body: {} }),

  // Lecture PUBLIQUE, sans jeton : la LCEN exige des mentions << aisement accessibles >>, et le
  // visiteur qui hesite a acheter est justement celui qui n'a pas encore de compte.

  // Base de connaissances. `/publics` et `/recherche` sont en accès public : ce sont elles que la
  // webapp cliente interroge, sans jeton.
  articlesAide: (params) => request('/api/support/articles/publics', { query: { itemsPerPage: 100, ...(params || {}) } }),
  rechercheArticles: (q) => request('/api/support/articles/recherche', { query: { q, itemsPerPage: 20 } }),
  categoriesAide: () => request('/api/categorie_aides', { query: { itemsPerPage: 100 } }),

  // REDACTION DE LA BASE DE CONNAISSANCES.
  //
  // Sept operations d'ecriture existaient cote serveur -- creer, modifier, publier, archiver -- et
  // AUCUN appel ne les atteignait. L'ecran affichait donc une base de connaissances en lecture seule,
  // qui ne pouvait jamais contenir un seul article : personne n'avait de moyen d'en ecrire un.
  //
  // /!\ `articlesAideStaff` lit la collection PRIVEE et non `/support/articles/publics`. La publique
  // ne rend que les articles PUBLIES : un brouillon qu'on vient d'ecrire y serait invisible, et le
  // redacteur conclurait que l'enregistrement a echoue. Il rechercherait ensuite dans le mauvais
  // endroit un article qui existe.
  articlesAideStaff: (params) =>
    request('/api/article_aides', { query: { itemsPerPage: 200, ...(params || {}) } }),
  creerArticleAide: (corps) => request('/api/article_aides', { method: 'POST', body: corps, ld: true }),
  majArticleAide: (id, corps) => request(`/api/article_aides/${id}`, { method: 'PATCH', body: corps }),
  publierArticleAide: (id) =>
    request(`/api/support/articles/${id}/publier`, { method: 'POST', body: {} }),
  archiverArticleAide: (id) =>
    request(`/api/support/articles/${id}/archiver`, { method: 'POST', body: {} }),
}
