// GARDE-FOU n°51 — LE BUNDLER AVERTIT, ET PERSONNE NE LIT.
//
// ── L'INCIDENT QUI L'A FAIT ÉCRIRE, LE 04/09 ────────────────────────────────────────────────────
//
// `npx vite build` signalait QUATRE clés en double dans `Icon.jsx` — `dashboard`, `personal-data`,
// `social`, `legal` — pendant que le garde-fou « Clés en double » annonçait « aucune ». Quatre
// icônes du menu n'étaient pas celles qu'on lisait dans le fichier : en JavaScript la dernière
// définition gagne, et la première ne dessine rien.
//
// ⚠ LES DEUX DISAIENT VRAI DANS LEUR PÉRIMÈTRE. C'est le périmètre qui était faux. Élargi depuis.
// Mais rien ne garantissait le PROCHAIN avertissement : le build passe avec, personne ne les
// regarde, et le seul endroit où ils s'affichent est le déploiement — trop tard, dans un journal
// que personne n'ouvre.
//
// ── CE QU'IL FAIT ───────────────────────────────────────────────────────────────────────────────
//
// Il construit, et il ÉCHOUE sur tout avertissement. Zéro aujourd'hui, donc aucune dette gelée :
// c'est le bon moment pour poser le cliquet, avant qu'il y en ait un à tolérer.
//
// ⚠ IL CONSTRUIT DANS UN RÉPERTOIRE TEMPORAIRE. Écrire dans `dist/` remplacerait le build servi par
// celui d'un contrôle — et un contrôle qui modifie ce qu'il mesure finit par mesurer son travail.

import { spawnSync } from 'node:child_process'
import { existsSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

/**
 * Une ligne de sortie du bundler est-elle un avertissement ?
 *
 * ⚠ ON RECONNAÎT LES AVERTISSEMENTS, ON N'ÉCARTE PAS LE NORMAL. L'inverse — tout signaler sauf ce
 * qu'on sait bénin — ferait crier le contrôle à chaque nouvelle ligne que vite décide d'imprimer,
 * et un contrôle qui crie faux s'apprend à sauter.
 */
function estUnAvertissement(ligne) {
  const l = ligne.trim()
  if (l === '') return false

  return (
    /\bwarn(ing)?\b/i.test(l)
    || /^\(!\)/.test(l) // le préfixe d'avertissement de rollup/vite
    || /duplicate key/i.test(l)
    || /\bdeprecat/i.test(l)
    || /\[plugin [^\]]+\]/.test(l) // un plugin qui parle est presque toujours un avertissement
  )
}

// ── LES TÉMOINS DU CLASSIFIEUR ──────────────────────────────────────────────────────────────────
//
// ⚠ ET ILS NE SUFFISENT PAS — C'EST LA LEÇON DE CE FICHIER. La première version passait ces six
// témoins ET annonçait « aucun avertissement » sur une vraie clé en double : elle lisait
// `execFileSync`, qui ne rend que **stdout**, alors que vite écrit sur **stderr**. Les témoins
// éprouvaient le CLASSIFIEUR, pas la CAPTURE.
//
// Un témoin par organe de lecture ne vaut pas un témoin par VOIE DE SORTIE. Le témoin décisif se
// fait donc en cassant du vrai code et en regardant le verdict — il est décrit en §8.14, et il a
// été refait après la correction.
const TEMOINS = [
  // doivent être SIGNALÉS
  ['src/components/Icon.jsx: Duplicate key "dashboard" in object literal', true],
  ['(!) Some chunks are larger than 500 kB after minification.', true],
  ['warning: "x" is imported but never used', true],
  // doivent être ÉPARGNÉS — c'est le cas qui démasque un contrôle trop large
  ['dist/assets/App-Cz7piclF.js                  345.73 kB │ gzip: 90.61 kB', false],
  ['✓ built in 4.92s', false],
  ['vite v5.4.21 building for production...', false],
]

