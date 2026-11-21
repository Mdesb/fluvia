// Section « Paiement par carte (Stripe) » — logique pure (lot D3-B de #365, G-6 et G-9).
// Lancer : `cd frontend && node --test src/components/`
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  adresseNotification, corpsEnregistrement, libelleMode, ligneDuProfil, messageErreur,
  peutConfigurerPaiement, profilEligible, saisieEnvoyable, texteConfirmationRetrait,
} from './paiementStripe.js'

const ID = '0b9f3c4e-5a1d-4c7e-8f20-123456789abc'

test('le droit : exact, joker, absent, et profil non chargé', () => {
  assert.equal(peutConfigurerPaiement(['payment.configure']), true)
  assert.equal(peutConfigurerPaiement(['*.*']), true)
  assert.equal(peutConfigurerPaiement(['payment.*']), true)
  assert.equal(peutConfigurerPaiement(['*.lire']), false)
  assert.equal(peutConfigurerPaiement(['paiement.configurer']), false)
  assert.equal(peutConfigurerPaiement([]), false)
  assert.equal(peutConfigurerPaiement(undefined), false)
})

test('le corps omet les champs vides et ne désigne le profil qu’à la création', () => {
  assert.deepEqual(corpsEnregistrement('', '', undefined), {})
  assert.deepEqual(corpsEnregistrement('  ', '  ', undefined), {})
  assert.deepEqual(corpsEnregistrement('rk_test_abcdefgh', '', undefined), { secretKeyPlain: 'rk_test_abcdefgh' })
  assert.deepEqual(corpsEnregistrement('', 'whsec_abcdefgh', undefined), { webhookSecretPlain: 'whsec_abcdefgh' })
  const creation = corpsEnregistrement(' rk_test_abcdefgh ', 'whsec_abcdefgh', ID)
  assert.deepEqual(creation, {
    secretKeyPlain: 'rk_test_abcdefgh', webhookSecretPlain: 'whsec_abcdefgh', businessProfile: `/api/profil_exploitants/${ID}`,
  })
  // Témoin : aucune clé de lecture du serveur (hasSecretKey…) ne peut être renvoyée par ce corps.
  assert.ok(Object.keys(creation).every((k) => !k.startsWith('has')))
})

test('une saisie est envoyable : la clé à la création, l’une des deux ensuite', () => {
  assert.equal(saisieEnvoyable({ cle: '', secret: 'whsec_x', existe: false }), false)
  assert.equal(saisieEnvoyable({ cle: 'rk_test_x', secret: '', existe: false }), true)
  assert.equal(saisieEnvoyable({ cle: '', secret: 'whsec_x', existe: true }), true)
  assert.equal(saisieEnvoyable({ cle: ' ', secret: ' ', existe: true }), false)
})

test('le mode : test, live, inconnu', () => {
  assert.match(libelleMode('test'), /test/i)
  assert.match(libelleMode('live'), /live/i)
  assert.notEqual(libelleMode('test'), libelleMode('live'))
  assert.equal(libelleMode('autre'), null)
  assert.equal(libelleMode(undefined), null)
})

test('l’adresse de notification : bien formée pour un UUID, refusée sinon', () => {
  assert.equal(
    adresseNotification('https://exemple.test', ID),
    `https://exemple.test/api/boutique/paiement-en-ligne/notification/stripe/${ID}`,
  )
  assert.equal(adresseNotification('https://exemple.test/', ID), adresseNotification('https://exemple.test', ID))
  assert.equal(adresseNotification('https://exemple.test', '../x'), null)
  assert.equal(adresseNotification('https://exemple.test', `${ID}\n`), null)
  assert.equal(adresseNotification('https://exemple.test', ''), null)
  assert.equal(adresseNotification('', ID), null)
})

test('la régie directe est inéligible, les autres types non', () => {
  assert.equal(profilEligible('regie_directe'), false)
  assert.equal(profilEligible('dsp'), true)
  assert.equal(profilEligible('groupe_prive'), true)
})

test('la ligne du profil est retrouvée par son IRI', () => {
  const lignes = [{ id: 'a', businessProfile: '/api/profil_exploitants/autre' }, { id: 'b', businessProfile: `/api/profil_exploitants/${ID}` }]
  assert.equal(ligneDuProfil(lignes, ID).id, 'b')
  assert.equal(ligneDuProfil(lignes, 'inconnu'), null)
  assert.equal(ligneDuProfil([], ID), null)
  assert.equal(ligneDuProfil(undefined, ID), null)
})

test('la confirmation de retrait nomme l’objet, avertit des paiements en cours et est dangereuse', () => {
  const c = texteConfirmationRetrait('Régie de Sète')
  assert.match(c.titre, /Régie de Sète/)
  assert.ok(c.texte.length > 0 && c.consequence.length > 0)
  assert.match(c.consequence, /paiements par carte sont en cours/)
  assert.equal(c.danger, true)
  assert.ok(texteConfirmationRetrait('').titre.length > 0)
})

test('les erreurs 409, 503, 422 et 404 s’affichent en clair, sans rien inventer', () => {
  assert.match(messageErreur({ status: 409, message: 'Déjà enregistrée.' }), /Déjà enregistrée\. .*Rechargez/)
  assert.match(messageErreur({ status: 503, message: 'Chiffrement absent.' }), /Chiffrement absent\. Rien n’a été enregistré/)
  assert.equal(messageErreur({ status: 422, message: 'Format de clé Stripe non reconnu.' }), 'Format de clé Stripe non reconnu.')
  assert.match(messageErreur({ status: 404, message: 'Not Found' }), /plus accessible/)
  assert.ok(messageErreur(undefined).length > 0)
})
