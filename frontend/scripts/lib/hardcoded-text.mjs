// LE TEXTE ÉCRIT EN DUR DANS UN ÉCRAN DÉJÀ CONVERTI À `t()`.
//
// Pas d'analyseur syntaxique : le contrôle tourne aussi dans `pre-receive`, conteneur sans réseau ni
// `node_modules`. D'où des motifs PRUDENTS — un faux positif fait contourner un garde-fou, un oubli
// se rattrape à la relecture — éprouvés par `src/i18n/i18n.test.js` :
//
//   1. du texte JSX entre une balise et une autre balise ou une expression `{…}` ;
//   2. une chaîne dans un attribut lu à l'écran (`placeholder`, `title`, `alt`, `label`, `aria-*`) ;
//   3. une chaîne qui a l'air d'une phrase (deux mots, une lettre accentuée, une ponctuation finale),
//      hors `className`, `style`, imports et clés de `t()` ;
//   4. une locale écrite en dur (`'fr-FR'`) : le formatage passe par `src/i18n/format.js`.
//
// Ce qu'il ne voit pas : un mot seul, sans accent, passé en argument (`setInfo('Valider')`). En texte
// JSX, en branche de ternaire ou en valeur de propriété, le même mot est vu.
//
// Une ligne qui porte `i18n-ignore` est sautée : l'échappatoire se lit dans le diff.

const WORD = /[A-Za-zÀ-ÖØ-öø-ÿ]{2,}/
const BRANDS = new Set(['Fluvia'])

/** Remplace les caractères d'une zone par des espaces, en gardant les sauts de ligne (et donc les numéros). */
function blank(text, start, end) {
  return text.slice(0, start) + text.slice(start, end).replace(/[^\n]/g, ' ') + text.slice(end)
}

/** Comme `blank`, mais le dernier caractère devient `"` : un `>` qui suit reste une fin de balise. */
function blankValue(text, start, end) {
  return `${blank(text, start, end).slice(0, end - 1)}"${text.slice(end)}`
}

function blankAll(text, regex) {
  let out = text
  for (const m of text.matchAll(regex)) out = blank(out, m.index, m.index + m[0].length)
  return out
}

/** Efface la valeur d'un attribut (`"…"`, `'…'` ou `{…}` équilibré). */
function blankAttributeValues(text, name) {
  let out = text
  for (const m of text.matchAll(new RegExp(`\\b${name}=`, 'g'))) {
    const start = m.index + m[0].length
    const open = out[start]
    let end = start
    if (open === '"' || open === "'") end = out.indexOf(open, start + 1) + 1
    else if (open === '{') {
      let depth = 0
      for (end = start; end < out.length; end += 1) {
        if (out[end] === '{') depth += 1
        else if (out[end] === '}' && --depth === 0) break
      }
      end += 1
    }
    if (end > start) out = blankValue(out, start, end)
  }
  return out
}

function isText(fragment) {
  const words = fragment.trim().split(/\s+/).filter((w) => WORD.test(w))
  return words.length > 0 && !words.every((w) => BRANDS.has(w))
}

function looksLikeCode(fragment) {
  return /[;=]|\breturn\b|\(\s*$|^\s*\)/m.test(fragment)
}

function looksLikeProse(value) {
  const s = value.trim()
  if (!WORD.test(s) || /^(\/|\.\/|https?:|#|var\(|@)/.test(s)) return false
  return /[A-Za-zÀ-ÖØ-öø-ÿ]{2,}\s+[A-Za-zÀ-ÖØ-öø-ÿ]{2,}/.test(s) || /[À-ÖØ-öø-ÿ]/.test(s) || /\w[.!?…]$/.test(s)
}

/** @returns {{ line: number, kind: string, text: string }[]} */
export function findHardcodedText(source) {
  let code = source
    .split('\n')
    .map((line) => (line.includes('i18n-ignore') ? line.replace(/[^\n]/g, ' ') : line))
    .join('\n')
  code = blankAll(code, /\/\*[\s\S]*?\*\//g)
  code = blankAll(code, /(^|[^:'"`\\])\/\/[^\n]*/g)
  code = blankAll(code, /^\s*import\s[^\n]*$/gm)
  code = blankAll(code, /^[^\n]*\bconsole\.\w+\([^\n]*$/gm)
  code = blankAll(code, /\bt\(\s*(['"`])(?:(?!\1)[^\n])*\1/g)
  for (const name of ['className', 'style', 'key']) code = blankAttributeValues(code, name)

  const findings = []
  const lineOf = (index) => code.slice(0, index).split('\n').length
  const add = (index, kind, text) => findings.push({ line: lineOf(index), kind, text: text.trim().replace(/\s+/g, ' ').slice(0, 80) })

  for (const m of code.matchAll(/(['"`])[a-z]{2}-[A-Z]{2}\1/g)) add(m.index, 'locale en dur', m[0])

  const attribute = /\b(placeholder|title|alt|label|aria-label|aria-description)\s*=\s*\{?\s*(['"`])((?:(?!\2)[^\n])*)\2/g
  for (const m of code.matchAll(attribute)) {
    if (isText(m[3])) add(m.index, `attribut ${m[1]}`, m[3])
    code = blankValue(code, m.index, m.index + m[0].length)
  }

  // Une fin de balise suit un nom, une valeur, une expression ou `/` — ou ouvre seule sa ligne
  // (attributs sur plusieurs lignes). ` > ` entre deux opérandes n'est ni l'un ni l'autre.
  for (const m of code.matchAll(/(?<=[\w"'}/]|\n[ \t]*)>([^<>{}]+)(?=<\/?[A-Za-z]|\{)/g)) {
    if (isText(m[1]) && !looksLikeCode(m[1])) add(m.index + 1, 'texte JSX', m[1])
  }
  for (const m of code.matchAll(/\}([^<>{}]+)(?=<\/[A-Za-z])/g)) {
    if (isText(m[1]) && !looksLikeCode(m[1])) add(m.index + 1, 'texte JSX', m[1])
  }

  for (const m of code.matchAll(/(['"])((?:\\.|(?!\1)[^\\\n])*)\1|`([^`]*)`/g)) {
    const value = m[2] ?? m[3].replace(/\$\{[^}]*\}/g, ' ')
    // Un mot capitalisé seul (`'Valider'`) n'est une phrase qu'en valeur de ternaire ou de propriété :
    // ailleurs, c'est trop souvent un identifiant (`'Bearer'`, un `@type`).
    const label = /^[A-ZÀ-Ý][a-zà-ÿ]{2,}$/.test(value) && /[?:]$/.test(code.slice(0, m.index).trimEnd())
    if (looksLikeProse(value) || (label && !BRANDS.has(value))) add(m.index, 'chaîne', value)
  }

  return findings.sort((a, b) => a.line - b.line)
}
