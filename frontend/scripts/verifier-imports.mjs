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
// ── ET DEPUIS LE 27/08, LES FONCTIONS UTILITAIRES DU PROJET ─────────────────────────────────────
//
// Le 27/08, `Personnel.jsx` a été déployé en appelant `aLeDroit(...)` sans l'importer. Build vert,
// quinze garde-fous verts, ce contrôle vert — et l'écran plantait à l'ouverture de l'onglet. Le
// défaut est exactement celui pour lequel ce fichier a été écrit ; il ne le voyait pas parce qu'il
// ne regardait que les BALISES.
//
// L'erreur d'origine mérite d'être nommée, parce qu'elle se répète : un script de correction qui
// pose l'import derrière `if ("aLeDroit" not in contenu)` APRÈS avoir inséré le code qui l'utilise
// voit sa propre insertion et conclut que l'import est là. **Une garde d'idempotence doit porter sur
// ce qu'elle pose, jamais sur ce qui l'utilise.**
//
// La liste des noms surveillés n'est pas écrite à la main : elle est LUE dans les modules utilitaires
// du projet. Une liste tenue à la main se désynchronise — c'est la leçon des trois listes redites de
// `bin/garde-fous.sh`.
//
// Précis avant qu'exhaustif : un contrôle qui crie au loup finit désactivé, et j'ai déjà payé ça une
// fois avec treize faux positifs. On ne signale donc un nom que s'il est **appelé** (`nom(`), qu'il
// n'apparaît dans **aucune** liaison locale du fichier, et qu'il n'est pas importé.

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
    else if (entree.endsWith('.jsx')) trouves.push(chemin)  // les .js du dossier api sont les sources
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

// LES MODULES UTILITAIRES DU PROJET — ceux dont un oubli d'import plante un écran.
// Lus, pas listés : une liste écrite à la main vieillit sans le dire.
const MODULES_UTILITAIRES = [
  join(SRC, 'api', 'droits.js'),
  join(SRC, 'api', 'client.js'),
  join(SRC, 'components', 'Liste.jsx'),
  // ⚠ AJOUTÉS LE 28/08 APRÈS UN ÉCRAN BLANC, et l'omission a coûté cher.
  //
  // `vocabulaire.js` et `produit.js` n'étaient pas surveillés. `Pilotage.jsx` lisait `GLOSSAIRE`
  // sans l'importer : `ReferenceError` au rendu, et — faute de garde-fou React — **toute
  // l'application passait à l'écran blanc**. Ce contrôle était vert.
  join(SRC, 'api', 'vocabulaire.js'),
  join(SRC, 'api', 'produit.js'),
  join(SRC, 'public', 'lib', 'format.js'),
  join(SRC, 'public', 'api', 'boutiqueClient.js'),
]

// DEUX FAMILLES D'EXPORTS, DEUX FAÇONS DE LES UTILISER — ET ON N'EN VOYAIT QU'UNE.
//
// Le motif d'origine n'acceptait que les noms commençant par une MINUSCULE (`[a-z]`), et le
// détecteur ne cherchait que des APPELS (`nom(`). Une constante exportée en majuscules et lue par
// accès de propriété — `GLOSSAIRE.fmi` — échappait donc deux fois au contrôle.
//
// C'est exactement ce qui a fait l'écran blanc de `Pilotage`. On sépare donc les deux :
// une fonction se repère à son `(`, une constante à son `.` ou son `[`.
const EXPORT_FONCTION = /export\s+(?:async\s+)?(?:function|const|let|var)\s+([a-z][A-Za-z0-9_$]*)/g
const EXPORT_CONSTANTE = /export\s+const\s+([A-Z][A-Z0-9_]*)\b/g

const HELPERS = new Set()
const CONSTANTES = new Set()
for (const module of MODULES_UTILITAIRES) {
  let texte
  try {
    texte = readFileSync(module, 'utf8')
  } catch {
    // Un module déplacé n'est pas une raison de tout arrêter : Vite le signalera.
    continue
  }
  let e = EXPORT_FONCTION.exec(texte)
  while (e !== null) {
    HELPERS.add(e[1])
    e = EXPORT_FONCTION.exec(texte)
  }
  let c = EXPORT_CONSTANTE.exec(texte)
  while (c !== null) {
    CONSTANTES.add(c[1])
    c = EXPORT_CONSTANTE.exec(texte)
  }
}

