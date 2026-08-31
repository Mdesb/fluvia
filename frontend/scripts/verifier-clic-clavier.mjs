#!/usr/bin/env node
// GARDE-FOU — un élément non interactif rendu cliquable, sans aucun chemin au clavier.
//
// ── ⚠ « 2 `tabIndex` SUR 115 FICHIERS » NE SE LIT PAS COMME UN DÉFICIT ─────────────────────────
//
// `<button>`, `<a href>`, `<input>`, `<select>` reçoivent le focus SANS qu'on écrive quoi que ce
// soit. Ajouter `tabIndex` est le plus souvent une mauvaise odeur : on l'écrit quand on a rendu
// cliquable ce qui ne l'était pas. Un dépôt qui en compte deux peut être en très bonne santé —
// exactement comme celui qui comptait « 6 fichiers avec `alt` » l'était.
//
// LE VRAI DÉFAUT : un `<div onClick>`, un `<tr onClick>`. La souris l'atteint, le clavier jamais.
// Pas de focus, pas d'Entrée, et aucun lecteur d'écran ne l'annonce comme actionnable.
//
// ── CE QUI INNOCENTE, ET POURQUOI CHAQUE PORTE EXISTE ──────────────────────────────────────────
//
// **Un élément interactif à l'intérieur.** C'est le bon remède pour une ligne de tableau, et le
// seul : poser `role="button"` sur un `<tr>` casse la structure que les lecteurs d'écran annoncent
// — plus de « ligne 4 sur 120 », plus de navigation par colonnes. On réparerait l'accès en
// détruisant la lecture. Un vrai bouton dans la cellule identifiante reçoit le focus par nature,
// répond à Entrée et Espace sans câblage, et le tableau reste un tableau.
//
// ⚠ Cette porte est LARGE : une ligne dont le seul bouton fait autre chose est innocentée à tort.
// C'est le sens où l'on veut se tromper — un contrôle qui accuse des écrans justes finit désactivé,
// et celui-ci a déjà servi à en corriger cinq.
//
// **`role` + `tabIndex` + une touche**, les trois ensemble : la panoplie complète d'un faux bouton.
// Deux sur trois est pire que zéro — un élément focusable qui ne répond pas à Entrée est un piège.
//
// **`stopPropagation` ou `preventDefault` seuls** : ce n'est pas une action, c'est l'annulation
// d'une autre. Il n'y a rien à atteindre.
//
// **Le marqueur `@clic-souris-seul:`**, avec sa raison. Pour un voile de fermeture, par exemple :
// il double une action que le clavier atteint déjà autrement, et le forcer dans l'ordre de
// tabulation ajouterait un arrêt qui ne dit rien.

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const RACINE = 'src'

const INERTES = new Set(['div', 'span', 'li', 'td', 'tr', 'p', 'section', 'article', 'header', 'footer', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'nav', 'aside', 'figure'])

function fichiers(dir) {
  const out = []
  for (const e of readdirSync(dir)) {
    const p = join(dir, e)
    if (statSync(p).isDirectory()) out.push(...fichiers(p))
    else if (/\.jsx?$/.test(e)) out.push(p)
  }
  return out
}

let total = 0
const nus = []

