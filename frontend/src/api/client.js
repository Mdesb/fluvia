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

  // @route-a-venir: ouverte par allaccess-8e dans le meme lot, contrat convenu et arrete ensemble.
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
  // ⚠ DEUX ROUTES DECLAREES QUE JE NE BRANCHE PAS, ET CHACUNE POUR SA RAISON.
  //
  // `/factures/verifier-chaine` REPOND 404. Declaree en `GetCollection` avec ce `uriTemplate`, elle
  // est captee par l'operation d'item `/factures/{id}` qui lit << verifier-chaine >> comme un
  // identifiant : le serveur repond << Invalid uri variables >>. Mesure contre la preprod le 29/08 :
  //     /api/factures?itemsPerPage=1   200
  //     /api/factures/verifier-chaine  404
  //     /api/mes-factures              200
  // Le bouton etait ecrit ; je l'ai retire plutot que d'en livrer un qui echoue. Signale au serveur.
  // C'est la meme famille que le GET du padel sur une route POST : << la route existe >> ne veut pas
  // dire << elle repond >>.
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
  // ⚠ LA ROUTE ETAIT AU SINGULIER, ET ELLE RENDAIT 404 DEPUIS TOUJOURS.
  //
  // Mesure du 30/08 : `/api/abonnement_fitness` -> 404, `/api/abonnement_fitnesses` -> 200. Le
  // pluriel est celui qu'API Platform derive du `shortName: 'AbonnementFitness'`. L'ecran Sport
  // avalait l'echec (`.catch(() => null)`) et affichait « Aucun abonnement fitness » — un vide qui
  // ressemblait a une absence de donnees et qui etait une adresse fausse.
  abonnementsFitness: () => request('/api/abonnement_fitnesses', { query: { itemsPerPage: 200 } }),
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
  // Référentiel des indicateurs (M7).
  indicateurs: () => request('/api/indicateurs', { query: { itemsPerPage: 100 } }),

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
  // LES CORRESPONDANCES COMPTABLES : quelle catégorie s'impute sur quel compte de produit.
  // Exposées depuis le début, appelées par aucun écran. Sans elles, impossible de dire à
  // l'exploitant si la catégorie qu'il choisit sur une ligne de facture change quoi que ce soit —
  // et `ResolveurComptesFacturation` se replie silencieusement sur le compte par défaut.
  mappingsComptables: () => request('/api/mapping_comptables', { query: { itemsPerPage: 200 } }),
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
  annulerFactureFournisseur: (id) =>
    request(`/api/finance/supplier-invoices/${id}/cancel`, { method: 'POST', body: {} }),

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
