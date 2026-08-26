// Combien d'opérations l'API expose, combien le front en appelle.
//
// POURQUOI CE SCRIPT EXISTE PLUTÔT QU'UN CHIFFRE DANS UN RAPPORT.
//
// J'ai annoncé « 135 sur 1042 », puis « 169 sur 1068 », en recomptant chaque fois à la main. Deux
// mesures qui ne se comparent pas ne mesurent rien : la seconde peut être plus haute parce qu'on a
// branché des écrans, ou parce qu'on a compté autrement, et personne — moi compris — ne peut dire
// laquelle. Un chiffre qu'on ne sait pas reproduire est une opinion.
//
// LA MÉTHODE, ÉCRITE POUR ÊTRE CONTESTABLE :
//
//   exposées — chaque `new Get(`, `new GetCollection(`, `new Post(`, `new Put(`, `new Patch(`,
//              `new Delete(` trouvé dans `app/src`. Une occurrence = une opération déclarée.
//   appelées — chaque chemin `/api/...` distinct de `frontend/src/api/client.js`, les interpolations
//              `${…}` ramenées à `{id}`, compté une fois par méthode HTTP employée sur ce chemin.
//
// UN ANGLE MORT CORRIGÉ, ET IL VENAIT DE MOI.
//
// La première version comptait les chemins présents dans `client.js`. Un helper **défini et appelé
// par aucun écran** était donc compté comme branché — alors qu'aucun utilisateur ne le déclenchera
// jamais. Trouvé en relevant les listes d'alerte : `ventesImpayeesRegie` et `rejetsSepa` existaient
// dans le client et n'étaient dans aucune page. J'en avais moi-même créé un le jour même.
//
// Le script rend donc maintenant DEUX nombres. « Appelées » compte ce que le client sait faire ;
// « atteignables » compte ce qu'un utilisateur peut réellement déclencher. C'est le second qui
// mesure le produit ; le premier ne mesure que le code.
//
// CE QUE LA MESURE NE VOIT TOUJOURS PAS :
//
// Elle compte des opérations, pas des signaux. Un champ calculé que le serveur publie et qu'aucun
// écran n'affiche — `alerteCouverture` par exemple — se trouve sur une opération déjà branchée : il
// est invisible pour ce script alors qu'il manque à l'exploitant. La part atteignable peut donc
// monter pendant que des informations utiles restent muettes.
//
// Et elle ne dit pas si une liste affichée mène quelque part. Une liste d'alertes sans le geste qui
// la vide compte comme branchée, et n'est pourtant que du décor.

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const ICI = dirname(fileURLToPath(import.meta.url))
const RACINE = join(ICI, '..', '..')

const OPERATIONS = [
  'new Get(',
  'new GetCollection(',
  'new Post(',
  'new Put(',
  'new Patch(',
  'new Delete(',
]