for (const chemin of fichiers(RACINE)) {
  const src = readFileSync(chemin, 'utf8')

  // ⚠ LES COMMENTAIRES DISPARAISSENT, LEURS SAUTS DE LIGNE RESTENT. Les remplacer par un espace
  // décalait toutes les positions suivantes, et le contrôle envoyait à une ligne où il n'y a rien.
  // Un contrôle qui a raison et qu'on ne peut pas vérifier finit ignoré.
  const propre = src
    .replace(/\/\*[\s\S]*?\*\//g, (c) => c.replace(/[^\n]/g, ' '))
    .replace(/(?<![:\w])\/\/.*$/gm, '')

  const marques = new Set()
  for (const m of src.matchAll(/@clic-souris-seul:\s*(\S+)/g)) marques.add(m[1])

  // ⚠ `[^<>]` NE DOIT PAS AVALER L'ACCOLADE, ET C'EST TOUT LE PIÈGE.
  //
  // Avec `[^<>]` permissif, la branche paresseuse consommait le `{` d'un `onClick={(e) => …}`, et
  // la balise se refermait sur le `>` du `=>`. Les attributs lus étaient tronqués — ` onClick={(e) =` —
  // donc l'exemption « ce n'est qu'un `stopPropagation` » ne se déclenchait jamais, et un `<td>` qui
  // annule une propagation était accusé. Découvert en éprouvant le cas que le contrôle doit ACCEPTER,
  // pas celui qu'il doit refuser.
  //
  // On exclut donc `{` de la branche simple : toute accolade passe par la branche dédiée, qui tolère
  // un niveau d'imbrication (`style={{ … }}`).
  const re = /<([a-z][a-z0-9]*)\b((?:[^<>{]|\{(?:[^{}]|\{[^{}]*\})*\})*?)(\/?)>/g
  let m
  while ((m = re.exec(propre)) !== null) {
    const [, balise, attributs, ferme] = m
    if (!INERTES.has(balise) || !/\bonClick\s*=/.test(attributs)) continue

    total += 1

    // Une annulation n'est pas une action.
    const gestionnaire = attributs.match(/onClick\s*=\s*\{([^{}]*)\}/)?.[1] ?? ''
    if (/^\s*\(?[\w]*\)?\s*=>\s*\w+\.(stopPropagation|preventDefault)\(\)\s*$/.test(gestionnaire)) continue

    if (marques.has(balise) || marques.has(attributs.match(/className="([\w-]+)/)?.[1] ?? '')) continue

    const aRole = /\brole\s*=/.test(attributs)
    const aTab = /\btabIndex\s*=/.test(attributs)
    const aTouche = /\bonKey(Down|Up|Press)\s*=/.test(attributs)
    if (aRole && aTab && aTouche) continue

    // Un élément interactif à l'intérieur : le clavier a une cible.
    if (ferme !== '/') {
      const fin = propre.indexOf(`</${balise}>`, re.lastIndex)
      const contenu = fin === -1 ? '' : propre.slice(re.lastIndex, fin)
      if (/<button\b|<a\s[^>]*href|<input\b|<select\b|<textarea\b/.test(contenu)) continue
    }

    const ligne = propre.slice(0, m.index).split('\n').length
    nus.push({ chemin, ligne, balise, extrait: m[0].replace(/\s+/g, ' ').slice(0, 100) })
  }
}

if (nus.length > 0) {
  console.error('\n=== ÉCHEC — cliquable à la souris, inatteignable au clavier ===\n')
  for (const n of nus) {
    console.error(`  ${n.chemin}:${n.ligne}  <${n.balise}>`)
    console.error(`    ${n.extrait}\n`)
  }
  console.error('TROIS SORTIES.')
  console.error('  1. Mettre un vrai `<button>` dans l’élément — le meilleur remède pour une ligne de')
  console.error('     tableau. ⚠ Ne PAS poser `role="button"` sur un `<tr>` : cela casse la structure')
  console.error('     que les lecteurs d’écran annoncent, et répare l’accès en détruisant la lecture.')
  console.error('  2. `role` + `tabIndex` + `onKeyDown`, les trois — pour un faux bouton hors tableau.')
  console.error('     Deux sur trois est pire que zéro : un élément focusable muet à Entrée est un piège.')
  console.error('  3. `// @clic-souris-seul: <balise ou classe>  <raison>` si le geste double un chemin')
  console.error('     que le clavier atteint déjà — un voile de fermeture, par exemple.')
  console.error(`\nMesure : ${nus.length} sur ${total} élément(s) inerte(s) rendu(s) cliquable(s).`)
  process.exit(1)
}

console.log(`✓ Clic au clavier : ${total} élément(s) inerte(s) cliquable(s), tous atteignables au clavier.`)
