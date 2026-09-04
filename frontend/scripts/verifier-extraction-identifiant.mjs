#!/usr/bin/env node
/**
 * `.split('/').pop()` — QUARANTE-QUATRE RÉÉCRITURES D'UNE FONCTION QUI EXISTE (n°53).
 *
 * ── CE QUE §8.3 A MESURÉ, ET CE QU'ELLE A MANQUÉ ────────────────────────────────────────────────
 *
 * §8.3 disait « `idDe` est dupliqué dix fois dans le frontal », le balayage a unifié seize copies,
 * et la section a été déclarée close sur ce compte : **une seule fonction nommée `idDe` subsiste**.
 *
 * ⚠ ELLE COMPTAIT DES NOMS, PAS L'ARTEFACT. Le défaut n'est pas la fonction `idDe` : c'est
 * l'expression `.split('/').pop()`, qui l'exprime sans porter son nom. Recomptée le 04/09 :
 * **44 occurrences dans 27 fichiers**, dont une qui portait encore le nom (`idDeProduit`).
 *
 * Une recherche par NOM ne trouve pas ce qui a divergé — et c'est précisément ce qui a divergé qui
 * échappe à l'unification.
 *
 * ── CE QUE CE CONTRÔLE AFFIRME, ET CE QU'IL N'AFFIRME PAS ───────────────────────────────────────
 *
 * `idDe()` (`api/iri.js`) filtre les segments vides, préfère `id` à `@id`, et ne rend jamais `''`
 * ni `undefined` — seulement un identifiant ou `null`. `.split('/').pop()` rend la **chaîne vide**
 * sur une référence terminée par un slash. Vérifié en exécutant les deux :
 *
 *     '/api/articles/abc-123'    les deux rendent 'abc-123'
 *     '/api/articles/abc-123/'   nu : ''          idDe : 'abc-123'
 *     '/api/articles//'          nu : ''          idDe : 'articles'
 *
 * ⚠ **CETTE DIVERGENCE EST LATENTE, PAS OBSERVÉE.** Je n'ai PAS montré que l'API émet des IRI à
 * slash final — API Platform n'en produit pas. Le risque se réalise sur une valeur construite à la
 * main, une concaténation, un champ vide sérialisé. Dire « l'écran affiche un blanc » serait donc
 * un abus : ce contrôle empêche la 45ᵉ réécriture, il ne répare pas un écran cassé.
 *
 * C'est aussi pourquoi il **gèle les 44 au lieu de les refuser** : les balayer en aveugle
 * changerait 27 fichiers pour un défaut que personne n'a vu se produire — exactement ce que §8.6
 * refuse de faire sur les rangées de formulaire.
 *
 * ── LIMITE CONNUE ───────────────────────────────────────────────────────────────────────────────
 *
 * ⚠ La détection des commentaires est HEURISTIQUE : une ligne dont le début est `//`, `*` ou `/*`
 * est ignorée. Node n'expose pas de tokeniseur JavaScript, et en réécrire un serait pire que la
 * limite. Conséquence : une occurrence à l'intérieur d'une chaîne, ou après du code sur la même
 * ligne qu'un commentaire, est comptée. Le cliquet le rend inoffensif — elle entre dans la ligne
 * de base et n'y bouge plus.
 *
 * Usage :
 *   node scripts/verifier-extraction-identifiant.mjs
 *   node scripts/verifier-extraction-identifiant.mjs --nettoyer
 */

import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { join, relative } from 'node:path'

const RACINE = 'src'
const LIGNE_DE_BASE = 'scripts/extraction-identifiant.ligne-de-base.json'

// La source canonique : c'est elle qui a le droit de découper une IRI, et son docblock cite
// l'expression pour l'expliquer.
const EXEMPT = new Set(['api/iri.js'])