let temoins = 0
let temoinsEpargne = 0
for (const [ligne, attendu] of TEMOINS) {
  const obtenu = estUnAvertissement(ligne)
  if (obtenu !== attendu) {
    console.error(
      `✗ Témoin en échec : ${JSON.stringify(ligne)} attendait `
      + `${attendu ? 'SIGNALÉ' : 'épargné'}, a obtenu ${obtenu ? 'SIGNALÉ' : 'épargné'}.`,
    )
    console.error("  Le détecteur ne lit pas ce qu'il prétend lire — ne rien conclure de son verdict.")
    process.exit(2)
  }
  temoins += 1
  if (!attendu) temoinsEpargne += 1
}

// ── LE BUILD ────────────────────────────────────────────────────────────────────────────────────
//
// ⚠ SANS `node_modules`, ON NE CONCLUT PAS. L'arbre du `pre-receive` n'en a pas ; un build
// impossible y déclarerait « aucun avertissement », c'est-à-dire un vert qui n'a rien mesuré.
if (!existsSync('node_modules')) {
  console.log('Avertissements du bundler : non exécuté — pas de `node_modules` dans cet arbre.')
  console.log("  Un build impossible déclarerait « aucun avertissement » sans avoir rien construit.")
  console.log('  Le contrôle tourne dans `./bin/garde-fous.sh` et en pre-commit.')
  // Le lanceur compte les abstentions grace a ce marqueur (voir `bin/garde-fous.sh`). Hors
  // du lanceur -- appel direct, hooks -- la variable est absente et rien n'est imprime.
  if (process.env.GARDE_FOU_MARQUEUR_ABSTENTION) console.log(process.env.GARDE_FOU_MARQUEUR_ABSTENTION)
  process.exit(0)
}

const sortieDir = mkdtempSync(join(tmpdir(), 'garde-fou-build-'))

// ⚠ LES DEUX VOIES DE SORTIE. `execFileSync` ne rend que stdout ; vite avertit sur stderr. La
// première version de ce contrôle était aveugle pour cette seule raison.
const resultat = spawnSync(
  'npx',
  ['vite', 'build', '--outDir', sortieDir, '--emptyOutDir', '--logLevel', 'warn'],
  { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 },
)
rmSync(sortieDir, { recursive: true, force: true })

const sortie = `${resultat.stdout ?? ''}\n${resultat.stderr ?? ''}`

// ⚠ UN BUILD QUI ÉCHOUE N'EST PAS « AUCUN AVERTISSEMENT ». On le dit, et on échoue en 2 — un code
// distinct de celui des avertissements, pour que « le contrôle n'a pas pu mesurer » ne se lise pas
// comme « le contrôle a trouvé quelque chose ».
if (resultat.status !== 0) {
  console.error("✗ Avertissements du bundler : LE BUILD A ÉCHOUÉ — rien n'a pu être mesuré.")
  console.error(sortie.trim().split('\n').slice(-12).map((l) => '  ' + l).join('\n'))
  process.exit(2)
}

const avertissements = sortie.split('\n').filter(estUnAvertissement)

if (avertissements.length > 0) {
  console.error('✗ Le bundler avertit, et rien ne le lisait :')
  console.error('')
  for (const ligne of avertissements) console.error('    ' + ligne.trim())
  console.error('')
  console.error("  Ces lignes ne s'affichaient qu'au déploiement, dans un journal que personne n'ouvre,")
  console.error("  et n'empêchaient rien. Le 04/09, quatre d'entre elles disaient que quatre icônes du")
  console.error("  menu n'étaient pas celles qu'on lisait dans le fichier.")
  process.exit(1)
}

console.log(`✓ Avertissements du bundler : aucun. ${sortie.split('\n').length} ligne(s) lue(s) sur les deux voies.`)
console.log(`  (${temoins} témoins de classement passés, dont ${temoinsEpargne} qui prouvent ce qu'il épargne.)`)
