// LE NOYAU DE TRADUCTION : la langue active, `t()`, et le repli sur le français.
//
// Le français est la langue SOURCE : toute clé existe dans `fr.json`. Une clé absente de la langue
// active se rend en français, jamais en clé brute ; l'absence est notée (`missingKeys()`) et
// signalée dans la console en développement, pour que l'agent `traducteur` la retrouve.
//
// Aucune dépendance : une recherche dans un objet et des jetons `{nom}`. Ce module ne lit aucun
// fichier — `index.js` lui donne les catalogues — pour que `node --test` le charge tel quel.

export const SOURCE_LANGUAGE = 'fr'

const catalogs = {}
const missing = new Set()
let language = SOURCE_LANGUAGE
let country = ''
let currency = 'EUR'

export function registerCatalog(lang, entries) {
  catalogs[lang] = entries
}

/** La première langue candidate qui a un catalogue (`es-ES` → `es`), sinon le français. */
export function pickLanguage(...candidates) {
  for (const candidate of candidates) {
    const primary = String(candidate || '').toLowerCase().split(/[-_]/)[0]
    if (catalogs[primary]) return primary
  }
  return SOURCE_LANGUAGE
}

/** `country` (ISO 3166, `pays` de l'établissement) et `currency` (ISO 4217) servent au formatage. */
export function setLanguage(lang, { country: c = '', currency: cur = 'EUR' } = {}) {
  language = pickLanguage(lang)
  country = /^[A-Za-z]{2}$/.test(c || '') ? c.toUpperCase() : ''
  currency = cur || 'EUR'
  if (typeof document !== 'undefined') document.documentElement.lang = language
  return language
}

export const activeLanguage = () => language
export const activeCurrency = () => currency
/** La locale `Intl` : la langue, précisée du pays quand on le connaît (`es-ES`, `fr-CH`). */
export const intlLocale = () => (country ? `${language}-${country}` : language)
export const missingKeys = () => [...missing]

export function hasKey(key) {
  return catalogs[language]?.[key] !== undefined || catalogs[SOURCE_LANGUAGE]?.[key] !== undefined
}

export function t(key, params = {}) {
  let text = catalogs[language]?.[key]
  if (text === undefined) {
    text = catalogs[SOURCE_LANGUAGE]?.[key]
    const miss = `${language}:${key}`
    if (!missing.has(miss)) {
      missing.add(miss)
      if (import.meta.env?.DEV) console.warn(`[i18n] « ${key} » manque en ${language} : rendu en français.`)
    }
    // Absente même du français : faute de développeur, que `verifier-chaines-traduites.mjs` refuse.
    if (text === undefined) return key
  }
  return text.replace(/\{(\w+)\}/g, (whole, name) => (params[name] === undefined ? whole : String(params[name])))
}

/**
 * Le texte d'une erreur d'API codée (`CodedHttpException` côté serveur) dans la langue active, ou
 * `null` si le corps ne porte pas de code connu : l'appelant garde alors le message du serveur.
 */
export function translateApiError(payload) {
  const key = typeof payload?.code === 'string' ? `error.${payload.code}` : null
  if (!key || !hasKey(key)) return null
  return t(key, payload.params && typeof payload.params === 'object' ? payload.params : {})
}
