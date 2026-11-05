import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  STEPS,
  MAX_LINES,
  MAX_QUANTITY,
  canRecharge,
  cartCount,
  cartItems,
  cartTotalCents,
  changeQuantity,
  isBlockedKey,
  labelOf,
  normalizeCardCode,
  offersFor,
  paymentOutcome,
} from './kioskFlow.js'

const OFFERS = [
  { offer: 'entree|plein', label: 'Entrée', price: '5.50', card: false },
  { offer: 'carte|plein', label: 'Carte 10', price: '45.00', card: true },
]

test('trois étapes, pas une de plus', () => {
  assert.deepEqual(STEPS, ['choose', 'pay', 'collect'])
})

test('le panier ajoute, plafonne et retire', () => {
  let cart = {}
  cart = changeQuantity(cart, 'entree|plein', 1)
  cart = changeQuantity(cart, 'entree|plein', 1)
  assert.deepEqual(cart, { 'entree|plein': 2 })
  for (let i = 0; i < 20; i++) cart = changeQuantity(cart, 'entree|plein', 1)
  assert.equal(cart['entree|plein'], MAX_QUANTITY)
  cart = changeQuantity(cart, 'entree|plein', -MAX_QUANTITY)
  assert.deepEqual(cart, {}, 'à zéro, la ligne disparaît')
})

test('le panier refuse une ligne de trop', () => {
  let cart = {}
  for (let i = 0; i < MAX_LINES; i++) cart = changeQuantity(cart, `o${i}`, 1)
  const full = changeQuantity(cart, 'one-more', 1)
  assert.equal(Object.keys(full).length, MAX_LINES)
})

test('le total se compte en centimes, sans erreur d’arrondi', () => {
  const cart = { 'entree|plein': 3, 'carte|plein': 1 }
  assert.equal(cartTotalCents(cart, OFFERS), 3 * 550 + 4500)
  assert.equal(cartCount(cart), 4)
  assert.deepEqual(cartItems(cart), [{ offer: 'entree|plein', quantity: 3 }, { offer: 'carte|plein', quantity: 1 }])
  assert.equal(cartTotalCents({ 'retirée|x': 2 }, OFFERS), 0, 'une offre disparue ne compte pas')
})

test('la recharge ne montre que les cartes, et seulement si la borne recharge', () => {
  assert.deepEqual(offersFor('recharge', OFFERS).map((o) => o.offer), ['carte|plein'])
  assert.equal(offersFor('buy', OFFERS).length, 2)
  assert.equal(canRecharge({ rechargeEnabled: true, offers: OFFERS }), true)
  assert.equal(canRecharge({ rechargeEnabled: false, offers: OFFERS }), false)
  assert.equal(canRecharge({ rechargeEnabled: true, offers: [OFFERS[0]] }), false)
})

test('un refus dit que rien n’a été débité ; un terminal muet envoie à l’accueil', () => {
  assert.equal(paymentOutcome('accepted').kind, 'ok')
  assert.match(paymentOutcome('refused').message, /Rien n’a été débité/)
  assert.equal(paymentOutcome('refused').kind, 'retry')
  assert.equal(paymentOutcome('unresolved').kind, 'help')
  assert.doesNotMatch(paymentOutcome('unresolved').message, /Rien n’a été débité/, 'on ne promet pas ce qu’on ne sait pas')
})

test('le verrou du clavier bloque les sorties et laisse taper le numéro de carte', () => {
  assert.equal(isBlockedKey({ key: 'F5' }), true)
  assert.equal(isBlockedKey({ key: 'Escape' }), true)
  assert.equal(isBlockedKey({ key: 'r', ctrlKey: true }), true)
  assert.equal(isBlockedKey({ key: 'ArrowLeft', altKey: true }), true)
  assert.equal(isBlockedKey({ key: 'Backspace' }), true, 'hors champ, retour arrière = page précédente')
  assert.equal(isBlockedKey({ key: 'Backspace' }, true), false, 'dans le champ, il efface')
  assert.equal(isBlockedKey({ key: 'v', ctrlKey: true }, true), false)
  assert.equal(isBlockedKey({ key: '7' }), false)
  assert.equal(isBlockedKey({ key: 'Enter' }, true), false)
})

test('un numéro lu par une douchette perd ses blancs et son retour chariot', () => {
  assert.equal(normalizeCardCode(' AB12 CD\r\n'), 'AB12CD')
  assert.equal(normalizeCardCode(null), '')
})

test('les libellés traduisibles du serveur se lisent', () => {
  assert.equal(labelOf({ fr: 'Entrée' }), 'Entrée')
  assert.equal(labelOf('Entrée'), 'Entrée')
  assert.equal(labelOf(null), '')
})
