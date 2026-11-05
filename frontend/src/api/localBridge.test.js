// Le relais du pont de caisse (#340). Lancer : `cd frontend && node --test src/api/localBridge.test.js`.
import { beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { DEFAULT_BRIDGE, bridgeAddress, isBridge, openDrawer, relayBridgePayment, resetBridgeRelays } from './localBridge.js'

const tentative = { id: '0b5e6a3c-0000-4000-8000-000000000001', montant: '45.00', connecteur: 'caisse_ap_pont' }
const reponse = (status, corps) => ({ ok: status < 300, status, json: async () => corps })

function pontQuiRend(signee, appels) {
  return async (url, init) => {
    appels.push({ url, corps: JSON.parse(init.body), type: init.headers['Content-Type'] })
    return reponse(200, signee)
  }
}

beforeEach(() => resetBridgeRelays())

test('l’adresse du pont : celle du point de vente, sinon le poste lui-même', () => {
  assert.equal(bridgeAddress({ tpe: { pont: 'http://127.0.0.1:9000' } }), 'http://127.0.0.1:9000')
  assert.equal(bridgeAddress({ tpe: { connecteur: 'caisse_ap_pont' } }), DEFAULT_BRIDGE)
  assert.equal(bridgeAddress(null), DEFAULT_BRIDGE)
  assert.equal(isBridge(tentative), true)
  assert.equal(isBridge({ connecteur: 'externe' }), false)
})

test('le montant de la tentative part au pont, l’issue signée revient au serveur TELLE QUELLE', async () => {
  const appels = []
  const relayes = []
  const signee = { result: '{"status":"refuse"}', signature: 'c2ln' }
  const api = { issuePont: async (venteId, corps) => { relayes.push([venteId, corps]); return { tentative: { statut: 'refused' } } } }

  const r = await relayBridgePayment({ api, venteId: 'v1', tentative, pont: DEFAULT_BRIDGE, fetchImpl: pontQuiRend(signee, appels) })

  assert.deepEqual(appels, [{ url: `${DEFAULT_BRIDGE}/v1/payments`, corps: { attempt: tentative.id, amount: '45.00', currency: 'EUR' }, type: 'application/json' }])
  assert.deepEqual(relayes, [['v1', { tentative: tentative.id, resultat: signee.result, signature: signee.signature }]])
  assert.equal(r.tentative.statut, 'refused')
})

test('une même tentative n’est demandée qu’une fois au pont, même appelée deux fois', async () => {
  const appels = []
  const api = { issuePont: async () => ({}) }
  const f = pontQuiRend({ result: '{}', signature: 'x' }, appels)
  await Promise.all([
    relayBridgePayment({ api, venteId: 'v1', tentative, pont: DEFAULT_BRIDGE, fetchImpl: f }),
    relayBridgePayment({ api, venteId: 'v1', tentative, pont: DEFAULT_BRIDGE, fetchImpl: f }),
  ])
  assert.equal(appels.length, 1)
})

test('pont éteint : une erreur qui le dit, et on peut réessayer', async () => {
  const api = { issuePont: async () => ({}) }
  const eteint = async () => { throw new TypeError('Failed to fetch') }
  await assert.rejects(relayBridgePayment({ api, venteId: 'v1', tentative, pont: DEFAULT_BRIDGE, fetchImpl: eteint }), (e) => e.code === 'bridge_unreachable' && /pont de caisse ne répond pas/.test(e.message))
  const appels = []
  await relayBridgePayment({ api, venteId: 'v1', tentative, pont: DEFAULT_BRIDGE, fetchImpl: pontQuiRend({ result: '{}', signature: 'x' }, appels) })
  assert.equal(appels.length, 1, 'le second essai part')
})

test('une coupure vers le serveur se retente : l’issue n’est pas perdue', async () => {
  let essais = 0
  const api = { issuePont: async () => { essais += 1; if (essais < 3) throw Object.assign(new Error('réseau'), { status: 0 }); return { ok: true } } }
  const r = await relayBridgePayment({ api, venteId: 'v1', tentative, pont: DEFAULT_BRIDGE, fetchImpl: pontQuiRend({ result: '{}', signature: 'x' }, []), pause: async () => {} })
  assert.deepEqual([essais, r.ok], [3, true])
})

test('un refus du serveur (signature fausse : 422) ne se retente pas', async () => {
  let essais = 0
  const api = { issuePont: async () => { essais += 1; throw Object.assign(new Error('non signée'), { status: 422 }) } }
  await assert.rejects(relayBridgePayment({ api, venteId: 'v1', tentative, pont: DEFAULT_BRIDGE, fetchImpl: pontQuiRend({ result: '{}', signature: 'x' }, []), pause: async () => {} }), /non signée/)
  assert.equal(essais, 1)
})

test('le pont occupé par un autre paiement le dit (409)', async () => {
  const api = { issuePont: async () => ({}) }
  const occupe = async () => reponse(409, { code: 'terminal_busy', message: 'Le terminal traite déjà un autre paiement.' })
  await assert.rejects(relayBridgePayment({ api, venteId: 'v1', tentative, pont: DEFAULT_BRIDGE, fetchImpl: occupe }), (e) => e.code === 'terminal_busy')
})

test('le tiroir : un POST au pont', async () => {
  const appels = []
  await openDrawer('http://127.0.0.1:8765', pontQuiRend({ opened: true }, appels))
  assert.equal(appels[0].url, 'http://127.0.0.1:8765/v1/drawer/open')
})
