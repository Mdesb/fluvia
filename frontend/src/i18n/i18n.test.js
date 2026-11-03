// `node --test src/i18n/*.test.js` — le noyau `t()`, son repli et le formatage. Catalogues de test :
// le contenu réel des catalogues est contrôlé par `verifier-chaines-traduites.mjs`.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  intlLocale, missingKeys, pickLanguage, registerCatalog, setLanguage, t, translateApiError,
} from './core.js'
import { formatDate, formatMoney, formatMoneyCents, formatNumber } from './format.js'

registerCatalog('fr', {
  'cart.count': '{count} article(s) pour {name}',
  'cart.title': 'Panier',
  'only.fr': 'Seulement en français',
  'error.sale.closed': 'La vente {number} est close.',
})
registerCatalog('es', { 'cart.count': '{count} artículo(s) para {name}', 'cart.title': 'Cesta', 'error.sale.closed': 'La venta {number} está cerrada.' })

const spaces = (s) => s.replace(/\s/g, ' ')

test('t() rend la langue active et remplace les jetons', () => {
  setLanguage('es')
  assert.equal(t('cart.title'), 'Cesta')
  assert.equal(t('cart.count', { count: 3, name: 'Ana' }), '3 artículo(s) para Ana')
  assert.equal(t('cart.count', { count: 0 }), '0 artículo(s) para {name}', 'un jeton sans valeur reste visible')
})

test('une clé absente de la langue cible se rend en français, et l’absence est notée', () => {
  setLanguage('es')
  assert.equal(t('only.fr'), 'Seulement en français')
  assert.ok(missingKeys().includes('es:only.fr'))
  assert.equal(t('nowhere.at.all'), 'nowhere.at.all', 'absente même du français : la clé, que le garde-fou refuse')
})

test('pickLanguage prend la première langue qui a un catalogue, sinon le français', () => {
  assert.equal(pickLanguage(null, 'es-ES', 'fr'), 'es')
  assert.equal(pickLanguage('en_GB', 'de'), 'fr')
  // La règle d'`applyContextLanguage` : la préférence de la personne, sinon l'établissement.
  assert.equal(pickLanguage('fr', 'es'), 'fr', 'la préférence de l’utilisateur l’emporte')
  assert.equal(pickLanguage(null, 'es'), 'es', 'sans préférence, la langue de l’établissement')
  assert.equal(setLanguage('ca'), 'fr', 'une langue sans catalogue retombe sur la source')
})

test('la locale Intl combine la langue et le pays de l’établissement', () => {
  setLanguage('es', { country: 'es' })
  assert.equal(intlLocale(), 'es-ES')
  setLanguage('fr', { country: 'pas un pays' })
  assert.equal(intlLocale(), 'fr')
})

test('une erreur d’API codée se traduit ; sans code connu, null', () => {
  setLanguage('es')
  assert.equal(translateApiError({ code: 'sale.closed', params: { number: 'V-12' }, message: 'La vente V-12 est close.' }), 'La venta V-12 está cerrada.')
  assert.equal(translateApiError({ code: 'inconnu.du.catalogue', message: 'x' }), null)
  assert.equal(translateApiError({ message: 'sans code' }), null)
  assert.equal(translateApiError(null), null)
})

test('dates, nombres et montants suivent la locale active, pas fr-FR', () => {
  setLanguage('fr', { country: 'FR' })
  assert.equal(spaces(formatNumber(1234567.5)), '1 234 567,5')
  assert.equal(spaces(formatMoney(1234567.5)), '1 234 567,50 €')
  assert.equal(formatDate('2026-10-08T12:00:00Z'), '08/10/2026')

  setLanguage('es', { country: 'ES', currency: 'EUR' })
  assert.equal(formatNumber(1234567.5), '1.234.567,5')
  assert.equal(spaces(formatMoneyCents(123456750)), '1.234.567,50 €')
  assert.equal(formatDate('2026-10-08T12:00:00Z'), '8/10/2026')

  assert.equal(formatMoney(''), '—')
  assert.equal(formatDate('pas une date'), '—')
})
