// Client HTTP centralisé.
// - Appelle des chemins relatifs (proxifiés par Vite vers le back), pas de CORS.
// - Ajoute automatiquement le Bearer JWT et l'en-tête X-Etablissement (établissement actif).
// - 401 => on notifie l'app pour repasser sur l'écran de connexion.

const TOKEN_KEY = 'billetterie.token'
const ETAB_KEY = 'billetterie.etablissement'

// ⚠ `sessionStorage`, PAS `localStorage` — voir `supportStore` ci-dessous. Le nom diffère du
// préfixe des deux autres clés pour qu'une inspection du stockage distingue d'un coup d'œil ce qui
// est partagé entre onglets de ce qui ne l'est pas.
const SUPPORT_KEY = 'fluvia.support.onglet'

let onUnauthorized = null
export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn
}

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (t) => localStorage.setItem(TOKEN_KEY, t),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

// ── MODE SUPPORT : LE CONTEXTE D'UN SEUL ONGLET ──────────────────────────────────────────────
//
// Un agent de l'éditeur bascule chez un client ; Maxime a choisi que cela ouvre un NOUVEL ONGLET,
// pour pouvoir répondre au ticket dans le premier pendant qu'on regarde chez le client dans le
// second.
//
// ⚠ ET C'EST EXACTEMENT LÀ QUE `localStorage` PIÈGE : il est PARTAGÉ entre tous les onglets d'une
// même origine. Poser l'établissement du client dans `localStorage` aurait changé l'établissement
// actif de l'onglet principal — l'agent y serait revenu, se serait retrouvé chez le client sans
// l'avoir demandé, sans bandeau, sans rien qui le dise. Il aurait encaissé chez quelqu'un d'autre.
//
// `sessionStorage` est PAR ONGLET. C'est la seule propriété qui rend le choix de Maxime tenable.
//
// On y garde le nom en plus de l'identifiant : le bandeau doit pouvoir se dessiner AVANT que la
// liste des établissements soit revenue du serveur. Un bandeau qui apparaît une seconde après le
// reste de l'écran est un bandeau qu'on peut ne pas voir.
export const supportStore = {
  get: () => {
    try {
      const brut = sessionStorage.getItem(SUPPORT_KEY)
      return brut ? JSON.parse(brut) : null
    } catch {
      // Onglet privé, stockage refusé, JSON corrompu : pas de mode support, et l'onglet se comporte
      // comme un onglet ordinaire. Le repli d'un mécanisme d'accès est de ne pas ouvrir.
      return null
    }
  },
  set: (contexte) => {
    try {
      sessionStorage.setItem(SUPPORT_KEY, JSON.stringify(contexte))
    } catch {
      // Ignoré volontairement : voir ci-dessus.
    }
  },
  clear: () => {
    try {
      sessionStorage.removeItem(SUPPORT_KEY)
    } catch {
      // Ignoré volontairement : voir ci-dessus.
    }
  },
}

export const etablissementStore = {
  // ⚠ LE CONTEXTE DE SUPPORT L'EMPORTE, et c'est ce qui rend le mode support automatique : toute
  // requête de cet onglet part avec l'établissement du client, sans qu'aucun écran n'ait à le
  // savoir ni à être modifié.
  get: () => supportStore.get()?.etablissementId || localStorage.getItem(ETAB_KEY),

  // ⚠ `set` ET `clear` NE TOUCHENT PAS AU CONTEXTE DE SUPPORT, DÉLIBÉRÉMENT. Un onglet de support
  // est épinglé sur son client, et on en sort par le bandeau — un geste explicite. Si le sélecteur
  // d'établissement pouvait écrire ici, l'onglet dériverait hors du client pendant que le bandeau
  // continuerait de le nommer : l'écran dirait une chose, l'en-tête une autre.
  set: (id) => localStorage.setItem(ETAB_KEY, id),
  clear: () => localStorage.removeItem(ETAB_KEY),
}

