// POINT D'ENTRÉE DES ÉCRANS : `import { t, formatMoney } from '../i18n/index.js'`.
//
// Il enregistre les catalogues et pose la langue de l'appareil avant tout rendu. Ajouter une langue :
// son catalogue ici, son nom ci-dessous, et son code dans `App\I18n\Locales::SUPPORTED` côté serveur
// (le garde-fou refuse les deux listes si elles divergent).

import fr from './fr.json'
import es from './es.json'
import { pickLanguage, registerCatalog, setLanguage } from './core.js'

registerCatalog('fr', fr)
registerCatalog('es', es)

/** Chaque langue dans sa propre langue : c'est ainsi qu'on la cherche dans une liste. */
export const LANGUAGE_NAMES = { fr: 'Français', es: 'Español' }

const STORAGE_KEY = 'fluvia.langue'

// AVANT LA CONNEXION, personne n'a encore de langue : ni utilisateur ni établissement. On prend
// celle que l'appareil a connue en dernier (une caisse de Barcelone ouvre en espagnol dès le
// lendemain), sinon celle du navigateur si on la parle, sinon le français.
function deviceLanguage() {
  let stored = null
  try {
    stored = localStorage.getItem(STORAGE_KEY)
  } catch {
    // Stockage refusé (navigation privée stricte) : on continue sans mémoire.
  }
  const browser = typeof navigator !== 'undefined' ? navigator.languages || [navigator.language] : []
  return pickLanguage(stored, ...browser)
}

setLanguage(deviceLanguage())

/**
 * Connecté : la préférence de la personne (`/me`.locale), sinon la langue de l'établissement actif.
 * Le pays et la devise de l'établissement règlent le formatage. Retenue pour la prochaine connexion.
 */
export function applyContextLanguage(user, establishment) {
  const lang = setLanguage(pickLanguage(user?.locale, establishment?.locale), {
    country: establishment?.pays,
    currency: establishment?.devise,
  })
  try {
    localStorage.setItem(STORAGE_KEY, lang)
  } catch {
    // Sans mémoire, la prochaine connexion repartira de la langue du navigateur : rien de cassé.
  }
  return lang
}

export * from './core.js'
export * from './format.js'
