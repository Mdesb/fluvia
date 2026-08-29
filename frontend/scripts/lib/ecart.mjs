// LE CALCUL DE L'ÉCART CLIENT/SERVEUR — écrit une fois, appelé par deux.
//
// POURQUOI CE FICHIER EXISTE.
//
// `mesurer-ecart.mjs` rendait un chiffre ; `garde-fou-ecart.mjs` doit refuser un commit sur le même
// chiffre. Recopier la méthode dans les deux, c'est se garantir qu'un jour la mesure affichée et la
// mesure contrôlée divergeront — et la première qu'on croira sera la mauvaise. Dans ce dépôt, trois
// listes redites se sont déjà désynchronisées, chaque fois attrapées par un filet et jamais par une
// relecture.
//
//   > Un seul calcul, deux appelants.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────────
// IL Y A TROIS FRONTS, ET LA MESURE N'EN VOYAIT QU'UN.
//
// Jusqu'au 27/08, ce calcul lisait `api/client.js` et lui seul. Or la boutique en ligne a son PROPRE
// client — `public/api/boutiqueClient.js`, vingt-quatre appels : catalogue, panier, paiement,
// billets, documents légaux. Toutes ces opérations étaient comptées « qu'aucun utilisateur ne peut
// déclencher », alors qu'elles sont exactement ce qu'un client final déclenche en achetant.
//
//   > Une mesure qui ignore un front entier ne se trompe pas un peu : elle compte comme absent ce
//   > qui marche.
//
// Le défaut ne se voyait pas parce que le chiffre restait plausible — 27 % d'API atteignable est
// aussi crédible que 29 %. C'est la même famille que les fuites de cloisonnement : pas d'erreur, des
// lignes en trop, et rien qui alerte.
//
// L'éditeur (`editeur/`) n'était pas concerné : il importe `api/client.js`, déjà lu.
// ─────────────────────────────────────────────────────────────────────────────────────────────────
//
// LA MÉTHODE, ÉCRITE POUR ÊTRE CONTESTABLE :
//
//   exposées      — chaque `new Get(`, `new GetCollection(`, `new Post(`, `new Put(`, `new Patch(`,
//                   `new Delete(` trouvé dans `app/src`. Une occurrence = une opération déclarée.
//   sans écran    — les opérations des fichiers portant le marqueur `@sans-ecran:` : déclarées
//                   volontairement inatteignables, avec leur raison. Retirées du dénominateur.
//   appelées      — chaque chemin `/api/...` distinct trouvé dans l'un des clients, interpolations
//                   ramenées à `{id}`, comptées une fois par méthode HTTP employée.
//   atteignables  — les mêmes, en ne gardant que les helpers réellement référencés par un écran DU
//                   MÊME FRONT. Un helper de la boutique référencé par le nom d'une fonction du
//                   back-office ne compte pas : les deux applications ne partagent pas de portée.
//
// UN APPEL VERS UNE ROUTE QUE PERSONNE NE DÉCLARE NE RENDAIT PAS L'API PLUS ATTEIGNABLE — ET LE
// CHIFFRE DISAIT LE CONTRAIRE.
//
// `atteignables` comptait tout appel client porté par un helper qu'un écran référence, SANS vérifier
// que le serveur déclare quoi que ce soit au bout. Trois helpers écrits le 29/08 vers des opérations
// pas encore ouvertes ont fait baisser l'écart de trois — et le cliquet proposait de geler dessus.
//
//   > Un compteur de couverture qui se laisse baisser par du vide mesure l'intention, pas la
//   > couverture. C'est la famille de mensonges que ce dépôt traque partout ailleurs.
//
// Pire : le sens était inversé. Un frontal qui appelle une route inexistante est un défaut — la
// mesure en faisait un progrès. Ces appels sont donc retirés du compte ET signalés à part, où ils
// FONT ÉCHOUER le garde-fou : c'est le seul cas de ce fichier qui n'admet aucune dette gelée, parce
// qu'il n'y en avait aucun le jour où le contrôle a été écrit.
//
// COMMENT ON SAIT QU'UNE ROUTE EXISTE, SANS BOOTER SYMFONY. Deux preuves, l'une exacte, l'autre
// nommée :
//   — un `uriTemplate:` littéral qui porte le chemin — exact ;
//   — le nom de la ressource retrouvé dans le segment du chemin (`sous_reseaus` → `SousReseau`,
//     `product_access_zones` → `ProductAccessZone`, `opportunities` → `Opportunity`) — parce
//     qu'API Platform dérive le chemin par défaut du nom, et qu'on ne peut pas le recalculer ici.
//     Les deux premiers segments sont essayés : une ressource peut porter un préfixe de route
//     (`/api/opening/opening_slots`).
//
// Mesuré avant d'être posé, sur les 374 appels du produit : ZÉRO faux positif. Et éprouvé en
// fabriquant le cas — un appel vers `/api/zzz_inexistants` est bien signalé.
//
// CE QUE LA MESURE NE VOIT TOUJOURS PAS :
//
// Elle compte des opérations, pas des signaux. Un champ calculé que le serveur publie et qu'aucun
// écran n'affiche vit sur une opération déjà branchée : invisible ici, alors qu'il manque à
// l'exploitant. C'est `mesurer-signaux-muets.mjs` qui le voit.
//
// Et elle ne dit pas si une liste affichée mène quelque part. Une liste d'alertes sans le geste qui
// la vide compte comme branchée, et n'est pourtant que du décor.

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, dirname, relative, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

