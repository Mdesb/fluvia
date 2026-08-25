#!/usr/bin/env node
// Vérifie que les droits sont testés correctement, et surtout qu'ils ARRIVENT.
//
// Trois défauts de la même forme en deux jours, tous invisibles à la compilation : du code qui
// compile, qui a l'air juste, et qui ne fait rien.
//
//   1. `droits.includes('caisse.lire')` — l'égalité stricte ne voit pas la permission joker `*.lire`.
//      Un administrateur se retrouvait avec un menu vide.
//   2. `estAdministrateur` — même défaut, et il renvoyait à la caisse depuis tout écran protégé.
//   3. `<HistoriqueVentesModal>` sans `droits` — le composant testait un droit, ne le recevait pas,
//      et son bouton ne s'affichait jamais.
//
// Le troisième ne se voit ni à la compilation ni au test : une propriété manquante vaut `undefined`,
// et `aLeDroit(undefined, …)` répond « non » en silence. J'ai commis ce défaut-là dans le lot même où
// je corrigeais les deux premiers — c'est bien qu'une relecture attentive ne suffit pas.
//
// PRÉCISION AVANT EXHAUSTIVITÉ. Un contrôle qui crie au loup finit désactivé. Il ne signale donc que
// ce qu'il sait délimiter exactement : le corps réel d'une fonction (accolades équilibrées) et la
// balise JSX complète (une fonction fléchée en attribut contient des `>` qu'il ne faut pas prendre
// pour la fin de la balise). Ce qu'il ne sait pas trancher, il le laisse passer.

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const RACINE = new URL('../src/', import.meta.url).pathname

function fichiers(dir) {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n)
    if (statSync(p).isDirectory()) return fichiers(p)
    return /\.(jsx|js)$/.test(n) ? [p] : []
  })
}

// Corps réel d'une fonction : de sa première accolade ouvrante à sa fermante, équilibrées.
function corpsFonction(src, depuis) {
  const debut = src.indexOf('{', depuis)
  if (debut === -1) return ''
  let profondeur = 0
  for (let i = debut; i < src.length; i++) {
    if (src[i] === '{') profondeur++
    else if (src[i] === '}') {
      profondeur--
      if (profondeur === 0) return src.slice(debut, i + 1)
    }
  }
  return src.slice(debut)
}

// Balise JSX ouvrante complète : on ignore les `>` qui vivent dans une expression `{...}` ou dans
// une chaîne. Sans ça, `onClose={() => setX(null)}` coupe la balise en deux.
function baliseOuvrante(src, debut) {
  let accolades = 0
  let guillemet = null
  for (let i = debut; i < src.length; i++) {
    const c = src[i]
    if (guillemet) {
      if (c === guillemet && src[i - 1] !== '\\') guillemet = null
      continue
    }
    if (c === '"' || c === "'" || c === '`') guillemet = c
    else if (c === '{') accolades++
    else if (c === '}') accolades--
    else if (c === '>' && accolades === 0) return src.slice(debut, i + 1)
  }
  return null
}


// Le composant `nom` est-il rendu dans ce corps ? Verification explicite plutot qu'une expression
// reguliere construite : dans un gabarit de chaine, `\s` vaut `s`, et la regex exigeait alors
// litteralement la lettre « s » juste apres le nom. Le controle passait au vert sans rien verifier —
// exactement le defaut qu'il est cense attraper.
function rendCompose(corps, nom) {
  let i = 0
  for (;;) {
    i = corps.indexOf('<' + nom, i)
    if (i === -1) return false
    const suivant = corps[i + nom.length + 1]
    if (suivant === undefined || /[\s/>]/.test(suivant)) return true
    i += 1
  }
}

const sources = new Map(fichiers(RACINE).map((f) => [f, readFileSync(f, 'utf8')]))
const anomalies = []
const court = (f) => f.slice(RACINE.length)