function fichiersPhp(repertoire) {
  const trouves = []
  for (const entree of readdirSync(repertoire)) {
    const chemin = join(repertoire, entree)
    if (statSync(chemin).isDirectory()) trouves.push(...fichiersPhp(chemin))
    else if (entree.endsWith('.php')) trouves.push(chemin)
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

let exposees = 0
for (const fichier of fichiersPhp(join(RACINE, 'app', 'src'))) {
  const texte = readFileSync(fichier, 'utf8')
  for (const operation of OPERATIONS) exposees += compterOccurrences(texte, operation)
}

const client = readFileSync(join(ICI, '..', 'src', 'api', 'client.js'), 'utf8')

// Littéraux, jamais construits : une expression régulière assemblée dans un gabarit m'a déjà fait
// écrire `\s` qui devient `s`, et le contrôle passait au vert en ne vérifiant rien.
const APPEL = /request\(\s*[`'"]([^`'"]+)[`'"]([^\n]*)/g
const INTERPOLATION = /\$\{[^}]*\}/g
const METHODE = /method:\s*'([A-Z]+)'/

// Le reste du front, `client.js` exclu : c'est lui qui dit ce qu'un utilisateur peut déclencher.
function fichiersFront(repertoire) {
  const trouves = []
  for (const entree of readdirSync(repertoire)) {
    const chemin = join(repertoire, entree)
    if (statSync(chemin).isDirectory()) trouves.push(...fichiersFront(chemin))
    else if (/\.jsx?$/.test(entree) && entree !== 'client.js') trouves.push(chemin)
  }
  return trouves
}

const ecrans = fichiersFront(join(ICI, '..', 'src'))
  .map((f) => readFileSync(f, 'utf8'))
  .join('\n')

// Les clés de l'objet `api` — `  nom: (`. Littéral, jamais construit.
const CLE = /^ {2}([a-zA-Z][a-zA-Z0-9]*):\s/gm
const debutApi = client.indexOf('export const api = {')
const corpsApi = client.slice(debutApi)

const orphelins = new Set()
let c = CLE.exec(corpsApi)
while (c !== null) {
  const nom = c[1]
  // Recherche par délimiteur de mot, sans construire d'expression : `indexOf` suffit puisqu'on
  // vérifie ensuite que les caractères voisins ne sont pas alphanumériques.
  if (!referenceDansLesEcrans(nom)) orphelins.add(nom)
  c = CLE.exec(corpsApi)
}

function referenceDansLesEcrans(nom) {
  let i = ecrans.indexOf(nom)
  while (i !== -1) {
    const avant = ecrans[i - 1] || ' '
    const apres = ecrans[i + nom.length] || ' '
    if (!/[A-Za-z0-9_$]/.test(avant) && !/[A-Za-z0-9_$]/.test(apres)) return true
    i = ecrans.indexOf(nom, i + 1)
  }
  return false
}

const appels = new Map()
const appelsAtteignables = new Map()
let m = APPEL.exec(client)
while (m !== null) {
  const chemin = m[1].replace(INTERPOLATION, '{id}')
  if (chemin.startsWith('/api/')) {
    const suite = METHODE.exec(m[2])
    const verbe = suite ? suite[1] : 'GET'
    if (!appels.has(chemin)) appels.set(chemin, new Set())
    appels.get(chemin).add(verbe)

    // À quel helper appartient cet appel : la dernière clé déclarée avant lui.
    const avant = client.slice(0, m.index)
    const derniere = [...avant.matchAll(/^ {2}([a-zA-Z][a-zA-Z0-9]*):\s/gm)].pop()
    const nom = derniere ? derniere[1] : null
    if (nom !== null && !orphelins.has(nom)) {
      if (!appelsAtteignables.has(chemin)) appelsAtteignables.set(chemin, new Set())
      appelsAtteignables.get(chemin).add(verbe)
    }
  }
  m = APPEL.exec(client)
}

let appelees = 0
for (const verbes of appels.values()) appelees += verbes.size

let atteignables = 0
for (const verbes of appelsAtteignables.values()) atteignables += verbes.size

const part = ((100 * atteignables) / exposees).toFixed(1)

console.log(`Opérations exposées par l'API   : ${exposees}`)
console.log(`Appelées depuis client.js       : ${appelees}`)
console.log(`Atteignables depuis un écran    : ${atteignables}   <-- la mesure qui compte`)
console.log(`Part atteignable                : ${part} %`)
console.log('')
console.log(`Restent ${exposees - atteignables} opérations qu'aucun utilisateur ne peut déclencher.`)

if (orphelins.size > 0) {
  console.log('')
  console.log(`${orphelins.size} appel(s) définis dans client.js et utilisés par aucun écran :`)
  for (const nom of [...orphelins].sort()) console.log(`  ${nom}`)
}

// `--par-module` : où l'écart se creuse, pour choisir le lot suivant sur une mesure et non au flair.
if (process.argv.includes('--par-module')) {
  const parModule = new Map()
  for (const chemin of appels.keys()) {
    const segment = chemin.split('/')[2] || '(racine)'
    const module = segment.split('_')[0]
    parModule.set(module, (parModule.get(module) || 0) + appels.get(chemin).size)
  }
  console.log('')
  console.log('Opérations appelées, par préfixe de chemin :')
  for (const [module, n] of [...parModule].sort((a, b) => b[1] - a[1])) {
    console.log(`  ${String(n).padStart(3)}  ${module}`)
  }
}
