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

// ⚠ DEUX FICHIERS, PAS UN. Ce script ne lisait que `src/styles.css`. `vitrine/styles.css` porte sa
// propre palette et cinq paires sous le seuil que RIEN ne mesurait — pas un faux vert : aucune
// mesure du tout, ce qui ne laisse aucune prise. Signale par `allaccess-8e` le 01/09.
//
// La vitrine n'est pas servie aujourd'hui. C'est exactement pour ca qu'elle doit etre mesuree
// maintenant : le jour ou quelqu'un la publie, personne ne repassera par ici.
//
// ⚠ `horsPerimetre` EST UNE PORTE, ET ELLE EST GARDEE. Une paire n'y entre que si le fichier ne
// porte AUCUN de ses deux jetons — c'est verifie plus bas, et declarer hors perimetre une paire
// mesurable est un echec. Sans cette garde, « ca ne s'applique pas ici » serait la facon la plus
// simple de faire taire un vrai defaut, sans laisser la moindre trace dans la sortie.
const FICHIERS = [
  {
    nom: 'produit',
    chemin: 'src/styles.css',
    horsPerimetre: {},
  },
  {
    nom: 'vitrine',
    chemin: '../vitrine/styles.css',
    horsPerimetre: {
      'warn/warn-bg': "la vitrine n'affiche aucun badge d'alerte",
      'crit/crit-bg': "la vitrine n'affiche aucun badge d'alerte",
      'side-ink/side-bg': "la vitrine n'a pas de menu de gauche",
      'side-ink-soft/side-bg': "la vitrine n'a pas de menu de gauche",
      'sur-accent/accent': "la vitrine n'a pas de bouton d'accent plein",
    },
  },
]

function jetons(bloc) {
  const m = {}
  // Les hexadecimaux ecrits en dur, ET les alias `--x: var(--y)`. Un alias n'est pas un cas
  // marginal : c'est la forme meme du point d'entree marque blanche (`--accent-marque:
  // var(--marque-bleu)`). Sans lui, un jeton parfaitement defini sortirait « absent » — donc en
  // echec, depuis que l'absence est bruyante.
  for (const [, nom, valeur] of bloc.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{3,8}|var\(\s*--[\w-]+\s*\))/g)) {
    m[nom] = valeur
  }
  return m
}

// ⚠ ON RESOUT CE QUE LE NAVIGATEUR CALCULE, PAS CE QUI EST ECRIT. Une chaine d'alias se suit
// jusqu'a l'hexadecimal. La borne a 10 sauts n'est pas de la prudence decorative : un cycle
// `--a: var(--b); --b: var(--a)` est du CSS syntaxiquement valide, et il ferait tourner ce script
// indefiniment au lieu de rendre un verdict.
function resoudre(palette) {
  const sortie = {}
  for (const nom of Object.keys(palette)) {
    let v = palette[nom]
    for (let saut = 0; saut < 10 && v && v.startsWith('var('); saut += 1) {
      v = palette[v.slice(v.indexOf('--') + 2, v.lastIndexOf(')')).trim()]
    }
    if (v && v.startsWith('#')) sortie[nom] = v
  }
  return sortie
}

// ⚠ TOUS LES BLOCS `:root` NUS, PAS LE PREMIER — ET C'EST UN DEFAUT VECU.
//
// Ce fichier lisait `CSS.indexOf(':root {')`, donc le premier bloc rencontre. Le 01/09, deux blocs
// d'identite (les jetons `--marque-*`, puis `--accent-marque` / `--sur-accent`) ont ete poses AVANT
// la palette : l'index a cesse de designer la palette, et le theme clair n'a plus ete mesure. Rien
// ne l'a signale — voir la note sur l'absence, plus bas.
//
// On modelise donc la cascade telle que le navigateur l'applique : la valeur effective d'un jeton
// est la DERNIERE declaration parmi tous les blocs `:root` nus. Aucun ordre d'ecriture ne peut plus
// rendre ce controle aveugle.
//
// `:root[data-theme="dark"]` et `:root:not([data-theme="light"])` ne matchent pas `':root {'` —
// l'un porte un crochet, l'autre deux points — donc seuls les blocs nus sont fusionnes ici.
function blocsRacine(css, selecteur) {
  const blocs = []
  let i = css.indexOf(selecteur)
  while (i !== -1) {
    // Comptage d'accolades plutot que le premier `}` : un bloc peut contenir une `@media`
    // imbriquee, et s'arreter a la premiere fermeture couperait la palette en deux.
    let profondeur = 0
    let j = css.indexOf('{', i)
    const debut = j
    for (; j < css.length; j += 1) {
      if (css[j] === '{') profondeur += 1
      else if (css[j] === '}' && (profondeur -= 1) === 0) break
    }
    blocs.push(css.slice(debut, j))
    i = css.indexOf(selecteur, j)
  }
  return blocs
}

