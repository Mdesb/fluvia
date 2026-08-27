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

/**
 * @returns {{
 *   exposees: number, sansEcran: number, attendues: number,
 *   appelees: number, atteignables: number, inatteignables: number,
 *   orphelins: string[], declares: {fichier: string, operations: number, raison: string}[],
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

  for (const fichier of fichiersPhp(join(RACINE, 'app', 'src'))) {
    const texte = readFileSync(fichier, 'utf8')
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

    let appeleesFront = 0
    let atteignablesFront = 0
    let m = APPEL.exec(source)
    while (m !== null) {
      const chemin = m[1].replace(INTERPOLATION, '{id}')
      if (chemin.startsWith('/api/')) {
        const suite = METHODE.exec(m[2])
        const verbe = suite ? suite[1] : 'GET'

        if (!appels.has(chemin)) appels.set(chemin, new Set())
        if (!appels.get(chemin).has(verbe)) appeleesFront += 1
        appels.get(chemin).add(verbe)

        // À quel helper appartient cet appel : la dernière clé déclarée avant lui.
        const derniere = [...source.slice(0, m.index).matchAll(/^ {2}([a-zA-Z][a-zA-Z0-9]*):\s/gm)].pop()
        const nom = derniere ? derniere[1] : null
        if (nom !== null && !orphelinsDuFront.has(nom)) {
          if (!appelsAtteignables.has(chemin)) appelsAtteignables.set(chemin, new Set())
          if (!appelsAtteignables.get(chemin).has(verbe)) atteignablesFront += 1
          appelsAtteignables.get(chemin).add(verbe)
        }
      }
      m = APPEL.exec(source)
    }

    parFront.push({ nom: front.nom, appelees: appeleesFront, atteignables: atteignablesFront })
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
    declares: declares.sort((a, b) => b.operations - a.operations),
    marqueursSansRaison,
    clientsNonDeclares,
    parFront,
    parPrefixe: [...parPrefixe].sort((a, b) => b[1] - a[1]),
  }
}
