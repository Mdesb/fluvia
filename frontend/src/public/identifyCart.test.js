// Le rattachement du panier à un client connecté doit porter son jeton de connexion.
// Lancer : `cd frontend && node --test src/public/`
//
// Vécu (13/10/2026) : un client connecté qui passait commande voyait « Votre session a expiré ».
// `boutique.identifier` appelait le serveur sans l'en-tête Authorization ; le serveur ne voyait
// donc personne et rendait 401, quel que soit l'état réel de la session.
import { test, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boutique, clientTokenStore } from './api/boutiqueClient.js'

let appels
const fetchOrigine = globalThis.fetch

beforeEach(() => {
  appels = []
  globalThis.fetch = async (url, init) => {
    appels.push({ url, headers: init?.headers || {} })
    return new Response(JSON.stringify({ id: 'p1' }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  }
  clientTokenStore.set('jeton-client')
})

afterEach(() => {
  globalThis.fetch = fetchOrigine
  clientTokenStore.clear()
})

test('mode session : le jeton du client part avec la requête', async () => {
  await boutique.identifier('p1', { mode: 'session' })
  assert.equal(appels.length, 1)
  assert.match(appels[0].url, /\/api\/boutique\/paniers\/p1\/identifier$/)
  assert.equal(appels[0].headers.Authorization, 'Bearer jeton-client')
})

test('mode invité : aucun jeton, même si un client est resté connecté', async () => {
  // Un jeton périmé ferait refuser toute la requête : l'invité n'en a pas besoin.
  await boutique.identifier('p1', { mode: 'invite', email: 'a@exemple.fr' })
  assert.equal(appels.length, 1)
  assert.equal(appels[0].headers.Authorization, undefined)
})
