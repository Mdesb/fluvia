// « Valider la vente » sur un panier gratuit. Lancer : `cd frontend && node --test src/api/validateCart.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { screenAfterValidation, validateCart } from './validateCart.js'

function caisse({ resteServeur = '0.00', ouverture = true } = {}) {
  const appels = []
  return {
    appels,
    openSale: async () => {
      appels.push('ouvrir')
      return ouverture ? { vObj: { id: 'v1' }, resteServeur } : undefined
    },
    finish: async (id, reglements) => { appels.push(['valider', id, reglements]) },
  }
}

test('panier à 0 € sans vente ouverte : la vente s’ouvre puis se valide, sans règlement', async () => {
  const c = caisse()
  const issue = await validateCart({ sale: null, remaining: 0, openSale: c.openSale, finish: c.finish })
  assert.equal(issue, 'validated')
  assert.deepEqual(c.appels, ['ouvrir', ['valider', 'v1', []]])
})

test('le serveur fait payer ce que l’écran croyait gratuit : la vente reste ouverte, rien n’est validé', async () => {
  const c = caisse({ resteServeur: '4.50' })
  assert.equal(await validateCart({ sale: null, remaining: 0, openSale: c.openSale, finish: c.finish }), 'unpaid')
  assert.deepEqual(c.appels, ['ouvrir'])
})

test('ouverture refusée (client à rattacher, caisse fermée) : rien n’est validé', async () => {
  const c = caisse({ ouverture: false })
  assert.equal(await validateCart({ sale: null, remaining: 0, openSale: c.openSale, finish: c.finish }), 'not-opened')
  assert.deepEqual(c.appels, ['ouvrir'])
})

test('vente déjà ouverte et soldée : validée sans être rouverte', async () => {
  const c = caisse()
  assert.equal(await validateCart({ sale: { id: 'v9' }, remaining: '0.00', openSale: c.openSale, finish: c.finish }), 'validated')
  assert.deepEqual(c.appels, [['valider', 'v9', null]])
})

test('vente ouverte avec un reste dû : on ne valide pas', async () => {
  const c = caisse()
  assert.equal(await validateCart({ sale: { id: 'v9' }, remaining: 3, openSale: c.openSale, finish: c.finish }), 'unpaid')
  assert.deepEqual(c.appels, [])
})

test('vente gratuite validée : le billet part directement, sans étape intermédiaire', () => {
  assert.equal(screenAfterValidation({ venteGratuite: true, impressionAutomatique: true }), 'pass')
  assert.equal(screenAfterValidation({ venteGratuite: false, impressionAutomatique: true }), 'receipt')
  assert.equal(screenAfterValidation({ venteGratuite: false, impressionAutomatique: false }), 'offer')
})
