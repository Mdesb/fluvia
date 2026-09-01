// GARDE-FOU n°45 — UNE ENTREE DE MENU QUI DESIGNE UN DESSIN INEXISTANT.
//
// ── LE DEFAUT ───────────────────────────────────────────────────────────────────────────────────
//
// `AppShell.jsx` declare chaque entree du menu avec un nom d'icone : `{ id: 'legal', ic: 'legal' }`.
// `Icon.jsx` resout ce nom dans une table : `ICONS[name]`. Une cle absente rend `undefined`.
//
// Mesure du 01/09 : 35 dessins definis, 36 entrees de menu, QUATRE noms sans dessin — `dashboard`,
// `legal`, `personal-data`, `social`. Le premier est celui du TABLEAU DE BORD, la premiere entree
// du menu de tout le monde.
//
// ⚠ CE N'EST PAS UNE ABSENCE INVISIBLE, ET J'AI ECRIT LE CONTRAIRE AVANT DE REGARDER.
//
// `Icon` fait `{drawing || FALLBACK}` : un nom inconnu rend un carre barre. J'avais ecrit dans
// `Icon.jsx` que le `<svg>` sortait vide — infere de `ICONS[name] === undefined`, sans lire ce que
// le composant en fait deux ecrans plus bas. Ce FALLBACK etait la depuis 14h07, des heures avant ma
// phrase, et il est delibere : un repli BRUYANT, parce qu'une icone qui ne dessine rien laisse une
// ligne sans sa puce sans que l'alignement bouge — donc sans que personne le voie. Le comptage
// etait mesure ; la consequence a l'ecran etait inventee.
//
// Ce que ce controle evite est donc un CARRE BARRE sur « Tableau de bord » — laid et visible, pas
// silencieux. Le build reste vert, aucun test ne tombe, et personne ne l'a signale pendant des
// semaines : visible ne veut pas dire vu.
//
// ── CE QU'IL LIT, ET CE QU'IL NE LIT PAS ────────────────────────────────────────────────────────
//
// Il ne parse pas le JSX. Il s'appuie sur deux formes stables :
//
//     dans Icon.jsx      une entree de premier niveau de `const ICONS = {` est indentee de deux
//                        espaces exactement, et se termine par `: (`
//     dans les ecrans    `ic: 'nom'` (une entree de menu) et `<Icon name="nom"` (un appel direct)
//
// Un appel dont le nom est CALCULE (`<Icon name={quelqueChose} />`) est hors de portee : on ne peut
// pas savoir sans executer. Il y en a deux aujourd'hui, tous deux `name={it.ic}` — donc couverts
// par l'autre bout, la declaration du menu. Le controle le dit plutot que de le taire.
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const FICHIER_ICONES = 'src/components/Icon.jsx'

// ── Les deux extracteurs, isoles pour que les temoins puissent les appeler ──────────────────────

function lireDessins(source) {
  const dessins = new Set()
  const lignes = source.split('\n')
  let dedans = false

  for (const ligne of lignes) {
    if (/^const ICONS = \{\s*$/.test(ligne)) { dedans = true; continue }
    if (dedans && /^\}/.test(ligne)) break
    if (!dedans) continue

    // `  nom: (`  ou  `  'nom-compose': (`
    const m = /^ {2}(?:'([^']+)'|([A-Za-z_$][A-Za-z0-9_$]*)): \(/.exec(ligne)
    if (m) dessins.add(m[1] || m[2])
  }

  return dessins
}

function lireUsages(source, chemin) {
  const usages = []
  const lignes = source.split('\n')

  for (let i = 0; i < lignes.length; i++) {
    for (const m of lignes[i].matchAll(/\bic: '([^']+)'/g)) {
      usages.push({ nom: m[1], chemin, ligne: i + 1, forme: 'entree de menu' })
    }
    for (const m of lignes[i].matchAll(/<Icon\s+name="([^"]+)"/g)) {
      usages.push({ nom: m[1], chemin, ligne: i + 1, forme: 'appel direct' })
    }
  }

  return usages
}

