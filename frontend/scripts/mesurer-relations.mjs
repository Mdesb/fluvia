#!/usr/bin/env node
// LES RELATIONS QUE LE FRONT LIT COMME DES OBJETS ET QUE LE SERVEUR REND EN IRI.
//
// Le 28/08, quatre défauts identiques ont été trouvés en une journée, dans trois modules :
//
//   ligne.mandat?.debiteurNom          → colonne « Débiteur » vide sur tout le détail d'une remise
//   representation.incident?.reference → colonne « Redevable » vide depuis toujours
//   mouvement.grilleAppliquee?.motif   → « montant libre » affiché sur des retenues qui suivaient
//                                        pourtant un barème
//   mandat.client?.nom                 → nom du client jamais affiché
//
// Une seule cause. API Platform embarque une relation UNIQUEMENT si l'entité liée déclare au moins
// une propriété dans le groupe de sérialisation courant ; sinon elle rend une IRI nue. Côté front,
// `x.relation?.propriete` sur une IRI ne lève pas : il vaut `undefined`. La colonne sort vide, le
// build passe, les tests passent, et un tiret se lit comme « pas de donnée » — jamais comme
// « je regarde au mauvais endroit ».
//
// ⚠ IL PRODUIT DES PISTES, PAS DES VERDICTS — ET LE FAUX POSITIF EST NOMMÉ ICI.
//
// Un nom de propriété PHP entre en collision avec une variable JavaScript ordinaire. Exemple réel,
// vérifié contre la préprod : `Campagnes.jsx` écrit `apercu.segment.label`, et le script le signale
// parce que `Segment` n'est embarqué nulle part. Sauf qu'ici `apercu` est un objet LOCAL dont le
// champ `segment` porte déjà un segment complet, lu depuis `/api/segments`. Le code est juste.
//
// Aucune analyse par expressions régulières ne peut lever cette ambiguïté : il faudrait savoir quel
// type contient chaque variable JavaScript. On liste donc, on explique, et on laisse trancher.
//
// Ce que ça vaut quand même : les cinq défauts confirmés du 28/08 figurent tous dans cette liste,
// dont `a.author?.nom` — le nom de l'agent sur le fil d'activité d'un client, que personne n'avait
// vu manquer. Une liste à dépouiller vaut mieux qu'un défaut qu'on ne cherche pas.
//
// CE SCRIPT MESURE, IL NE BLOQUE PAS. C'est délibéré, et c'est le patron du dépôt
// (`mesurer-ecart.mjs` a constaté pendant une semaine avant que `garde-fou-ecart.mjs` ne serre) :
// l'analyse croise deux langages par expressions régulières, elle rendra des approximations. Un
// contrôle qui crie sur du code juste se fait désactiver, et emporte les vrais signalements avec
// lui. On sépare donc ce qui est certain de ce qui demande un œil.
//
// CE QU'IL SAIT FAIRE
//   1. lire les entités PHP : quelle classe déclare quelle propriété dans quel groupe ;
//   2. en déduire, pour chaque relation, si elle est EMBARQUÉE ou rendue en IRI dans chaque groupe ;
//   3. relever dans le JSX les accès `.relation?.x` / `.relation.x` ;
//   4. les classer : CERTAIN quand la relation est en IRI partout, À VÉRIFIER sinon.
//
// CE QU'IL NE SAIT PAS FAIRE : dire quelle entité une variable JavaScript contient. Il travaille
// sur le NOM de la propriété, ce qui suffit parce que ces noms sont distinctifs (`grilleAppliquee`,
// `rejetOrigine`, `incident`) — mais explique pourquoi un nom banal comme `client` atterrit dans la
// colonne « à vérifier » plutôt que dans les certitudes.

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const RACINE = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const APP = join(RACINE, '..', 'app', 'src')
const FRONT = join(RACINE, 'src')

function fichiers(dossier, filtre) {
  const trouves = []
  let entrees
  try {
    entrees = readdirSync(dossier)
  } catch {
    return trouves
  }
  for (const entree of entrees) {
    const chemin = join(dossier, entree)
    if (statSync(chemin).isDirectory()) trouves.push(...fichiers(chemin, filtre))
    else if (filtre(entree)) trouves.push(chemin)
  }
  return trouves
}

// --- 1. Lecture des entités PHP ------------------------------------------------------------------

/** classe -> Set des groupes dans lesquels elle expose au moins une propriété. */
const groupesDeLaClasse = new Map()
/** relations relevées : { proprietaire, propriete, cible, groupes } */
const relations = []

