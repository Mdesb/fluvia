// Mentions imprimées du billet et du ticket. Lancer : `cd frontend && node --test src/api/receiptPrint.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { isFree, issuerLines, passesToPrint, printedDateTime, thermalPageCss, vatRows, venueName } from './receiptPrint.js'

const emetteur = {
  nom: 'CIAP Casa Xanxo',
  raisonSociale: 'Ville de Perpignan',
  adresse: 'Place de la Loge, 66000 Perpignan',
  siret: '21660136400019',
  numeroTva: null,
}

test('le billet s’ouvre sur le nom de l’établissement', () => {
  assert.equal(venueName(emetteur), 'CIAP Casa Xanxo')
  assert.equal(venueName({ nom: '', raisonSociale: 'Ville de Perpignan' }), 'Ville de Perpignan')
  assert.equal(venueName(null), '')
})

test('le ticket nomme l’émetteur : raison sociale, établissement, adresse, SIRET — et rien d’inventé', () => {
  assert.deepEqual(issuerLines(emetteur), [
    'Ville de Perpignan',
    'CIAP Casa Xanxo',
    'Place de la Loge, 66000 Perpignan',
    'SIRET 21660136400019',
  ])
  assert.deepEqual(issuerLines({ nom: 'CIAP Casa Xanxo', raisonSociale: null, adresse: null, siret: null }), ['CIAP Casa Xanxo'])
})

test('date et heure dans le fuseau de l’établissement', () => {
  // 13:05 UTC le 1er décembre = 14:05 à Perpignan (heure d’hiver).
  assert.equal(printedDateTime('2026-12-01T13:05:00+00:00'), '01/12/2026 14:05')
  assert.equal(printedDateTime('pas une date'), '')
  assert.equal(printedDateTime(null), '')
})

test('un billet à prix nul dit « Gratuit »', () => {
  assert.equal(isFree('0.00'), true)
  assert.equal(isFree(0), true)
  assert.equal(isFree('4.95'), false)
})

test('la page du rouleau fait 80 mm de large et la hauteur du document, sans marge', () => {
  // 378 px CSS ≈ 100 mm, plus 4 mm de garde pour la coupe.
  assert.equal(thermalPageCss(378), '@page { size: 80mm 105mm; margin: 0; }')
  // Un document vide ou non mesuré ne fait pas une page de 0 mm.
  assert.equal(thermalPageCss(0), '@page { size: 80mm 40mm; margin: 0; }')
  assert.equal(thermalPageCss(undefined), '@page { size: 80mm 40mm; margin: 0; }')
})

test('la TVA se ventile par taux, et un taux inconnu ne s’imprime pas « 0 % »', () => {
  assert.deepEqual(vatRows([
    { taux: '20.00', totalHt: '8.25', totalTva: '1.65', totalTtc: '9.90' },
    { taux: '5.50', totalHt: '9.48', totalTva: '0.52', totalTtc: '10.00' },
    { taux: null, totalHt: '3.00', totalTva: '0.00', totalTtc: '3.00' },
  ]), [
    { libelle: 'TVA 20 %', ht: 8.25, tva: 1.65, ttc: 9.9 },
    { libelle: 'TVA 5,5 %', ht: 9.48, tva: 0.52, ttc: 10 },
    { libelle: 'TVA non renseignée', ht: 3, tva: 0, ttc: 3 },
  ])
  assert.deepEqual(vatRows(undefined), [])
})

test('un billet récapitulatif par défaut, avec le code du premier support', () => {
  const ticket = {
    codeSupport: 'C1',
    lignes: [{ id: 'l1', quantite: 3, libelle: 'Entrée' }],
    supports: [{ code: 'C1', ligne: 'l1' }, { code: 'C2', ligne: 'l1' }, { code: 'C3', ligne: 'l1' }],
    billetParUnite: false,
  }
  assert.deepEqual(passesToPrint(ticket), [{ code: 'C1', lignes: [{ id: 'l1', quantite: 3, libelle: 'Entrée' }] }])
})

test('« un billet par unité » : 3 unités, 3 billets, chacun son code et « 1 × » sa ligne', () => {
  const ticket = {
    codeSupport: 'C1',
    lignes: [{ id: 'l1', quantite: 2, libelle: 'Entrée' }, { id: 'l2', quantite: 1, libelle: 'Visite guidée' }],
    supports: [{ code: 'C1', ligne: 'l1' }, { code: 'C2', ligne: 'l1' }, { code: 'C3', ligne: 'l2' }],
    billetParUnite: true,
  }
  assert.deepEqual(passesToPrint(ticket), [
    { code: 'C1', lignes: [{ quantite: 1, libelle: 'Entrée' }] },
    { code: 'C2', lignes: [{ quantite: 1, libelle: 'Entrée' }] },
    { code: 'C3', lignes: [{ quantite: 1, libelle: 'Visite guidée' }] },
  ])
})

test('sans support, aucun billet : une vente de boutique n’imprime pas un billet vide', () => {
  assert.deepEqual(passesToPrint({ codeSupport: null, lignes: [], billetParUnite: true }), [])
})