const ICI = dirname(fileURLToPath(import.meta.url))
export const RACINE = join(ICI, '..', '..', '..')

const SRC = join(RACINE, 'frontend', 'src')

// LES FRONTS, DÉCLARÉS PLUTÔT QUE DEVINÉS.
//
// Chacun a son fichier d'appels et son périmètre d'écrans. Ajouter une application au produit sans
// l'ajouter ici la ferait compter comme inexistante — d'où le contrôle de complétude plus bas, qui
// refuse tout fichier `*Client.js` ou `client.js` qu'aucun front ne réclame.
const FRONTS = [
  {
    nom: 'back-office',
    client: join(SRC, 'api', 'client.js'),
    // Tout le front SAUF la boutique publique : le back-office et l'éditeur partagent `client.js`.
    ecrans: (chemin) => !chemin.startsWith(join(SRC, 'public') + sep),
  },
  {
    nom: 'boutique en ligne',
    client: join(SRC, 'public', 'api', 'boutiqueClient.js'),
    ecrans: (chemin) => chemin.startsWith(join(SRC, 'public') + sep),
  },
]

const OPERATIONS = [
  'new Get(',
  'new GetCollection(',
  'new Post(',
  'new Put(',
  'new Patch(',
  'new Delete(',
]

// LE MARQUEUR QUI DÉCLARE UNE SURFACE VOLONTAIREMENT SANS ÉCRAN.
//
// Il doit être suivi d'une raison sur la même ligne. Un marqueur nu autoriserait à taire n'importe
// quoi en trois caractères — et six mois plus tard, personne ne saurait plus si l'absence d'écran
// était un choix ou un oubli. C'est la même exigence que `@cloisonnement-verifie:`.
const MARQUEUR = '@sans-ecran:'

// LE MARQUEUR SYMÉTRIQUE, CÔTÉ CLIENT — ET POURQUOI IL EXISTE.
//
// Le contrôle ci-dessous refuse un appel vers une route que rien ne déclare. Mais le dépôt travaille
// souvent dans l'autre sens : l'écran d'abord, les opérations serveur ensuite, pour ne pas ouvrir
// des opérations que personne n'appelle — c'est la règle que le cliquet impose par ailleurs. Sans
// échappatoire, ces deux règles se contredisent et la seconde gagne toujours.
//
// Un helper peut donc annoncer qu'il précède son opération, avec sa raison sur la même ligne :
//
//     // @route-a-venir: opérations ouvertes par claude-A dans le même lot, contrat convenu.
//     zonesProduit: (ref) => request('/api/product_access_zones', { query: { productRef: ref } }),
//
// Il reste NON COMPTÉ comme atteignable — il ne rend rien atteignable tant que la route n'existe
// pas. Le marqueur dit « c'est voulu et voici pourquoi », il ne dit pas « c'est branché ». Un
// marqueur nu, sans raison, ne vaut rien et échoue comme s'il était absent : même exigence que
// `@sans-ecran:`.
const MARQUEUR_ROUTE_A_VENIR = '@route-a-venir:'

function fichiersPhp(repertoire) {
  const trouves = []
  for (const entree of readdirSync(repertoire)) {
    const chemin = join(repertoire, entree)
    if (statSync(chemin).isDirectory()) trouves.push(...fichiersPhp(chemin))
    else if (entree.endsWith('.php')) trouves.push(chemin)
  }
  return trouves
}