const MOTIF = /split\(\s*['"]\/['"]\s*\)\s*\.\s*pop\(\)/g

function fichiers(dossier) {
  const out = []
  for (const nom of readdirSync(dossier)) {
    if (nom === 'node_modules') continue
    const chemin = join(dossier, nom)
    if (statSync(chemin).isDirectory()) out.push(...fichiers(chemin))
    else if (/\.(js|jsx)$/.test(nom)) out.push(chemin)
  }
  return out
}

function estCommentaire(ligne) {
  const t = ligne.trim()
  return t.startsWith('//') || t.startsWith('*') || t.startsWith('/*')
}

const compte = {}

for (const chemin of fichiers(RACINE)) {
  const cle = relative(RACINE, chemin).split('\\').join('/')
  if (EXEMPT.has(cle)) continue

  const lignes = readFileSync(chemin, 'utf8').split('\n')
  let n = 0
  for (const ligne of lignes) {
    if (estCommentaire(ligne)) continue
    n += (ligne.match(MOTIF) || []).length
  }
  if (n > 0) compte[cle] = n
}

const total = Object.values(compte).reduce((a, b) => a + b, 0)

// ⚠ UN TOTAL NUL EST SUSPECT, PAS RASSURANT. Ce dépôt en portait 44 le jour où ce contrôle a été
//   écrit. Zéro signifierait plutôt que la lecture est cassée — on refuse de conclure.
if (total === 0) {
  console.error('\n=== ERREUR — aucune occurrence trouvée, ce qui n’est pas crédible ===\n')
  console.error("  Ce contrôle en a compté 44 le 04/09. Zéro veut dire que la lecture ne marche")
  console.error('  plus (motif, chemin, extensions), pas que le dépôt est propre.\n')
  process.exit(2)
}

const nettoyer = process.argv.includes('--nettoyer')

if (nettoyer) {
  writeFileSync(LIGNE_DE_BASE, JSON.stringify(compte, null, 2) + '\n')
  console.log(`Ligne de base réécrite : ${total} occurrence(s) dans ${Object.keys(compte).length} fichier(s).`)
  process.exit(0)
}

const gelees = existsSync(LIGNE_DE_BASE) ? JSON.parse(readFileSync(LIGNE_DE_BASE, 'utf8')) : {}

// ── Les témoins : on prouve que le compteur VOIT, et ce qu'il ÉPARGNE ──────────────────────────
const temoins = [
  ["const id = ref.split('/').pop()", 1, 'une occurrence nue est vue'],
  ['const id = ref.split( "/" ).pop()', 1, 'les espaces ne la cachent pas'],
  ["  // ref.split('/').pop() dans un commentaire", 0, 'un commentaire est épargné'],
  ["  * `.split('/').pop()` dans un docbloc", 0, 'un docbloc est épargné'],
  ["const id = idDe(ref)", 0, "l'appel canonique est épargné"],
  ["a.split('/').pop() + b.split('/').pop()", 2, 'deux sur une ligne comptent deux'],
]

const rates = []
for (const [ligne, attendu, quoi] of temoins) {
  const vu = estCommentaire(ligne) ? 0 : (ligne.match(MOTIF) || []).length
  if (vu !== attendu) rates.push(`${quoi} — attendu ${attendu}, vu ${vu}`)
}

if (rates.length > 0) {
  console.error('\n=== ÉCHEC — les témoins de ce contrôle ne passent pas ===\n')
  for (const r of rates) console.error('  · ' + r)
  console.error('\nUn contrôle dont les témoins tombent ne mesure plus ce qu’il annonce.\n')
  process.exit(1)
}

const neufs = []
for (const [fichier, n] of Object.entries(compte)) {
  const avant = gelees[fichier] ?? 0
  if (n > avant) neufs.push(`${fichier} : ${avant} → ${n}`)
}

if (neufs.length > 0) {
  console.error('\n=== ÉCHEC — nouvelle réécriture de `idDe` ===\n')
  for (const l of neufs) console.error('  ' + l)
  console.error('')
  console.error("  `idDe()` existe dans `src/api/iri.js`. Elle filtre les segments vides, préfère")
  console.error("  `id` à `@id`, et ne rend jamais '' ni undefined — seulement un identifiant ou null.")
  console.error("  `.split('/').pop()` rend la CHAÎNE VIDE sur une référence terminée par un slash,")
  console.error('  ce qui fait échouer en silence toute recherche par clé.')
  console.error('')
  console.error("      import { idDe } from '../api/iri.js'")
  console.error('')
  console.error('  Si l’occurrence est légitime (ce n’est pas une IRI), `--nettoyer` fige le nouveau')
  console.error('  compte — mais dis dans le commit pourquoi elle l’est.')
  console.error('')
  process.exit(1)
}

const resorbees = Object.entries(gelees)
  .filter(([f, n]) => (compte[f] ?? 0) < n)
  .map(([f, n]) => `${f} : ${n} → ${compte[f] ?? 0}`)

console.log(
  `Extraction d’identifiant : OK — ${total} réécriture(s) gelée(s) dans ` +
    `${Object.keys(compte).length} fichier(s), aucune nouvelle. (${temoins.length} témoins passés, ` +
    `dont 3 qui prouvent ce qu’il épargne.)`,
)

if (resorbees.length > 0) {
  console.log(`  ${resorbees.length} résorbée(s) — pense à --nettoyer :`)
  for (const l of resorbees) console.log('    ' + l)
}