for (const fichier of fichiers(APP, (n) => n.endsWith('.php'))) {
  const source = readFileSync(fichier, 'utf8')
  const nomClasse = source.match(/^(?:final )?class (\w+)/m)?.[1]
  if (!nomClasse) continue

  // Les `#[Groups([...])]` qui précèdent une propriété. On lit le fichier ligne à ligne et on garde
  // les groupes vus depuis la dernière propriété : c'est la forme réelle du dépôt (attribut juste
  // au-dessus), et ça évite de faire de l'analyse syntaxique PHP pour trois lignes.
  const lignes = source.split('\n')
  let groupesEnAttente = []
  let cibleEnAttente = null

  for (const ligne of lignes) {
    const g = ligne.match(/#\[Groups\(\[([^\]]*)\]\)\]/)
    if (g) {
      groupesEnAttente = [...g[1].matchAll(/'([^']+)'/g)].map((m) => m[1])
      continue
    }
    // `targetEntity: Foo::class` sur la ligne de l'attribut ORM juste au-dessus de la propriété.
    const t = ligne.match(/targetEntity:\s*(\w+)::class/)
    if (t) {
      cibleEnAttente = t[1]
      continue
    }
    const p = ligne.match(/^\s*(?:private|protected|public)\s+\??([\w\\|]+)\s+\$(\w+)/)
    if (!p) continue

    const [, type, propriete] = p
    if (groupesEnAttente.length > 0) {
      if (!groupesDeLaClasse.has(nomClasse)) groupesDeLaClasse.set(nomClasse, new Set())
      for (const groupe of groupesEnAttente) groupesDeLaClasse.get(nomClasse).add(groupe)

      // Une relation : type qui commence par une majuscule et n'est pas un type natif ni une date.
      const cible = cibleEnAttente || (/^[A-Z]/.test(type) ? type : null)
      const natifs = ['DateTimeImmutable', 'DateTime', 'Uuid', 'Collection', 'ArrayCollection']
      if (cible && !natifs.includes(cible) && !cible.startsWith('\\')) {
        relations.push({ proprietaire: nomClasse, propriete, cible, groupes: groupesEnAttente })
      }
    }
    groupesEnAttente = []
    cibleEnAttente = null
  }
}

// --- 2. Embarquée ou IRI ? -----------------------------------------------------------------------

// Une relation est EMBARQUÉE dans le groupe G si la classe cible expose au moins une propriété
// dans G. Sinon, le serveur rend une IRI nue.
const parPropriete = new Map()
for (const rel of relations) {
  const groupesCible = groupesDeLaClasse.get(rel.cible) || new Set()
  const embarquee = rel.groupes.some((g) => groupesCible.has(g))
  if (!parPropriete.has(rel.propriete)) parPropriete.set(rel.propriete, [])
  parPropriete.get(rel.propriete).push({ ...rel, embarquee })
}

// --- 3. Les accès du front -----------------------------------------------------------------------

// LE FILTRE QUI FAIT LA DIFFÉRENCE ENTRE UN OUTIL ET UN BRUITEUR.
//
// Un nom de propriété d'entité PHP entre souvent en collision avec une variable JavaScript
// ordinaire : `motif` est une propriété de `MotifGratuite` ET le nom d'un champ de formulaire ;
// `valeurs` est une relation ET un tableau local. Le premier jet a signalé `motif.trim()` et
// `valeurs.map()` — du code parfaitement juste.
//
// Le départage est simple et sûr : **si ce qu'on lit derrière le point est une méthode de
// JavaScript, alors ce qu'on lit devant est une valeur JavaScript**, pas une ressource de l'API.
// Une IRI est une chaîne : personne n'écrit `.trim()` en croyant lire une relation.
const METHODES_JS = new Set([
  'map', 'filter', 'find', 'findIndex', 'some', 'every', 'reduce', 'forEach', 'flat', 'flatMap',
  'join', 'slice', 'splice', 'concat', 'includes', 'indexOf', 'lastIndexOf', 'push', 'pop', 'shift',
  'unshift', 'sort', 'reverse', 'at', 'keys', 'values', 'entries', 'fill',
  'trim', 'trimStart', 'trimEnd', 'split', 'replace', 'replaceAll', 'toUpperCase', 'toLowerCase',
  'startsWith', 'endsWith', 'padStart', 'padEnd', 'charAt', 'substring', 'substr', 'match',
  'matchAll', 'normalize', 'repeat', 'localeCompare',
  'toFixed', 'toString', 'valueOf', 'toISOString', 'getTime', 'then', 'catch', 'finally',
  'length', 'size', 'has', 'get', 'set', 'add', 'delete', 'clear',
])

// CE QUI A DÉJÀ ÉTÉ TRANCHÉ, POUR QUE PERSONNE NE LE RÉEXAMINE.
//
// Chaque entrée est un faux positif VÉRIFIÉ le 28/08, avec sa raison. Sans cette liste, le prochain
// qui lance l'outil recommence le même travail d'élimination — et un outil qu'on doit re-trier en
// entier à chaque passage finit par ne plus être lancé.
//
// ⚠ On n'ajoute ici que ce qu'on a VÉRIFIÉ, jamais ce qu'on suppose. Une entrée de trop et l'outil
// se met à taire un vrai défaut, ce qui est exactement ce qu'il sert à empêcher.
const TRANCHES = new Map([
  ['pmv', 'FicheClient360Provider compose « pmv » à la main comme un tableau simple : ce n’est pas '
    + 'la relation PorteMonnaieVirtuel, juste le même nom.'],
  ['etat', 'Carte locale (`etats[s.id]` dans Musée, `{ article, restant }` dans Stock), pas une '
    + 'relation sérialisée.'],
  ['grille', 'TypeTarif expose bien trois propriétés dans `grille:read` : la relation EST embarquée.'],
  ['segment', 'Objet local de l’aperçu de campagne, qui porte déjà un segment complet lu depuis '
    + '/api/segments.'],
])

const certains = []
const aVerifier = []
const dejaVus = new Set()

for (const fichier of fichiers(FRONT, (n) => n.endsWith('.jsx') || n.endsWith('.js'))) {
  const lignes = readFileSync(fichier, 'utf8').split('\n')
  lignes.forEach((ligne, i) => {
    // On ignore les commentaires : le dépôt en est plein, et ils citent justement ces expressions.
    const nue = ligne.replace(/^\s*(\/\/|\*).*/, '')
    for (const m of nue.matchAll(/\.(\w+)\??\.(\w+)/g)) {
      const [, propriete, sousPropriete] = m
      const declarations = parPropriete.get(propriete)
      if (!declarations) continue
      if (METHODES_JS.has(sousPropriete)) continue
      if (TRANCHES.has(propriete)) continue
      // `.id` est le seul champ qu'une relation EMBARQUÉE porte toujours, et c'est aussi ce qu'on
      // extrait légitimement d'une IRI. On ne le signale pas.
      if (sousPropriete === 'id') continue

      // Une même ligne peut porter deux fois la même expression (`x.y && x.y.z`) : on ne la compte
      // qu'une fois, sinon le total gonfle sans que le travail augmente.
      const ou = `${relative(RACINE, fichier)}:${i + 1}`
      const cle = `${ou}|${propriete}.${sousPropriete}`
      if (dejaVus.has(cle)) continue
      dejaVus.add(cle)

      const jamaisEmbarquee = declarations.every((d) => !d.embarquee)
      const entree = { ou, propriete, sousPropriete, cible: declarations[0].cible, declarations }
      if (jamaisEmbarquee) certains.push(entree)
      else aVerifier.push(entree)
    }
  })
}

// --- 4. Rapport ----------------------------------------------------------------------------------

console.log(`Entités lues                     : ${groupesDeLaClasse.size}`)
console.log(`Relations sérialisées relevées   : ${relations.length}`)
console.log(`  dont rendues en IRI nue        : ${relations.filter((r) => {
  const gc = groupesDeLaClasse.get(r.cible) || new Set()
  return !r.groupes.some((g) => gc.has(g))
}).length}`)
if (TRANCHES.size > 0) {
  console.log(`${TRANCHES.size} nom(s) de propriété écarté(s), déjà tranchés comme faux positifs :`)
  for (const [nom, raison] of TRANCHES) console.log(`  .${nom} — ${raison}`)
  console.log('')
}

console.log('')

if (certains.length === 0) {
  console.log('Aucun accès du front sur une relation rendue en IRI dans tous ses groupes.')
} else {
  console.log(`${certains.length} accès À EXAMINER — la relation lue n'est embarquée dans aucun de`)
  console.log('ses groupes, donc le serveur en rend une IRI. Reste à vérifier que la variable porte')
  console.log('bien une ressource de l\'API, et non un objet local qui a le même nom :\n')
  for (const c of certains) {
    console.log(`  ${c.ou}`)
    console.log(`      .${c.propriete}.${c.sousPropriete}  —  ${c.propriete} pointe ${c.cible}, qui`)
    console.log('      n\'expose aucune propriété dans le groupe qui la porte : le serveur rend une')
    console.log('      IRI, et cette expression vaut `undefined`.')
  }
}

if (aVerifier.length > 0) {
  console.log(`\n${aVerifier.length} accès à vérifier à l'œil — la relation est embarquée dans`)
  console.log('certains groupes et pas dans d\'autres, et ce script ne sait pas lequel s\'applique ici :\n')
  const parNom = new Map()
  for (const v of aVerifier) {
    if (!parNom.has(v.propriete)) parNom.set(v.propriete, [])
    parNom.get(v.propriete).push(v.ou)
  }
  for (const [nom, endroits] of [...parNom].sort((a, b) => b[1].length - a[1].length)) {
    console.log(`  .${nom} — ${endroits.length} accès : ${endroits.slice(0, 3).join(', ')}${endroits.length > 3 ? ' …' : ''}`)
  }
}

console.log(
  '\nUn accès sur une IRI ne lève pas : il vaut `undefined`, la colonne sort vide, et un tiret se'
  + '\nlit comme « pas de donnée ». Corriger : soit résoudre l\'IRI contre une liste déjà chargée,'
  + '\nsoit demander un `#[Groups]` sur la propriété de l\'entité cible.',
)