// ⚠ ON SEPARE LES DEUX THEMES AVANT D'EXTRAIRE, PARCE QU'ILS PARTAGENT PARFOIS LE SELECTEUR.
//
// `src/styles.css` ecrit son sombre en `:root[data-theme="dark"]` et
// `:root:not([data-theme="light"])` — deux selecteurs qui ne matchent pas `':root {'`.
// `vitrine/styles.css` l'ecrit en `:root { ... }` DANS une `@media (prefers-color-scheme: dark)` :
// meme selecteur que le clair.
//
// Fusionner sans distinguer donnerait, pour la vitrine, un theme unique melant les deux — un objet
// bien rempli, parfaitement faux, sur lequel le temoin des jetons fondamentaux passerait sans
// broncher. C'est le piege que `allaccess-8e` a nomme avant que j'y tombe.
function scinderMediaSombre(css) {
  const MARQUEUR = '@media (prefers-color-scheme: dark)'
  let hors = ''
  let sombre = ''
  let i = 0

  for (;;) {
    const debut = css.indexOf(MARQUEUR, i)
    if (debut === -1) { hors += css.slice(i); break }

    hors += css.slice(i, debut)

    // Comptage d'accolades : une `@media` contient des blocs, s'arreter au premier `}` la couperait.
    let profondeur = 0
    let j = css.indexOf('{', debut)
    const ouvrante = j
    for (; j < css.length; j += 1) {
      if (css[j] === '{') profondeur += 1
      else if (css[j] === '}' && (profondeur -= 1) === 0) break
    }
    sombre += css.slice(ouvrante + 1, j)
    i = j + 1
  }

  return { hors, sombre }
}

function palettes(css) {
  const { hors, sombre: dansMedia } = scinderMediaSombre(css)

  const clair = {}
  for (const bloc of blocsRacine(hors, ':root {')) Object.assign(clair, jetons(bloc))

  // Le sombre HERITE du clair : il ne redefinit que ce qu'il change. Deux sources, dans l'ordre de
  // la cascade — le `:root` nu d'une @media sombre (vitrine), puis le `[data-theme="dark"]`
  // explicite (produit), qui l'emporte parce qu'un choix de l'utilisateur bat une preference systeme.
  const sombre = { ...clair }
  for (const bloc of blocsRacine(dansMedia, ':root {')) Object.assign(sombre, jetons(bloc))
  for (const bloc of blocsRacine(css, ':root[data-theme="dark"]')) Object.assign(sombre, jetons(bloc))

  return { clair: resoudre(clair), sombre: resoudre(sombre) }
}



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
  // ⚠ CETTE PAIRE MANQUAIT, ET `styles.css` ANNONCAIT SON ABSENCE.
  //
  // Le commentaire de `--sur-accent` dit : « le jour ou un club choisit un accent clair, le texte
  // devient illisible sur ses propres boutons — et rien ne le signale ». C'etait exact, y compris
  // sur le « rien ne le signale » : le controle n'existait pas. Huit regles ecrivent
  // `color: var(--sur-accent)` sur `background: var(--accent)` — `.btn.primary`, `.pay-chip.on`,
  // `.bq-skip`, `.bq-cart-count`, `.msgr-b.moi` et trois selections de calendrier.
  //
  // C'est aussi le seul point d'entree de la marque blanche (T11) : un club repeint `--accent`, et
  // cette paire est ce qui l'empeche de rendre ses propres boutons illisibles.
  // Quatre regles ecrivent `--accent-2` sur un fond `--accent-soft` : `.badge.info`,
  // `.banner-info`, `.fiche-avatar`, `.bq-nav-link.on`. La paire passait deja avant le
  // repeint (6,72 et 6,93) — elle est ajoutee pour qu'elle ne puisse plus se degrader sans
  // que rien ne le dise, pas pour corriger un defaut.
  // `--ink` sur `--accent-soft` : la tuile de produit selectionnee en caisse, la ligne de
  // tableau active, la zone de depot survolee, l'encadre de moyen de paiement. Quatre
  // emplois, aucun mesure jusqu'ici.
  ['ink', 'accent-soft', 4.5, 'texte courant sur un fond d’accent doux', true],
  ['accent-2', 'accent-soft', 4.5, 'texte sur un fond d’accent doux', true],
  ['sur-accent', 'accent', 4.5, 'texte sur un bouton d’accent — point d’entrée marque blanche', true],
]

