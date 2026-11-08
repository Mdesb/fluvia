// Le retour de l'acheteur après un paiement chez le prestataire (#339).
// Lancer : `cd frontend && node --test src/public/`
//
// L'acheteur QUITTE la boutique pour payer (PayFiP, banque) puis revient. Au retour, la page doit
// vérifier LE panier et LA transaction notés au départ, avec LE jeton de ce panier — même si le
// stockage du panier a changé entre-temps (autre onglet, panier rouvert).
import { test, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boutique, paiementEnCoursStore, panierStore } from './api/boutiqueClient.js'

let appels
const fetchOrigine = globalThis.fetch

beforeEach(() => {
  appels = []
  globalThis.fetch = async (url, init) => {
    appels.push({ url, headers: init?.headers || {}, body: init?.body })
    return new Response(JSON.stringify({ statut: 'en_attente' }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  }
})

afterEach(() => {
  globalThis.fetch = fetchOrigine
  paiementEnCoursStore.clear()
  panierStore.clear()
})

test('le paiement en cours se note et se relit tel quel', () => {
  paiementEnCoursStore.set({ panierId: 'p1', panierToken: 'jeton-p1', reference: 'idop-1', moyen: 'payfip' })
  assert.deepEqual(paiementEnCoursStore.get(), { panierId: 'p1', panierToken: 'jeton-p1', reference: 'idop-1', moyen: 'payfip' })
  paiementEnCoursStore.clear()
  assert.equal(paiementEnCoursStore.get(), null)
})

test('le retour part avec le jeton du panier payé, pas celui du panier courant', async () => {
  panierStore.set('p2', 'jeton-p2')
  await boutique.retourPaiement('p1', { referenceTransaction: 'idop-1' }, 'jeton-p1')
  assert.equal(appels.length, 1)
  assert.equal(appels[0].url, '/api/boutique/paniers/p1/retour-paiement')
  assert.equal(appels[0].headers['X-Panier-Token'], 'jeton-p1')
  // On n'envoie qu'une référence : jamais de statut, que le serveur ne lirait d'ailleurs pas.
  assert.deepEqual(JSON.parse(appels[0].body), { referenceTransaction: 'idop-1' })
})

test('sans jeton explicite, le jeton du panier courant reste celui qui part', async () => {
  panierStore.set('p2', 'jeton-p2')
  await boutique.retourPaiement('p2', { referenceTransaction: 'idop-2' })
  assert.equal(appels[0].headers['X-Panier-Token'], 'jeton-p2')
})
