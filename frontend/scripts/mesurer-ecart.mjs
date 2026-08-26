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
// CE QUE LA MESURE NE VOIT PAS, ET QU'IL FAUT LIRE AVEC ELLE :
//
// Elle compte des opérations, pas des signaux. Un champ calculé que le serveur publie et qu'aucun
// écran n'affiche — `alerteCouverture` par exemple — se trouve sur une opération déjà branchée : il
// est invisible pour ce script alors qu'il manque à l'exploitant. La part appelée peut donc monter
// pendant que des informations utiles restent muettes.

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

const appels = new Map()
let m = APPEL.exec(client)
while (m !== null) {
  const chemin = m[1].replace(INTERPOLATION, '{id}')
  if (chemin.startsWith('/api/')) {
    const suite = METHODE.exec(m[2])
    const verbe = suite ? suite[1] : 'GET'
    if (!appels.has(chemin)) appels.set(chemin, new Set())
    appels.get(chemin).add(verbe)
  }
  m = APPEL.exec(client)
}

let appelees = 0
for (const verbes of appels.values()) appelees += verbes.size

const part = ((100 * appelees) / exposees).toFixed(1)

console.log(`Opérations exposées par l'API      : ${exposees}`)
console.log(`Chemins distincts appelés par le front : ${appels.size}`)
console.log(`Opérations appelées                : ${appelees}`)
console.log(`Part branchée                      : ${part} %`)
console.log('')
console.log(`Restent ${exposees - appelees} opérations qu'aucun écran n'appelle.`)

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