// ⚠ ZERO, ET C EST UN INVARIANT, PLUS UN CLIQUET.
//
// Le plafond a valu 7 le temps d une decision : la palette appartient au produit, et corriger neuf
// teintes avant que l identite soit tranchee aurait ete defaire ensuite. Maxime a tranche le 31/08
// — « tout, y compris le turquoise » — et les sept sont corrigees.
//
// On ne bouge donc plus une teinte vers le bas. La variable d environnement reste pour eprouver le
// controle sans toucher au fichier.
const PLAFOND = Number(process.env.CONTRASTES_PLAFOND ?? '0')

let echecs = 0
const lignes = []

for (const fichier of FICHIERS) {
  const css = readFileSync(fichier.chemin, 'utf8')
  const { clair: clairResolu, sombre: sombreResolu } = palettes(css)

  // ⚠ TEMOIN : L'EXTRACTION A-T-ELLE LU LA PALETTE, OU UN BLOC QUELCONQUE ?
  //
  // C'est la question que personne ne posait, et elle a coute le controle pendant un commit. Un
  // selecteur qui glisse rend un objet non vide — il rend les MAUVAIS jetons. On exige donc que les
  // deux themes portent les fonds et les encres, sans quoi on ne mesure pas : on refuse.
  for (const [theme, palette] of [['clair', clairResolu], ['sombre', sombreResolu]]) {
    const manquants = ['bg', 'panel', 'ink', 'accent'].filter((j) => !palette[j])
    if (manquants.length > 0) {
      console.error(`\n=== ÉCHEC — ${fichier.nom} : le thème ${theme} n'a pas pu être lu ===\n`)
      console.error(`Jetons fondamentaux introuvables : ${manquants.join(', ')}.`)
      console.error(`\nL'extraction ne désigne plus la palette de \`${fichier.chemin}\`. Tant que ce`)
      console.error('témoin est rouge, ce garde-fou ne mesure RIEN — et un vert serait un mensonge.')
      process.exit(1)
    }
  }

  lignes.push(`\n════ ${fichier.nom} — ${fichier.chemin} ════`)

  // ⚠ LA DECLARATION HORS PERIMETRE EST ELLE-MEME VERIFIEE. Exempter une paire dont les deux jetons
  // existent, c'est faire taire une mesure possible — la porte ne s'ouvre que sur du vide.
  for (const [cle, raison] of Object.entries(fichier.horsPerimetre)) {
    const [av, ar] = cle.split('/')
    if (clairResolu[av] && clairResolu[ar]) {
      console.error(`\n=== ÉCHEC — ${fichier.nom} : « ${cle} » est déclarée hors périmètre ===\n`)
      console.error(`Raison invoquée : ${raison}`)
      console.error('Or les deux jetons EXISTENT dans ce fichier : la paire est mesurable.')
      console.error('Une exemption ne peut porter que sur ce qui est absent, jamais sur ce qui gêne.')
      process.exit(1)
    }
    lignes.push(`  ·  ${cle} — hors périmètre : ${raison}`)
  }

  for (const [theme, palette] of [['clair', clairResolu], ['sombre', sombreResolu]]) {
    lignes.push(`\n── thème ${theme} ──`)
    for (const [av, ar, seuil, quoi, compte] of PAIRES) {
    if (fichier.horsPerimetre[`${av}/${ar}`]) continue
    const a = palette[av]
    const b = palette[ar]
    // ⚠ UNE PAIRE QU'ON NE PEUT PAS MESURER EST UN ECHEC, PAS UNE ABSTENTION.
    //
    // Ce bloc disait « on ne conclut pas » et passait au suivant. Le 01/09, les quatorze paires du
    // theme clair sont sorties ainsi — et la ligne finale a quand meme annonce « 0 paire(s) sous le
    // seuil, plafond 0 ». Un controle qui s'abstient en silence rend le meme vert qu'un controle
    // qui a mesure. C'est la forme la plus couteuse du faux vert : le cliquet etait a zero depuis
    // l'arbitrage du 31/08, et il ne retenait plus rien.
    if (!a || !b) {
      const absents = [!a && av, !b && ar].filter(Boolean).join(', ')
      lignes.push(`  ✗  ${av} / ${ar} — NON MESURÉE : jeton absent (${absents})`)
      if (compte) echecs += 1
      continue
    }
    const r = ratio(a, b)
    const ok = r >= seuil
    if (!ok && compte) echecs += 1
    const marque = ok ? '✓' : compte ? '✗' : '·'
    lignes.push(`  ${marque}  ${r.toFixed(2)}:1  (min ${seuil})  ${av} sur ${ar} — ${quoi}`)
    }
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
