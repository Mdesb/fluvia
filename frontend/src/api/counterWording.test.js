// Les mots de la caisse en cas d'échec. Lancer : `cd frontend && node --test src/api/counterWording.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { api, ApiError } from './client.js'
import { CARD_TERMINAL, isNetworkLost, saleErrorMessage } from './counterWording.js'

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

test('une autre erreur garde son message, ou le repli', () => {
  assert.equal(saleErrorMessage(new ApiError('Stock insuffisant', 422, null), 'open', 'repli'), 'Stock insuffisant')
  assert.equal(saleErrorMessage(null, 'open', 'repli'), 'repli')
})

test('le terminal de carte ne parle plus de simulation', () => {
  assert.doesNotMatch(CARD_TERMINAL.titre, /simul/i)
  assert.match(CARD_TERMINAL.titre, /terminal/)
  assert.deepEqual(CARD_TERMINAL.choix.map((c) => c.code), ['accepte', 'refuse', 'timeout'], 'les codes du port ne changent pas')
  for (const c of CARD_TERMINAL.choix) assert.doesNotMatch(c.libelle, /timeout|simul/i)
})
