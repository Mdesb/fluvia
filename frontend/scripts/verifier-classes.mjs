#!/usr/bin/env node
// LES CLASSES CSS QUI N'EXISTENT PAS, ET QUI NE LEVENT RIEN.
//
// Ce contrôle est né d'un constat du 28/08 : quatre noms de classe — `page-head`, `panel`,
// `panel-h`, `alert` — étaient utilisés 136 fois dans 22 fichiers, et AUCUN n'était défini dans
// `styles.css`. Neuf écrans entiers s'affichaient donc sans cadre, sans en-tête et sans couleur
// d'erreur, avec un vocabulaire parallèle à celui du reste de l'application.
//
// CE QUI REND CE DÉFAUT PARTICULIER : il ne casse rien. Le navigateur ignore une classe inconnue
// sans un mot dans la console, React ne s'en occupe pas, le `build` passe, les tests passent. Le
// seul symptôme est visuel, et il ne ressemble pas à une panne : il ressemble à un écran mal
// dessiné. Personne ne va lire la feuille de style pour un écran « moche ».
//
// C'est la même famille que les 61 en-têtes `.num` alignés à gauche et que les 312 `.sub` sans
// règle globale : une convention appliquée par les auteurs et jamais honorée par le CSS.
//
// CE QUE LE CONTRÔLE NE FAIT PAS. Il ne lit que les `className="…"` littéraux — pas les
// expressions (`className={...}`), où une classe se compose à l'exécution. C'est assumé : les
// littéraux sont l'écrasante majorité, et un contrôle qui prétendrait couvrir les expressions
// donnerait surtout des faux positifs.

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const RACINE = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const SRC = join(RACINE, 'src')
const CSS = join(SRC, 'styles.css')

// Classes posées par des bibliothèques ou par le navigateur, jamais déclarées chez nous.
const TOLEREES = new Set(['on', 'active', 'sr-only'])

function fichiersJsx(dossier) {
  const trouves = []
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree)
    if (statSync(chemin).isDirectory()) trouves.push(...fichiersJsx(chemin))
    else if (entree.endsWith('.jsx')) trouves.push(chemin)
  }
  return trouves
}

const css = readFileSync(CSS, 'utf8')
// Tous les sélecteurs de classe déclarés, y compris composés (`.card-h .sub`) et les états
// (`.btn.primary:hover`) : on ne retient que le nom de classe lui-même.
const declarees = new Set([...css.matchAll(/\.([a-zA-Z][\w-]*)/g)].map((m) => m[1]))

const manquantes = new Map()
for (const fichier of fichiersJsx(SRC)) {
  const source = readFileSync(fichier, 'utf8')
  const lignes = source.split('\n')
  lignes.forEach((ligne, i) => {
    for (const m of ligne.matchAll(/className="([^"{}]+)"/g)) {
      for (const classe of m[1].split(/\s+/).filter(Boolean)) {
        if (declarees.has(classe) || TOLEREES.has(classe)) continue
        if (!manquantes.has(classe)) manquantes.set(classe, [])
        manquantes.get(classe).push(`${relative(RACINE, fichier)}:${i + 1}`)
      }
    }
  })
}

if (manquantes.size === 0) {
  console.log('✓ Classes : aucune classe CSS utilisée sans être déclarée dans styles.css.')
  process.exit(0)
}

const total = [...manquantes.values()].reduce((n, l) => n + l.length, 0)
console.log(`✗ Classes : ${manquantes.size} classe(s) utilisée(s) ${total} fois sans être déclarée(s).\n`)
for (const [classe, endroits] of [...manquantes].sort((a, b) => b[1].length - a[1].length)) {
  console.log(`  .${classe} — ${endroits.length} fois`)
  for (const endroit of endroits.slice(0, 4)) console.log(`      ${endroit}`)
  if (endroits.length > 4) console.log(`      … et ${endroits.length - 4} autre(s)`)
}
console.log(
  '\nUne classe inconnue ne lève rien : le navigateur l’ignore, le build passe, et l’écran sort\n'
  + 'sans cadre ni couleur. Soit le nom est une faute de frappe, soit la règle manque à styles.css.',
)
process.exit(1)
