// Client HTTP PUBLIC de la boutique en ligne (front client final).
// Volontairement distinct de src/api/client.js (back-office staff) :
//  - PAS de JWT staff ni d'en-tête X-Etablissement (l'établissement est déduit de la vitrine côté back).
//  - Propriété du panier prouvée par l'en-tête applicatif `X-Panier-Token` (jeton rendu une seule fois
//    à l'ouverture du panier, cf. OuvrirPanierProcessor / PanierProprietaireGuard).
//  - Espace client (« Mes billets / commandes ») authentifié par un JWT client obtenu via /auth,
//    stocké sous une clé DIFFÉRENTE du token staff pour ne jamais les mélanger.
// Chemins relatifs proxifiés par Vite (/auth, /api) — même origine, pas de CORS.

/**
 * STOCKAGE LOCAL QUI NE JETTE JAMAIS — parce que la boutique est faite pour vivre en IFRAME.
 *
 * Maxime, le 27/08 : *« l'iframe il faut faire attention je pense avec les differents navigateurs,
 * il faut que ce soit nickel »*. Le piege n'est pas la mise en page, c'est le stockage.
 *
 * Dans une iframe servie depuis un autre domaine que la page qui l'heberge, Safari et Firefox
 * **cloisonnent** le stockage par site parent, et Safari le **refuse purement et simplement** dans
 * certaines configurations. `localStorage.getItem` leve alors une `SecurityError`.
 *
 * Le cout exact, si on n'y fait rien : `panierStore.getToken()` leve au premier rendu, l'application
 * ne monte pas, et **le client voit une page blanche**. Pas un message, pas un panier vide -- rien.
 *
 * Le repli en memoire garde la boutique fonctionnelle sur la duree de la visite. Ce qui se perd, c'est
 * la persistance entre deux ouvertures d'onglet -- une degradation reelle, et sans commune mesure avec
 * une page blanche.
 *
 * > **Un stockage indisponible est un cas courant, pas une panne. Ce qui casse, ce n'est pas son
 * > absence : c'est de ne pas l'avoir prevue.**
 */
const memoire = new Map()

const stockage = {
  get(cle) {
    try {
      return window.localStorage.getItem(cle)
    } catch {
      return memoire.get(cle) ?? null
    }
  },
  set(cle, valeur) {
    // On ecrit TOUJOURS en memoire, meme quand localStorage marche : si le quota explose en cours de
    // visite -- un navigateur en navigation privee le fait -- le panier reste lisible.
    memoire.set(cle, valeur)
    try {
      window.localStorage.setItem(cle, valeur)
    } catch {
      /* la memoire a deja la valeur */
    }
  },
  remove(cle) {
    memoire.delete(cle)
    try {
      window.localStorage.removeItem(cle)
    } catch {
      /* rien a faire */
    }
  },
}

const PANIER_TOKEN_KEY = 'boutique.panierToken'
const PANIER_ID_KEY = 'boutique.panierId'
const CLIENT_TOKEN_KEY = 'boutique.clientToken'
const VITRINE_KEY = 'boutique.vitrine'

export const panierStore = {
  getToken: () => stockage.get(PANIER_TOKEN_KEY),
  getId: () => stockage.get(PANIER_ID_KEY),
  set: (id, token) => {
    if (id) stockage.set(PANIER_ID_KEY, id)
    if (token) stockage.set(PANIER_TOKEN_KEY, token)
  },
  clear: () => {
    stockage.remove(PANIER_ID_KEY)
    stockage.remove(PANIER_TOKEN_KEY)
  },
}

export const clientTokenStore = {
  get: () => stockage.get(CLIENT_TOKEN_KEY),
  set: (t) => stockage.set(CLIENT_TOKEN_KEY, t),
  clear: () => stockage.remove(CLIENT_TOKEN_KEY),
}