/**
 * Le texte débarrassé de ses commentaires.
 *
 * **Le premier essai a crié sur une phrase française.** `MentionsLegales.jsx` explique « un mug de la
 * boutique (quatorze jours) » dans un bloc de documentation, et le motif d'appel y voyait un appel de
 * `boutique`. Ce fichier porte beaucoup de prose — c'est voulu — et une prose assez longue finit
 * toujours par contenir un mot suivi d'une parenthèse.
 *
 * Le dépouillement est volontairement grossier. Une erreur ici RETIRE du texte à analyser : elle fait
 * donc taire le contrôle, jamais crier. C'est le sens dans lequel on préfère se tromper.
 */
function sansCommentaires(texte) {
  return texte
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    // `//` non precede de `:` — sinon on couperait les URL `https://…`.
    .replace(/(^|[^:])\/\/[^\n]*/g, '$1')
}

// Toute liaison locale au fichier : déclaration, paramètre, déstructuration, propriété d'objet.
// Volontairement large — on préfère taire un vrai défaut que crier sur un innocent.
function lieLocalement(texte, nom) {
  const motifs = [
    new RegExp(`(?:function|const|let|var)\\s+${nom}\\b`),
    new RegExp(`\\bfunction\\s+[A-Za-z0-9_$]*\\s*\\([^)]*\\b${nom}\\b`),
    new RegExp(`\\(\\s*\\{[^}]*\\b${nom}\\b[^}]*\\}`),
    new RegExp(`\\{[^{}]*\\b${nom}\\s*[,}]`),
    new RegExp(`\\b${nom}\\s*:`),
  ]
  return motifs.some((m) => m.test(texte))
}

const anomalies = []

for (const fichier of fichiers(SRC)) {
  const texte = readFileSync(fichier, 'utf8')
  // Les imports et les balises se lisent sur le texte brut ; les appels d'utilitaires sur le
  // texte dépouillé, où une phrase ne peut plus ressembler à un appel.
  const code = sansCommentaires(texte)
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

  // Les fonctions utilitaires du projet, appelées sans être importées.
  for (const helper of HELPERS) {
    if (connus.has(helper)) continue
    const appel = new RegExp(`\\b${helper}\\s*\\(`)
    const trouve = appel.exec(code)
    if (trouve === null) continue
    if (lieLocalement(code, helper)) continue
    const ligne = texte.slice(0, trouve.index).split('\n').length
    const chemin = relative(join(ICI, '..', '..'), fichier).replace(/\\/g, '/')
    anomalies.push(`${chemin}:${ligne} — ${helper}() est appelé sans être importé.`)
  }

  // Les CONSTANTES exportées, lues sans être importées. Une constante ne s'appelle pas : elle se
  // déréférence (`GLOSSAIRE.fmi`) ou s'indexe (`MOTS[code]`). C'est ce motif-là qu'il faut chercher.
  for (const constante of CONSTANTES) {
    if (connus.has(constante)) continue
    const lecture = new RegExp(`\\b${constante}\\s*[.[]`)
    const trouve = lecture.exec(code)
    if (trouve === null) continue
    if (lieLocalement(code, constante)) continue
    const ligne = texte.slice(0, trouve.index).split('\n').length
    const chemin = relative(join(ICI, '..', '..'), fichier).replace(/\\/g, '/')
    anomalies.push(`${chemin}:${ligne} — ${constante} est lu sans être importé.`)
  }
}

if (anomalies.length === 0) {
  console.log(`✓ Imports : aucun composant, utilitaire ou constante (${HELPERS.size + CONSTANTES.size} surveillé(s)) utilisé sans être importé.`)
  process.exit(0)
}

console.error(`✗ Imports : ${anomalies.length} identifiant(s) qui feront planter l'écran à l'exécution.\n`)
for (const a of [...new Set(anomalies)]) console.error('  - ' + a)
console.error(
  "\nLe build ne voit pas ce défaut : Vite ne fait pas d'analyse de portée sur le JSX, et un\n"
    + "identifiant inconnu n'est une erreur qu'au moment où le composant est rendu. Ajoutez l'import\n"
    + 'manquant — ou supprimez la balise si le composant n\'existe plus.',
)
process.exit(1)
