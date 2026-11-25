// Section « Paiement par PayFiP » — logique pure (lot D2-B de #365, G-6).
// Lancer : `cd frontend && node --test src/components/*.test.js`
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  corpsNumeroClient, libelleEtat, messageErreurNumeroClient, messageNumeroClient, numeroClientValide,
  peutConfigurerPaiement, profilEligiblePayFip, texteConfirmationEffacement,
} from './paiementPayFip.js'

test('seule une régie directe est éligible à PayFiP', () => {
  assert.equal(profilEligiblePayFip('regie_directe'), true)
  assert.equal(profilEligiblePayFip('groupe_prive'), false)
  assert.equal(profilEligiblePayFip('dsp'), false)
  assert.equal(profilEligiblePayFip('autre'), false)
  assert.equal(profilEligiblePayFip(undefined), false)
})

test('le droit : masqué sans, visible avec (exact ou joker), via le test de droit déjà partagé', () => {
  assert.equal(peutConfigurerPaiement([]), false)
  assert.equal(peutConfigurerPaiement(undefined), false)
  assert.equal(peutConfigurerPaiement(['compta.gerer']), false)
  assert.equal(peutConfigurerPaiement(['payment.configure']), true)
  assert.equal(peutConfigurerPaiement(['*.*']), true)
  assert.equal(peutConfigurerPaiement(['payment.*']), true)
})

test('le numéro compte exactement 6 chiffres, espaces de bord rognés', () => {
  assert.equal(numeroClientValide('123456'), true)
  assert.equal(numeroClientValide(' 123456 '), true)
  assert.equal(numeroClientValide('000001'), true)
  assert.equal(numeroClientValide('12345'), false)
  assert.equal(numeroClientValide('1234567'), false)
  assert.equal(numeroClientValide('12345a'), false)
  assert.equal(numeroClientValide('12 3456'), false)
  assert.equal(numeroClientValide(''), false, 'vide = effacement, pas une saisie valide')
  assert.equal(numeroClientValide(undefined), false)
  assert.equal(numeroClientValide(123456), true, 'un nombre est rendu en texte')
})

test('le corps : six chiffres rognés, ou null pour effacer', () => {
  assert.deepEqual(corpsNumeroClient(' 123456 '), { payfipClientNumber: '123456' })
  assert.deepEqual(corpsNumeroClient(''), { payfipClientNumber: null })
  assert.deepEqual(corpsNumeroClient('   '), { payfipClientNumber: null })
  assert.deepEqual(corpsNumeroClient(undefined), { payfipClientNumber: null })
  assert.throws(() => corpsNumeroClient('12345'), /6 chiffres/, 'un numéro invalide ne part jamais')
})

test('le message de saisie : vide pour une saisie valide ou vide, explicite sinon', () => {
  assert.equal(messageNumeroClient('123456'), '')
  assert.equal(messageNumeroClient(''), '')
  assert.match(messageNumeroClient('12345'), /6 chiffres/)
  assert.match(messageNumeroClient('12345a'), /6 chiffres/)
  assert.equal(messageNumeroClient('12345', ), messageNumeroClient('1234567'))
})

test('le libellé d’état : actif avec un numéro, bouchon sans', () => {
  assert.match(libelleEtat('123456'), /PayFiP actif/)
  assert.match(libelleEtat(null), /non configuré/)
  assert.match(libelleEtat(null), /bouchon/)
  assert.match(libelleEtat(''), /non configuré/)
  assert.notEqual(libelleEtat('123456'), libelleEtat(null))
})

test('les erreurs du serveur : le texte du serveur, sans écho de la valeur', () => {
  assert.equal(messageErreurNumeroClient({ status: 404, message: 'x' }), 'Cet exploitant n’est plus accessible depuis cet établissement.')
  assert.match(messageErreurNumeroClient({ status: 403, message: 'Access Denied.' }), /droit/i)
  assert.equal(messageErreurNumeroClient({ status: 422, message: 'Seule une régie directe encaisse par PayFiP.' }), 'Seule une régie directe encaisse par PayFiP.')
  assert.equal(messageErreurNumeroClient(undefined), 'L’opération a échoué.')
})

test('la confirmation d’effacement nomme l’objet, dit la conséquence et est dangereuse', () => {
  const t = texteConfirmationEffacement()
  assert.match(t.titre, /numéro client PayFiP/)
  assert.match(t.consequence, /déjà ouverts/)
  assert.equal(t.danger, true)
  assert.equal(t.libelleOk, 'Effacer')
})
