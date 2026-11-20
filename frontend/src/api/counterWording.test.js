// Les mots de la caisse en cas d'échec. Lancer : `cd frontend && node --test src/api/counterWording.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { api, ApiError } from './client.js'
import { isNetworkLost, saleErrorMessage } from './counterWording.js'

const memoire = () => {
  const m = new Map()
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }
}
globalThis.localStorage ??= memoire()
globalThis.sessionStorage ??= memoire()

test('coupure réseau : un message pour l’agent, pas pour un développeur', async () => {
  const avant = globalThis.fetch
  globalThis.fetch = () => Promise.reject(new TypeError('Failed to fetch'))
  try {
    await assert.rejects(api.vente('v1'), (e) => {
      assert.ok(e instanceof ApiError)
      assert.doesNotMatch(e.message, /localhost|API|back/i)
      assert.match(e.message, /Connexion perdue/)
      assert.ok(isNetworkLost(e), 'la coupure se reconnaît à son code, pas à sa phrase')
      return true
    })
  } finally {
    globalThis.fetch = avant
  }
})

test('pendant l’ouverture du panier : « la vente n’a pas été enregistrée »', () => {
  const coupure = new ApiError('x', 0, { code: 'network_unreachable' })
  assert.equal(saleErrorMessage(coupure, 'open', 'repli'), 'Connexion perdue. Vérifiez internet ; la vente n’a pas été enregistrée.')
})

test('pendant la validation : on ne promet pas qu’elle n’a pas abouti', () => {
  const coupure = new ApiError('x', 0, { code: 'network_unreachable' })
  const m = saleErrorMessage(coupure, 'validate', 'repli')
  assert.match(m, /Connexion perdue/)
  assert.doesNotMatch(m, /n’a pas été enregistrée/)
  assert.match(m, /historique/)
})

// NF525, étape 15 — la caisse n'a rien à changer : on épingle que le refus de scellement lui arrive tel quel.
// Corps de forme réelle API Platform 4.3.17 pour un 409 : `title`, `detail`, `description`, SANS `message`
// ni `hydra:description`. Le message de l'exception est dans `detail` ; `messageFromPayload` l'y trouve.
const REFUS_SCELLEMENT = 'Scellement refusé : la chaîne fiscale est bloquée. L’administrateur et l’éditeur sont prévenus. Référence de l’alerte : NF525-0A1B2C3D.'

test('refus de scellement (409) : le message du serveur s’affiche tel quel à la validation et à l’ouverture', async () => {
  const avant = globalThis.fetch
  const corps = { '@type': 'Error', title: 'An error occurred', detail: REFUS_SCELLEMENT, description: REFUS_SCELLEMENT, status: 409 }
  globalThis.fetch = () => Promise.resolve(new Response(JSON.stringify(corps), {
    status: 409,
    headers: { 'Content-Type': 'application/problem+json' },
  }))
  try {
    await assert.rejects(api.vente('v1'), (e) => {
      assert.ok(e instanceof ApiError)
      assert.equal(e.status, 409)
      assert.equal(e.message, REFUS_SCELLEMENT)
      assert.ok(!isNetworkLost(e))
      assert.equal(saleErrorMessage(e, 'validate', 'repli'), REFUS_SCELLEMENT)
      assert.equal(saleErrorMessage(e, 'open', 'repli'), REFUS_SCELLEMENT)
      return true
    })
  } finally {
    globalThis.fetch = avant
  }
})

test('une autre erreur garde son message, ou le repli', () => {
  assert.equal(saleErrorMessage(new ApiError('Stock insuffisant', 422, null), 'open', 'repli'), 'Stock insuffisant')
  assert.equal(saleErrorMessage(null, 'open', 'repli'), 'repli')
})