function compterNomsCalcules(source) {
  return (source.match(/<Icon\s+name=\{/g) || []).length
}

// ── LES TEMOINS ─────────────────────────────────────────────────────────────────────────────────
//
// ⚠ DEUX MOITIES, ET LA SECONDE COMPTE AUTANT. Un extracteur qui rend un ensemble VIDE declarerait
// toutes les entrees fautives ; un extracteur d'usages vide rendrait un vert parfait sur un ecran
// entierement casse. Les temoins prouvent donc qu'il VOIT une faute posee expres, et qu'il EPARGNE
// un nom correct — parce qu'un detecteur qui signale tout est aussi inutile qu'un qui ne signale
// rien, et les deux passent pour « vert » selon le sens ou on les lit.
function temoins() {
  const passes = []

  const faux = "const ICONS = {\n  vrai: (\n    <path />\n  ),\n}\n"
  const d = lireDessins(faux)
  if (d.size !== 1 || !d.has('vrai')) {
    return { ok: false, quoi: "l'extracteur de dessins ne lit pas une table minimale" }
  }
  passes.push('lit une table de dessins')

  const ecran = "      { id: 'x', ic: 'vrai' },\n      { id: 'y', ic: 'absent' },\n      <Icon name=\"aussi-absent\" />\n"
  const u = lireUsages(ecran, 'temoin')
  if (u.length !== 3) {
    return { ok: false, quoi: "l'extracteur d'usages n'a pas lu les trois formes" }
  }
  passes.push('lit les deux formes d usage')

  const manquants = u.filter((x) => !d.has(x.nom))
  if (manquants.length !== 2) {
    return { ok: false, quoi: 'le detecteur ne voit pas les deux noms absents' }
  }
  passes.push('VOIT deux noms sans dessin')

  const epargnes = u.filter((x) => d.has(x.nom))
  if (epargnes.length !== 1 || epargnes[0].nom !== 'vrai') {
    return { ok: false, quoi: 'le detecteur ne reconnait pas un nom correct' }
  }
  passes.push('EPARGNE le nom qui a un dessin')

  return { ok: true, passes }
}

const t = temoins()
if (!t.ok) {
  console.error('✗ INSTRUMENT MORT : ' + t.quoi + '.')
  console.error('  Aucun chiffre de ce controle ne vaut : il ne mesure pas ce qu il annonce.')
  process.exit(1)
}

// ── LA MESURE REELLE ────────────────────────────────────────────────────────────────────────────

if (!existsSync(FICHIER_ICONES)) {
  console.error('✗ INSTRUMENT MORT : ' + FICHIER_ICONES + ' est introuvable.')
  process.exit(1)
}

const dessins = lireDessins(readFileSync(FICHIER_ICONES, 'utf8'))

if (dessins.size < 10) {
  console.error('✗ INSTRUMENT MORT : ' + dessins.size + ' dessin(s) lus dans ' + FICHIER_ICONES + '.')
  console.error('  Moins de dix : la table n a pas ete comprise. Un ensemble vide ou tronque')
  console.error('  declarerait fautives des entrees parfaitement valides — le contraire d un controle.')
  process.exit(1)
}

function fichiersJsx(racine) {
  const sortie = []
  for (const e of readdirSync(racine)) {
    const p = join(racine, e)
    if (statSync(p).isDirectory()) sortie.push(...fichiersJsx(p))
    else if (p.endsWith('.jsx')) sortie.push(p)
  }
  return sortie
}

const usages = []
let calcules = 0
for (const f of fichiersJsx('src')) {
  const src = readFileSync(f, 'utf8')
  usages.push(...lireUsages(src, f))
  calcules += compterNomsCalcules(src)
}

if (usages.length < 10) {
  console.error('✗ INSTRUMENT MORT : ' + usages.length + ' usage(s) d icone lus dans src/.')
  console.error('  Le menu en declare des dizaines. Un vert obtenu sur un ensemble vide ne dirait rien.')
  process.exit(1)
}

const fautes = usages.filter((u) => !dessins.has(u.nom))

if (fautes.length > 0) {
  console.error('✗ ' + fautes.length + ' usage(s) d icone designent un dessin qui n existe pas :')
  for (const f of fautes) {
    console.error('    ' + f.chemin + ':' + f.ligne + '  « ' + f.nom + ' »  (' + f.forme + ')')
  }
  console.error('')
  console.error('  `Icon` rend alors le FALLBACK : un carre barre, a la bonne taille, sans erreur et')
  console.error('  avec un build vert. Quatre entrees etaient dans ce cas le 01/09, dont le tableau')
  console.error('  de bord — la premiere entree du menu de tout le monde.')
  console.error('')
  console.error('  Ajoute le dessin dans ' + FICHIER_ICONES + ', ou corrige le nom.')
  process.exit(1)
}

console.log(
  '✓ Icônes du menu : ' + usages.length + ' usage(s) nommé(s) explicitement, tous dans la table de '
  + dessins.size + ' dessin(s).'
)
console.log(
  '  (' + t.passes.length + ' témoins passés, dont 1 qui prouve ce que le détecteur épargne · '
  + calcules + ' usage(s) à nom calculé, hors de portée par construction.)'
)
