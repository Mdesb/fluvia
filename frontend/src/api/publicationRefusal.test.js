// Le refus de publier ne survit pas à ce qu'il disait (vu à l'écran le 03/11).
// Lancer : `cd frontend && node --test src/api/`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { codesManquants, refusAAfficher } from './publication.js'

const AU_REFUS = [{ code: 'prix', message: 'Donnez-lui au moins un prix.' }, { code: 'zone_acces', message: 'Choisissez les zones.' }]
const refus = { message: 'Publication impossible. Donnez-lui au moins un prix. Choisissez les zones.', codes: codesManquants(AU_REFUS) }

test('tant que la readiness relue dit la même chose, le refus reste affiché', () => {
  assert.equal(refusAAfficher(refus, [...AU_REFUS].reverse()), refus.message)
})

test('un manque comblé (le prix ajouté) fait disparaître le refus : l’encadré à jour parle seul', () => {
  assert.equal(refusAAfficher(refus, [{ code: 'zone_acces', message: 'Choisissez les zones.' }]), null)
})

test('plus rien ne manque : le refus disparaît', () => {
  assert.equal(refusAAfficher(refus, []), null)
})

test('readiness illisible : le refus du serveur reste la seule information', () => {
  assert.equal(refusAAfficher(refus, null), refus.message)
})

test('aucun refus : rien à afficher', () => {
  assert.equal(refusAAfficher(null, []), null)
})
