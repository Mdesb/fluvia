#!/usr/bin/env node
// GARDE-FOU — aucune encre en dur sur un fond qui change avec le theme.
//
// ── CE QU'IL EXISTE POUR ATTRAPER ──────────────────────────────────────────────────────────────
//
// Le 01/09, `.lien-evitement` — le lien d'evitement au clavier du back-office — ecrivait
// `color: #fff` sur `background: var(--accent)`. Tant que l'accent etait un turquoise sombre, le
// blanc tenait. En theme sombre il vaut un cyan clair, et le blanc y rendait 2,49:1 : l'affordance
// d'accessibilite elle-meme etait illisible, depuis le 31/08.
//
// ⚠ LE CONTROLE DES CONTRASTES NE POUVAIT PAS LE VOIR. Il mesure des JETONS entre eux — il verifie
// que `--sur-accent` se lit sur `--accent`. Il ne verifie pas qu'une REGLE utilise le jeton. Une
// paire de jetons irreprochable et une regle qui l'ignore rendent le meme vert.
//
// C'est la meme forme, un cran plus haut, que celle qu'on corrige toute la journee : un controle
// juste dont le PERIMETRE ne couvre pas ce qu'on croit qu'il couvre.
//
// ── ET POURQUOI UNE RECHERCHE PAR LIGNE NE SUFFIT PAS ──────────────────────────────────────────
//
// En convertissant les huit autres regles de cette famille le matin meme, je les ai trouvees en
// appariant `background` et `color` sur une MEME ligne. `.lien-evitement` est ecrite sur plusieurs
// lignes, et elle est passee. Ce script decoupe donc en REGLES, pas en lignes.

import { readFileSync } from 'node:fs'

// Une couleur ecrite en dur : hexadecimal, fonction de couleur, ou mot-cle opaque. `transparent`,
// `inherit`, `currentColor` et consorts n'en sont pas — ils ne fixent aucune teinte.
const LITTERALE = /^\s*(#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\(|white\b|black\b|silver\b|gray\b|grey\b)/

function regles(css) {
  const out = []
  // On retire les commentaires AVANT de decouper : un `color: #fff` cite dans un commentaire — et
  // ce fichier-ci en cite — ne doit pas etre lu comme une declaration.
  const net = css.replace(/\/\*[\s\S]*?\*\//g, '')
  for (const [, selecteur, corps] of net.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    out.push({ selecteur: selecteur.trim().replace(/\s+/g, ' '), corps })
  }
  return out
}

function fautes(css) {
  const trouvees = []
  for (const { selecteur, corps } of regles(css)) {
    const fond = corps.match(/(?:^|;)\s*background(?:-color)?\s*:([^;]*)/)
    if (!fond || !fond[1].includes('var(--')) continue

    const encre = corps.match(/(?:^|;)\s*color\s*:([^;]*)/)
    if (!encre || !LITTERALE.test(encre[1])) continue

    trouvees.push({ selecteur, fond: fond[1].trim(), encre: encre[1].trim() })
  }
  return trouvees
}

// ── ⚠ TEMOINS — LE DETECTEUR SE PROUVE AVANT DE JUGER ──────────────────────────────────────────
//
// Un detecteur qui ne trouve rien peut etre un detecteur qui ne regarde rien. Et celui-ci a la
// forme la plus trompeuse : quand tout va bien, il est SILENCIEUX. On lui donne donc quatre cas
// dont on connait la reponse — deux qu'il doit signaler, DEUX QU'IL DOIT EPARGNER.
//
// Les temoins d'epargne comptent autant : un detecteur trop large signale des regles saines, on
// prend l'habitude de le contourner, et il cesse de proteger quoi que ce soit.
const TEMOINS = [
  ['.a { background: var(--accent); color: #fff; }', 1, 'encre en dur sur un fond thematique'],
  ['.b {\n  background: var(--accent);\n  color: white;\n}', 1, 'la meme, ecrite sur plusieurs lignes'],
  ['.c { background: var(--accent); color: var(--sur-accent); }', 0, 'epargne : encre en jeton'],
  ['.d { background: #eee; color: #111; }', 0, 'epargne : fond en dur, aucun theme en jeu'],
]

for (const [css, attendu, quoi] of TEMOINS) {
  const n = fautes(css).length
  if (n !== attendu) {
    console.error(`\n=== ÉCHEC — le détecteur ne se comporte pas comme annoncé ===\n`)
    console.error(`Témoin « ${quoi} » : ${n} faute(s) trouvée(s), ${attendu} attendue(s).`)
    console.error('\nTant que ce témoin est faux, le verdict de ce contrôle ne vaut rien —')
    console.error('ni son rouge, ni surtout son vert.')
    process.exit(1)
  }
}

const CSS = readFileSync('src/styles.css', 'utf8')
const trouvees = fautes(CSS)
const total = regles(CSS).length

if (trouvees.length > 0) {
  console.error(`\n=== ÉCHEC — ${trouvees.length} règle(s) écrivent une encre en dur sur un fond thématique ===\n`)
  for (const f of trouvees) {
    console.error(`  ${f.selecteur}`)
    console.error(`      background: ${f.fond}   color: ${f.encre}`)
  }
  console.error('\nUn fond declaré en jeton change avec le thème ; une encre écrite en dur, non.')
  console.error('Le jour où ce fond devient clair, le texte devient illisible — et rien ne le dit,')
  console.error('parce que le contrôle des contrastes mesure les jetons entre eux, pas leur emploi.')
  console.error('\nÉcris `color: var(--sur-accent)`, ou le jeton d’encre qui correspond à ce fond.')
  process.exit(1)
}

console.log(`✓ Encre sur fond : aucune encre en dur sur un fond thématique. ${total} règle(s) lues.`)
console.log(`  (4 témoins passés, dont 2 qui prouvent ce que le détecteur épargne.)`)
