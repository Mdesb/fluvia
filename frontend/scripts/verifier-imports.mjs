// Un composant utilisé et jamais importé : le build passe, l'écran plante.
//
// POURQUOI CE CONTRÔLE EXISTE.
//
// J'ai remplacé une liste par un composant dans `Boutique.jsx` et oublié l'import. `npm run build`
// est passé au vert — Vite ne fait pas d'analyse de portée sur le JSX, un identifiant inconnu n'est
// une erreur qu'à l'exécution. L'écran aurait planté au premier clic sur l'onglet, en production,
// devant l'utilisateur, après un build vert et deux garde-fous verts.
//
// C'est la pire forme de défaut de cette base : **tout ce qui vérifie dit oui.**
//
// CE QU'IL VÉRIFIE, ET CE QU'IL NE VÉRIFIE PAS.
//
// Pour chaque balise JSX commençant par une majuscule, il exige que le nom soit importé, déclaré
// dans le fichier, ou membre d'un objet (`<Foo.Bar>` — on ne suit pas les objets). Il ne vérifie pas
// que l'import pointe vers un fichier existant : Vite, lui, le voit et échoue.
//
// Précis avant qu'exhaustif : un contrôle qui crie au loup finit désactivé, et j'ai déjà payé ça une
// fois avec treize faux positifs.

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, dirname, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const ICI = dirname(fileURLToPath(import.meta.url))
const SRC = join(ICI, '..', 'src')

function fichiers(repertoire) {
  const trouves = []
  for (const entree of readdirSync(repertoire)) {
    const chemin = join(repertoire, entree)
    if (statSync(chemin).isDirectory()) trouves.push(...fichiers(chemin))
    else if (entree.endsWith('.jsx')) trouves.push(chemin)
  }
  return trouves
}

// Littérales, jamais construites : une expression assemblée dans un gabarit transforme `\s` en `s`,
// et le contrôle passe au vert en ne vérifiant rien. Ça m'est arrivé trois fois.
const IMPORT = /^import\s+([^;]+?)\s+from\s+['"][^'"]+['"]/gm
const DECLARATION = /(?:^|\n)\s*(?:export\s+)?(?:default\s+)?(?:function|class)\s+([A-Z][A-Za-z0-9_]*)/g
const AFFECTATION = /(?:^|\n)\s*(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=/g
const BALISE = /<([A-Z][A-Za-z0-9_]*)(?![A-Za-z0-9_.])/g

// React est fourni par le runtime JSX automatique de Vite ; `Fragment` s'écrit <>.
const FOURNIS = new Set(['React', 'Fragment', 'Suspense', 'StrictMode'])

const anomalies = []

for (const fichier of fichiers(SRC)) {
  const texte = readFileSync(fichier, 'utf8')
  const connus = new Set(FOURNIS)

  let m = IMPORT.exec(texte)
  while (m !== null) {
    // `Defaut, { a, b as c }` — on retient les noms utilisables tels quels.
    for (const morceau of m[1].split(/[{},]/)) {
      const nom = morceau.trim().split(/\s+as\s+/).pop().trim()
      if (/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(nom)) connus.add(nom)
    }
    m = IMPORT.exec(texte)
  }

  for (const motif of [DECLARATION, AFFECTATION]) {
    let d = motif.exec(texte)
    while (d !== null) {
      connus.add(d[1])
      d = motif.exec(texte)
    }
  }

  let b = BALISE.exec(texte)
  while (b !== null) {
    const nom = b[1]
    if (!connus.has(nom)) {
      const ligne = texte.slice(0, b.index).split('\n').length
      const chemin = relative(join(ICI, '..', '..'), fichier).replace(/\\/g, '/')
      anomalies.push(`${chemin}:${ligne} — <${nom}> n'est ni importé ni déclaré dans ce fichier.`)
    }
    b = BALISE.exec(texte)
  }
}

if (anomalies.length === 0) {
  console.log('✓ Imports : aucun composant utilisé sans être importé ni déclaré.')
  process.exit(0)
}

console.error(`✗ Imports : ${anomalies.length} composant(s) qui feront planter l'écran à l'exécution.\n`)
for (const a of [...new Set(anomalies)]) console.error('  - ' + a)
console.error(
  "\nLe build ne voit pas ce défaut : Vite ne fait pas d'analyse de portée sur le JSX, et un\n"
    + "identifiant inconnu n'est une erreur qu'au moment où le composant est rendu. Ajoutez l'import\n"
    + 'manquant — ou supprimez la balise si le composant n\'existe plus.',
)
process.exit(1)
