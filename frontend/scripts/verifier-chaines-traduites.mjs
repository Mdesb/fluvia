#!/usr/bin/env node
/*
 * LES ÉCRANS TRADUITS LE RESTENT, ET LES CATALOGUES SE TIENNENT (i18n, lot « socle » du 08/10/2026).
 *
 * ── CE QU'IL EMPÊCHE ────────────────────────────────────────────────────────────────────────────
 *
 * Un écran converti à `t()` qui reçoit une phrase en dur la semaine suivante : rien ne casse, le
 * français s'affiche, et l'écran espagnol redevient bilingue sans que personne ne le voie. C'est le
 * défaut qu'un garde-fou attrape et qu'une consigne écrite ne retient pas.
 *
 * ── CE QU'IL CONTRÔLE ───────────────────────────────────────────────────────────────────────────
 *
 *   1. Les fichiers de `src/i18n/converted-files.json` : aucun texte en dur (voir
 *      `lib/hardcoded-text.mjs`). La liste GRANDIT d'un lot à l'autre ; un fichier non converti
 *      n'est pas regardé, il n'est donc jamais bloqué.
 *   2. Toute clé écrite `t('…')` dans `src/` existe dans `fr.json`, la langue source.
 *   3. Chaque catalogue cible ne porte que des clés de `fr.json`, non vides, avec les mêmes jetons
 *      `{nom}` : une clé inventée est du bruit, un jeton perdu affiche « {nom} » à l'écran.
 *   4. Les catalogues présents sont exactement les langues que le serveur accepte
 *      (`App\I18n\Locales::SUPPORTED`) : sinon un établissement choisirait une langue sans écran.
 *
 * Aucune dépendance (`node:fs`, `node:path`, `node:url`) : il tourne aussi dans `pre-receive`.
 */

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { findHardcodedText } from './lib/hardcoded-text.mjs'

const FRONT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const I18N = join(FRONT, 'src/i18n')
const failures = []

const catalogs = Object.fromEntries(
  readdirSync(I18N)
    .filter((name) => /^[a-z]{2}\.json$/.test(name))
    .map((name) => [name.slice(0, 2), JSON.parse(readFileSync(join(I18N, name), 'utf8'))]),
)
const source = catalogs.fr ?? {}
const tokens = (text) => [...String(text).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',')

for (const [lang, entries] of Object.entries(catalogs)) {
  if (lang === 'fr') continue
  for (const [key, text] of Object.entries(entries)) {
    if (!(key in source)) failures.push(`${lang}.json : « ${key} » n'existe pas dans fr.json (clé inventée ou retirée de la source).`)
    else if (tokens(text) !== tokens(source[key])) failures.push(`${lang}.json : « ${key} » n'a pas les jetons de la source (${tokens(source[key]) || 'aucun'}).`)
    if (typeof text !== 'string' || text.trim() === '') failures.push(`${lang}.json : « ${key} » est vide.`)
  }
}

const php = readFileSync(join(FRONT, '../app/src/I18n/Locales.php'), 'utf8')
const server = [...(/SUPPORTED = \[([^\]]*)\]/.exec(php)?.[1] ?? '').matchAll(/'([a-z]{2})'/g)].map((m) => m[1]).sort()
const front = Object.keys(catalogs).sort()
if (server.join() !== front.join()) {
  failures.push(`Langues : le serveur accepte [${server}] et le frontal a des catalogues pour [${front}].`)
}

function files(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return files(path)
    return /\.(jsx?|mjs)$/.test(name) && !name.endsWith('.test.js') ? [path] : []
  })
}

// Seuls les fichiers qui importent `i18n/index.js` : d'autres écrans ont leur propre `t`, sans
// rapport (`const { t } = useVocabulaireVerticales()` dans `Reservation.jsx`, `t('resource')`).
for (const path of files(join(FRONT, 'src'))) {
  const text = readFileSync(path, 'utf8')
  if (!/from\s+['"][^'"]*i18n\/index\.js['"]/.test(text)) continue
  for (const m of text.matchAll(/\bt\(\s*['"]([\w.-]+)['"]/g)) {
    if (!(m[1] in source)) failures.push(`${relative(FRONT, path)} : t('${m[1]}') n'existe pas dans fr.json.`)
  }
}

const converted = JSON.parse(readFileSync(join(I18N, 'converted-files.json'), 'utf8'))
for (const file of converted) {
  const path = join(FRONT, file)
  if (!existsSync(path)) {
    failures.push(`converted-files.json : ${file} n'existe plus. Retire-le de la liste (ou corrige son chemin).`)
    continue
  }
  for (const f of findHardcodedText(readFileSync(path, 'utf8'))) {
    failures.push(`${file}:${f.line} — ${f.kind} : « ${f.text} »`)
  }
}

if (failures.length > 0) {
  console.error(`✗ Chaînes traduites : ${failures.length} écart(s).\n`)
  for (const f of failures) console.error(`    ${f}`)
  console.error(
    "\nUn fichier listé dans src/i18n/converted-files.json est traduit : son texte passe par t('clé'),\n" +
      'avec la clé dans src/i18n/fr.json (source) et sa traduction dans les autres catalogues ;\n' +
      'ses dates et montants passent par src/i18n/format.js. Une chaîne qui ne se traduit vraiment\n' +
      'pas (code technique, exemple de saisie) porte « // i18n-ignore » sur sa ligne.',
  )
  process.exit(1)
}

console.log(`✓ Chaînes traduites : ${converted.length} écran(s) converti(s) sans texte en dur, catalogues [${front}] cohérents.`)
