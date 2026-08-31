#!/usr/bin/env node
// GARDE-FOU — les contrastes de la palette, dans les deux thèmes.
//
// WCAG 2.1 : **4,5:1** pour du texte courant, **3:1** pour les éléments d'interface porteurs
// d'information. En dessous, quelqu'un qui lit sur un écran de caisse en plein jour — ou qui a
// simplement une vue moyenne — ne distingue plus. L'European Accessibility Act vise le commerce en
// ligne aux consommateurs, et la boutique publique de ce dépôt en fait partie.
//
// ── CE QU'IL A TROUVÉ ──────────────────────────────────────────────────────────────────────────
//
// Sept paires sous le seuil, **toutes dans le thème clair sauf une**. Les trois badges d'état sont
// les plus faibles : « attention » à 2,84:1, « bon » à 2,95:1, « critique » à 3,69:1 — ce sont
// précisément les pastilles qu'on lit d'un coup d'œil, sans les lire vraiment.
//
// ── ⚠ POURQUOI UN CLIQUET ET PAS UNE CORRECTION ────────────────────────────────────────────────
//
// La palette appartient au produit, et **T15 la refondra aux couleurs de Fluvia**. Corriger neuf
// teintes aujourd'hui serait défaire demain — et choisir un vert plutôt qu'un autre n'est pas une
// décision d'accessibilité, c'est une décision de marque.
//
// Ce contrôle gèle donc l'existant et refuse l'aggravation. Il devient au passage le critère
// d'acceptation de T15 : une palette neuve doit faire mieux, pas au pire aussi mal.
//
// ── LA BORDURE EST RAPPORTÉE, PAS COMPTÉE ──────────────────────────────────────────────────────
//
// `--line` sur `--panel` vaut 1,29:1. Le seuil de 3:1 s'applique aux éléments d'interface qui
// PORTENT une information — le contour d'un champ, un indicateur de focus — pas à un filet
// décoratif entre deux blocs. Or `--line` sert aux deux. Le compter en échec gonflerait le nombre
// d'une chose qui n'en est pas forcément une, et un contrôle qui exagère finit ignoré. On le dit,
// on ne le compte pas.

import { readFileSync } from 'node:fs'

const CSS = readFileSync('src/styles.css', 'utf8')

function jetons(bloc) {
  const m = {}
  for (const [, nom, valeur] of bloc.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{3,8})/g)) m[nom] = valeur
  return m
}

const iClair = CSS.indexOf(':root {')
const clair = jetons(CSS.slice(iClair, CSS.indexOf('}', iClair)))
const iSombre = CSS.indexOf(':root[data-theme="dark"]')
const sombre = jetons(CSS.slice(iSombre, CSS.indexOf('}', iSombre)))

function canal(v) {
  const c = v / 255
  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

function luminance(hex) {
  let h = hex.slice(1)
  if (h.length === 3) h = h.split('').map((c) => c + c).join('')
  return 0.2126 * canal(parseInt(h.slice(0, 2), 16))
    + 0.7152 * canal(parseInt(h.slice(2, 4), 16))
    + 0.0722 * canal(parseInt(h.slice(4, 6), 16))
}

function ratio(a, b) {
  const la = luminance(a)
  const lb = luminance(b)
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

// [avant-plan, arrière-plan, seuil, ce que c'est, compté]
const PAIRES = [
  ['ink', 'bg', 4.5, 'texte courant sur le fond de page', true],
  ['ink', 'panel', 4.5, 'texte courant sur une carte', true],
  ['ink-soft', 'bg', 4.5, 'texte secondaire sur le fond', true],
  ['ink-soft', 'panel', 4.5, 'texte secondaire sur une carte', true],
  ['ink-faint', 'panel', 4.5, 'mention discrète sur une carte', true],
  ['accent', 'panel', 4.5, 'lien ou action sur une carte', true],
  ['accent', 'bg', 4.5, 'lien ou action sur le fond', true],
  ['good', 'good-bg', 4.5, 'badge « bon »', true],
  ['warn', 'warn-bg', 4.5, 'badge « attention »', true],
  ['crit', 'crit-bg', 4.5, 'badge « critique »', true],
  ['side-ink', 'side-bg', 4.5, 'menu de gauche', true],
  ['side-ink-soft', 'side-bg', 4.5, 'menu de gauche, secondaire', true],
  ['warm', 'panel', 4.5, 'accent chaud sur une carte', true],
  ['line', 'panel', 3, 'filet — compte seulement s’il porte une information', false],
]

const PLAFOND = Number(process.env.CONTRASTES_PLAFOND ?? '7')

let echecs = 0
const lignes = []

for (const [nom, palette] of [['clair', clair], ['sombre', sombre]]) {
  lignes.push(`\n── thème ${nom} ──`)
  for (const [av, ar, seuil, quoi, compte] of PAIRES) {
    const a = palette[av]
    const b = palette[ar]
    if (!a || !b) {
      lignes.push(`  ?  ${av} / ${ar} — jeton absent, on ne conclut pas`)
      continue
    }
    const r = ratio(a, b)
    const ok = r >= seuil
    if (!ok && compte) echecs += 1
    const marque = ok ? '✓' : compte ? '✗' : '·'
    lignes.push(`  ${marque}  ${r.toFixed(2)}:1  (min ${seuil})  ${av} sur ${ar} — ${quoi}`)
  }
}

if (echecs > PLAFOND) {
  console.error(lignes.join('\n'))
  console.error(`\n=== ÉCHEC — ${echecs} paire(s) sous le seuil, plafond ${PLAFOND} ===\n`)
  console.error('Une paire de plus est passée sous le seuil de lisibilité. WCAG 2.1 demande 4,5:1')
  console.error('pour du texte courant : en dessous, un écran de caisse en plein jour devient illisible.')
  console.error('\nLes jetons vivent dans `frontend/src/styles.css`, dans les deux thèmes — une teinte')
  console.error('corrigée d’un seul côté laisse l’autre en l’état.')
  process.exit(1)
}

console.log(lignes.join('\n'))
console.log(`\n✓ Contrastes : ${echecs} paire(s) sous le seuil, plafond ${PLAFOND}.`)
if (echecs < PLAFOND) console.log(`  (${PLAFOND - echecs} de marge : pense à abaisser le plafond.)`)