// Extrait un message d'erreur lisible d'une réponse API (auth, API Platform, opérations custom).
//
// ── UN REFUS DE DROIT NE DOIT PAS RESSEMBLER À UNE PANNE ─────────────────────────────────────
//
// Sur un refus d'autorisation, API Platform rend `detail: "Access Denied."`. Affiché tel quel, en
// anglais, dans un bandeau rouge, ce texte se lit comme une erreur technique : l'exploitant
// appelle au support et cherche une panne, alors qu'il lui manque une permission.
//
// `allaccess-8e` a relevé que QUATORZE écrans affichent le message brut du serveur sans traiter
// le 403 — ils n'utilisent pas `components/Liste.jsx`, qui, lui, l'explique depuis toujours.
// Corriger quatorze écrans aurait produit quatorze phrases légèrement différentes ; la traduction
// se fait donc ICI, au seul endroit où le message est fabriqué, et les quatorze en profitent
// d'un coup. La formulation reprend celle de `Liste.jsx` pour que l'application dise la même
// chose au même moment.
//
// ⚠ ON NE REMPLACE QUE LE MESSAGE GÉNÉRIQUE. Certains 403 portent une raison précise — un motif
// métier, une règle nommée — et l'écraser par une phrase générale ferait perdre l'information la
// plus utile. On ne traduit donc que « Access Denied », pas ce que le serveur a pris la peine
// d'écrire.
function messageFromPayload(payload, status) {
  if (status === 403) {
    const brut = payload?.detail || payload?.message || payload?.['hydra:description'] || ''
    if (!brut || /access denied/i.test(brut)) {
      return 'Accès non autorisé pour ce compte sur cet établissement (droits insuffisants). '
        + "Ce n'est pas une panne : demandez la permission à un administrateur, ou vérifiez que "
        + "vous êtes sur le bon établissement."
    }
    return brut
  }
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
// `itemsPerPage` EST HONORÉ DEPUIS LE 29/08. CE COMMENTAIRE A DIT LE CONTRAIRE PENDANT UN JOUR,
// ET C'EST CE QUI REND L'HISTOIRE UTILE.
//
// Ce qui était écrit ici le 28/08, mesuré et exact ce jour-là : `api_platform.yaml` ne déclarait
// aucun bloc `pagination`, donc 30 éléments par page et `pagination_client_items_per_page` à
// `false` — le paramètre ne faisait rien. La preuve citée était
// `GET /api/mandat_sepas?itemsPerPage=1 → rend les 3 lignes`.
//
// La même mesure, refaite le 29/08 : **elle rend 1 ligne.** La configuration déclare désormais
//     pagination_items_per_page: 100      (le défaut, quand l'écran ne demande rien)
//     pagination_client_items_per_page: true
//     pagination_maximum_items_per_page: 500
//
// LA LEÇON N'EST PAS « LE PLAFOND A CHANGÉ », C'EST QU'UNE MESURE PORTE UNE DATE. Celle-ci était
// juste, datée, citée avec sa commande — et c'est précisément ce qui l'a rendue crédible pendant
// vingt-quatre heures de trop. Dix-neuf endroits du frontal la répétaient, dont six bandeaux
// affichés à l'exploitant : ils lui annonçaient des données manquantes qui ne manquaient plus.
//
// CE QUI RESTE VRAI, ET QUI EST LA SEULE CHOSE À RETENIR : un plafond, quel qu'il soit, tronque en
// SILENCE. 100 par défaut, 500 au maximum — une collection plus longue arrive coupée avec un
// code 200 et la même forme de réponse. `totalItems` porte le total réel : le comparer au nombre
// de lignes reçues est la seule façon de transformer « il en manque » en « il en manque, et voici
// combien ». C'est ce que fait `components/Liste.jsx`, et c'est ce qu'un écran doit faire plutôt
// que d'annoncer un nombre appris par cœur.
//
// EN ATTENDANT, C'EST L'AFFICHAGE QUI PORTE L'AVERTISSEMENT. La réponse Hydra contient `totalItems`
// — le total réel, pas la taille de la page — donc une réponse tronquée est reconnaissable.
// `components/Liste.jsx` affiche « 30 sur 47 » à côté du titre, et les trois écrans qui RECOUPENT
// deux listes (SEPA, cautions, recouvrement) le signalent plus fort : chez eux, une liste coupée ne
// rend pas l'écran incomplet, elle le rend faux.
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
    // API Platform impose `application/merge-patch+json` sur les PATCH (sinon 415).
    //
    // ⚠ POUR LE RESTE, « JSON SIMPLE » NE PASSE NULLE PART PAR TOLÉRANCE.
    //
    // Ce commentaire a longtemps dit que les autres écritures « acceptent JSON simple ». C'est
    // faux, et la croyance a coûté sept boutons morts. En interrogeant le registre d'API Platform :
    // sur 540 opérations d'écriture, 296 n'acceptent QUE `application/ld+json`, et les 244 autres
    // ne contrôlent pas le type du tout parce qu'elles ne désérialisent pas.
    //
    // Autrement dit : `application/json` ne réussit jamais parce qu'il est accepté, il réussit là
    // où personne ne regarde. Dès que l'opération lit le corps, il faut `ld: true` — c'est ce que
    // `frontend/scripts/verifier-formats.mjs` vérifie désormais, route par route.
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

  // ⚠ DEUX SESSIONS ONT ÉCRIT CE MÊME BLOC INDÉPENDAMMENT, à une heure d'intervalle. Les deux
  // versions étaient justes et disaient la même chose ; celle-ci a été retenue à la fusion. Le
  // doublon n'a coûté qu'un conflit — il aurait pu coûter deux mécanismes concurrents dans la même
  // fonction, dont un seul aurait servi.
  // LE JETON SE RENOUVELLE PENDANT QU'ON TRAVAILLE, ET CA SE LIT ICI PARCE QU'ICI VOIT TOUT.
  //
  // Le serveur renvoie un jeton frais dans `X-JETON-RENOUVELE` des que le jeton courant a passe la
  // moitie de sa vie. L'en-tete N'EST PAS sur toutes les reponses : son absence est le cas normal,
  // pas une anomalie.
  //
  // Un seul endroit a modifier, et c'est deliberement celui-la : `request()` est la seule fonction
  // qui voit toutes les reponses. Le poser ecran par ecran donnerait des ecrans qui prolongent la
  // session et d'autres non, sans que rien ne distingue les deux.
  //
  // ⚠ CE MECANISME PEUT ETRE INERTE SANS QUE RIEN NE LE DISE. Lire l'en-tete et oublier de remplacer
  // le jeton stocke marche exactement comme avant pendant une heure, puis ejecte -- et rien ne
  // signale qu'il n'a jamais servi. Eprouve en comparant le jeton stocke AVANT et APRES une reponse
  // qui porte l'en-tete, pas en constatant qu'on est encore connecte dix minutes plus tard.
  //
  // ET IL NE SUPPRIME PAS L'EXPIRATION. Passe douze heures depuis la premiere connexion, le serveur
  // cesse de reemettre : une session qui se prolonge sans fin n'est plus une session, c'est un mot
  // de passe. L'ecran de connexion doit donc toujours savoir apparaitre.
  const renouvele = res.headers.get('X-JETON-RENOUVELE')
  if (renouvele && auth) tokenStore.set(renouvele)

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
      // UNE RÉPONSE QUI N'EST PAS DU JSON NE SE RECOPIE PAS TELLE QUELLE DANS L'ÉCRAN.
      //
      // `{ message: text }` mettait le corps BRUT dans le message d'erreur. Or toutes les erreurs
      // ne viennent pas d'API Platform : un 405 sorti du routeur, un 502 de nginx, une page de
      // maintenance rendent du HTML. Constaté le 28/08 sur l'écran Padel — la page affichait
      // « <!DOCTYPE html> <html lang="en"> <head>… » dans son bandeau d'erreur, sur toute la
      // largeur, à la place du message.
      //
      // Le corps reste dans `payload` pour qui débogue ; ce qui remonte à l'utilisateur est une
      // phrase. Un message illisible ne dit pas seulement « erreur » : il fait croire à un bug de
      // l'affichage plutôt qu'à un appel qui a échoué, et on cherche au mauvais endroit.
      const ressembleAduHtml = /^\s*<(?:!doctype|html|\?xml)/i.test(text)
      payload = {
        message: ressembleAduHtml
          ? `Le serveur a répondu une page (${res.status}) au lieu de données. `
            + "L'appel n'a probablement pas atteint l'API."
          : text.slice(0, 300),
        corpsBrut: text,
      }
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
  // `Produit` declare un SearchFilter sur `code` (partiel), `libelleRecherche` (partiel), `statut`
  // et `typeCode` -- quatre filtres testes cote serveur, et cette fonction n'en transmettait aucun :
  // elle ne prenait meme pas d'argument. Le catalogue chargeait donc les trente premiers produits et
  // n'offrait aucun moyen d'atteindre les suivants.
  //
  // Le piege qu'on evite en le corrigeant tout de suite : passer un objet a une fonction qui l'ignore
  // ne leve rien. On aurait vu des champs de filtre a l'ecran, une requete partir, une reponse
  // arriver -- et la meme liste. Un resultat plausible et faux.
  produits: (params) => request('/api/produits', { query: params }),
  // Le détail ajoute le groupe `produit:compta` (compte, TVA, règle PCA), absent de la collection.
  produit: (id) => request(`/api/produits/${id}`),
  // LES PROMOTIONS — cinq routes servies, aucune appelee jusqu'ici.
  //
  // ⚠ `eligibilite.produits` VIDE = LA PROMOTION NE S'APPLIQUE A AUCUN PRODUIT. Pas a tous.
  // `PriceQuoter` rend `false` des que la liste est vide ou absente, et le code le souligne parce
  // que quelqu'un a failli le « corriger ». Les `canaux`, eux, suivent la regle INVERSE : vides,
  // ils valent tous. Deux tableaux voisins qui se lisent a l'envers l'un de l'autre.
  promotions: () => request('/api/promotions', { query: { itemsPerPage: 200 } }),
  creerPromotion: (corps) => request('/api/promotions', { method: 'POST', body: corps, ld: true }),
  majPromotion: (id, corps) => request(`/api/promotions/${id}`, { method: 'PATCH', body: corps }),
  supprimerPromotion: (id) => request(`/api/promotions/${id}`, { method: 'DELETE' }),
  // DUPLIQUER — la route existe depuis l'origine, aucune fonction cliente ne l'appelait.
  //
  // La copie reprend type, grilles et categories, regenere un code unique, nait au statut
  // BROUILLON quel que soit l'original, et son libelle est suffixe « – copie ». Elle est donc
  // faite pour etre ouverte et modifiee tout de suite : l'appelant atterrit dessus.
  //
  // ⚠ Pas de `ld: true` : l'operation porte `input: false`, elle ne deserialise pas. Le drapeau y
  // serait inerte, et l'ecrire ferait croire que l'appel est verifie alors qu'il ne l'est pas.
  dupliquerProduit: (id) =>
    request(`/api/produits/${id}/dupliquer`, { method: 'POST', body: {} }),

  // Controler un billet SANS materiel : ni equipement, ni espace, ni porte. C'est l'outil des sites
  // sans tourniquet, ou un billet vendu est aujourd'hui invendable en pratique faute de pouvoir le
  // controler a l'entree.
  controlerBillet: (identifiantSupport) =>
    request('/api/acces/controle-billet', { method: 'POST', body: { identifiantSupport } }),

  // Les complements d'un produit — « le casier avec l'entree ». Ce lien remplace `produitsAssocies`,
  // qui etait un ManyToMany sans `remove` que rien ne lisait cote serveur : l'ecran y ecrivait dans
  // le vide, et l'enregistrement reussissait.
  complementsDeProduit: (produitId) =>
    request('/api/complementary_products', {
      query: { product: `/api/produits/${produitId}`, itemsPerPage: 100 },
    }),
  ajouterComplement: (produitId, complementId, mode, quantite) =>
    // ⚠ method sur la MEME ligne que request( : la mesure d ecart detecte la methode sur le
    // reste de la ligne de l appel. Ecrite en dessous, elle retombait sur le defaut GET et se
    // confondait avec le GET du meme chemin. Corrige par allaccess-73, pas encore integre.
    request('/api/complementary_products', { method: 'POST', ld: true,
      body: {
        product: `/api/produits/${produitId}`,
        complement: `/api/produits/${complementId}`,
        mode,
        defaultQuantity: quantite,
      },
    }),
  retirerComplement: (id) => request(`/api/complementary_products/${id}`, { method: 'DELETE' }),
  majProduit: (id, corps) => request(`/api/produits/${id}`, { method: 'PATCH', body: corps }),
  // L'ONGLET COMPTA D'UN PRODUIT : TROIS CHAMPS ECRIVABLES, AFFICHES ET JAMAIS PROPOSES.
  //
  // `PATCH /produits/{id}/compta` existe depuis le debut, avec son propre groupe (`produit:compta`)
  // et son propre droit (`offre.modifier_compta`, distinct de `offre.modifier`). La fiche produit
  // montrait compte, taux et regle PCA ; le formulaire << Modifier >> n'offrait que le nom, les
  // canaux, la couleur en caisse et la note interne.
  //
  // Route sur mesure et `input: false` cote serveur : pas de `ld: true` a poser.
  majComptaProduit: (id, corps) =>
    request(`/api/produits/${id}/compta`, { method: 'PATCH', body: corps }),
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

  // LES FACTURES : DIX OPERATIONS EXPOSEES, ZERO ROUTE DANS CE FICHIER.
  //
  // L'ecran << Facturation >> ne montrait pas des factures : il montrait des PIECES COMMERCIALES et
  // enseignait une chaine devis -> commande -> livraison -> facture. Sa seule action etait
  // << + Nouveau devis >>. Une facture emise sortait de l'ecran et n'etait plus visible NULLE PART :
  // il n'existait aucune liste des factures, donc aucun moyen de savoir qui doit combien.
  //
  // C'est une chaine d'ERP imposee a des gens qui n'en ont pas besoin : une piscine facture une ecole
  // pour une sortie de groupe, un club de padel facture une entreprise pour un tournoi. Ni devis, ni
  // bon de livraison.
  //
  // QUATRE DROITS DISTINCTS, DONC QUATRE BOUTONS : emettre (`facturation.emettre_directe`), lettrer
  // (`facturation.lettrer`), avoir (`facturation.avoir`), Chorus (`facturation.deposer_chorus`). Qui
  // encaisse un reglement n'a pas a pouvoir annuler la facture par un avoir.
  //
  // Toutes ces routes portent un `uriTemplate` sur mesure et `input: false` : pas de `ld: true`.
  factures: (params) => request('/api/factures', { query: params }),
  facture: (id) => request(`/api/factures/${id}`),
  // LE DOCUMENT LEGAL LUI-MEME. `FactureRenduProvider` existait depuis le debut et n'etait appele
  // par AUCUN ecran : Fluvia savait creer, numeroter, sceller, emettre, encaisser et deposer sur
  // Chorus une facture -- mais pas la DONNER a celui qui doit la payer.
  //
  // Le rendu porte les montants FIGES (par ligne, par taux, et les trois totaux). L'ecran les
  // affiche verbatim et n'additionne rien : recalculer depuis `tauxTva`, qui est lu vivant sur la
  // fiche du taux, fabriquerait un troisieme chiffre.
  renduFacture: (id) => request(`/api/factures/${id}/rendu`),
  // Cree un BROUILLON : aucun numero n'est consomme tant qu'on n'a pas emis (RG-FACT-01). C'est ce
  // qui permet de se tromper sans trouer la sequence legale des numeros.
  creerFactureDirecte: (corps) => request('/api/factures', { method: 'POST', body: corps }),
  majFactureDirecte: (id, corps) => request(`/api/factures/${id}`, { method: 'PATCH', body: corps }),
  emettreFacture: (id) => request(`/api/factures/${id}/emettre`, { method: 'POST', body: {} }),
  // Corps : { montant: "150.00", moyen: "virement", reference?: "..." }.
  enregistrerReglement: (id, corps) =>
    request(`/api/factures/${id}/reglements`, { method: 'POST', body: corps }),
  // Avoir TOTAL, sans corps : la simplification est assumee cote serveur (plan §7).
  genererAvoirFacture: (id) => request(`/api/factures/${id}/avoir`, { method: 'POST', body: {} }),
  // Corps : { numeroEngagement?, serviceExecutant? } -- exiges par certains donneurs d'ordre publics.
  deposerFactureChorus: (id, corps) =>
    request(`/api/factures/${id}/chorus`, { method: 'POST', body: corps }),
  // INTEGRITE DE LA CHAINE DES FACTURES (NF525).
  //
  // ⚠ CE COMMENTAIRE A TENU LE BOUTON DEBRANCHE DEUX JOURS APRES LA CORRECTION DU DEFAUT QU'IL
  // DECRIT. Il disait, et c'etait vrai le 29/08 :
  //
  //     « `/factures/verifier-chaine` REPOND 404. Declaree en `GetCollection` avec ce
  //       `uriTemplate`, elle est captee par l'operation d'item `/factures/{id}` qui lit
  //       "verifier-chaine" comme un identifiant. […] Le bouton etait ecrit ; je l'ai retire
  //       plutot que d'en livrer un qui echoue. Signale au serveur. »
  //
  // Il a ete signale, il a ete corrige, et personne n'est revenu rebrancher le bouton. La phrase
  // est restee juste dans sa date et fausse dans le present -- et rien ne reliait les deux. On
  // garde la date et la raison, on retire la conclusion. Signale par `allaccess-b8`.
  //
  // Remesure le 31/08, authentifie, avec DEUX TEMOINS NEGATIFS dans la meme passe :
  //     /api/factures/verifier-chaine                    200   { intacte, nbDocuments, anomalies }
  //     /api/factures/00000000-0000-4000-8000-0000…      404   temoin : identifiant inconnu
  //     /api/factures/route-inexistante                  404   temoin : rien ici
  // Le routeur declare desormais la route litterale AVANT le motif `{id}` : la collision n'a
  // plus lieu.
  //
  // ⚠ Sans les temoins, un 200 ne prouverait rien : `Accept` mal negocie rend 406 sur TOUT, y
  // compris sur les routes qui marchent. C'est le piege qui a failli tromper b8.
  verifierChaineFactures: () => request('/api/factures/verifier-chaine'),
  //
  // L'AUTRE ROUTE RESTE DEBRANCHEE, ET POUR UNE RAISON QUI N'A PAS CHANGE :
  //
  // `/factures/depuis-vente` fonctionne, mais son geste appartient a l'historique des ventes -- on
  // emet une facture justificative EN REGARDANT une vente, pas en regardant la liste des factures.
  // La brancher ici aurait demande de ressaisir la vente, c'est-a-dire exactement ce que la facture
  // justificative existe pour eviter.

  pointDeVentes: () => request('/api/point_de_ventes'),
  creerPointDeVente: (corps) => request('/api/point_de_ventes', { method: 'POST', body: corps, ld: true }),
  majPointDeVente: (id, corps) => request(`/api/point_de_ventes/${id}`, { method: 'PATCH', body: corps }),
  caisses: () => request('/api/caisses'),
  // UNE CAISSE NE POUVAIT PAS ETRE CREEE, ET C'EST CE QUI BLOQUAIT LA VENTE.
  //
  // `POST /api/caisses` existe (droit `caisse.gerer`) et n'etait appele de nulle part. Consequence
  // observee sur GI-ONE FITNESS : le formulaire d'ouverture de caisse propose << Aucune caisse >>
  // comme unique option, sans valeur, avec le bouton actif -- puis refuse avec << Point de vente et
  // caisse requis >> alors que le point de vente EST choisi. Il reproche deux champs quand un seul
  // manque, et celui-la etait impossible a remplir depuis l'application.
  //
  // Operation API Platform standard (pas d'`uriTemplate`) : elle deserialise, donc `ld: true`.
  creerCaisse: (corps) => request('/api/caisses', { method: 'POST', body: corps, ld: true }),
  majCaisse: (id, corps) => request(`/api/caisses/${id}`, { method: 'PATCH', body: corps }),
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
  // ⚠ LE CORPS N'EST PLUS VIDE, ET IL NE L'AURAIT JAMAIS DU ETRE.
  //
  // `ValiderVenteProcessor` lit `supports` depuis toujours, et `ValiderVenteService` en tire deux
  // comportements distincts (RG-CQ1-01) :
  //
  //   identifiant inedit    -> emission d'un support neuf portant cet identifiant
  //   identifiant deja connu -> RECHARGE de la carte existante, au lieu d'une emission
  //
  // Le second est le geste le plus courant d'un exploitant qui vend des cartes, et il etait
  // inatteignable : cet appel envoyait `{}`. La contrainte de quantite (RG-CQ8-02 : un identifiant
  // explicite impose une quantite de 1) est appliquee par l'ECRAN avant d'appeler, pour que le
  // caissier voie pourquoi une ligne n'est pas appairable au lieu de se prendre un 422.
  valider: (venteId, supports = null) =>
    request(`/api/ventes/${venteId}/valider`, {
      method: 'POST',
      body: supports?.length ? { supports } : {},
      timeoutMs: 30000,
    }),
  ticket: (venteId, mode = 'imprimer') =>
    request(`/api/ventes/${venteId}/ticket`, { method: 'POST', body: { mode } }),

  // CRM.
  rechercheClients: (params) => request('/api/crm/clients/recherche', { query: params }),
  ficheClient: (id) => request(`/api/clients/${id}/fiche-360`),

  // ⚠ TROIS PIEGES DE ROUTAGE SUR LA FUSION, MESURES LE 31/08 CONTRE LA PREPROD. Ils sont
  // consignes ici parce que DEUX sessions ont ecrit cet ecran le meme jour sans se voir, et que
  // la seconde n'avait pas ces mesures.
  //
  //   1. LA COLLECTION N'EST PAS SOUS `/crm/fusions`. Le `POST` occupe ce chemin ; le
  //      `GetCollection` n'a pas d'`uriTemplate` et vit donc sous le nom derive de l'entite.
  //          GET /api/crm/fusions      -> 405 Method Not Allowed   (et non 404)
  //          GET /api/journal_fusions  -> 200
  //      Un 405 se lit << mauvaise methode >>, pas << mauvais chemin >>.
  //
  //   2. LA PREVISUALISATION PREND DES UUID, LA FUSION PREND DES IRI. Le provider lit
  //      `?maitre=<uuid>&sources[]=<uuid>` ; le processeur lit `{ maitre: iri, sources: [iri] }`.
  //
  //   3. LES DEUX `POST` SONT EN `input: false` : corps BRUT, pas de `ld: true`.
  // La fiche 360 ne porte qu'un sous-ensemble des champs : pour modifier, il faut le client entier.
  client: (id) => request(`/api/clients/${id}`),
  majClient: (id, corps) => request(`/api/clients/${id}`, { method: 'PATCH', body: corps }),
  // Relevé de mouvements du porte-monnaie virtuel (US-L5-04). Renvoie { mouvements: [...] }.
  // LES CONSENTEMENTS — les deux moities, lecture et ecriture.
  //
  // ⚠ `SearchFilter` sur `client` est DECLARE en `exact` (verifie dans l'entite) : `?client=<IRI>`
  // filtre vraiment. Sans cette verification, un filtre non declare serait accepte et IGNORE, et
  // la fiche afficherait les consentements du voisin sans qu'aucune erreur ne le dise.
  // ⚠ CETTE ROUTE S'APPELLE « rattacher » ET NE RATTACHE RIEN. Le processeur VERIFIE qu'un numero
  // de support appartient bien au client, et rend une erreur sinon — aucune ecriture. Le nom de la
  // fonction cliente dit ce qu'elle FAIT : c'est l'appelant qu'il ne faut pas tromper.
  //
  // 200 = c'est bien sa carte · 404 = carte inconnue · 422 = carte d'un AUTRE client.
  verifierCarteClient: (idClient, identifiant) =>
    request(`/api/clients/${idClient}/rattacher-support`, { method: 'POST', body: { identifiant } }),
  consentementsClient: (idClient) =>
    request('/api/consentements', {
      query: {
        client: `/api/clients/${idClient}`,
        itemsPerPage: 100,
        'order[dateRecueil]': 'desc',
      },
    }),
  // ⚠ `canal` ET `etat` SONT REQUIS (422 sinon), et un consentement ACCORDE pour un MINEUR exige
  // `recueilliParRepresentant` — RG-M4-10. L'ecran porte la regle ; ce commentaire dit pourquoi
  // elle n'est pas une coquetterie d'interface.
  enregistrerConsentement: (idClient, corps) =>
    request(`/api/clients/${idClient}/consentements`, { method: 'POST', body: corps }),
  pmvMouvements: (id) => request(`/api/clients/${id}/pmv/mouvements`),
  // RECHARGER UN PORTE-MONNAIE — le geste qui manquait pour que le solde puisse remonter.
  //
  // `Caisse.jsx` accepte `pmv` comme moyen de paiement depuis toujours ; le seul appel PMV du
  // frontal etait la lecture des mouvements. Un client qui verse 50 EUR au comptoir n'avait aucun
  // chemin : les soldes se vidaient sans jamais remonter.
  //
  // ⚠ CONTRAT MESURE CONTRE L'API, PAS SUPPOSE -- et paye : voir le message de commit.
  //   `montant` est REQUIS et doit etre STRICTEMENT POSITIF (0 et -10 rendent 422).
  //   La reponse porte { mouvement, montant, soldeApres, dateEcheance, statutPmv, motif }.
  //   ⚠ DEUX EFFETS, PAS UN : l'operation credite le solde ET REPOUSSE L'ECHEANCE du
  //   porte-monnaie. L'ecran doit donc reafficher les deux, sinon il montre une moitie de verite.
  //   Le mouvement cree porte `canal: "caisse"` : c'est un encaissement, pas un ajustement.
  rechargerPmv: (id, corps) =>
    request(`/api/clients/${id}/pmv/recharger`, { method: 'POST', body: corps, ld: true }),
  // Création rapide d'une fiche client (US-L5-02). L'établissement de création / le groupe sont
  // fixés côté back depuis l'établissement actif (en-tête X-Etablissement).
  creerClient: (corps) => request('/api/clients', { method: 'POST', body: corps, ld: true }),

  // RGPD — le droit a l'effacement (RG-M4-08/09). Trois routes qui existaient depuis le debut et
  // qu'aucun ecran n'appelait : une obligation legale sans aucun chemin dans le produit.
  //
  // `traiter` porte un `uriTemplate` sur mesure et `input: false` cote serveur : pas de corps du
  // tout, et donc pas de `ld: true` -- seul l'identifiant de la route compte. La creation, elle,
  // est une operation API Platform standard : elle deserialise, d'ou `ld: true` et l'IRI du client.
  demandesRgpd: (params) => request('/api/demande_rgpds', { query: params }),
  creerDemandeRgpd: (corps) =>
    request('/api/demande_rgpds', { method: 'POST', body: corps, ld: true }),
  traiterDemandeRgpd: (id) => request('/api/demandes-rgpd/' + id + '/traiter', { method: 'POST' }),

  // CONTACTS D'UN CLIENT PROFESSIONNEL. `Beneficiaire` porte une semantique de FAMILLE
  // (payeur, beneficiaire) : elle ne sait pas dire << directrice >> ni << comptabilite >>.
  //
  // LE FILTRE SERVEUR EST REVENU, ET LE TRI LOCAL EST PARTI AVEC.
  //
  // Ces trois lectures chargeaient la collection entiere et triaient dans le navigateur, parce que
  // le `SearchFilter` sur un identifiant Uuid rendait soit tout, soit rien, sans jamais lever
  // (famille D58). Le 29/08, un decorateur de plateforme a repare les 145 proprietes concernees.
  //
  // Mesure faite avant de retirer, sur des donnees fabriquees pour l'occasion puis effacees :
  //     2 contacts sur 2 clients differents
  //     ?customer=<IRI du client 1>  -> 1     le filtre discrimine
  //     ?customer=nimportequoi       -> 0     et il se ferme sur une valeur illisible
  //
  // ⚠ L'IRI EST CONSTRUIT SANS GARDE, ET C'EST VOULU. Un identifiant absent donne
  // `/api/clients/undefined`, que le serveur ne resout pas : la reponse est VIDE. C'est le bon
  // echec. La garde -- `clientId ? … : undefined` -- retirerait le parametre, et `qs()` rendrait
  // alors la collection ENTIERE : les contacts de tout le monde sous le nom d'une seule personne.
  contactsClient: (clientId) =>
    request('/api/customer_contacts', { query: { customer: `/api/clients/${clientId}` } }),
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
  // PHOTOS DE PRODUIT -- le fichier part en multipart, sans en-tete Content-Type : le navigateur
  // pose lui-meme la frontiere du corps, et l'ecrire a la main la casse.
  photosProduit: (produitId) => request(`/api/offre/produits/${produitId}/photos`),
  televerserPhotoProduit: (produitId, fichier, altText) => {
    const corps = new FormData()
    corps.append('file', fichier)
    corps.append('altText', altText)

    return request(`/api/offre/produits/${produitId}/photos`, { method: 'POST', formData: corps })
  },
  supprimerPhotoProduit: (id) => request(`/api/offre/photos-produit/${id}`, { method: 'DELETE' }),

  fidelite: (clientId) => request(`/api/marketing/fidelite/${clientId}`),
  mouvementFidelite: (corps) =>
    request('/api/marketing/fidelite/mouvements', { method: 'POST', body: corps, ld: true }),

  // PARRAINAGE -- le code est CREE au premier appel : demander son code est le geste qui l'attribue.
  codeParrainage: (clientId) => request(`/api/marketing/parrainage/code/${clientId}`),
  // ⚠ ON ENVOIE LE PARAMÈTRE MÊME VIDE, ET C'EST DÉLIBÉRÉ.
  //
  // La forme précédente — `parrain ? { parrain } : {}` — échoue OUVERT : sans identifiant, aucun
  // filtre ne part et le serveur rend TOUS les parrainages, que l'écran affiche alors sous le nom
  // de la personne ouverte. La fiche client se garde bien (`if (!clientId) return`), mais cette
  // garde tient à une ligne dans un seul appelant.
  //
  // Mesuré ailleurs le 30/08 : `/api/ventes?client=nimportequoi` rendait les 15 ventes au lieu de
  // zéro — un filtre écrit à la main abandonnait la contrainte sur une valeur illisible. Corrigé
  // depuis côté serveur, mais le frontal n'a pas à compter là-dessus.
  //
  // Un identifiant absent donne donc `parrain=undefined`, que le serveur ne résout pas : la
  // réponse est vide. C'est le bon échec — « je ne montre rien » se remarque, « je montre tout »
  // ressemble à des données.
  parrainages: (parrain) =>
    request('/api/marketing/parrainages', { query: { parrain: String(parrain) } }),
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
  // Filtree par le serveur — voir `contactsClient` pour la mesure et pour la raison de ne pas
  // garder l'identifiant absent.
  activitesCommerciales: (clientId) =>
    request('/api/commercial_activities', { query: { customer: `/api/clients/${clientId}` } }),
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
  // LES TROIS GESTES QUE LE MODULE SAIT FAIRE ET QU'AUCUN BOUTON NE DECLENCHAIT.
  //
  // ⚠ CES TROIS OPERATIONS PORTENT `input: false` ET LISENT POURTANT UN CORPS. Dans ce depot,
  // `input: false` marque une operation SUR MESURE, pas une operation sans entree : les champs
  // ci-dessous sont ceux que les PROCESSEURS lisent (`SetRetentionProcessor`,
  // `IssuePublicLinkProcessor`), verifies dans leur code. Se fier a l'attribut aurait produit des
  // POST vides, donc des 422.
  politiquesRetention: () => request('/api/retention_policies', { query: { itemsPerPage: 100 } }),
  // `retentionPolicyCode: null` + `retainUntilOverride: null` = LEVER la politique. Les deux
  // absents = 422 : le processeur refuse une demande qui ne dit rien.
  poserRetentionDocument: (id, corps) =>
    request(`/api/documents/${id}/retention`, { method: 'POST', body: corps }),
  emettreLienPublicDocument: (id, corps) =>
    request(`/api/documents/${id}/public-links`, { method: 'POST', body: corps }),
  // ⚠ SUPPRESSION LOGIQUE, et REFUSEE (409) si une conservation court encore — RG-DMS-13. Ce n'est
  // pas un cas d'erreur a masquer : c'est la regle qui protege une piece que la loi oblige a garder.
  supprimerDocumentDms: (id) => request(`/api/documents/${id}`, { method: 'DELETE' }),
  remplacerVersionDocument: (id, formData) =>
    request(`/api/documents/${id}/replace-version`, { method: 'POST', formData }),
  versionsDocument: () => request('/api/document_versions', { query: { itemsPerPage: 300 } }),
  urlTelechargementDocument: (id) => `/dms/documents/${id}/download`,

  // REPRISE INITIALE (App\Import) -- quatre routes serveur, aucun appelant jusqu'ici. Le shortName
  // API est `Import`, donc la collection vit sous /api/imports.
  //
  // UN IMPORT EST UN OBJET, PAS UNE ACTION : il porte le fichier (base64, conserve), son verdict, et
  // ce qu'il a cree. DEUX TEMPS STRICTS (D98) :
  //   POST /imports            valide TOUT sans rien ecrire en base metier -- il rend l'objet avec
  //                            status `validated` (rowCount lignes) ou `rejected` (+ `errors`,
  //                            ligne -> message, la liste ENTIERE). Un rejet est un 201 comme un
  //                            succes : le verdict est dans l'objet, pas dans le code HTTP.
  //   POST /imports/{id}/appliquer  ecrit en une transaction (status `applied`).
  //   POST /imports/{id}/annuler    defait ce que CE lot a cree (status `reverted`), et REFUSE en
  //                            409 si une ligne creee a servi depuis (« on corrige par un second
  //                            import, on ne defait pas ce qui a servi »).
  //
  // `POST /imports` est une operation API Platform STANDARD : elle deserialise le corps (groupe
  // import_batch:write -- type, content base64, fileName, mimeType, expectedTotal), d'ou `ld: true`,
  // comme creerClient. Les deux gestes portent un uriTemplate et `input: false` cote serveur : pas de
  // corps, pas de `ld: true`, tout est dans la route -- meme patron que publierProduit.
  imports: (params) => request('/api/imports', { query: { itemsPerPage: 100, ...(params || {}) } }),
  detailImport: (id) => request(`/api/imports/${id}`),
  creerImport: (corps) => request('/api/imports', { method: 'POST', body: corps, ld: true }),
  appliquerImport: (id) => request(`/api/imports/${id}/appliquer`, { method: 'POST' }),
  annulerImport: (id) => request(`/api/imports/${id}/annuler`, { method: 'POST' }),

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
  // ECHEANCIER SEPA du fitness. `abonnement` n'arrive qu'en talon `{ id }` sous le groupe
  // `echeance:read` (verifie dans l'entite) : le nom de l'adherent se recoupe avec la liste des
  // abonnements de l'ecran, jamais avec cette reponse.
  echeancesSepaSport: (params = {}) =>
    request('/api/echeance_sepas', { query: { itemsPerPage: 200, ...params } }),
  // LE MOTIF EST EXIGE PAR LE SERVEUR : blanc ou vide, il rend 422. Ce n'est pas de la
  // bureaucratie — une echeance annulee est une somme que le club n'encaissera jamais, et la seule
  // question posee six mois plus tard sera « pourquoi ». Operation SUR MESURE, donc pas de
  // `ld: true`, qui est le drapeau des operations standard.
  // ENREGISTRER UN RETOUR BANQUE DE REJET.
  //
  // ⚠ LA ROUTE S'APPELLE `simuler-rejet` ET NE SIMULE RIEN. Son processeur passe l'echeance en
  // `Rejetee` et cree un `IncidentImpaye` reel via le moteur de recouvrement partage. En
  // production ce fait viendrait de `CollecteurSepaInterface::releverRetours()` ; cette route est
  // le point d'entree operationnel equivalent. Le nom de la fonction cliente dit ce qu'elle FAIT,
  // pas ce que la route s'appelle — c'est l'appelant qu'il faut ne pas tromper.
  //
  // `codeRetour` est REQUIS (code retour SEPA, ex. AM04) : 422 sans lui.
  enregistrerRejetEcheance: (id, corps) =>
    request(`/api/sport/echeances/${id}/simuler-rejet`, { method: 'POST', body: corps }),
  annulerEcheanceSepa: (id, motif) =>
    request(`/api/sport/echeances/${id}/annuler`, { method: 'POST', body: { motif } }),
  // LA DETECTION A LA DEMANDE — la route existait, aucun bouton ne l'appelait.
  //
  // ⚠ ELLE EST PAR ESPACE, pas globale. L'appelant doit donc boucler, et surtout COMPTER ce qu'il
  // a balaye : conclure « aucune presence isolee » apres avoir interroge zero espace serait une
  // absence jamais mesuree.
  detecterPresenceIsolee: (idEspace) =>
    request(`/api/sport/espaces/${idEspace}/detecter-presence-isolee`, { method: 'POST', body: {} }),
  // LE STATUT D'ACCES D'UN ADHERENT — lisible depuis l'origine, jamais lu par un ecran.
  //
  // ⚠ AUCUN `ApiFilter` N'EST DECLARE : passer `?abonnement=` serait accepte et IGNORE en silence.
  // L'appelant lit la collection et trie lui-meme sur l'identifiant imbrique. Un filtre non
  // declare est le pire des deux mondes — il a l'air de marcher.
  statutsAccesFitness: () =>
    request('/api/statut_acces_fitnesses', { query: { itemsPerPage: 200 } }),
  alertesPresenceIsolee: () => request('/api/alerte_presence_isolees', { query: { itemsPerPage: 100 } }),
  // ⚠ LA ROUTE ETAIT AU SINGULIER, ET ELLE RENDAIT 404 DEPUIS TOUJOURS.
  //
  // Mesure du 30/08 : `/api/abonnement_fitness` -> 404, `/api/abonnement_fitnesses` -> 200. Le
  // pluriel est celui qu'API Platform derive du `shortName: 'AbonnementFitness'`. L'ecran Sport
  // avalait l'echec (`.catch(() => null)`) et affichait « Aucun abonnement fitness » — un vide qui
  // ressemblait a une absence de donnees et qui etait une adresse fausse.
  abonnementsFitness: () => request('/api/abonnement_fitnesses', { query: { itemsPerPage: 200 } }),
  // LES DEUX ROLES D'UN ABONNEMENT, INTERROGEABLES SEPAREMENT.
  //
  // Un abonnement porte un ADHERENT (`Beneficiaire`, celui qui entre) et un PAYEUR (`Client`,
  // celui qui est preleve). La fiche client doit repondre aux deux questions, et ce ne sont pas
  // les memes : « que paie-t-il ? » sert au litige bancaire, « a quoi a-t-il droit ? » sert a la
  // porte. Le pont entre les deux est `Beneficiaire.client`, d'ou le second appel.
  //
  // ⚠ CES TROIS APPELS ONT EXIGE D'OUVRIR DES FILTRES COTE SERVEUR. Ni `AbonnementFitness` ni
  //   `Beneficiaire` n'en declarait : un `?payeur=` etait ignore EN SILENCE et l'endpoint rendait
  //   TOUT. Ca a la forme de donnees filtrees, ca arrive, et personne ne le remet en cause.
  // UNE OFFRE SUR UN PRÉLÈVEMENT À VENIR — parrainage, geste commercial, mois offert.
  //
  // ⚠ `montantCentimes` EST CE QU'ON RETIRE, PAS LE MONTANT D'ARRIVÉE. « moins 10 € » et
  //   « à 10 € » se confondent dans une tête pressée, et la confusion ne produit ni erreur ni
  //   message : elle produit un prélèvement faux. Le serveur exige un ENTIER — `(int) '10,50'`
  //   vaudrait 10, une saisie fausse qui passerait en silence.
  // LE CHEMIN DU RETOUR D'UN MARQUAGE « IMPAYEE REGIE ».
  //
  // ⚠ IL N'EXISTAIT PAS, ET CE N'ETAIT PAS QU'UNE GENE D'ECRAN : `GenerateurEReportingHandler`
  //   excluait de la declaration DGFiP toute vente marquee, sans regarder de statut. Un cheque
  //   finalement encaisse restait exclu pour toujours.
  reglerImpayeeRegie: (id, corps) =>
    request(`/api/compta/ventes-impayees-regie/${id}/regler`, { method: 'POST', body: corps }),
  reduireEcheance: (id, corps) =>
    request(`/api/sport/echeances/${id}/reduire`, { method: 'POST', body: corps }),
  abonnementsDuPayeur: (clientId) =>
    request('/api/abonnement_fitnesses', { query: { payeur: clientId, itemsPerPage: 100 } }),
  beneficiairesDuClient: (clientId) =>
    request('/api/beneficiaires', { query: { client: clientId, itemsPerPage: 100 } }),
  abonnementsDesAdherents: (ids) =>
    request('/api/abonnement_fitnesses', { query: { adherent: ids, itemsPerPage: 100 } }),
  // LES DEUX GESTES QUE L'ECRAN AVOUAIT NE PAS FAIRE.
  //
  // Les routes existent depuis l'origine du module ; aucune fonction cliente ne les appelait, donc
  // aucun bouton ne pouvait exister. Operations SUR MESURE (`input: false` cote serveur), donc pas
  // de `ld: true` -- ce drapeau est celui des operations standard.
  //
  // ⚠ Le MOTIF de resiliation est exige par le serveur : vide, il rend 422. Ce n'est pas de la
  // bureaucratie -- une resiliation revoque le mandat, et la seule question posee six mois plus
  // tard sera « pourquoi ».
  pauserAbonnement: (id, corps) =>
    request(`/api/sport/abonnements/${id}/pauses`, { method: 'POST', body: corps }),
  resilierAbonnement: (id, corps) =>
    request(`/api/sport/abonnements/${id}/resiliations`, { method: 'POST', body: corps }),
  // SOUSCRIRE : le premier pas de la chaine souscription -> echeance -> prelevement -> rejet ->
  // impaye -> recouvrement. Tout l'aval avait ete construit ; l'entree, non.
  // `input: false` cote serveur, le processeur lit le corps brut : pas de `ld: true`.
  souscrireAbonnement: (corps) =>
    request('/api/sport/abonnements/souscrire', { method: 'POST', body: corps }),
  rattacherDroitAccesAbonnement: (abonnementId, droitAccesId) =>
    request(`/api/sport/abonnements/${abonnementId}/rattacher-droit-acces`, {
      method: 'POST',
      body: { droitAcces: droitAccesId },
    }),

  tableauProjets: () => request('/api/projets/tableau'),
  creerProjet: (corps) => request('/api/projects', { method: 'POST', body: corps, ld: true }),
  majProjet: (id, corps) => request(`/api/projects/${id}`, { method: 'PATCH', body: corps }),
  // Filtrees par le serveur — voir `contactsClient`.
  tachesProjet: (projetId) =>
    request('/api/project_tasks', { query: { project: `/api/projects/${projetId}` } }),
  creerTacheProjet: (corps) => request('/api/project_tasks', { method: 'POST', body: corps, ld: true }),
  majTacheProjet: (id, corps) => request(`/api/project_tasks/${id}`, { method: 'PATCH', body: corps }),
  supprimerTacheProjet: (id) => request(`/api/project_tasks/${id}`, { method: 'DELETE' }),
  creerOpportunite: (corps) => request('/api/opportunities', { method: 'POST', body: corps, ld: true }),
  majOpportunite: (id, corps) => request(`/api/opportunities/${id}`, { method: 'PATCH', body: corps }),
  // Le detail d'une affaire, pour retrouver SON CLIENT. La carte du pipeline ne porte que le nom
  // affichable du client (`PipelineProvider` compose une chaine), pas son identifiant : impossible
  // d'en faire un destinataire de devis sans relire l'affaire.
  opportunite: (id) => request(`/api/opportunities/${id}`),
  // Rattache (ou crée) un client sur une vente ouverte (M2, CA-7). Corps : un de
  // { client: uuid } | { recherche: "..." } | { creer: { nom, prenom, email, telephone } }.
  rattacherClientVente: (venteId, corps) =>
    request(`/api/ventes/${venteId}/client`, { method: 'POST', body: corps, timeoutMs: 20000 }),

  // --- Options produits (App\OptionProduit) ---
  // ⚠ LE SOMMET DE LA HIÉRARCHIE `Groupe > Région > Établissement > Espace` (RG-SOCLE-01), qui
  // n'avait AUCUN appel client alors que l'API expose cinq opérations. Les deux niveaux du dessous
  // avaient chacun leur section dans Paramètres.
  //
  // ⚠ NI CRÉATION NI SUPPRESSION, ET C'EST DÉLIBÉRÉ. `StructureOnboarding` (« Ouvrir une
  // structure ») et `ProvisioningService` (l'abonnement) créent déjà le groupe AVEC sa région et
  // son établissement, d'un seul geste : un second chemin divergerait. Et supprimer un groupe
  // couperait le rattachement de sites entiers.
  groupes: () => request('/api/groupes', { query: { itemsPerPage: 100 } }),
  majGroupe: (id, corps) => request(`/api/groupes/${id}`, { method: 'PATCH', body: corps }),

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

  // --- Sejours (App\Stay) ---
  //
  // La note d'un sejour : on l'ouvre pour un client, les lignes s'y accumulent, puis on cloture,
  // puis on regle. Sept routes servies, aucune appelee jusqu'ici — et deux sejours ouverts en base
  // que personne ne pouvait lire.
  sejours: () => request('/api/stays', { query: { itemsPerPage: 200 } }),
  // `customer` est un UUID NU, pas une IRI (`OpenStayProcessor` fait `Uuid::isValid()` dessus). La
  // reference du sejour n'est PAS fournie : le serveur la fabrique, volontairement non sequentielle.
  ouvrirSejour: (corps) => request('/api/stays', { method: 'POST', body: corps }),
  // La note est une vue CALCULEE a chaque lecture, jamais un total entretenu : elle rend `balance`,
  // `lineCount` et le detail des lignes.
  noteSejour: (id) => request(`/api/stays/${id}/folio`),
  // ⚠ `amount` EST UNE CHAINE, et le negatif est accepte : c'est ainsi que s'enregistre un
  // reglement, faute d'un geste dedie. Un nombre JSON serait refuse (perte du centime).
  ajouterLigneSejour: (id, corps) =>
    request(`/api/stays/${id}/charges`, { method: 'POST', body: corps }),
  // ⚠ CLOTURER EST SANS RETOUR. Un sejour clos n'accepte plus de ligne, et rien ne le rouvre :
  // s'il reste un solde, il ne pourra plus jamais etre regle (le reglement EST une ligne).
  cloturerSejour: (id) => request(`/api/stays/${id}/close`, { method: 'POST', body: {} }),
  // Exige un sejour CLOS et un solde NUL, sinon 409.
  reglerSejour: (id) => request(`/api/stays/${id}/settle`, { method: 'POST', body: {} }),

  // --- Places liberees (App\SmartFlow) ---
  //
  // La liste d'attente PAR RESSOURCE, a distinguer de `inscrireListeAttente` (par creneau precis,
  // App\Reservation) et de `patinoireInscrireListeAttente`. Trois files coexistent dans le produit.
  propositionsReport: () =>
    request('/api/smart-flow/reschedule-proposals', { query: { itemsPerPage: 100 } }),
  // ⚠ ET LE GARDE-FOU DES FORMATS NE DIT RIEN DE CES TROIS ECRITURES. `verifier-formats.mjs` fait
  // `continue` sur toute operation portant `input: false` : mes trois routes sont dans la famille
  // qu'il saute. Son exemption est juste — une operation qui ne desserialise pas ne controle pas le
  // type — mais un vert obtenu par exemption n'est pas une verification. Ce qui fonde le choix ici,
  // c'est la lecture des declarations d'operations, pas un outil.
  //
  // ⚠ `confirmedReservationRef` EST OBLIGATOIRE : le module ne cree jamais de reservation, il clot
  // la proposition en la rattachant a une reservation deja creee par le chemin normal. Le serveur
  // revalide qu'elle appartient au meme etablissement ET au meme client (IDOR, RG-SF-16).
  accepterPropositionReport: (id, confirmedReservationRef) =>
    request(`/api/smart-flow/reschedule-proposals/${id}/accept`, {
      method: 'POST',
      body: { confirmedReservationRef },
    }),
  declinerPropositionReport: (id) =>
    request(`/api/smart-flow/reschedule-proposals/${id}/decline`, { method: 'POST', body: {} }),
  inscriptionsPlaceLiberee: () =>
    request('/api/smart-flow/waitlist-entries', { query: { itemsPerPage: 100 } }),
  // Corps : { resourceId, beneficiaryId, searchWindowStart, searchWindowEnd } — les quatre sont
  // exiges (422 sinon), et la fenetre doit finir apres son debut.
  inscrirePlaceLiberee: (corps) =>
    request('/api/smart-flow/waitlist-entries', { method: 'POST', body: corps }),
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
  // CREER ET CORRIGER UNE ACTIVITE — les deux routes existaient, aucun ecran ne les appelait.
  //
  // ⚠ LE NOM EST DELIBEREMENT LONG : `creerActivite` existe deja et designe une ACTIVITE
  // COMMERCIALE du CRM (un appel, une relance). Deux objets sans rapport sous le meme mot — le
  // piege qui m'a deja coute deux mesures fausses aujourd'hui.
  //
  // ⚠ `produitTarifReference` s'ecrit en IRI, et `null` DETACHE. Un champ absent laisserait la
  // valeur precedente : sans le `null` explicite, on ne pourrait jamais retirer un produit pose
  // par erreur.
  creerActiviteReservation: (corps) =>
    request('/api/reservation_activites', { method: 'POST', body: corps, ld: true }),
  majActiviteReservation: (id, corps) =>
    request(`/api/reservation_activites/${id}`, { method: 'PATCH', body: corps }),
  // PLACEMENT LIBRE : les debuts ou un rendez-vous TIENDRAIT, un jour donne.
  //
  // Sans `ressource`, on interroge tous les praticiens capables -- le mode << avec qui est
  // libre >>, qui est celui qui remplit un agenda. Ce point d'entree ne reserve rien : il
  // propose, et la reservation reste la creation d'un creneau puis d'une reservation.
  creneauxLibres: (params) => request('/api/reservation/creneaux-libres', { query: params }),
  creerCreneau: (corps) =>
    request('/api/reservation/creneaux', { method: 'POST', body: corps }),
  // ── ANNULER UNE RESERVATION ───────────────────────────────────────────────────────────────────
  //
  // ⚠ L'ECRAN N'A RIEN A DECIDER : tout vit dans `AnnulerReservationProcessor`. Dans le delai franc
  // porte par `dateLimiteAnnulation`, l'annulation est libre — la place est rendue, le credit
  // restitue, un avoir emis si la vente etait validee, et la liste d'attente promue. Hors delai, le
  // serveur REFUSE en libre-service et seul un agent portant `reservation.annuler` peut qualifier
  // l'issue en annulation tardive facturee (RG-M5-09).
  //
  // Le message de refus est formule par le serveur ; on l'affiche tel quel plutot que d'en ecrire
  // un second qui divergerait le jour ou la regle bouge.
  //
  // ⚠ ET C'EST LA CONDITION D'ENTREE D'UNE PROTECTION DEJA ECRITE. `BasculerNoShowCommand` ne
  // bascule que les reservations encore `Confirmee` : une reservation annulee en sort. Mais tant
  // qu'aucun ecran n'annule, l'exploitant note sur un carnet, la reservation reste `Confirmee`, et
  // la protection ne se declenche jamais. Elle est la ; rien ne l'atteignait.
  annulerReservation: (id) =>
    request(`/api/reservation/reservations/${id}/annuler`, { method: 'POST', body: {} }),

  // ⚠ LA ROUTE EXISTAIT DEPUIS LE 04/09 ET AUCUN ÉCRAN NE L'APPELAIT — c'est le geste central de
  // R15(a). Une réservation « à confirmer » ne pouvait donc être ni confirmée ni (avant le 05/09)
  // annulée : elle occupait un créneau jusqu'à l'expiration, et le mécanisme a dû être éteint en
  // préproduction faute de pouvoir le mener à terme.
  //
  // ⚠ LA SESSION DE CAISSE EST FACULTATIVE, ET CE N'EST PAS UN DÉTAIL. Le processeur n'encaisse que
  // si on lui en passe une : sans elle il confirme sans créer de vente — ce qui est le cas d'un
  // paiement déjà reçu, ou d'une confirmation faite hors comptoir. Le serveur refuse lui-même la
  // combinaison impossible (session + activité sans produit tarifaire) ; on n'énonce pas la règle
  // ici, on affiche son refus.
  confirmerReservation: (id, session) =>
    request(`/api/reservation/reservations/${id}/confirmer`, {
      method: 'POST',
      body: session ? { session: `/api/session_caisses/${session}` } : {},
      ld: true,
    }),

  // ── FUSION DE FICHES CLIENTS (US-L5-08, RG-M4-06) ─────────────────────────────────────────────
  //
  // ⚠ TROIS TEMPS, ET LE PREMIER EST CE QUI REND LE GESTE PRATICABLE. On ne fusionne pas a
  // l'aveugle : on demande d'abord CE QUI DIVERGE entre les fiches, on tranche champ par champ, et
  // seulement ensuite on ecrit. Sans cette etape, fusionner reviendrait a ecraser des donnees qu'on
  // n'a pas regardees.
  //
  // Rend `{ maitre, sources, champsDivergents: { champ: { maitre, sources: { id: valeur } } } }`.
  // Un objet vide signifie que les fiches ne se contredisent nulle part — la fusion est alors sans
  // arbitrage.
  previsualiserFusion: (maitre, sources) =>
    // ⚠ `sources` SANS CROCHETS : `qs()` les ajoute lui-meme pour un tableau. Ecrit
    // `'sources[]'`, il produisait `sources[][]=…`, que le serveur ne lit pas -- la
    // previsualisation rendait alors 422 << sources[] requis >>. Mesure le 31/08.
    request('/api/crm/fusions/previsualiser', { query: { maitre, sources } }),

  // Corps : { portee: 'client', sources: [iri], maitre: iri, champsArbitres?: {...}, motif?: '…' }
  //
  // ⚠ `champsArbitres` NE PORTE QUE CE QU'ON A TRANCHE. Un champ absent garde la valeur du maitre :
  // c'est le defaut sur, et il evite qu'un ecran distrait impose une valeur qu'il n'a pas montree.
  fusionnerClients: (corps) =>
    request('/api/crm/fusions', { method: 'POST', body: corps }),

  // ⚠ CE QUI REND LA FUSION ACCEPTABLE : elle se defait. Les fiches sources sont restaurees a
  // l'identique. Sans ce retour en arriere, personne de sense ne fusionnerait deux fiches d'un
  // client qui reclame.
  defusionner: (idJournal) =>
    request(`/api/crm/fusions/${idJournal}/defusionner`, { method: 'POST', body: {} }),

  // Le journal des fusions : c'est lui qui rend la defusion atteignable, et qui dit qui a fusionne
  // quoi, quand, et pourquoi.
  journalFusions: () => request('/api/journal_fusions', { query: { itemsPerPage: 50 } }),

  // ── DOUBLE AUTHENTIFICATION (§2.3 plan-backoffice.md) ─────────────────────────────────────────
  //
  // Cinq points d'entree, tous serves et tous eprouves par `MfaTest` — et aucun n'etait appele.
  //
  // ⚠ CE QUE LEUR ABSENCE A COUTE : `AffectationProcessor` exigeait le MFA avant d'affecter un role
  // a privileges. Comme aucun ecran ne l'activait, on ne pouvait nommer AUCUN administrateur, chez
  // aucun client. La garde est suspendue depuis le 31/08 ; ces appels sont ce qui permettra de la
  // retablir.
  //
  // Le secret et les codes de recuperation ne sont rendus QU'UNE FOIS, a l'activation : le serveur
  // ne stocke que leur forme chiffree ou hachee. Un ecran qui ne les montre pas a ce moment-la les
  // perd definitivement.
  mfaActiver: (id) =>
    request(`/api/utilisateurs/${id}/mfa/activer`, { method: 'POST', body: {} }),

  // Confirme l'activation avec un code de l'application d'authentification. Tant qu'on n'a pas
  // confirme, `mfaActif` reste faux : un secret pose sans confirmation n'enferme personne dehors.
  mfaConfirmer: (id, code) =>
    request(`/api/utilisateurs/${id}/mfa/confirmer`, { method: 'POST', body: { code } }),

  // Desactive, avec un code TOTP ou un code de recuperation. ⚠ Le serveur REFUSE si le compte
  // detient un role a privileges — la regle vit la-bas, l'ecran affiche son message.
  mfaDesactiver: (id, code) =>
    request(`/api/utilisateurs/${id}/mfa/desactiver`, { method: 'POST', body: { code } }),

  // Reinitialisation par un administrateur : appareil perdu et codes de recuperation epuises. Trace
  // dans le journal d'audit, avec l'etat avant et apres.
  mfaReinitialiser: (id) =>
    request(`/api/utilisateurs/${id}/mfa/reinitialiser`, { method: 'POST', body: {} }),

  // ⚠ LE SECOND FACTEUR A LA CONNEXION S'AUTHENTIFIE AVEC LE JETON PRE-AUTH, PAS AVEC CELUI DU
  // STOCKAGE — qui n'existe pas encore a ce stade. D'ou `auth: false` et l'en-tete pose a la main :
  // sans cela, `request` ecraserait l'`Authorization` par le jeton courant, absent, et le serveur
  // repondrait « non authentifie » sur une requete parfaitement formee.
  //
  // Le code accepte est un code TOTP OU un code de recuperation ; le serveur consomme ce dernier.
  mfaVerifier: (jetonPreAuth, code) =>
    request('/auth/mfa-verifier', {
      method: 'POST',
      body: { code },
      auth: false,
      headers: { Authorization: `Bearer ${jetonPreAuth}` },
    }),

  // ── DEPLACER UNE SEULE SEANCE (RG-M5-07, CA-6) ────────────────────────────────────────────────
  //
  // Corps : { debut?, fin?, ressource? } en ISO. PATCH, donc `application/merge-patch+json` — pose
  // par `request` des que la methode est PATCH.
  //
  // Ne touche PAS a la serie : les autres occurrences restent ou elles sont, et celle-ci se marque
  // `occurrenceModifiee` pour se distinguer. Le serveur refuse le chevauchement avec le meme garde
  // que la creation.
  //
  // ⚠ AUCUNE NOTIFICATION N'EST ENVOYEE AUX PERSONNES DEJA INSCRITES — mesure faite :
  // `NotificationReservationInterface` ne declare que la promotion de liste d'attente et
  // l'arbitrage. L'ecran le dit avant d'agir plutot que de laisser croire le contraire.
  modifierCreneau: (id, corps) =>
    request(`/api/reservation/creneaux/${id}`, { method: 'PATCH', body: corps }),

  // ── ARBITRER UN CONFLIT DE RECURRENCE (RG-M5-11) ──────────────────────────────────────────────
  //
  // Une occurrence de recurrence qui chevauche une autre occupation est desormais CREEE, marquee
  // `enAttenteArbitrage`, et non reservable — au lieu d'etre perdue en silence. Decision de Maxime
  // du 31/08 : « ne jamais deplacer tout seul ».
  //
  // Deux gestes, un seul appel : avec `idRessource`, on deplace la seance ; sans, on la confirme
  // telle quelle. Dans les deux cas le drapeau tombe et la seance devient reservable.
  //
  // ⚠ LE SERVEUR REFUSE UNE RESSOURCE OCCUPEE (409) : arbitrer ne peut pas deplacer le conflit
  // ailleurs. On affiche son message tel quel — il nomme la ressource.
  arbitrerCreneau: (id, idRessource) =>
    request(`/api/reservation/creneaux/${id}/arbitrer`, {
      method: 'POST',
      body: idRessource ? { ressource: idRessource } : {},
    }),

  // ── ANNULER UN CRENEAU (le geste de l'exploitant, pas du client) ──────────────────────────────
  //
  // ⚠ CE N'EST PAS UNE ANNULATION DE RESERVATION EN GROS. `AnnulerCreneauProcessor` bascule TOUTES
  // les reservations en `annulee_libre` — aucun no-show, aucun frais, le credit restitue — parce
  // que c'est l'exploitant qui annule et que le client n'y est pour rien. La regle du delai franc
  // ne s'applique pas : il n'y a rien a arbitrer, la seance revient toujours.
  //
  // C'est la difference qui compte a l'ecran : le meme mot « annuler » designe deux gestes dont
  // l'un facture et l'autre jamais.
  annulerCreneau: (id) =>
    request(`/api/reservation/creneaux/${id}/annuler`, { method: 'POST', body: {} }),

  // ── AFFECTER UNE INSTANCE A UNE RESERVATION FAITE SUR UN TYPE (ACT-1, D16) ────────────────────
  //
  // « Personne ne reserve la chambre 214 : on reserve une chambre double. » L'instance s'affecte
  // apres coup. Corps : { ressource }.
  //
  // Le serveur refuse trois choses, et la troisieme protege un client reel : une instance qui n'est
  // pas un enfant du type reserve, une instance d'un autre etablissement (404, pas 403), et une
  // instance DEJA affectee a une reservation qui chevauche. Sans ce dernier refus, deux personnes
  // recoivent la chambre 214 pour la meme nuit et personne ne s'en apercoit avant l'arrivee.
  affecterRessource: (idReservation, idRessource) =>
    request(`/api/reservation/reservations/${idReservation}/affecter`, {
      method: 'POST',
      body: { ressource: idRessource },
    }),

  // ── LISTE D'ATTENTE ───────────────────────────────────────────────────────────────────────────
  //
  // Les inscriptions, tous creneaux confondus. Le rang est calcule par le serveur a l'inscription ;
  // l'ecran ne le pose jamais lui-meme — deux personnes inscrites au meme instant depuis deux
  // postes obtiendraient le meme rang si le client le calculait.
  reservationListesAttente: () =>
    request('/api/reservation_liste_attentes', { query: { itemsPerPage: 200 } }),

  // S'inscrire sur un creneau. Corps : { beneficiaire, quantity? }.
  //
  // ⚠ `quantity` COMPTE : on attend pour N unites, pas pour « une place ». Une table de huit qui
  // s'inscrirait pour une seule serait promue sur une place libre et ne pourrait pas s'asseoir.
  inscrireListeAttente: (idCreneau, corps) =>
    request(`/api/reservation/creneaux/${idCreneau}/liste-attente`, { method: 'POST', body: corps }),

  // ── PAIEMENT PARTAGE ──────────────────────────────────────────────────────────────────────────
  //
  // Ajouter un participant a une reservation. Corps : { personne, estOrganisateur?, partMontant? }.
  // `personne` accepte un UUID ou une IRI ; a defaut de `partMontant`, le serveur repartit le
  // `montantDu` restant a parts egales entre les participants deja declares et le nouveau.
  //
  // ⚠ LE BENEFICIAIRE PASSE PAR LE CONTROLE DE PERIMETRE (D3/D8). Sans lui, on ajoutait a sa propre
  // reservation la fiche de n'importe qui — elle apparait ensuite dans la liste des participants,
  // avec son identite et sa part. Le serveur rend le meme message pour « inconnu » et « hors
  // perimetre », volontairement : les distinguer offrirait un oracle d'enumeration sur les fiches
  // clients. L'ecran affiche donc ce message tel quel, sans chercher a preciser.
  ajouterParticipant: (idReservation, corps) =>
    request(`/api/reservation/reservations/${idReservation}/participants`, { method: 'POST', body: corps }),

  // Marquer une part encaissee. `{id}` est celui du PARTICIPANT, pas de la reservation.
  payerPartParticipant: (idParticipant) =>
    request(`/api/reservation/participants/${idParticipant}/payer`, { method: 'POST', body: {} }),

  // ── EMARGER ───────────────────────────────────────────────────────────────────────────────────
  //
  // Corps : { statut: 'present' | 'absent', compteRendu? }. `input: false` cote serveur, donc JSON
  // simple et pas de `ld: true`.
  //
  // ⚠ SEUL ECRIVAIN DE `presenceConfirmee` DANS TOUT LE DEPOT — voir l'en-tete de ce fichier de
  // correctif. Sans cet appel, la branche `Honoree` de la bascule no-show n'est atteignable par
  // aucun chemin, et tout client qui s'est presente serait facture pour son absence.
  emargerReservation: (id, statut, compteRendu) =>
    request(`/api/reservation/reservations/${id}/emarger`, {
      method: 'POST',
      body: compteRendu ? { statut, compteRendu } : { statut },
    }),

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

  // LE CONTRÔLE D'ACCÈS N'AVAIT QUE SA SUPERVISION : ON REGARDAIT, ON N'AGISSAIT PAS.
  //
  // Dix-huit opérations exposées, deux atteignables (`/acces/supervision` et `/api/passages`). Les
  // deux gestes qu'un exploitant fait le plus souvent — bloquer un badge perdu, appairer une carte —
  // n'étaient possibles depuis aucun écran. Un adhérent qui perd sa carte ne pouvait pas être
  // protégé : le badge restait valide jusqu'à ce que quelqu'un touche la base.
  //
  // Toutes ces écritures portent un `uriTemplate` sur mesure et `input: false` : leur processor lit
  // le corps brut, elles n'exigent donc PAS `application/ld+json` (cf. `scripts/verifier-formats.mjs`).
  droitsAcces: () => request('/api/droit_acces', { query: { itemsPerPage: 200 } }),
  declarationsPerteVol: () =>
    request('/api/declaration_perte_vols', { query: { itemsPerPage: 200 } }),
  terminauxAcces: () => request('/api/acces/terminaux', { query: { itemsPerPage: 100 } }),
  // Corps : { identifiantSupport, typeSupport: QR|RFID|wallet, droit: iri|uuid, mode: caisse|autonome }.
  // Le support est créé à la volée s'il n'existe pas — c'est le geste « appairer une carte neuve ».
  appairerSupport: (corps) => request('/api/acces/appairages', { method: 'POST', body: corps }),
  revoquerAppairage: (id) =>
    request(`/api/acces/appairages/${id}/revoquer`, { method: 'POST', body: {} }),
  // Perte/vol : blocage serveur immédiat + nouvelle version de liste de révocation pour chaque
  // contrôleur (refus hors ligne aussi, à leur prochaine synchro). Motif OBLIGATOIRE côté serveur.
  bloquerSupport: (id, motif) =>
    request(`/api/acces/supports/${id}/bloquer`, { method: 'POST', body: { motif } }),
  // Le déblocage passe par l'annulation de la DÉCLARATION, pas par le support : c'est la trace qui
  // porte la réversibilité (qui a débloqué, quand), et le support suit.
  annulerDeclarationPerteVol: (id) =>
    request(`/api/acces/declarations/${id}/annuler`, { method: 'POST', body: {} }),
  // Enrôlement et rotation renvoient LE SECRET EN CLAIR UNE SEULE FOIS (RG-SOCLE-06 : il est haché
  // en base, jamais restitué ensuite). L'écran doit le montrer et le dire.
  enrolerTerminal: (corps) => request('/api/acces/terminaux', { method: 'POST', body: corps }),
  rotationJetonTerminal: (id) =>
    request(`/api/acces/terminaux/${id}/jetons`, { method: 'POST', body: {} }),
  revoquerTerminal: (id) =>
    request(`/api/acces/terminaux/${id}/revoquer`, { method: 'POST', body: {} }),

  // TOPOLOGIE DU CONTRÔLE D'ACCÈS (A-01) — QUATRE ENTITÉS COMPLÈTES, ZÉRO ÉCRAN.
  //
  // `EspaceAcces`, `Controleur`, `Equipement` et `SousReseau` exposent chacune GetCollection + Get
  // + Post + Patch depuis l'origine du module, et aucune n'était atteignable : sur une installation
  // neuve, déclarer un tourniquet passait par la base de données. Le seuil de jauge d'un espace, le
  // mode au dépassement, le délai d'anti-passback et les marges d'avance/retard se réglaient au même
  // endroit — c'est-à-dire nulle part, pour un exploitant.
  //
  // Les chemins ne sont PAS déductibles du nom de la ressource, et deux d'entre eux surprennent :
  // `EspaceAcces` donne `/api/espace_acces` (pas de « s » final) et `SousReseau` donne
  // `/api/sous_reseaus` (le pluriel est fabriqué mécaniquement). Vérifiés sur `debug:router`, pas
  // supposés.
  espacesAcces: () => request('/api/espace_acces', { query: { itemsPerPage: 200 } }),
  creerEspaceAcces: (corps) => request('/api/espace_acces', { method: 'POST', body: corps, ld: true }),
  majEspaceAcces: (id, corps) => request(`/api/espace_acces/${id}`, { method: 'PATCH', body: corps }),
  controleursAcces: () => request('/api/controleurs', { query: { itemsPerPage: 200 } }),
  creerControleur: (corps) => request('/api/controleurs', { method: 'POST', body: corps, ld: true }),
  majControleur: (id, corps) => request(`/api/controleurs/${id}`, { method: 'PATCH', body: corps }),
  equipementsAcces: () => request('/api/equipements', { query: { itemsPerPage: 200 } }),
  creerEquipement: (corps) => request('/api/equipements', { method: 'POST', body: corps, ld: true }),
  majEquipement: (id, corps) => request(`/api/equipements/${id}`, { method: 'PATCH', body: corps }),
  sousReseauxAcces: () => request('/api/sous_reseaus', { query: { itemsPerPage: 100 } }),
  creerSousReseau: (corps) => request('/api/sous_reseaus', { method: 'POST', body: corps, ld: true }),
  majSousReseau: (id, corps) => request(`/api/sous_reseaus/${id}`, { method: 'PATCH', body: corps }),

  // JOURNAL DES PASSAGES (A-05). `passages` ci-dessus rend les vingt derniers pour la supervision ;
  // celui-ci porte les filtres du serveur (`espace`, `controleur`, `equipement`, `resultat` en
  // SearchFilter, `horodatage` en DateFilter).
  journalPassages: (query) => request('/api/passages', { query }),
  // ⚠ L'EXPORT N'A PAS LES MÊMES NOMS DE PARAMÈTRES QUE LE JOURNAL. `PassageExportProvider` est écrit
  // à la main : il lit `depuis`, `jusqua`, `espace`, `equipement`, `resultat` — et ignore
  // silencieusement `horodatage[after]`. Passer les paramètres du journal rendrait un export NON
  // FILTRÉ qui a toutes les apparences d'un export filtré.
  exportPassages: (query) => request('/api/acces/passages/export', { query }),
  // LES DEUX GESTES DE LA SUPERVISION, PREVUS PAR LA SPEC ET ATTEIGNABLES DEPUIS NULLE PART.
  //
  // L'écran A-03 décrit un agent qui, devant un porteur bloqué, ouvre la porte lui-même — et un
  // comptage « +1 » pour qui entre sans support (un groupe scolaire, un accompagnant). Les deux
  // opérations existent côté serveur depuis l'origine du module ; aucune interface ne les appelait.
  // Sans elles, un exploitant devant une barrière qui refuse à tort n'a aucun recours dans le
  // logiciel : il ouvre à la main, et le passage n'est nulle part.
  //
  // Le MOTIF est obligatoire côté serveur pour les deux, et c'est ce qui fait la différence entre un
  // franchissement forcé et une trace exploitable : la question n'est pas « qui a ouvert » mais
  // « pourquoi il a fallu ouvrir ». Corps : { equipement: uuid|iri, motif: string, sens?: entree|sortie }.
  ouvertureManuelle: (corps) => request('/api/acces/passages/manuel', { method: 'POST', body: corps }),
  comptageNonNominatif: (corps) =>
    request('/api/acces/passages/non-nominatif', { method: 'POST', body: corps }),

  // « CE PRODUIT OUVRE TELLE ET TELLE ZONE » (ProductAccessZone).
  //
  // Une ligne = une zone ouverte par un produit. AUCUNE LIGNE = LE PRODUIT OUVRE TOUT : c'est la
  // règle de compatibilité du serveur, et c'est l'inverse de ce qu'une liste vide laisse croire.
  //
  // `productRef` est un identifiant NU et non une relation : le module Accès ne dépend pas de
  // l'Offre (D2). Il n'y a ni GET unitaire ni PATCH — une déclaration n'a rien à montrer seule, et
  // la modifier c'est en retirer une et en poser une autre. L'établissement n'est jamais dans le
  // corps : le serveur l'estampille depuis l'en-tête actif (D41).
  zonesProduit: (productRef) => request('/api/product_access_zones', { query: { productRef } }),
  declarerZoneProduit: (corps) =>
    request('/api/product_access_zones', { method: 'POST', body: corps, ld: true }),
  retirerZoneProduit: (id) => request(`/api/product_access_zones/${id}`, { method: 'DELETE' }),

  // État réseau des contrôleurs (bascule en ligne / hors ligne, US-L3-07).
  etatSynchroAcces: () => request('/api/acces/synchro/etat'),

  // LA CLOCHE — trois opérations, et une règle d'admission qui les rend rares.
  //
  // Une notification est un ÉVÉNEMENT DE DOMAINE qu'on a décidé de montrer à quelqu'un : facture
  // échue, prélèvement rejeté, devis expiré. Pas un refus au tourniquet — plusieurs par minute à
  // l'ouverture des portes, et personne n'agit sur un refus isolé.
  //
  // Le compte de la pastille se lit sur `totalItems` de CETTE requête, jamais sur un point d'entrée
  // séparé : deux sources qui comptent la même chose finissent par diverger.
  //
  // La liste de la cloche. Le compteur de la pastille se prend sur `totalItems` de CETTE
  // requête : un second point d'entrée qui compterait la même chose finirait par diverger,
  // et c'est la pastille qu'on croirait.
  notifications: (params) =>
    request('/api/notifications', {
      query: { lue: false, 'order[horodatage]': 'desc', itemsPerPage: 20, ...(params || {}) },
    }),
  // Marquer lue est idempotent côté serveur : la date de première lecture ne se réécrit pas.
  marquerNotificationLue: (id) =>
    request(`/api/notifications/${id}/lue`, { method: 'POST', body: {}, ld: true }),
  // « Tout marquer comme lu » ne vide QUE l'établissement actif : un geste qui effacerait
  // aussi les autres sites ferait disparaître, sans les avoir affichées, des alertes que
  // personne n'a vues.
  marquerToutesNotificationsLues: () =>
    request('/api/notifications/tout-lu', { method: 'POST', body: {}, ld: true }),

  // Reporting / Pilotage (M7). Route hors /api (proxifiée via /reporting).
  dashboardEtablissement: (id) => request(`/reporting/dashboards/etablissement/${id}`),
  // LES DEUX CONSOLIDATIONS, QUE PERSONNE N'APPELAIT. Le tableau de bord d'UN site etait branche
  // depuis l'origine ; ceux de la region et du groupe — classement des sites, ecart vs objectif,
  // ecart vs n-1, badge de fraicheur — ne l'etaient par aucun ecran. C'est pourtant la vue d'un
  // dirigeant : il ne regarde pas une piscine, il regarde son perimetre.
  //
  // ⚠ Les deux acceptent `periodeDebut`/`periodeFin` ; sans elles, la journee.
  // ⚠ Le groupe ne rend PAS d'ecart vs objectif — seulement vs n-1. L'ecran ne doit pas afficher
  // une case vide comme si c'etait un zero.
  dashboardRegion: (id, params) =>
    request(`/reporting/dashboards/region/${id}`, { query: params }),
  dashboardGroupe: (id, params) =>
    request(`/reporting/dashboards/groupe/${id}`, { query: params }),
  // Référentiel des indicateurs (M7).
  indicateurs: () => request('/api/indicateurs', { query: { itemsPerPage: 100 } }),
  // L'Explorateur (M7-04) : un indicateur x un perimetre x UN JOUR. Route hors `/api`, comme le
  // tableau de bord voisin. Rend 404 quand aucune mesure ne couvre le jour demande -- ce qui n'est
  // pas zero, et l'ecran doit les distinguer.
  explorerIndicateur: ({ indicateur, niveau, entiteId, periodeDebut, periodeFin }) =>
    request('/reporting/explorateur', {
      query: { indicateur, niveau, entiteId, periodeDebut, periodeFin },
    }),
  // Objectifs d'indicateur (M7, CA-3 << ecart vs objectifs >>). Contrat eprouve par un POST reel
  // avant d'ecrire le formulaire : `indicateur` est une IRI, la periode des dates `Y-m-d`, et le
  // rattachement suit le triplet niveau + une seule FK.
  objectifsIndicateur: () => request('/api/objectif_indicateurs', { query: { itemsPerPage: 200 } }),
  creerObjectif: (corps) =>
    request('/api/objectif_indicateurs', { method: 'POST', body: corps, ld: true }),
  supprimerObjectif: (id) =>
    request(`/api/objectif_indicateurs/${id}`, { method: 'DELETE' }),

  // Tableaux de bord (M7, RG-M7-06). `Post` et `Patch` passent par un processeur : le corps est
  // du JSON simple, jamais du ld+json a relations — le rattachement se donne par le couple
  // `niveau` + `entiteId`, parce que les proprietes de perimetre n'ont pas de setter.
  tableauxDeBord: () => request('/api/tableau_de_bords', { query: { itemsPerPage: 100 } }),
  creerTableauDeBord: (corps) =>
    request('/api/tableau_de_bords', { method: 'POST', body: corps, ld: true }),
  modifierTableauDeBord: (id, corps) =>
    request(`/api/tableau_de_bords/${id}`, { method: 'PATCH', body: corps }),
  // ⚠ AUCUNE SUPPRESSION : la ressource n'expose pas de `Delete`, par choix de la spec (§7, meme
  // patron qu'`Indicateur`). On retire de la circulation par `modifierTableauDeBord(id, { actif:
  // false })`. Une fonction de suppression ici rendrait un 405 a tous les coups.

  // Rapports planifies (M7, RG-M7-07). Le corps est du JSON simple : le processeur lit
  // `destinataires` comme une liste de { email, niveau, etablissement|region|groupe }, et chaque
  // destinataire est confronte au perimetre du CREATEUR, pas a celui de la cible.
  rapportsPlanifies: () => request('/api/rapport_planifies', { query: { itemsPerPage: 100 } }),
  creerRapportPlanifie: (corps) =>
    request('/api/rapport_planifies', { method: 'POST', body: corps, ld: true }),
  modifierRapportPlanifie: (id, corps) =>
    request(`/api/rapport_planifies/${id}`, { method: 'PATCH', body: corps }),
  // ⚠ Pas de suppression non plus ici : on suspend par `etat: 'suspendu'`.

  // EXPORTS D'ANALYSE (M7). Ce que le destinataire emporte : un fichier, pour une periode.
  //
  // ⚠ `/reporting/exports` prend `periodeDebut`/`periodeFin` DEPUIS le 05/09 — avant, il etait fige
  // sur la journee en cours. Sans dates, il rend toujours la journee, ce qui reste vrai pour un
  // appel ancien.
  exportsAnalyse: (params) =>
    request('/api/exports', { query: { itemsPerPage: 50, ...(params || {}) } }),
  creerExportAnalyse: (corps) =>
    request('/api/reporting/exports', { method: 'POST', body: corps, ld: true }),
  // Le contenu n'est servi QUE par cette route, et en base64 : la collection ne le porte pas,
  // sans quoi lister vingt exports rendrait vingt fichiers.
  telechargerExportAnalyse: (id) =>
    request(`/api/reporting/exports/${id}/telecharger`),

  // Le referentiel des indicateurs s'edite : `Patch` existait cote serveur et aucun ecran ne
  // l'appelait — un seuil de completude ou une unite se corrigeaient en base.
  modifierIndicateur: (id, corps) =>
    request(`/api/indicateurs/${id}`, { method: 'PATCH', body: corps }),

  // --- Paramètres (référentiels, lecture) ---
  espaces: () => request('/api/espaces', { query: { itemsPerPage: 200 } }),
  regions: () => request('/api/regions', { query: { itemsPerPage: 100 } }),
  // Un etablissement EXIGE une region : sans ces deux appels, une installation neuve ne pouvait
  // creer aucun site. La lecture seule etait branchee, l'ecriture non.
  // OUVRIR UNE STRUCTURE -- l'annuaire officiel des entreprises, interroge par le SERVEUR : la
  // frappe de l'exploitant ne part pas chez un tiers depuis son poste.
  chercherEntreprise: (q) => request('/api/organisation/entreprises', { query: { q } }),
  // Cree d'un geste le groupe, la region, l'etablissement, l'affectation de l'auteur, le point de
  // vente et l'identite legale.
  ouvrirStructure: (corps) =>
    request('/api/organisation/structures', { method: 'POST', body: corps, ld: true }),

  creerRegion: (corps) => request('/api/regions', { method: 'POST', body: corps, ld: true }),
  majRegion: (id, corps) => request(`/api/regions/${id}`, { method: 'PATCH', body: corps }),
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

  // LE CATALOGUE DES TAUX LÉGAUX — ce que la loi fixe, par opposition à `tauxTvas` qui est ce que
  // CET exploitant emploie. Les deux cohabitent à l'écran et ne se remplacent pas : le premier se
  // consulte, le second se possède.
  //
  // ⚠ UNE SEULE LECTURE, ET ELLE REND AUSSI LES MASQUAGES. Le croisement « quels taux sont masqués
  // pour moi » se fait côté serveur, où le profil comptable est connu : le laisser à l'écran, c'est
  // deux requêtes qui peuvent échouer séparément et un croisement à refaire dans chaque écran qui
  // affichera un jour cette liste.
  // MOT DE PASSE OUBLIÉ — la seule route du produit appelée SANS être authentifié.
  //
  // `auth: false` est indispensable : le transport pose sinon un en-tête `Authorization` vide, et
  // le serveur répond 401 sur une route qui doit justement servir à quelqu'un qui n'a pas de jeton.
  //
  // ⚠ La réponse porte `envoiCourrielBranche`. C'est le seul moyen pour cet écran de connaître ce
  // fait : `/me` ne lui a rien rendu, puisque personne n'est connecté. Le déduire d'une constante
  // reproduirait exactement le défaut qu'on corrige.
  demanderReinitialisation: (email) =>
    request('/mot-de-passe/oublie', { method: 'POST', body: { email }, auth: false }),
  // LES DEUX BOUTS QUI MANQUAIENT AUX FLUX DE COMPTE. `oublie` demandait le courriel ; le lien
  // qu'il envoie pointe vers `/mot-de-passe/reinitialiser?jeton=…` et `/activation?jeton=…`, et
  // AUCUN ecran n'appelait ces deux routes-la. Les liens tombaient sur l'ecran de connexion.
  //
  // ⚠ `auth: false` : ces deux appels se font sans session, par definition — celui qui active son
  // compte n'en a pas encore.
  activerCompte: ({ jeton, motDePasse }) =>
    request('/utilisateurs/activation', {
      method: 'POST', body: { jeton, motDePasse }, auth: false,
    }),
  reinitialiserMotDePasse: ({ jeton, nouveauMotDePasse }) =>
    request('/mot-de-passe/reinitialiser', {
      method: 'POST', body: { jeton, nouveauMotDePasse }, auth: false,
    }),

  // PARAMÉTRAGE DE FACTURATION — une ressource complète (Get, Post, Patch) que RIEN n'appelait.
  //
  // ⚠ CE N'ÉTAIT PAS UN CONFORT MANQUANT. Deux manques signalés ailleurs viennent de là :
  //
  //   — `tauxPenaliteRetard` est nullable et personne ne pouvait le renseigner. Le taux de
  //     pénalités « absent des factures » n'était pas absent du modèle, il était inatteignable ;
  //   — `ResolveurComptesFacturation` dit « renseignez une catégorie comptable mappée, OU un compte
  //     de produit par défaut dans le paramétrage de facturation ». La seconde voie n'existait pas :
  //     un repli qu'on ne pouvait pas armer.
  parametresFacturation: () =>
    request('/api/parametres-facturation', { query: { itemsPerPage: 50 } }),
  creerParametreFacturation: (corps) =>
    request('/api/parametres-facturation', { method: 'POST', body: corps, ld: true }),
  majParametreFacturation: (id, corps) =>
    request(`/api/parametres-facturation/${id}`, { method: 'PATCH', body: corps }),

  catalogueTauxTva: (pays) =>
    request('/api/compta/vat-rate-catalog', { query: pays ? { country: pays } : undefined }),

  // « Reprendre » : on n'envoie qu'un identifiant. Le serveur lit le libellé, la valeur et le profil
  // comptable à la source — l'écran n'a pas à les connaître, et ne peut donc pas les recopier de
  // travers. Il rend le catalogue à jour, ce qui évite une seconde requête pendant laquelle l'écran
  // afficherait l'inverse de ce qui vient de se passer.
  reprendreTauxLegal: (idTauxLegal) =>
    request('/api/compta/vat-rate-catalog/adopt', {
      method: 'POST',
      body: { legalVatRateId: idTauxLegal },
      ld: true,
    }),
  // Masquer et démasquer par le MÊME identifiant, tous deux en POST sur la vue. Le référentiel
  // n'étant pas une ressource exposée, il n'a pas d'IRI à donner — et la symétrie a un second
  // mérite : l'écran n'a aucune ligne de préférence à retenir entre deux chargements.
  masquerTauxLegal: (idTauxLegal) =>
    request('/api/compta/vat-rate-catalog/hide', {
      method: 'POST',
      body: { legalVatRateId: idTauxLegal },
      ld: true,
    }),
  demasquerTauxLegal: (idTauxLegal) =>
    request('/api/compta/vat-rate-catalog/unhide', {
      method: 'POST',
      body: { legalVatRateId: idTauxLegal },
      ld: true,
    }),
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

  // LES CAPACITÉS D'UN ÉTABLISSEMENT — ON POUVAIT LES LIRE, PAS LES ACTIVER.
  //
  // L'onglet s'appelait « Capacités activables » et n'offrait AUCUNE action : douze lignes toutes
  // marquées « inactive », et rien pour en changer. Maxime, à la revue : « je ne sais pas ce que
  // c'est ». Un onglet nommé « activables » où l'on ne peut rien activer n'explique pas ce qu'il
  // fait — il laisse conclure que le logiciel ne le permet pas.
  //
  // Les deux écritures existaient depuis le début. Elles sont gardées par `fonctionnalite.gerer` ou
  // `organisation.gerer`, contrôlé sur l'établissement DU CHEMIN et non sur l'établissement actif
  // (RG-SOCLE-05) : c'est pour ça que l'identifiant est dans l'URL et pas dans un en-tête.
  catalogueCapacites: () => request('/api/fonctionnalites/catalogue'),

  // CE QUI EST VENDABLE, ET A QUEL PRIX.
  //
  // ⚠ LE PREFIXE `/editor/` NE VEUT PAS DIRE « RESERVE A L'EDITEUR ». Il dit A QUI APPARTIENT le
  // catalogue — c'est celui de Fluvia, pas celui de l'exploitant. La ressource est declaree
  // `is_granted('PUBLIC_ACCESS')` : n'importe quel client peut lire ce qu'on lui vend, et c'est
  // exactement ce qu'il faut pour une boutique.
  //
  // Rend `{ capability, label, monthlyPriceCents }`. La liste est VIDE tant que l'editeur n'a cree
  // aucune option — auquel cas l'ecran le dit, plutot que d'afficher des modules a 0 €.
  //
  // ⚠ `ld: true` N'EST PAS FACULTATIF ICI. Cette ressource n'expose que `application/ld+json` : sans
  // ce drapeau elle rend un 406, que l'ecran transformait en « aucun module propose a la vente »
  // alors que vingt options existent. Verifie en interrogeant la route dans les deux formats.
  optionsVendables: () => request('/api/editor/plan-options', { ld: true }),
  fonctionnalitesEtablissement: (id) => request(`/api/etablissements/${id}/fonctionnalites`),
  // Corps : { capaciteCode, active, parametres? }.
  majFonctionnalite: (id, corps) =>
    request(`/api/etablissements/${id}/fonctionnalites`, { method: 'PATCH', body: corps }),
  // Corps : { metier: piscine|sport|padel|patinoire|musee }. ADDITIF : n'éteint jamais une capacité
  // déjà active — vérifié dans `Fonctionnalites::appliquerPreset`, pas supposé.
  appliquerPresetCapacites: (id, metier) =>
    request(`/api/etablissements/${id}/appliquer-preset`, { method: 'POST', body: { metier } }),

  // --- Comptabilité / Régie (M6) ---
  journaux: () => request('/api/journals', { query: { itemsPerPage: 100 } }),
  profilsExploitant: () => request('/api/profil_exploitants', { query: { itemsPerPage: 20 } }),

  // L'IDENTITE LEGALE DU VENDEUR — LUE DEPUIS TOUJOURS, JAMAIS ECRITE.
  //
  // ⚠ L'API acceptait `Post` et `Patch` depuis le debut, et les cinq champs d'identite sont dans le
  // groupe `profil:write`. Ce qui manquait etait ici : aucun appel d'ecriture, donc aucun ecran
  // possible. Mesure du 02/09 : `facturation:einvoicing:etat` reclamait une identite vendeur qu'AUCUN
  // ecran ne permettait de saisir — un rapport qui demande de remplir un formulaire qui n'existe pas.
  creerProfilExploitant: (corps) =>
    request('/api/profil_exploitants', { method: 'POST', body: corps, ld: true }),
  majProfilExploitant: (id, corps) =>
    request(`/api/profil_exploitants/${id}`, { method: 'PATCH', body: corps }),
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
  // ── LETTRAGE (US-L4-14, RG-M6-14) ─────────────────────────────────────────────────────────────
  //
  // Les lettrages existants : c'est ce qui permet de distinguer une ligne SOLDEE d'une ligne qui
  // reste due. Sans cette liste, un ecran de lettrage proposerait de relettrer ce qui l'est deja.
  // ── SAISIE MANUELLE D'ECRITURE (US-L4-11, RG-M6-11) ───────────────────────────────────────────
  //
  // Corps : { businessProfile, journal, date, label, lines: [{ account, vatRate, debit|credit, label? }] }
  //
  // ⚠ QUATRE REFUS DU SERVEUR, MESURES EN LISANT `SaisirEcritureManuelleHandler` :
  //  1. debit != credit  -> 422. L'ECRAN BLOQUE, contrairement au lettrage qui tolere un partiel.
  //  2. une ligne portant a la fois un debit et un credit, ou aucun des deux -> 422.
  //  3. un compte inactif -> 422 (`actif` est lisible : on ne propose que les comptes actifs).
  //  4. `vatRate` absent -> 422. Le taux est OBLIGATOIRE sur chaque ligne, meme une OD.
  //
  // ⚠ ET UN CINQUIEME QUI NE VIENT PAS DU HANDLER : la periode doit exister ET etre ouverte. C'est
  // `DirectLedgerEntryBuilder` qui refuse, via `estOuverte()` — chercher le mot « Cloturee » ne le
  // trouve pas, le garde-fou est ecrit a l'endroit et non a l'envers.
  saisirEcritureManuelle: (corps) =>
    request('/api/compta/journal-entries/manual', { method: 'POST', body: corps }),

  // ── LES DATES D'UN PRODUIT (onglet Agenda de la fiche) ────────────────────────────────────────
  //
  // Une `Exposition` porte produit + date de debut + date de fin + jauge. C'est le seul objet du
  // depot qui date un produit du catalogue — la Reservation, elle, ne reference aucun produit.
  //
  // ⚠ GARDEE PAR `musee.lire`, PAS PAR `offre.lire`. Qui peut lire un produit ne peut pas
  // forcement lire les expositions : un 403 doit se dire, pas se rendre en liste vide.
  expositionsDuProduit: (idProduit) =>
    request('/api/musee_expositions', {
      query: { produit: `/api/produits/${idProduit}`, itemsPerPage: 50, 'order[dateDebut]': 'asc' },
    }),

  lettragesEcritures: () =>
    request('/api/lettrage_ecritures', { query: { itemsPerPage: 500 } }),

  // Lettrer un groupe de lignes. Corps : { lines: [id, ...] } — au moins deux.
  //
  // ⚠ LE SERVEUR N'EXIGE PAS L'EQUILIBRE, et c'est mesure en le lisant. Il verifie deux lignes
  // minimum, un profil exploitant commun, et le cloisonnement de chacune. Un lettrage partiel est un
  // geste comptable legitime — solder un reglement en plusieurs fois — donc l'ecran AFFICHE l'ecart
  // sans jamais bloquer.
  //
  // Un identifiant nu suffit : `idDepuisReference` accepte l'IRI comme l'UUID.
  lettrerGroupe: (idsLignes) =>
    request('/api/compta/lettrages/groupe', { method: 'POST', body: { lines: idsLignes } }),

  verifierChaineEcritures: (journalId) =>
    request('/api/compta/ecritures/verifier-chaine', { query: { journal: journalId } }),
  cloturerPeriode: (id) =>
    request(`/api/compta/periodes/${id}/cloturer`, { method: 'POST', body: {} }),
  telechargerExport: (id) => request(`/api/compta/exports/${id}/telecharger`),
  // --- Achats & tresorerie ---
  facturesFournisseur: () =>
    request('/api/supplier_invoices', { query: { itemsPerPage: 200 } }),
  // ON POUVAIT APPROUVER UNE FACTURE FOURNISSEUR, ON NE POUVAIT PAS EN ENREGISTRER UNE.
  //
  // Maxime : << Achats & tresorerie -- on ne peut pas enregistrer une facture fournisseur, il faut
  // donc creer le module fournisseur. >> L'ecran savait rapprocher, approuver, contester, resoudre un
  // litige et annuler : il traitait une file qu'aucun geste ne remplissait.
  //
  // LE MODULE FOURNISSEUR AU SENS DES TIERS EXISTE DEJA (`creerFournisseur`, cote Stock). Ce qui
  // manquait est l'ENTREE de la facture -- un formulaire, pas une chaine d'extraction.
  //
  // Operations API Platform STANDARD (pas d'`uriTemplate`) : elles deserialisent, donc `ld: true`,
  // et les relations partent en IRI.
  // ⚠ LA LECTURE AUTOMATIQUE D'UN DOCUMENT — elle existait cote serveur et personne ne l'appelait.
  //
  // Le contrat : `{ content: <base64>, mimeType }`. Elle rend le fournisseur, le numero, la date,
  // les montants HT/TTC, la TVA et son taux — plus un SCORE DE CONFIANCE, qui est la seule chose
  // qui distingue une suggestion d'une saisie.
  extraireFactureFournisseur: (content, mimeType) =>
    request('/api/finance/supplier-invoices/extract', {
      method: 'POST',
      body: { content, mimeType },
      ld: true,
    }),
  creerFactureFournisseur: (corps) =>
    request('/api/supplier_invoices', { method: 'POST', body: corps, ld: true }),
  // Modification libre TANT QUE brouillon : le serveur repond 409 << Facture scellee >> au-dela.
  majFactureFournisseur: (id, corps) =>
    request(`/api/supplier_invoices/${id}`, { method: 'PATCH', body: corps }),
  // LES LIGNES SONT UNE RESSOURCE A PART, ET CE N'EST PAS UN DETAIL D'IMPLEMENTATION.
  //
  // `SupplierInvoice.lines` ne porte AUCUN groupe d'ecriture : les lignes ne s'embarquent pas dans le
  // corps de la facture, elles se posent une a une sur une facture deja creee. Verifie dans l'entite
  // avant d'ecrire le formulaire, pas devine -- l'envoi groupe aurait ete accepte en 201 avec une
  // facture a zero euro et aucune ligne.
  creerLigneFactureFournisseur: (corps) =>
    request('/api/supplier_invoice_lines', { method: 'POST', body: corps, ld: true }),
  // ⚠ PAS DE SUPPRESSION DE LIGNE : `SupplierInvoiceLine` ne declare ni `Delete` ni desactivation.
  // Une ligne posee sur un brouillon y reste. Le formulaire compose donc la facture AVANT de la
  // creer, et ne pose ses lignes qu'une fois -- se tromper coute une facture a annuler, pas une
  // ligne a retirer. Verifie dans l'entite, et signale : c'est une lacune du serveur, pas un choix
  // de cet ecran.
  // Le referentiel des natures de depense : c'est lui qui dit sur quel compte une ligne s'impute.
  // Une nature SANS mapping laisse la facture sans imputation comptable -- l'ecran le signale.
  // ─── NOTES DE FRAIS ──────────────────────────────────────────────────────────────────────
  //
  // Module ENTIER sans ecran : lister, creer, soumettre, rouvrir, finaliser une escalade, passer
  // en comptabilite, rembourser. Un salarie qui avance des frais n'avait aucun chemin, et un
  // remboursement qu'on ne peut pas tracer se regle de travers puis se discute apres coup.
  //
  // ⚠ DEUX FAMILLES DE CHEMINS, ET C'EST DELIBERE COTE SERVEUR :
  //   la ressource elle-meme vit sous `/api/expense_reports` (collection, item, PATCH) ;
  //   les GESTES vivent sous `/api/finance/expense-reports/{id}/…`. Confondre les deux rend 404.
  //
  // Contrat LU dans l'entite et le catalogue des permissions, pas sonde -- j'ai deja paye une
  // sonde aujourd'hui. Droits : `finance.expense_report_submit` pour creer et soumettre,
  // `finance.expense_report_post_to_ledger` pour comptabiliser et rembourser, et la lecture
  // s'ouvre aussi a `finance.expense_report_read_own`.
  notesDeFrais: (params) =>
    request('/api/expense_reports', { query: { itemsPerPage: 200, ...(params || {}) } }),
  creerNoteDeFrais: (corps) =>
    request('/api/expense_reports', { method: 'POST', body: corps, ld: true }),
  majNoteDeFrais: (id, corps) =>
    request(`/api/expense_reports/${id}`, { method: 'PATCH', body: corps }),
  // La ligne porte la nature, la date, le montant TTC et la piece justificative.
  // LIRE UN JUSTIFICATIF — meme contrat que l'extraction de facture fournisseur.
  //
  // ⚠ `content` EST DU BASE64 NU, pas une data-URL. `readAsDataURL` rend
  // « data:<mime>;base64,<contenu> » : le serveur attend le contenu SEUL, et rend 422 sur le
  // prefixe. C'est le meme piege que sur les factures, et il ne se voit qu'a l'execution.
  //
  // ⚠ AUCUNE CREATION AUTOMATIQUE DE LIGNE (RG-EXP-03) : cette route ne fait que pre-remplir.
  extraireJustificatifFrais: (content, mimeType) =>
    request('/api/finance/expense-reports/extract', {
      method: 'POST',
      body: { content, mimeType },
      ld: true,
    }),
  creerLigneFrais: (corps) =>
    request('/api/expense_lines', { method: 'POST', body: corps, ld: true }),
  supprimerLigneFrais: (id) =>
    request(`/api/expense_lines/${id}`, { method: 'DELETE' }),
  soumettreNoteDeFrais: (id) =>
    request(`/api/finance/expense-reports/${id}/submit`, { method: 'POST', body: {} }),
  rouvrirNoteDeFrais: (id) =>
    request(`/api/finance/expense-reports/${id}/reopen`, { method: 'POST', body: {} }),
  finaliserEscaladeNoteDeFrais: (id) =>
    request(`/api/finance/expense-reports/${id}/finalize-escalade`, { method: 'POST', body: {} }),
  passerEnComptaNoteDeFrais: (id) =>
    request(`/api/finance/expense-reports/${id}/post-to-ledger`, { method: 'POST', body: {} }),
  // Le remboursement est une RESSOURCE, pas un simple geste : il porte une date, un montant, un
  // moyen de paiement et une reference. C'est ce qui permettra de le rapprocher en banque.
  rembourserNoteDeFrais: (id, corps) =>
    request(`/api/finance/expense-reports/${id}/reimbursements`, { method: 'POST', body: corps, ld: true }),

  mappingsDepense: () =>
    request('/api/expense_account_mappings', { query: { itemsPerPage: 200 } }),
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
  // L'AVOIR — la route existait, aucun bouton ne l'appelait.
  //
  // ⚠ `amount` ABSENT VAUT AVOIR TOTAL (§0.9 du plan). Ce n'est donc PAS un champ optionnel qu'on
  // peut omettre par commodite : l'omettre est une decision comptable. L'appelant doit avoir
  // tranche avant, et l'ecran le lui demande explicitement.
  avoirFactureFournisseur: (id, corps) =>
    request(`/api/finance/supplier-invoices/${id}/credit-note`, { method: 'POST', body: corps }),
  annulerFactureFournisseur: (id) =>
    request(`/api/finance/supplier-invoices/${id}/cancel`, { method: 'POST', body: {} }),

  // ── Trésorerie (T26) : comptes bancaires, import de relevés, rapprochement ──────────────────────
  // Cloisonnement et en-tête X-Etablissement posés par `request()` ; jamais à répéter ici.
  comptesBancaires: (params) => request('/api/bank_accounts', { query: params }),
  // Créations standard → `ld: true` (application/ld+json), la convention du frontal (D50).
  creerCompteBancaire: (corps) =>
    request('/api/bank_accounts', { method: 'POST', body: corps, ld: true }),
  majCompteBancaire: (id, corps) =>
    request(`/api/bank_accounts/${id}`, { method: 'PATCH', body: corps }),
  // Import : corps JSON avec `content` en base64 — PAS de multipart (le serveur déchiffre puis vide).
  importsReleve: (params) => request('/api/bank_statement_imports', { query: params }),
  importerReleve: (corps) =>
    request('/api/bank_statement_imports', { method: 'POST', body: corps, ld: true }),
  // Lignes de relevé + rapprochement. Filtres : `statementImport`, `statementImport.bankAccount`, `status`.
  lignesReleve: (params) => request('/api/bank_statement_lines', { query: params }),
  ajouterLigneReleve: (corps) =>
    request('/api/bank_statement_lines', { method: 'POST', body: corps, ld: true }),
  suggestionsLigneReleve: (id) =>
    request(`/api/finance/treasury/statement-lines/${id}/suggestions`),
  rapprocherLigneReleve: (id, corps) =>
    request(`/api/finance/treasury/statement-lines/${id}/reconcile`, { method: 'POST', body: corps }),
  ignorerLigneReleve: (id, corps) =>
    request(`/api/finance/treasury/statement-lines/${id}/ignore`, { method: 'POST', body: corps }),
  // Tableaux de bord (fournisseurs `provide()` → JSON brut, PAS une collection Hydra : pas de membres()).
  positionTresorerie: (params) => request('/api/finance/treasury/position', { query: params }),
  echeancierTresorerie: (params) => request('/api/finance/treasury/payment-schedule', { query: params }),
  previsionTresorerie: (params) => request('/api/finance/treasury/cashflow-forecast', { query: params }),
  ecartsTresorerie: () => request('/api/finance/treasury/discrepancies'),

  // Recouvrement : les deux gestes qui closent un impaye, et le compteur d'acces bloques.
  tableauBordRecouvrement: () => request('/api/recouvrement/tableau-bord'),

  // D84 — « ce redevable n'est jamais bloque ». L'exemption porte sur le REDEVABLE, pas sur le
  // dossier : forcer une reouverture vaut pour un impaye, l'exemption vaut aussi pour ceux a venir.
  exemptionsBlocage: () => request('/api/recouvrement/exemptions', { query: { itemsPerPage: 100 } }),
  exempterRedevable: (typeRedevable, referenceRedevable, motif) =>
    request('/api/recouvrement/exemptions/accorder', {
      method: 'POST',
      body: { typeRedevable, referenceRedevable, motif },
    }),
  retirerExemption: (id) =>
    request(`/api/recouvrement/exemptions/${id}/retirer`, { method: 'POST', body: {} }),
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
  // ── VERSER UNE REGIE (US-L4-02, CA-5) ─────────────────────────────────────────────────────────
  //
  // Corps : { montant: '123.45', justificatifs?: ['ref', ...] }
  //
  // ⚠ CE N'EST PAS UN CONFORT : `ClotureGuard` refuse la cloture d'une periode tant qu'une regie
  // depasse son plafond d'encaisse. Sans cet appel, le comptable lisait « versement requis » sans
  // aucun endroit ou verser — une obligation legale sans sortie.
  //
  // Deux refus du serveur, anticipes par l'ecran : montant <= 0 -> 422, montant > solde -> 409.
  //
  // Le handler genere l'ecriture comptable du versement dans la foulee : elle ne se saisit pas a la
  // main dans l'onglet voisin.
  verserRegie: (idRegie, corps) =>
    request(`/api/compta/regies/${idRegie}/versements`, { method: 'POST', body: corps }),

  // ── IMPAYES DE REGIE (RG-M6-09) ───────────────────────────────────────────────────────────────
  //
  // Corps : { motif?: string }. Sans motif, le serveur enregistre « Recette de regie » — ce qui ne
  // dira rien a qui relira la ligne dans six mois, donc l'ecran encourage a le remplir.
  //
  // ⚠ Refus du serveur : 409 si la vente est DEJA marquee. L'ecran ne peut prevenir que dans un
  // sens — trouvee dans la liste chargee => deja marquee ; l'absence ne prouve rien, la liste est
  // paginee.
  marquerImpayeeRegie: (idVente, corps) =>
    request(`/api/compta/ventes/${idVente}/marquer-impayee-regie`, { method: 'POST', body: corps }),

  // ── ENCAISSEMENTS PAYFIP ──────────────────────────────────────────────────────────────────────
  //
  // Le referentiel entier n'avait aucune trace dans l'interface. Un bordereau bloque en
  // `en_attente` etait invisible de partout.
  //
  // ⚠ IL N'Y A PAS D'APPEL DE REJEU ICI, ET C'EST DELIBERE. `rejouer()` n'incremente qu'un compteur
  // de tentatives : il n'interroge pas la DGFiP et ne change aucun statut. L'exposer donnerait
  // l'illusion d'avoir relance. Le vrai rejeu depend de la signature des rappels du Tresor —
  // bloqueur externe E-7.
  bordereauxPayFip: () =>
    request('/api/bordereau_pay_fi_ps', { query: { itemsPerPage: 100 } }),

  bordereauxVersement: () =>
    request('/api/bordereau_versements', { query: { itemsPerPage: 100 } }),
  comptesComptables: () =>
    request('/api/compte_comptables', { query: { itemsPerPage: 200 } }),
  // LES CORRESPONDANCES COMPTABLES — ce qui décide du compte de produit d'une catégorie de vente.
  //
  // La table existait depuis l'origine du module et rien ne permettait de la remplir : UNE seule
  // correspondance sur la préprod. Sans correspondance active, les ventes de la catégorie ne sont
  // pas comptabilisées du tout — elles ressortent en anomalie à la génération (`MappingComptableGuard`
  // puis `GenerateurEcrituresHandler`, qui saute la vente). Ce n'est pas un repli sur un compte
  // par défaut : c'est une écriture qui n'existe pas.
  //
  // ⚠ ET LA FACTURATION, ELLE, SE REPLIE — CE N'EST PAS LE MÊME CHEMIN.
  //
  // Mesuré dans `ResolveurComptesFacturation::compteProduit()` :
  //
  //   1. `MappingComptable` résolu depuis la catégorie comptable de la ligne ;
  //   2. à défaut, `ParametreFacturationEtablissement::compteProduitDefaut` — repli SILENCIEUX ;
  //   3. à défaut des deux, un 422 explicite qui nomme les deux sorties.
  //
  // Donc deux comportements opposés pour la même donnée absente, selon le chemin. Et le repli de
  // la facturation est silencieux quand il RÉUSSIT, jamais quand il échoue : dire l'un sans
  // l'autre laisse croire qu'une ligne sans compte passe toujours.
  //
  // ⚠ CE COMMENTAIRE EST NÉ D'UN DOUBLON, ET LE MÉCANISME VAUT D'ÊTRE RETENU. Cette clé était
  // déclarée DEUX FOIS dans cet objet, avec deux commentaires qui se lisaient comme une
  // contradiction — produits par une fusion SANS conflit, la dernière définition gagnant en
  // silence. Aucun des deux n'était faux ; aucun ne nommait son périmètre. C'est ce qui les
  // faisait se contredire.
  mappingsComptables: () => request('/api/mapping_comptables', { query: { itemsPerPage: 200 } }),
  creerMappingComptable: (corps) =>
    request('/api/mapping_comptables', { method: 'POST', body: corps, ld: true }),
  majMappingComptable: (id, corps) =>
    request(`/api/mapping_comptables/${id}`, { method: 'PATCH', body: corps }),
  cautions: () => request('/api/cautions', { query: { itemsPerPage: 100 } }),
  // Le JOURNAL d'une caution, et le BARÈME qui chiffre ses retenues. Les deux ressources existaient
  // sans appelant : l'écran montrait un montant retenu sans jamais dire *qui* l'a retenu, *quand*,
  // ni *au nom de quelle règle* — les trois seules choses qu'un client conteste au guichet.
  cautionMouvements: () => request('/api/caution_mouvements', { query: { itemsPerPage: 200 } }),
  grillesRetenue: () => request('/api/caution_grille_retenues', { query: { itemsPerPage: 100 } }),
  // Opérations STANDARD : elles désérialisent, donc `ld: true` (cf. `creerExportComptable`).
  creerGrilleRetenue: (corps) =>
    request('/api/caution_grille_retenues', { method: 'POST', body: corps, ld: true }),
  majGrilleRetenue: (id, corps) =>
    request(`/api/caution_grille_retenues/${id}`, { method: 'PATCH', body: corps }),

  // SEPA : remises de prélèvement (pain.008), mandats, rejets.
  remisesSepa: () => request('/api/remise_sepas', { query: { itemsPerPage: 100 } }),
  mandatsSepa: () => request('/api/mandat_sepas', { query: { itemsPerPage: 100 } }),
  rejetsSepa: () => request('/api/rejet_sepas', { query: { itemsPerPage: 100 } }),

  // LES QUATRE ÉCRITURES SEPA, QUI EXISTAIENT TOUTES SANS APPELANT.
  //
  // `/api/sepa/mandats` et `/api/sepa/remises/generer` sont des opérations à corps brut (elles
  // passent par `LecteurCorps`, pas par la désérialisation d'API Platform) : PAS de `ld: true`,
  // sinon on annonce un type que l'opération n'attend pas.
  //
  // `/api/rejet_sepas` en POST est l'inverse : opération STANDARD, donc `ld: true` obligatoire —
  // sans lui API Platform répond 415 et la déclaration de rejet échoue. Je l'avais écrite sans le
  // drapeau ; c'est `scripts/verifier-formats.mjs` qui l'a arrêtée, pas une relecture.
  creerMandatSepa: (corps) => request('/api/sepa/mandats', { method: 'POST', body: corps }),
  // Revocation : POST sans corps, meme forme que l'avoir de facture (`body: {}`, le serveur declare
  // `input: false`). Operation SUR MESURE — `uriTemplate` dedie — donc pas de `ld: true`, qui est le
  // drapeau des operations standard.
  revoquerMandatSepa: (id) => request(`/api/sepa/mandats/${id}/revoquer`, { method: 'POST', body: {} }),
  genererRemiseSepa: (dateExecution) =>
    request('/api/sepa/remises/generer', {
      method: 'POST',
      body: dateExecution ? { dateExecution } : {},
      // La génération parcourt toutes les échéances dues de l'établissement et compose le XML :
      // c'est la plus lente des écritures SEPA, et un spinner sans fin s'y lirait comme un blocage.
      timeoutMs: 30000,
    }),
  declarerRejetSepa: (corps) =>
    request('/api/rejet_sepas', { method: 'POST', body: corps, ld: true }),

  // LES LIGNES D'UNE REMISE — ET POURQUOI ON LES CHARGE TOUTES.
  //
  // `LigneRemiseSepa` n'a AUCUN filtre déclaré côté serveur (aucun `#[ApiFilter]` sur l'entité) :
  // `?remise=...` serait accepté par l'URL et IGNORÉ par Doctrine. On aurait alors la liste complète
  // en croyant lire celle d'une remise — un résultat plausible et faux, exactement le défaut que la
  // fonction `qs()` en tête de ce fichier documente. On charge donc large et on filtre côté client,
  // et c'est écrit ici pour que personne n'ajoute un paramètre qui ne sert à rien.
  lignesRemiseSepa: () => request('/api/ligne_remise_sepas', { query: { itemsPerPage: 500 } }),

  // Le PARAMÉTRAGE du créancier : ICS, nom, IBAN de collecte. Sans lui, aucune remise ne peut être
  // composée — c'est la première chose à remplir du module, et elle n'avait pas d'écran.
  configsCreancierSepa: () =>
    request('/api/config_creancier_sepas', { query: { itemsPerPage: 20 } }),
  creerConfigCreancierSepa: (corps) =>
    request('/api/config_creancier_sepas', { method: 'POST', body: corps, ld: true }),
  majConfigCreancierSepa: (id, corps) =>
    request(`/api/config_creancier_sepas/${id}`, { method: 'PATCH', body: corps }),

  // Le pain.008 n'est PAS une opération API Platform mais un contrôleur simple qui rend du XML.
  // Même patron que `urlTelechargementDocument` : une URL, pas un appel — le jeton doit voyager
  // dans l'en-tête, donc l'appelant fait son `fetch` et lit un blob (voir `PrelevementsSepa.jsx`).
  //
  // ⚠ Cette route est hors `/api` : elle doit être routée explicitement vers Symfony (proxy Vite en
  // dev, bloc nginx en préprod). Sans ça le SPA rend son propre `index.html` avec un 200, et le
  // « fichier » téléchargé est une page HTML portant l'extension .xml.
  urlPain008: (id) => `/sepa/remises/${id}/pain008`,

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
  // LA RÈGLE ÉTAIT LISIBLE ET PAS MODIFIABLE, alors que le serveur accepte POST et PATCH depuis le
  // début. Un exploitant qui trouvait le blocage d'accès trop brutal pouvait le constater sur
  // l'écran, et nulle part le corriger : il en concluait que le logiciel était comme ça.
  creerPolitiqueRecouvrement: (corps) =>
    request('/api/politique_recouvrements', { method: 'POST', body: corps, ld: true }),
  majPolitiqueRecouvrement: (id, corps) =>
    request(`/api/politique_recouvrements/${id}`, { method: 'PATCH', body: corps }),

  // --- Relance des recettes (App\RevenueRecovery) ---
  //
  // A NE PAS CONFONDRE AVEC LE RECOUVREMENT CI-DESSUS, malgre la parente des noms. Le recouvrement
  // traite des IMPAYES : representations bancaires, acces bloques, exemptions. La relance des
  // recettes est un mecanisme EVENEMENTIEL : un no-show, une annulation, un paiement refuse ouvrent
  // un dossier, et des courriels partent selon une politique. Les deux modules sont distincts cote
  // serveur (`App\Recouvrement` et `App\RevenueRecovery`, repliques et jamais importes l'un dans
  // l'autre) : ce sont deux ecrans, et ce doit rester deux jeux d'appels.
  dossiersRelance: () => request('/api/revenue-recovery/cases', { query: { itemsPerPage: 100 } }),
  // Le motif est FACULTATIF et n'a rien d'evident : la route est declaree `input: false`, mais son
  // processeur lit le corps brut et y cherche `reason`. Sans cet appel-la, `stopReason` resterait
  // vide sur tous les arrets manuels.
  arreterDossierRelance: (id, reason) =>
    request(`/api/revenue-recovery/cases/${id}/stop`, { method: 'POST', body: { reason } }),
  tentativesRelance: () => request('/api/revenue-recovery/attempts', { query: { itemsPerPage: 200 } }),
  politiquesRelance: () => request('/api/revenue-recovery/sequences', { query: { itemsPerPage: 100 } }),
  // ⚠ `ld: true` OBLIGATOIRE ICI : l'operation desserialise le corps, donc elle n'accepte que
  // `application/ld+json` et repondrait 415 a du JSON simple.
  creerPolitiqueRelance: (corps) =>
    request('/api/revenue-recovery/sequences', { method: 'POST', body: corps, ld: true }),
  majPolitiqueRelance: (id, corps) =>
    request(`/api/revenue-recovery/sequences/${id}`, { method: 'PATCH', body: corps }),

  // --- Boutique en ligne (M3, vue admin) ---
  // Les paniers en ligne ne sont pas listables (accès par id) : la vue admin s'appuie sur les
  // demandes de remboursement (listables) et les comptes clients boutique.
  demandesRemboursement: () =>
    request('/api/boutique/demandes-remboursement', { query: { itemsPerPage: 100 } }),
  // LES RETRAITS CLICK & COLLECT — deux routes, aucun ecran jusqu'ici.
  //
  // ⚠ `codeRetrait` ARRIVE DANS LA REPONSE : il est declare dans le groupe `retrait:read`. Le
  // serveur le compare pourtant avec `hash_equals`, une comparaison en temps constant — celle
  // qu'on reserve aux secrets. Les deux ne peuvent pas etre vrais en meme temps : ou bien le code
  // est une preuve, et il ne doit pas etre lisible ; ou bien il ne l'est pas, et `hash_equals` est
  // du decor. L'ecran ne l'affiche pas, mais ce n'est qu'un pansement : le correctif est de le
  // retirer du groupe de lecture. Signale.
  retraitsClickCollect: () =>
    request('/api/retrait_click_collects', { query: { itemsPerPage: 200 } }),
  // `codeRetrait` REQUIS et compare a l'identique (422 sinon).
  // `identifiantSupportPhysique` facultatif.
  validerRetraitClickCollect: (id, corps) =>
    request(`/api/boutique/retraits/${id}/valider`, { method: 'POST', body: corps }),
  comptesClientBoutique: () =>
    request('/api/compte_clients', { query: { itemsPerPage: 100 } }),
  vitrines: () => request('/api/boutique/vitrines', { query: { itemsPerPage: 100 } }),
  // LA REVENTE PAR DES PARTENAIRES EN LIGNE — sept routes servies, aucune appelee jusqu'ici.
  //
  // ⚠ LES NOMS DE COLLECTION NE SE DEVINENT PAS. Le partenaire est `boutique_partenaire_otas`
  // (il appartient a la Boutique) et le reversement `reversement_otas` — pas `musee_*`, malgre
  // l'entite `Musee\Entity\Reversement`. Verifies au routeur.
  partenairesOta: () =>
    request('/api/boutique_partenaire_otas', { query: { itemsPerPage: 200 } }),
  creerPartenaireOta: (corps) =>
    request('/api/boutique_partenaire_otas', { method: 'POST', body: corps, ld: true }),
  majPartenaireOta: (id, corps) =>
    request(`/api/boutique_partenaire_otas/${id}`, { method: 'PATCH', body: corps }),
  // LES QUOTAS ALLOUES — le maillon entre le partenaire et le reversement.
  //
  // ⚠ `quotaConsomme` EST EN LECTURE SEULE cote serveur : il est alimente par les ventes que la
  // plateforme partenaire pousse sur `/boutique/ota/ventes`. On ne l'envoie donc jamais en
  // ecriture — le corriger a la main ferait diverger le registre de la realite.
  quotasOta: () =>
    request('/api/boutique_allocation_quota_otas', { query: { itemsPerPage: 200 } }),
  creerQuotaOta: (corps) =>
    request('/api/boutique_allocation_quota_otas', { method: 'POST', body: corps, ld: true }),
  majQuotaOta: (id, corps) =>
    request(`/api/boutique_allocation_quota_otas/${id}`, { method: 'PATCH', body: corps }),
  // LE MODULE << CONNECTEURS >> -- destinations sortantes Slack, Teams, Discord.
  //
  // /!\ L'URL NE REVIENT JAMAIS EN LECTURE. Elle vaut un mot de passe : qui la detient peut ecrire
  // dans le canal au nom de l'etablissement. Le serveur ne la met dans aucun groupe de
  // serialisation ; seul l'hote ressort. Ne jamais ajouter ici de route qui la relirait.
  connecteurs: () =>
    request('/api/integration_outbound_endpoints', { query: { itemsPerPage: 100 } }),
  creerConnecteur: (corps) =>
    request('/api/integration_outbound_endpoints', { method: 'POST', body: corps, ld: true }),
  majConnecteur: (id, corps) =>
    request(`/api/integration_outbound_endpoints/${id}`, { method: 'PATCH', body: corps }),
  supprimerConnecteur: (id) =>
    request(`/api/integration_outbound_endpoints/${id}`, { method: 'DELETE' }),
  // La liste des evenements auxquels on peut s'abonner. Servie par le serveur depuis les manifestes
  // de module, JAMAIS recopiee ici : une liste tenue a la main aurait diverge au premier module
  // ajoute, et une faute de frappe dans un nom ne produit AUCUNE erreur -- l'abonnement ne matche
  // simplement jamais, en silence.
  evenementsConnecteurs: () =>
    request('/api/integrations/evenements-disponibles'),
  reversementsOta: () =>
    request('/api/reversement_otas', { query: { itemsPerPage: 200 } }),
  // ⚠ LE MONTANT N'EST PAS FOURNI : le serveur le calcule sur les ventes de la periode. C'est ce
  // qui permet de le refaire a l'identique si le partenaire le conteste.
  // ⚠ `ld: true` PARCE QUE CETTE ROUTE DESERIALISE. Elle ne porte pas `input: false` :
  // elle lit le corps comme une operation standard et n'accepte donc que `application/ld+json`.
  // Sans le drapeau, 415 SYSTEMATIQUE — pas seulement dans certains cas. Le garde-fou n°11
  // l'a attrape avant la livraison, une heure apres qu'un pair l'a etendu aux routes sur
  // mesure.
  //
  // ⚠ NE PAS AJOUTER CE DRAPEAU PARTOUT : sur une operation qui ne deserialise pas, il est
  // INERTE, et l'ecrire fait croire que l'appel est verifie alors qu'il ne l'est pas.
  genererReversement: (corps) =>
    request('/api/musee/reversements/generer', { method: 'POST', body: corps, ld: true }),
  marquerReversementVerse: (id) =>
    request(`/api/musee/reversements/${id}/marquer-verse`, { method: 'POST', body: {} }),
  // `POST /boutique/vitrines` existe depuis le debut (droit `boutique.gerer_vitrine`) et n'etait
  // appele de nulle part : l'ecran savait renommer une vitrine, changer ses couleurs et lire ses
  // remboursements, mais un etablissement neuf n'avait aucun moyen d'en ouvrir une — donc aucune
  // boutique en ligne, donc aucune vente en ligne.
  //
  // ⚠ L'ETABLISSEMENT N'EST PAS DANS LE CORPS, ET IL NE FAUT PAS L'Y METTRE. Il est estampille par
  // `EstablishmentStampProcessor` depuis la session serveur. La vitrine est un point d'entree
  // PUBLIC en lecture : laisser l'appelant choisir son rattachement serait une faille, pas une
  // commodite. C'est aussi pourquoi l'entite ne porte PAS d'`Assert\NotNull` sur ce champ — la
  // validation s'execute avant l'estampillage et refusait une valeur que le serveur allait poser
  // lui-meme.
  creerVitrine: (corps) =>
    request('/api/boutique/vitrines', { method: 'POST', body: corps, ld: true }),
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
  // `POST /api/employes` existe depuis le debut (droit `personnel.gerer_employe`) et n'etait
  // appele de nulle part : l'ecran declarait des absences, emettait et revoquait des badges pour
  // des employes qu'aucun ecran ne savait creer.
  //
  // Contrat LU dans l'entite, pas sonde : `Employe` n'offre aucune operation de suppression, et
  // sonder par un corps vide y laisserait une trace definitive. `nom`, `prenom` et `poste` portent
  // `Assert\NotBlank` ; `typeContrat` est une enumeration ; le reste est facultatif.
  creerEmploye: (corps) =>
    request('/api/employes', { method: 'POST', body: corps, ld: true }),
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

  // ─── LE PLANNING, LA SORTIE, L'INCIDENT ──────────────────────────────────────────────────
  //
  // L'ecran savait declarer un employe, ses absences et emettre ses badges. Il ne savait ni le
  // PLANIFIER, ni le faire SORTIR, ni signaler un badge perdu. Quatre gestes servis, zero appel.
  //
  // ⚠ `POST /personnel/creneaux-travail` LIT LE CORPS BRUT (pas de `ld: true`), et son
  // `etablissement` est un UUID ou une IRI recoupe cote serveur. Contraintes COMPTEES dans
  // l'entite, pas survolees -- `libellePoste` NotBlank, `debut` et `fin` NotNull, `effectifRequis`
  // >= 1 -- plus un validateur de CLASSE, `TopologieTravailCoherente`, qui exige en outre
  // `fin > debut` et un etablissement resolu. C'est lui qui produirait le 422 surprenant.
  // L'AFFECTATION — le second geste que le planning annoncait ne pas faire.
  //
  // ⚠ LE SERVEUR REFUSE POUR CINQ RAISONS, et deux sont INVISIBLES a l'ecran : un conflit de
  // planning sur un AUTRE etablissement (RG-PERSO-04) — le cloisonnement le cache par
  // construction — et une absence validee (RG-PERSO-05). Les trois autres : creneau annule,
  // creneau sans fenetre horaire, qualification manquante (CA-5).
  //
  // On ne pre-filtre donc pas les employes : on propose tout le monde, et on affiche le refus du
  // serveur tel quel. Il nomme la regle mieux que n'importe quelle reformulation.
  affectationsTravail: () =>
    request('/api/affectation_travails', { query: { itemsPerPage: 300 } }),
  affecterEmploye: (corps) =>
    request('/api/personnel/affectations', { method: 'POST', body: corps }),
  annulerAffectationTravail: (id) =>
    request(`/api/personnel/affectations/${id}/annuler`, { method: 'POST', body: {} }),
  creneauxTravail: (params) =>
    request('/api/creneau_travails', { query: { itemsPerPage: 200, ...(params || {}) } }),
  creerCreneauTravail: (corps) =>
    request('/api/personnel/creneaux-travail', { method: 'POST', body: corps }),
  annulerCreneauTravail: (id) =>
    request(`/api/personnel/creneaux-travail/${id}/annuler`, { method: 'POST', body: {} }),

  // ⚠ SUSPENDRE UN EMPLOYE SUSPEND AUSSI SES BADGES. Le processeur le fait en cascade
  // (« Suspension de l'employe »), et la reactivation les remet. Ce n'est pas un detail : la
  // personne perd ses acces physiques a l'instant du clic. L'ecran le dit avant, pas apres.
  suspendreEmploye: (id) =>
    request(`/api/personnel/employes/${id}/suspendre`, { method: 'POST', body: {} }),
  reactiverEmploye: (id) =>
    request(`/api/personnel/employes/${id}/reactiver`, { method: 'POST', body: {} }),

  // Perte ou vol : le serveur exige un `motif` non vide, et lui seul est lu au corps.
  declarerIncidentBadge: (id, motif) =>
    request(`/api/personnel/badges/${id}/declarer-incident`, { method: 'POST', body: { motif } }),
  // LE REGISTRE, ET LE RETOUR EN ARRIERE. Declarer marchait ; lire et annuler n'etaient appeles par
  // personne.
  //
  // /!\ `annulee` EST UN DRAPEAU STOCKE sur la declaration, pas un etat derive du badge (mesure
  // dans `DeclarationIncidentBadgeProvider`). Reactiver un badge depuis la liste des badges ne
  // referme donc PAS son incident : le badge revient en service et sa declaration reste ouverte.
  // C'est `annuler` qui fait les deux -- elle delegue a `RevocationBadgeHandler::reactiver()`.
  declarationsIncidentBadge: () =>
    request('/api/personnel/declarations-incident', { query: { itemsPerPage: 200 } }),
  annulerDeclarationIncidentBadge: (id) =>
    request(`/api/personnel/declarations-incident/${id}/annuler`, { method: 'POST', body: {} }),

  // --- Verticales (routes explicites privilégiées) ---
  // Piscine
  bassins: () => request('/api/bassins', { query: { itemsPerPage: 100 } }),
  // `POST /api/bassins` existe depuis le debut, protege par `piscine.configurer`, et n'etait
  // appele d'aucun ecran : la piscine savait attribuer un casier, relancer un retard et forcer une
  // ouverture, mais pas declarer le bassin sur lequel tout cela porte.
  creerBassin: (corps) => request('/api/bassins', { method: 'POST', body: corps, ld: true }),
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
  // LA MOITIE AMONT DE RG-PISC-02, sans laquelle « valider » ne peut que refuser.
  //
  // ⚠ LA CREATION D'UNE QUALIFICATION RENDAIT 500 jusqu'au 05/09 : son etablissement est `NOT NULL`
  // et hors du groupe d'ecriture, et rien ne le posait. Corrige cote serveur en etendant
  // `App\Piscine\State\EstablishmentStampProcessor`, qui ne couvrait que `Casier`.
  qualificationsEncadrant: () =>
    request('/api/qualification_encadrants', { query: { itemsPerPage: 200 } }),
  creerQualificationEncadrant: (corps) =>
    request('/api/qualification_encadrants', { method: 'POST', body: corps, ld: true }),
  majQualificationEncadrant: (id, corps) =>
    request(`/api/qualification_encadrants/${id}`, { method: 'PATCH', body: corps }),
  // ⚠ AUCUN FILTRE DECLARE sur cette ressource : on ne peut pas demander les affectations d'un
  // creneau, il faut charger et regrouper. D48 interdit de PRETENDRE filtrer cote serveur.
  affectationsEncadrant: () =>
    request('/api/affectation_encadrants', { query: { itemsPerPage: 200 } }),
  affecterEncadrant: (corps) =>
    request('/api/affectation_encadrants', { method: 'POST', body: corps, ld: true }),
  retirerAffectationEncadrant: (id) =>
    request(`/api/affectation_encadrants/${id}`, { method: 'DELETE' }),
  // Patinoire
  patinoireConflits: () => request('/api/patinoire/conflits-glace'),
  patinoireLocations: () =>
    request('/api/patinoire_location_patins', { query: { itemsPerPage: 100 } }),
  patinoireAffutages: () =>
    request('/api/patinoire_affutages', { query: { itemsPerPage: 100 } }),
  // Le parc par pointure : c'est lui qui dit ce qui est louable, pas la liste des locations.
  patinoireParc: () =>
    request('/api/patinoire_parc_patins', { query: { itemsPerPage: 200 } }),
  // `POST /api/patinoire_parc_patins` existe depuis le début (droit `patinoire.configurer`) et
  // n'était appelé de nulle part : la patinoire sortait, rendait et affûtait des patins qu'aucun
  // écran ne savait déclarer.
  //
  // ⚠ CETTE ENTITÉ N'A AUCUNE CONTRAINTE ET AUCUNE SUPPRESSION. Un POST au corps vide rend 201 et
  // crée une pointure 28 à zéro paire — vérifié, et payé : un parc vide traîne en préproduction,
  // que `DELETE` refuse (405). D'où la validation faite ICI, dans le formulaire, faute d'en avoir
  // une côté serveur. Signalé pour le moteur.
  creerParcPatins: (corps) =>
    request('/api/patinoire_parc_patins', { method: 'POST', body: corps, ld: true }),
  patinoireListeAttente: () =>
    request('/api/patinoire_liste_attente_pointures', { query: { itemsPerPage: 100 } }),
  patinoireRetenues: () =>
    request('/api/patinoire_retenue_cautions', { query: { itemsPerPage: 100 } }),
  // LE BARÈME DE LA PATINOIRE : QUATRE OPÉRATIONS EXPOSÉES, AUCUNE ATTEIGNABLE.
  //
  // Le socle a son barème générique (`caution_grille_retenues`, branché dans l'écran Cautions), mais
  // la patinoire expose LE SIEN — même donnée vue par sa verticale, avec un motif en énumération
  // (casse, non rendu, perte, restitution partielle) et un parc de patins au lieu d'un `sousCible`
  // en texte libre.
  //
  // Ça change tout pour qui règle la retenue : sur l'écran central, il faut taper `patinoire.patins`
  // à la main dans un champ libre — une faute de frappe y crée un barème que rien n'applique, sans
  // erreur. Ici la cible est implicite et le motif se choisit dans une liste.
  patinoireGrillesRetenue: () =>
    request('/api/patinoire_grille_retenues', { query: { itemsPerPage: 100 } }),
  creerPatinoireGrilleRetenue: (corps) =>
    request('/api/patinoire_grille_retenues', { method: 'POST', body: corps, ld: true }),
  majPatinoireGrilleRetenue: (id, corps) =>
    request(`/api/patinoire_grille_retenues/${id}`, { method: 'PATCH', body: corps }),
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
  // LES TRANSFERTS ENTRE SITES — trois routes, aucun ecran jusqu'ici.
  //
  // ⚠ LE CLOISONNEMENT DU TRANSFERT EST UN « OU » : source OU destination = etablissement actif.
  // On voit donc ses transferts DANS LES DEUX SENS, alors que les articles, eux, sont limites a
  // l'etablissement actif. Un seul des deux articles d'un transfert est donc lisible — celui qui
  // est chez soi — et c'est ce qui donne la direction.
  stockTransferts: () =>
    request('/api/stock_transferts', { query: { itemsPerPage: 200, 'order[dateDemande]': 'desc' } }),
  // ⚠ 409 SI L'ETAT NE S'Y PRETE PAS : `expedier` exige `demande`, `recevoir` exige `expedie`.
  // Et le serveur restreint l'un a l'etablissement SOURCE, l'autre a la DESTINATION (RG-STOCK-14).
  expedierTransfertStock: (id) =>
    request(`/api/stock/transferts/${id}/expedier`, { method: 'POST', body: {} }),
  recevoirTransfertStock: (id) =>
    request(`/api/stock/transferts/${id}/recevoir`, { method: 'POST', body: {} }),
  stockLots: () => request('/api/stock_lots', { query: { itemsPerPage: 500 } }),
  stockMouvements: () =>
    request('/api/stock_mouvements', { query: { itemsPerPage: 50, 'order[date]': 'desc' } }),
  stockParametrage: () => request('/api/stock_parametrages', { query: { itemsPerPage: 5 } }),
  // LES RETOURS CLIENTS — le geste que le serveur attend d'un humain, sans bouton jusqu'ici.
  //
  // ⚠ PAS D'`order[...]` ICI : `Avoir` ne declare AUCUN filtre. Un parametre d'ordre serait ignore
  // en silence et la liste aurait l'air triee. Le tri se fait dans le composant, qui le sait.
  avoirs: () => request('/api/avoirs', { query: { itemsPerPage: 200 } }),
  // Sert a savoir ce qui a DEJA ete reintegre : le mouvement genere porte `referenceType: 'Avoir'`
  // et `referenceId`. `type` est l'un des trois seuls filtres declares sur `MouvementStock` — il
  // n'y en a aucun sur la reference, d'ou la lecture large et le controle de troncature.
  stockAjustementsPositifs: () =>
    request('/api/stock_mouvements', { query: { type: 'ajustement_positif', itemsPerPage: 500 } }),
  // ⚠ RIEN N'EMPECHE DE LA REJOUER : le processeur verifie le perimetre et les champs, pas
  // l'unicite. Deux appels font entrer la marchandise deux fois, sans erreur.
  reintegrerRetour: (corps) =>
    request('/api/stock/mouvements/reintegration-retour', { method: 'POST', body: corps }),
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
    request(`/api/stock/articles/${id}/rattacher-produit`, { method: 'POST', body: { produit }, ld: true }),
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
  // ⚠ `/api/padel/terrains` N'EXISTE QU'EN POST — c'est la création d'un terrain.
  //
  // La LECTURE de la collection est `/api/padel_terrains`, la route standard d'API Platform. Le
  // client faisait un GET sur la route de création : le routeur répond **405**, et un 405 sorti du
  // routeur rend une page HTML, pas du JSON. L'écran Padel affichait donc « <!DOCTYPE html>… » dans
  // son bandeau d'erreur et ne listait AUCUN terrain — alors qu'il y en a.
  //
  // Deux routes qui se ressemblent (`/padel/terrains` et `/padel_terrains`), l'une en écriture et
  // l'autre en lecture : c'est le genre de confusion que seul un appel réel révèle.
  padelTerrains: () => request('/api/padel_terrains', { query: { itemsPerPage: 100 } }),
  // ⚠ LA CREATION N'EST PAS SUR LA COLLECTION : elle porte un `uriTemplate` a elle,
  // `/padel/terrains`. Un POST sur `/api/padel_terrains` rend 405 — mesure du 30/08, faite avant
  // d'ecrire cette ligne.
  //
  // `CreerTerrainProcessor` cascade la `Ressource` du socle (`codeType='terrain_padel'`) puis pose
  // l'overlay padel : on ne cree donc PAS la ressource ici, et il ne faut pas le faire — deux
  // ressources pour un terrain, et le planning ne saurait plus laquelle reserver.
  // Corps : { libelle, type: 'indoor'|'outdoor', dureesAutoriseesMinutes?: [60, 90] }
  creerTerrainPadel: (corps) =>
    request('/api/padel/terrains', { method: 'POST', body: corps, ld: true }),
  padelReservations: () =>
    request('/api/padel_reservations', { query: { itemsPerPage: 200 } }),
  padelLocationsMateriel: () =>
    request('/api/padel_location_materiels', { query: { itemsPerPage: 200 } }),
  // Operations sur mesure : `input: false`, pas de `ld: true`.
  padelReserverTerrain: (id, corps) =>
    request(`/api/padel/terrains/${id}/reservations`, { method: 'POST', body: corps }),
  padelRejoindrePartie: (id, corps) =>
    request(`/api/padel/parties-ouvertes/${id}/rejoindre`, { method: 'POST', body: corps }),
  // SORTIR DU MATERIEL — l'ecran savait le rendre, pas le louer.
  //
  // ⚠ `article` EST UNE REFERENCE AU CATALOGUE (M1), en uuid nu — pas une IRI, pas une entite de
  // stock. Le processeur le nomme ainsi : « champ article obligatoire (reference catalogue M1) ».
  // `quantite` doit valoir au moins 1, et `caution` est facultative (consignation deleguee au
  // service de caution generique).
  padelLouerMateriel: (corps) =>
    request('/api/padel/locations', { method: 'POST', body: corps, ld: true }),
  padelRetournerMateriel: (id, corps) =>
    request(`/api/padel/locations/${id}/retour`, { method: 'POST', body: corps }),
  // `padel.acces_forcer` : passer outre l'automatisme d'eclairage. Motif obligatoire.
  padelEclairageManuel: (id, corps) =>
    request(`/api/padel/terrains/${id}/eclairage/repli-manuel`, { method: 'POST', body: corps }),
  // ⚠ CETTE FONCTION NE DEMANDAIT RIEN ET RÉPONDAIT « AUCUN TOURNOI ».
  //
  // Elle rendait `Promise.resolve({ 'hydra:member': [] })`, justifiée par un commentaire qui
  // affirmait qu'aucune collection n'existait — « seulement des routes custom
  // /api/padel/tournois/{id}/... ». C'était faux : le document OpenAPI que l'API sert publie
  // `GET, POST /api/padel_tournois`, et la collection répond 200 avec un tournoi en base sur la
  // préproduction. L'écran qui l'aurait appelée aurait donc affirmé une absence en contradiction
  // avec les données, sans qu'aucune requête n'ait eu lieu.
  //
  // Le commentaire était le cœur du défaut : il ne décrivait pas un choix, il posait un fait — et
  // un fait faux ferme la question pour tous ceux qui le lisent ensuite. Réfutable en une commande :
  //
  //     curl -H "Authorization: Bearer <jeton>" .../api/padel_tournois
  padelTournois: () => request('/api/padel_tournois', { query: { itemsPerPage: 100 } }),
  // Musée
  museeExpositions: () => request('/api/musee_expositions', { query: { itemsPerPage: 100 } }),
  museeVisitesGuidees: () =>
    request('/api/musee_visite_guidees', { query: { itemsPerPage: 100 } }),
  museeSalles: () => request('/api/musee_salles', { query: { itemsPerPage: 100 } }),
  // `POST /api/musee_salles` existe depuis le debut (droit `musee.configurer`) et n'etait appele
  // de nulle part : l'ecran comptait les presents salle par salle sans savoir declarer une salle.
  // Contrat sonde : un corps vide rend 422 et n'ecrit rien -- `nom` non vide et `espace` requis.
  creerSalleMusee: (corps) =>
    request('/api/musee_salles', { method: 'POST', body: corps, ld: true }),
  museeGuides: () => request('/api/musee_guides', { query: { itemsPerPage: 100 } }),
  // LES AUDIOGUIDES ET LEURS LANGUES. Quatre routes servies depuis l'origine, aucune appelee.
  //
  // /!\ LE CROISEMENT EST L'INTERET : une langue que l'audioguide propose et qu'AUCUN guide ne
  // parle est une bascule CERTAINE -- chaque visite demandee dans cette langue partira en repli,
  // avec sa remise. C'est une lacune de recrutement, pas un alea d'agenda.
  museeAudioguides: () =>
    request('/api/musee_audioguides', { query: { itemsPerPage: 100 } }),
  museeCreerAudioguide: (corps) =>
    request('/api/musee_audioguides', { method: 'POST', body: corps, ld: true }),
  museeMajAudioguide: (id, corps) =>
    request(`/api/musee_audioguides/${id}`, { method: 'PATCH', body: corps }),
  museeQualificationsLangue: () =>
    request('/api/musee_qualification_langue_guides', { query: { itemsPerPage: 200 } }),
  museeBasculesAudioguide: () =>
    request('/api/musee_bascule_audioguides', { query: { itemsPerPage: 100 } }),
  museeContingentsGratuite: () =>
    request('/api/musee_contingent_gratuites', { query: { itemsPerPage: 50 } }),
  museeDossiersGroupe: () =>
    request('/api/musee_dossier_groupe_scolaires', { query: { itemsPerPage: 100 } }),
  // L'etat d'une salle se lit salle par salle : il n'existe pas de vue d'ensemble cote serveur.
  museeEtatSalle: (id) => request(`/api/musee/salles/${id}/etat`),
  // ⚠ CE COMMENTAIRE DISAIT L'INVERSE, ET LES DEUX CRÉATIONS CI-DESSOUS RENDAIENT 415.
  //
  // Il annonçait « `input: false`, pas de `ld: true` » — vrai des confirmations, faux des
  // deux créations, qui désérialisent leur corps. Une règle écrite pour un groupe d'appels
  // et appliquée au voisin : les créations de visite guidée et de dossier de groupe n'ont
  // jamais abouti.
  //
  // Les confirmations, elles, ne désérialisent pas : le drapeau y serait inerte.
  museeCreerVisite: (corps) =>
    request('/api/musee/visites-guidees', { method: 'POST', body: corps, ld: true }),
  museeConfirmerVisite: (id) =>
    request(`/api/musee/visites-guidees/${id}/confirmer`, { method: 'POST', body: {} }),
  museeCreerDossierGroupe: (corps) =>
    request('/api/musee/dossiers-groupe', { method: 'POST', body: corps, ld: true }),
  museeConfirmerDossierGroupe: (id, corps) =>
    request(`/api/musee/dossiers-groupe/${id}/confirmer`, { method: 'POST', body: corps }),

  // Administration de l'éditeur (ED-6). Le serveur répond 404 si la session n'est pas celle de
  // l'éditeur : le contrôle est une identité de tenant, pas une permission, et il n'est pas rejoué
  // ici (D39).
  editorSubscriptions: () => request('/api/editor/subscriptions'),

  // Catalogue d'offres, côté administration éditeur (ED-6). Ces routes rendent AUSSI ce que la
  // vitrine cache — formules retirées de la vente, formules incohérentes — parce que c'est le seul
  // écran où on peut les corriger.

  // ── Site vitrine de l'editeur : blog et page d'accueil (ED-10) ───────────────────────────────
  //
  // ⚠ CES APPELS SONT GARDES PAR L'ETABLISSEMENT ACTIF, PAS PAR CET OBJET. `/editor/website/**`
  // repond 404 a une session qui n'est pas celle de l'editeur — l'ecran n'a donc aucune regle de
  // droits a rejouer, et c'est D39 dans sa forme la plus sure : ne pas filtrer du tout plutot que
  // filtrer a moitie.
  editorArticles: () => request('/api/editor/website/posts'),
  editorArticle: (id) => request(`/api/editor/website/posts/${id}`),
  // ⚠ `ld: true` : ces ressources n'exposent que `application/ld+json`. Sans lui, la creation
  // part en `application/json` et le back repond 415 — et RIEN ne l'avait dit :
  // `verifier-formats.mjs` saute deliberement les routes a `uriTemplate` sur mesure, parce qu'il
  // ne peut pas savoir lesquelles ont `input: false` (ou ld+json est au contraire interdit).
  // Trouve le 04/09 en ouvrant l'ecran : le formulaire affichait le message d'API Platform, en
  // anglais, a la place de l'article qu'on venait d'ecrire.
  creerEditorArticle: (corps) => request('/api/editor/website/posts', { method: 'POST', body: corps, ld: true }),
  majEditorArticle: (id, corps) => request(`/api/editor/website/posts/${id}`, { method: 'PATCH', body: corps }),
  supprimerEditorArticle: (id) => request(`/api/editor/website/posts/${id}`, { method: 'DELETE' }),

  editorRubriques: () => request('/api/editor/website/categories'),
  creerEditorRubrique: (corps) => request('/api/editor/website/categories', { method: 'POST', body: corps, ld: true }),
  majEditorRubrique: (id, corps) => request(`/api/editor/website/categories/${id}`, { method: 'PATCH', body: corps }),
  supprimerEditorRubrique: (id) => request(`/api/editor/website/categories/${id}`, { method: 'DELETE' }),

  editorBlocs: () => request('/api/editor/website/blocks'),
  editorBloc: (cle) => request(`/api/editor/website/blocks/${encodeURIComponent(cle)}`),
  // PUT et non PATCH : un bloc n'a qu'une valeur, et elle se remplace en entier. Une fusion
  // partielle sur une liste de cartes demanderait une semantique d'index que personne n'a demandee.
  enregistrerEditorBloc: (cle, corps) =>
    request(`/api/editor/website/blocks/${encodeURIComponent(cle)}`, { method: 'PUT', body: corps, ld: true }),

  editorPlans: () => request('/api/editor/catalog/plans'),
  // ⚠ `ld: true` OBLIGATOIRE : cette opération désérialise le corps, et n'accepte donc que
  // `application/ld+json`. Sans le drapeau, la requête part en `application/json` et le serveur
  // répond 415 — toujours, pour tout le monde, depuis l'écriture de l'appel.
  //
  // CE QUE CE DÉFAUT A COÛTÉ, ET POURQUOI IL A TENU SI LONGTEMPS.
  //
  // L'écran « Offres » n'a jamais pu créer une formule. `subscription_plan` est donc resté vide,
  // et le tunnel de souscription du site vitrine refusait toute composition — Maxime l'a signalé
  // comme « le souscrire n'est pas actif », à trois écrans de sa cause.
  //
  // `verifier-formats.mjs` sautait alors EN BLOC les routes à `uriTemplate` sur mesure, faute de
  // savoir lesquelles désérialisent : c'était le seul endroit du client que rien ne surveillait,
  // et sept écritures y étaient cassées. Le contrôle lit désormais chaque déclaration et couvre
  // ces routes — vérifié en confrontant son verdict à celui du registre d'API Platform.
  creerEditorPlan: (corps) => request('/api/editor/catalog/plans', { method: 'POST', body: corps, ld: true }),
  majEditorPlan: (id, corps) => request(`/api/editor/catalog/plans/${id}`, { method: 'PATCH', body: corps }),
  supprimerEditorPlan: (id) => request(`/api/editor/catalog/plans/${id}`, { method: 'DELETE' }),

  editorOptions: () => request('/api/editor/catalog/options'),
  creerEditorOption: (corps) => request('/api/editor/catalog/options', { method: 'POST', body: corps, ld: true }),
  majEditorOption: (id, corps) => request(`/api/editor/catalog/options/${id}`, { method: 'PATCH', body: corps }),
  supprimerEditorOption: (id) => request(`/api/editor/catalog/options/${id}`, { method: 'DELETE' }),

  // Fiche client de l'éditeur (ED-6). La collection ne rend QUE les clients du CRM de l'éditeur :
  // les clients finaux des exploitants vivent dans la même table et n'ont rien à faire ici.
  editorCustomers: () => request('/api/editor/customers'),

  // ── ACCES D'ASSISTANCE ────────────────────────────────────────────────────────────────────────
  //
  // Les accès encore ouverts, tous agents confondus. Sert au bandeau — un accès qu'on ne voit nulle
  // part est un accès que personne ne referme.
  editorSupportAccesses: () => request('/api/editor/support-accesses'),

  // Ouvrir un accès. Corps : { granteeId, establishmentId, reason, hours? }
  //
  // ⚠ `input: false` côté serveur : le processeur lit le corps lui-même, donc JSON simple et
  // surtout PAS de `ld: true` — une écriture en ld+json sur une opération sans input part et ne
  // pose rien, sans erreur.
  //
  // `hours` est plafonné à 8 par le serveur, et vaut 2 par défaut. Le motif est obligatoire et
  // sera lu par le client s'il le demande : ce n'est pas un champ de formulaire, c'est la trace.
  ouvrirAccesAssistance: (corps) =>
    request('/api/editor/support-accesses', { method: 'POST', body: corps }),

  // Refermer avant le terme. L'entrée reste : c'est l'historique de qui a pu voir quoi.
  revoquerAccesAssistance: (id) =>
    request(`/api/editor/support-accesses/${id}/revoke`, { method: 'POST', body: {} }),
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
  // LES PLAFONDS ÉTAIENT LISIBLES ET PAS MODIFIABLES, comme la règle de recouvrement ce matin.
  //
  // `POST`, `PATCH` et `DELETE` sur `/api/limite_autorisations` existent depuis le début, protégés
  // par `autorisation.gerer`, et aucun écran ne les appelait — le pied de page de l'écran le disait
  // même en toutes lettres : « la création et la modification passent encore par l'API ».
  //
  // Or un plafond REFUSE des opérations au guichet. Le voir sans pouvoir le corriger, c'est
  // constater un blocage et devoir appeler quelqu'un pour le lever.
  creerLimiteAutorisation: (corps) =>
    request('/api/limite_autorisations', { method: 'POST', body: corps, ld: true }),
  majLimiteAutorisation: (id, corps) =>
    request(`/api/limite_autorisations/${id}`, { method: 'PATCH', body: corps }),
  supprimerLimiteAutorisation: (id) =>
    request(`/api/limite_autorisations/${id}`, { method: 'DELETE' }),

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

  // ── AGENDA ────────────────────────────────────────────────────────────────────────────────────
  //
  // UN SEUL APPEL POUR TOUT CE QUI OCCUPE L'INTERVALLE, et c'est délibéré. L'agenda agrège des
  // créneaux de réservation, des créneaux de travail, des plages d'ouverture et des événements
  // saisis à la main. Un appel par source, assemblé ici, ferait dépendre l'affichage de l'ordre
  // d'arrivée des réponses et obligerait chaque écran à réécrire la règle de tri.
  journalAgenda: (du, au, scope = 'site') =>
    request('/api/calendar/feed', { query: { du, au, scope } }),
  // `duSite` n'est pas une propriété de l'entité : c'est une INTENTION, lue par le serveur pour
  // décider si l'événement appartient au site ou à son auteur. Elle ne se relit pas telle quelle.
  creerEvenementAgenda: (corps) =>
    request('/api/calendar/calendar_events', { method: 'POST', body: corps, ld: true }),
  supprimerEvenementAgenda: (id) =>
    request(`/api/calendar/calendar_events/${id}`, { method: 'DELETE' }),
  abonnementIcs: () => request('/api/calendar/ics-subscription'),
  regenererIcs: () => request('/api/calendar/ics-subscription/regenerate', { method: 'POST', body: {} }),

  // ── PLANNING D'OUVERTURE ──────────────────────────────────────────────────────────────────────
  //
  // `planningOuverture` rend les fenêtres RÉSOLUES — exceptions appliquées, traversée de minuit
  // comprise, fuseau de l'établissement compris. Les tranches brutes (`plagesOuverture`) ne servent
  // qu'à l'écran de SAISIE : les résoudre ici, côté client, ferait dessiner une ouverture pendant
  // laquelle la porte refuse.
  planningOuverture: (du, au) => request('/api/opening/schedule', { query: { du, au } }),
  plagesOuverture: () => request('/api/opening/opening_slots', { query: { itemsPerPage: 200 } }),
  creerPlageOuverture: (corps) =>
    request('/api/opening/opening_slots', { method: 'POST', body: corps, ld: true }),
  supprimerPlageOuverture: (id) =>
    request(`/api/opening/opening_slots/${id}`, { method: 'DELETE' }),
  exceptionsOuverture: () =>
    request('/api/opening/opening_exceptions', { query: { itemsPerPage: 200 } }),
  creerExceptionOuverture: (corps) =>
    request('/api/opening/opening_exceptions', { method: 'POST', body: corps, ld: true }),
  supprimerExceptionOuverture: (id) =>
    request(`/api/opening/opening_exceptions/${id}`, { method: 'DELETE' }),
  reglageOuverture: () => request('/api/opening/opening_settings'),
  // UN CORPS, PAS UN BOOLEEN. Le reglage porte trois champs — appliquer, zone scolaire, droit local
  // d'Alsace-Moselle — et il en portera d'autres. Une fonction par champ, c'est une fonction qu'on
  // oubliera d'ajouter.
  majReglageOuverture: (id, corps) =>
    request(`/api/opening/opening_settings/${id}`, { method: 'PATCH', body: corps }),

  // CE QUE LE CALENDRIER SAIT SANS QU'ON LE SAISISSE.
  //
  // Deux natures dans une seule reponse, et elles ne se melangent pas : les JOURS FERIES sont des
  // propositions de fermeture (l'exploitant coche), les VACANCES SCOLAIRES sont un fond de
  // calendrier qui ne ferme rien. `schoolHolidaysAvailable` distingue « pas de vacances » de
  // « le ministere n'a pas repondu » — deux phrases differentes a l'ecran.
  indicesOuverture: (from, to) => request('/api/opening/calendar-hints', { query: { from, to } }),
}
