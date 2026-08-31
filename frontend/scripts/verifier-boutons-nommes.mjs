#!/usr/bin/env node
// GARDE-FOU — un bouton qu'un lecteur d'écran annonce « bouton », et rien d'autre.
//
// ── LE DÉFAUT ───────────────────────────────────────────────────────────────────────────────────
//
// Neuf boutons du back-office portaient `↻` pour tout contenu. Un symbole n'a pas de nom
// accessible : il n'est même pas prononçable. Quelqu'un qui navigue au clavier avec une synthèse
// vocale entend « bouton », sur un écran qui en compte parfois trente — et le seul moyen de savoir
// ce qu'il déclenche est de l'essayer. Certains essais ne se défont pas.
//
// L'European Accessibility Act vise le commerce en ligne aux consommateurs ; la boutique publique
// de ce dépôt en fait partie. Mais l'argument légal n'est pas le meilleur : un bouton sans nom est
// aussi celui dont personne ne sait dire ce qu'il fait en relisant le code six mois plus tard.
//
// ── ⚠ DEUX MESURES FAUSSES AVANT CELLE-CI, ET ELLES DISENT COMMENT NE PAS MESURER ──────────────
//
// 1. Chercher `<img` sans `alt=` PAR LIGNE : trois manques annoncés, trois `alt` présents à la
//    ligne suivante. En JSX les attributs d'un élément vivent sur plusieurs lignes ; une mesure par
//    ligne ne mesure rien. Le vrai compte d'images sans `alt` est ZÉRO.
//
// 2. Supprimer les expressions `{…}` avant de chercher du texte : un bouton libellé
//    `{enCours ? 'Création…' : 'Créer'}` perdait tout son contenu et paraissait anonyme.
//    107 accusations, presque toutes fausses.
//
// ── LA RÈGLE ────────────────────────────────────────────────────────────────────────────────────
//
// Un bouton a un nom s'il porte l'une de ces choses :
//
//   · une lettre HORS accolades — du texte écrit ;
//   · une chaîne littérale contenant une lettre DANS les accolades — un libellé calculé ;
//   · un appel de fonction dans le contenu — `{jourLabel(j)}` rend du texte qu'on ne peut pas lire
//     ici, et l'innocenter est le sens où l'on veut se tromper ;
//   · `aria-label`, `aria-labelledby` ou `title`.
//
// `title` compte : il fournit un nom accessible, faute de mieux, et une infobulle par-dessus. On
// refuse l'absence des quatre, pas le choix entre eux.
//
// ── PAS DE CLIQUET, ET C'EST DÉLIBÉRÉ ──────────────────────────────────────────────────────────
//
// La dette est à zéro après ce lot. Un plafond n'aurait rien à geler, et un plafond à zéro qui se
// lit comme un cliquet ferait croire à une dette. Ici l'invariant est net : tout bouton se nomme.

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const RACINE = 'src'

function fichiers(dir) {
  const out = []
  for (const e of readdirSync(dir)) {
    const p = join(dir, e)
    if (statSync(p).isDirectory()) out.push(...fichiers(p))
    else if (/\.jsx?$/.test(e)) out.push(p)
  }
  return out
}

const LETTRE = /[\p{L}]/u

function porteUnNom(contenu) {
  const enClair = contenu.replace(/<[^>]*>/g, ' ').replace(/\{[^{}]*\}/g, ' ')
  if (LETTRE.test(enClair)) return true

  for (const m of contenu.matchAll(/['"`]([^'"`]*)['"`]/g)) {
    if (LETTRE.test(m[1] ?? '')) return true
  }

  // Un appel de fonction dans le contenu est un libellé, jusqu'à preuve du contraire.
  if (/\{[^{}]*\w\s*\(/.test(contenu)) return true

  return false
}

let total = 0
const nus = []

for (const chemin of fichiers(RACINE)) {
  const src = readFileSync(chemin, 'utf8')

  // ⚠ ON NE LIT PAS LES COMMENTAIRES. Un exemple de code dans une explication n'est pas du code —
  // une sonde qui lit ses propres explications confirme tout ce qu'on y écrit.
  const propre = src.replace(/\/\*[\s\S]*?\*\//g, (c) => c.replace(/[^\n]/g, ' ')).replace(/(?<![:\w])\/\/.*$/gm, '')

  const re = /<button\b([^>]*)>([\s\S]*?)<\/button>/g
  let m
  while ((m = re.exec(propre)) !== null) {
    total += 1
    if (porteUnNom(m[2])) continue
    if (/aria-label\s*=|aria-labelledby\s*=|title\s*=/.test(m[1])) continue

    const ligne = propre.slice(0, m.index).split('\n').length
    nus.push({ chemin, ligne, extrait: m[0].replace(/\s+/g, ' ').slice(0, 120) })
  }
}

if (nus.length > 0) {
  console.error('\n=== ÉCHEC — un bouton sans nom accessible ===\n')
  for (const n of nus) {
    console.error(`  ${n.chemin}:${n.ligne}`)
    console.error(`    ${n.extrait}\n`)
  }
  console.error('Un lecteur d’écran annonce « bouton » et rien d’autre : le seul moyen de savoir ce')
  console.error('qu’il déclenche est de l’essayer, et certains essais ne se défont pas.\n')
  console.error('TROIS SORTIES.')
  console.error('  1. `title="…"` — le nom accessible ET une infobulle pour ceux qui voient. Le plus')
  console.error('     utile quand l’icône est un raccourci que tout le monde gagne à voir nommé.')
  console.error('  2. `aria-label="…"` — le nom sans l’infobulle, quand le sens est évident à l’œil.')
  console.error('  3. Mettre du texte dans le bouton. Le meilleur des trois quand la place le permet :')
  console.error('     il sert aussi ceux qui ne reconnaissent pas le symbole.')
  console.error(`\nMesure : ${nus.length} bouton(s) sans nom sur ${total} lus.`)
  process.exit(1)
}

console.log(`✓ Boutons nommés : ${total} bouton(s) lus, tous porteurs d’un nom accessible.`)