// --- 1. Aucune comparaison brute de droit (D39) -------------------------------------------------
for (const [f, src] of sources) {
  if (court(f) === 'api/droits.js') continue
  src.split('\n').forEach((ligne, i) => {
    if (/\bdroits\s*\.\s*includes\s*\(/.test(ligne) && !ligne.trimStart().startsWith('//')) {
      anomalies.push(
        `${court(f)}:${i + 1} — comparaison brute de droit. Utilise aLeDroit()/aUnDesDroits() : ` +
          'la permission joker `*.lire` ne sera jamais vue par une égalité stricte.',
      )
    }
  })
}

// --- 2. Un composant qui teste un droit doit recevoir `droits` ----------------------------------
const exigent = new Map()
const composants = []

for (const [f, src] of sources) {
  for (const m of src.matchAll(/function\s+([A-Z][A-Za-z0-9_]*)\s*\(\s*\{([^}]*)\}/g)) {
    const [, nom, params] = m
    const corps = corpsFonction(src, m.index + m[0].length)
    // On ne signale que l'usage AVÉRÉ d'un test de droit, pas la simple présence du mot : un
    // composant peut nommer une variable `droits` sans rien tester.
    const teste = /\b(aLeDroit|aUnDesDroits)\s*\(\s*droits\b/.test(corps)
    const recoit = /(^|[,\s])droits\s*(=|,|$)/.test(params)
    if (teste && !recoit) {
      anomalies.push(
        `${court(f)} — « ${nom} » teste un droit sans recevoir \`droits\` en propriété. ` +
          'La valeur sera `undefined` et le test répondra « non » en silence.',
      )
    }
    if (recoit && teste) exigent.set(nom, court(f))
    composants.push({ nom, fichier: court(f), corps, recoit })
  }
}

// Propagation : un composant qui rend un composant exigeant devient exigeant a son tour. Sans cela,
// une propriete oubliee a la racine d'une chaine passe inapercue — un parent peut recevoir `droits`
// et se contenter de les transmettre, c'est meme le cas le plus frequent.
let change = true
while (change) {
  change = false
  for (const c of composants) {
    if (exigent.has(c.nom)) continue
    for (const nomExigeant of exigent.keys()) {
      if (rendCompose(c.corps, nomExigeant)) {
        if (!c.recoit) {
          anomalies.push(
            `${c.fichier} — « ${c.nom} » rend « ${nomExigeant} », qui teste un droit, et ne recoit ` +
              'pas `droits` : la chaine est rompue ici.',
          )
        }
        exigent.set(c.nom, c.fichier)
        change = true
        break
      }
    }
  }
}

// --- 3. Tout usage d'un composant qui exige `droits` doit le lui passer --------------------------
for (const [f, src] of sources) {
  for (const nom of exigent.keys()) {
    for (const m of src.matchAll(new RegExp(`<${nom}(?=[\\s/>])`, 'g'))) {
      const balise = baliseOuvrante(src, m.index)
      if (balise === null) continue
      if (!/\bdroits\s*=/.test(balise)) {
        const ligne = src.slice(0, m.index).split('\n').length
        anomalies.push(
          `${court(f)}:${ligne} — « ${nom} » teste un droit (voir ${exigent.get(nom)}) et ne reçoit ` +
            'pas `droits` ici. Sa fonctionnalité disparaîtra sans aucune erreur.',
        )
      }
    }
  }
}

if (anomalies.length === 0) {
  console.log('✓ Droits : aucune comparaison brute, aucune propriété `droits` manquante.')
  process.exit(0)
}

console.error(`✗ Droits : ${anomalies.length} anomalie(s).\n`)
anomalies.forEach((a) => console.error('  - ' + a))
console.error(
  '\nCes défauts ne produisent ni erreur ni test rouge : ils font simplement disparaître une\n' +
    "fonctionnalité de l'écran. C'est pour ça qu'ils sont vérifiés ici.",
)
process.exit(1)