function fichiersJs(repertoire) {
  const trouves = []
  for (const entree of readdirSync(repertoire)) {
    const chemin = join(repertoire, entree)
    if (statSync(chemin).isDirectory()) trouves.push(...fichiersJs(chemin))
    else if (/\.jsx?$/.test(entree)) trouves.push(chemin)
  }
  return trouves
}

function compterOccurrences(texte, aiguille) {
  let n = 0
  let i = texte.indexOf(aiguille)
  while (i !== -1) {
    n += 1
    i = texte.indexOf(aiguille, i + aiguille.length)
  }
  return n
}

// Littéraux, jamais construits : une expression régulière assemblée dans un gabarit m'a déjà fait
// écrire `\s` qui devient `s`, et le contrôle passait au vert en ne vérifiant rien.
const APPEL = /request\(\s*[`'"]([^`'"]+)[`'"]([^\n]*)/g
const INTERPOLATION = /\$\{[^}]*\}/g
const METHODE = /method:\s*'([A-Z]+)'/
const CLE = /^ {2}([a-zA-Z][a-zA-Z0-9]*):\s/gm

function referenceDans(texte, nom) {
  let i = texte.indexOf(nom)
  while (i !== -1) {
    const avant = texte[i - 1] || ' '
    const apres = texte[i + nom.length] || ' '
    if (!/[A-Za-z0-9_$]/.test(avant) && !/[A-Za-z0-9_$]/.test(apres)) return true
    i = texte.indexOf(nom, i + 1)
  }
  return false
}

// Les clés qu'un segment de chemin peut désigner. L'inflecteur d'API Platform met au pluriel ;
// on défait les trois formes qu'il produit dans ce dépôt.
function clesDeSegment(segment) {
  const nu = (segment || '').replace(/_/g, '')
  const cles = new Set([nu])
  if (nu.endsWith('s')) cles.add(nu.slice(0, -1))
  if (nu.endsWith('es')) cles.add(nu.slice(0, -2))
  if (nu.endsWith('ies')) cles.add(nu.slice(0, -3) + 'y')
  return cles
}

// L'annonce ne vaut que dans le bloc de commentaires CONTIGU au-dessus du helper. Une fenêtre de
// N caractères aurait fait déteindre le marqueur d'un helper sur son voisin — un contrôle qui se
// trompe de propriétaire est pire qu'un contrôle absent.
function annonceRouteAVenir(source, indexCle) {
  const lignes = source.slice(0, indexCle).split('\n')
  const bloc = []
  let i = lignes.length - 1
  // ⚠ La tranche s'arrête au DÉBUT de la ligne du helper : son dernier élément est donc une chaîne
  // vide, et un `while` qui exige un commentaire s'arrêtait dessus sans avoir rien lu. Le marqueur
  // n'était jamais trouvé — les deux cas d'essai, l'exemption et la péremption, tombaient à faux.
  while (i >= 0 && lignes[i].trim() === '') i -= 1
  while (i >= 0 && /^\s*\/\//.test(lignes[i])) {
    bloc.unshift(lignes[i])
    i -= 1
  }
  return /@route-a-venir:[ \t]*(\S[^\n]*)/.exec(bloc.join('\n'))
}

function adosseAuServeur(chemin, gabarits, noms) {
  const sansApi = chemin.replace(/^\/api/, '')
  if (gabarits.has(sansApi)) return true
  const segments = sansApi.split('/').filter(Boolean)
  const cles = new Set([...clesDeSegment(segments[0]), ...clesDeSegment(segments[1])])
  return [...cles].some((k) => k !== '' && noms.has(k))
}

/**
 * @returns {{
 *   exposees: number, sansEcran: number, attendues: number,
 *   appelees: number, atteignables: number, inatteignables: number,
 *   orphelins: string[], appelsSansServeur: string[], marqueursPerimes: string[],
 *   declares: {fichier: string, operations: number, raison: string}[],
 *   marqueursSansRaison: string[], clientsNonDeclares: string[],
 *   parFront: {nom: string, appelees: number, atteignables: number}[],
 *   parPrefixe: [string, number][],
 * }}
 */
export function mesurer() {
  // ── CÔTÉ SERVEUR ──────────────────────────────────────────────────────────────────────────────
  let exposees = 0
  let sansEcran = 0
  const declares = []
  const marqueursSansRaison = []
  // De quoi savoir qu'un chemin appelé existe : les gabarits littéraux, et les noms de ressource.
  const gabaritsDeclares = new Set()
  const nomsRessource = new Set()

  for (const fichier of fichiersPhp(join(RACINE, 'app', 'src'))) {
    const texte = readFileSync(fichier, 'utf8')

    if (texte.includes('ApiResource')) {
      for (const m of texte.matchAll(/uriTemplate:\s*'([^']+)'/g)) {
        gabaritsDeclares.add(m[1].replace(/\{[^}]+\}/g, '{id}').replace(/^\/api/, ''))
      }
      for (const m of texte.matchAll(/shortName:\s*'([^']+)'/g)) nomsRessource.add(m[1].toLowerCase())
      for (const m of texte.matchAll(/^(?:final\s+)?class\s+([A-Za-z0-9_]+)/gm)) {
        nomsRessource.add(m[1].toLowerCase())
      }
    }

    let n = 0
    for (const operation of OPERATIONS) n += compterOccurrences(texte, operation)
    if (n === 0) continue
    exposees += n

    const i = texte.indexOf(MARQUEUR)
    if (i === -1) continue

    const raison = texte.slice(i + MARQUEUR.length, texte.indexOf('\n', i)).trim()
    if (raison === '') {
      // Un marqueur nu ne déclare rien : il tait. On le refuse plutôt que de le compter.
      marqueursSansRaison.push(relative(RACINE, fichier))
      continue
    }
    sansEcran += n
    declares.push({ fichier: relative(RACINE, fichier), operations: n, raison })
  }

  // ── CÔTÉ CLIENT, FRONT PAR FRONT ──────────────────────────────────────────────────────────────
  const tousJs = fichiersJs(SRC)
  const clientsDeclares = new Set(FRONTS.map((f) => f.client))

  // FILET DE COMPLÉTUDE. Un quatrième front ajouté au produit et pas ici serait compté comme
  // inexistant — précisément le défaut corrigé le 27/08 sur la boutique en ligne. On refuse donc
  // tout fichier qui EST un client d'API sans être déclaré.
  //
  // Le critère n'est pas le nom du fichier : `ContactsClient.jsx` et `ActivitesClient.jsx` sont des
  // composants React, et une heuristique sur « …Client.js » les signalait à tort. Un contrôle qui
  // crie sur des innocents finit désarmé. Le vrai signal est qu'un client d'API **définit son
  // transport** — c'est ce qui le distingue d'un écran, qui se contente d'appeler `api.quelqueChose()`.
  const DEFINIT_LE_TRANSPORT = /(async\s+)?function request\s*\(|const request\s*=/
  const clientsNonDeclares = tousJs
    .filter((c) => !clientsDeclares.has(c) && DEFINIT_LE_TRANSPORT.test(readFileSync(c, 'utf8')))
    .map((c) => relative(RACINE, c))

  const appels = new Map()
  const appelsAtteignables = new Map()
  const orphelins = new Set()
  const appelsSansServeur = new Set()
  const marqueursPerimes = new Set()
  const parFront = []

  for (const front of FRONTS) {
    let source
    try {
      source = readFileSync(front.client, 'utf8')
    } catch {
      // Un front déclaré dont le client a disparu : on le dit plutôt que de compter zéro en silence.
      clientsNonDeclares.push(`${relative(RACINE, front.client)} (déclaré, introuvable)`)
      continue
    }

    const ecrans = tousJs
      .filter((c) => c !== front.client && front.ecrans(c))
      .map((c) => readFileSync(c, 'utf8'))
      .join('\n')

    // Les helpers exportés, repérés par leur clé `  nom:` — quel que soit l'objet qui les porte
    // (`api` dans le back-office, `boutique` dans la boutique).
    const orphelinsDuFront = new Set()
    let c = CLE.exec(source)
    while (c !== null) {
      if (!referenceDans(ecrans, c[1])) {
        orphelinsDuFront.add(c[1])
        orphelins.add(`${c[1]}  (${front.nom})`)
      }
      c = CLE.exec(source)
    }

    // COMPTES LOCAUX, ET C'EST LE POINT.
    //
    // Une premiere version incrementait les compteurs du front en regardant les tables GLOBALES :
    // `/auth` etant appele par les deux applications, il comptait comme << appele >> pour la premiere
    // et << atteignable >> pour la seconde. La boutique affichait « 21 atteignables sur 20 appelees ».
    // Un total qui depasse son propre denominateur est la forme la plus visible d'un compte fait au
    // mauvais endroit -- et la plus rare : le meme defaut sur des chiffres plausibles ne se serait
    // jamais vu.
    const appelsFront = new Map()
    const atteignablesDuFront = new Map()
    let m = APPEL.exec(source)
    while (m !== null) {
      const chemin = m[1].replace(INTERPOLATION, '{id}')
      if (chemin.startsWith('/api/')) {
        const suite = METHODE.exec(m[2])
        const verbe = suite ? suite[1] : 'GET'

        if (!appels.has(chemin)) appels.set(chemin, new Set())
        appels.get(chemin).add(verbe)
        if (!appelsFront.has(chemin)) appelsFront.set(chemin, new Set())
        appelsFront.get(chemin).add(verbe)

        // À quel helper appartient cet appel : la dernière clé déclarée avant lui.
        const derniere = [...source.slice(0, m.index).matchAll(/^ {2}([a-zA-Z][a-zA-Z0-9]*):\s/gm)].pop()
        const nom = derniere ? derniere[1] : null
        const annonce = annonceRouteAVenir(source, derniere ? derniere.index : m.index)

        if (!adosseAuServeur(chemin, gabaritsDeclares, nomsRessource)) {
          // Appelé, référencé par un écran, et pourtant sans rien au bout : ce n'est pas une
          // opération couverte, c'est un appel dans le vide. Il ne compte pas — et il se dit, sauf
          // s'il annonce précéder son opération avec sa raison.
          if (annonce === null) appelsSansServeur.add(`${chemin}  (${front.nom})`)
        } else {
          // LE MARQUEUR SE PÉRIME TOUT SEUL, SINON IL DEVIENT UN ANGLE MORT EN FORME DE COMMENTAIRE.
          //
          // Le jour où l'opération est ouverte, l'appel redevient légitime et l'annonce devient
          // fausse — mais elle reste. Six mois plus tard, personne ne sait si `@route-a-venir:`
          // désigne une route encore à venir ou une route posée depuis longtemps. Et tant qu'elle
          // traîne, l'appel resterait non compté : le cliquet mentirait dans l'autre sens.
          if (annonce !== null) marqueursPerimes.add(`${chemin}  (${front.nom})`)

          if (nom !== null && !orphelinsDuFront.has(nom)) {
            if (!appelsAtteignables.has(chemin)) appelsAtteignables.set(chemin, new Set())
            appelsAtteignables.get(chemin).add(verbe)
            if (!atteignablesDuFront.has(chemin)) atteignablesDuFront.set(chemin, new Set())
            atteignablesDuFront.get(chemin).add(verbe)
          }
        }
      }
      m = APPEL.exec(source)
    }

    const compter = (table) => [...table.values()].reduce((n, verbes) => n + verbes.size, 0)
    parFront.push({ nom: front.nom, appelees: compter(appelsFront), atteignables: compter(atteignablesDuFront) })
  }

  let appelees = 0
  for (const verbes of appels.values()) appelees += verbes.size

  let atteignables = 0
  for (const verbes of appelsAtteignables.values()) atteignables += verbes.size

  // Où l'écart se creuse, pour choisir le lot suivant sur une mesure et non au flair.
  const parPrefixe = new Map()
  for (const [chemin, verbes] of appels) {
    const segment = chemin.split('/')[2] || '(racine)'
    const prefixe = segment.split('_')[0]
    parPrefixe.set(prefixe, (parPrefixe.get(prefixe) || 0) + verbes.size)
  }

  const attendues = exposees - sansEcran

  return {
    exposees,
    sansEcran,
    attendues,
    appelees,
    atteignables,
    // LE CHIFFRE QUI SE GÈLE. Il ne peut pas descendre sous zéro : `atteignables` compte des chemins
    // d'appel, `attendues` des déclarations, et les deux ne se recouvrent pas exactement.
    inatteignables: Math.max(0, attendues - atteignables),
    orphelins: [...orphelins].sort(),
    appelsSansServeur: [...appelsSansServeur].sort(),
    marqueursPerimes: [...marqueursPerimes].sort(),
    declares: declares.sort((a, b) => b.operations - a.operations),
    marqueursSansRaison,
    clientsNonDeclares,
    parFront,
    parPrefixe: [...parPrefixe].sort((a, b) => b[1] - a[1]),
  }
}