export const vitrineStore = {
  get: () => stockage.get(VITRINE_KEY),
  set: (id) => stockage.set(VITRINE_KEY, id),
  clear: () => stockage.remove(VITRINE_KEY),
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
  // D104 : la boutique designee par l'HOTE (`piscine-ville.fluvia-app.com`). Rend 404 quand l'hote
  // n'est pas un sous-domaine client connu -- ce qui est le cas en developpement et en preprod, ou
  // l'on tombe alors sur les formes d'URL existantes.
  vitrineCourante: () => request('/api/boutique/vitrine-courante'),
  vitrine: (id) => request(`/api/boutique/vitrines/${id}`),
  catalogue: (id) => request(`/api/boutique/vitrines/${id}/catalogue`),
  // LES HORAIRES D'UN PRODUIT, DANS LE PERIMETRE DE CETTE BOUTIQUE-CI.
  //
  // ⚠ LA VITRINE N'EST PAS UN CONFORT D'AFFICHAGE, C'EST LE CLOISONNEMENT. Depuis le 07/09, un
  // produit trouve ses creneaux par les ACTIVITES qui le referencent — et deux etablissements qui
  // diffusent le meme produit ont chacun les leurs. Sans ce parametre, le serveur retombe sur tous
  // les etablissements ou le produit est diffuse : la boutique de l'un annoncerait les horaires de
  // l'autre, et un client reserverait a cent kilometres de chez lui sans que rien ne le signale.
  //
  // Elle est lue dans le magasin plutot que passee par l'appelant : `FicheProduit` recoit une
  // entree de catalogue, pas la vitrine qui l'a servie, et la lui faire descendre a travers deux
  // composants aurait cree un chemin de plus ou l'oublier.
  creneaux: (produitId) => {
    const vitrine = vitrineStore.get()
    return request(
      `/api/boutique/produits/${produitId}/creneaux${vitrine ? `?vitrine=${encodeURIComponent(vitrine)}` : ''}`,
    )
  },

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

  // Confirmer l'adresse depuis le lien recu par courriel.
  //
  // ⚠ SANS JETON D'AUTHENTIFICATION, ET C'EST LA FORME NORMALE : on clique ce lien depuis sa boite
  // mail, donc sans session. C'est le jeton du corps qui autorise, et lui seul.
  verifierEmail: (jeton) =>
    request('/api/boutique/comptes/verifier-email', { method: 'POST', body: { jeton } }),

  // --- Espace client (JWT client requis) ---
  login: (email, motDePasse) =>
    request('/auth', { method: 'POST', body: { email, motDePasse } }),
  moiCompte: () => request('/api/boutique/comptes/me', { auth: true }),
  mesCommandes: () => request('/api/boutique/comptes/me/commandes', { auth: true }),

  // Souscrire un abonnement en ligne. Corps :
  // { produit, vitrine, iban, bicDebiteur?, debiteurNom, dateSignature? }
  //
  // ⚠ CE N'EST PAS UN AJOUT AU PANIER. L'appel cree une VENTE et signe un MANDAT DE PRELEVEMENT
  // dans le meme geste : pas de tunnel, pas de paiement a l'etape suivante, rien a retirer ensuite.
  //
  // ⚠ `vitrine` DESIGNE LA BOUTIQUE OU L'ACHAT A LIEU, et pas celle ou le compte est ne. Sans lui,
  // le serveur retombe sur la vitrine de creation du compte : un client inscrit chez Piscine A qui
  // s'abonne chez Patinoire B ferait entrer l'abonnement, le mandat et l'argent DANS LES COMPTES DE
  // PISCINE A. Un compte global est une identite, pas une appartenance commerciale.
  //
  // Le serveur refuse les invites, les produits sans facette SEPA, et un `iban` ou un `debiteurNom`
  // vide. L'ecran evite les trois avant le clic plutot que d'afficher son refus apres.
  souscrireAbonnement: (corps) =>
    request('/api/boutique/abonnements/souscrire', { method: 'POST', body: corps, auth: true }),

  // Demander le remboursement d'une commande. Corps : { vente, ligne?, motif, piecesJustificatives? }
  //
  // ⚠ AUCUN REMBOURSEMENT N'EST AUTOMATIQUE : la demande est deposee, motivee, et un humain tranche
  // (RG-M3-15). Le serveur refuse une commande qui n'appartient pas au compte connecte, et exige un
  // motif non vide.
  //
  // ⚠ ET LE CLIENT NE PEUT PAS LISTER SES DEMANDES : la collection est reservee a l'exploitant
  // (`boutique.lire`), seule la lecture d'UNE demande lui est ouverte. L'ecran ne peut donc pas
  // afficher « demande en cours » apres un rechargement — il le dit plutot que de le laisser croire.
  deposerDemandeRemboursement: (corps) =>
    request('/api/boutique/demandes-remboursement', { method: 'POST', body: corps, auth: true }),
  mesBillets: () => request('/api/boutique/comptes/me/billets', { auth: true }),
}
