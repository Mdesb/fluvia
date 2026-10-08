// La fiche guidée (lot des garde-fous, 08/10) : où régler ce qui manque, et sur quel onglet ouvrir.
// Lancer : `cd frontend && node --test src/api/`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { complementDuType, complementValide, corpsCreation, lignesManquantes, ongletInitial } from './publication.js'

const ONGLETS = [['vitrine', 'Présentation'], ['vente', 'Vente'], ['caisse', 'Caisse'], ['acces', 'Accès']]

test('chaque manque mène à son onglet, et un onglet absent ici ne mène nulle part', () => {
  const lignes = lignesManquantes([
    { code: 'carte', message: 'Créez sa carte.' },
    { code: 'zone_acces', message: 'Choisissez les zones.' },
    { code: 'creneau', message: 'Programmez un créneau.' },
  ], ONGLETS)
  assert.deepEqual(lignes.map((l) => [l.code, l.onglet, l.libelleOnglet]), [
    ['carte', 'vente', 'Vente'],
    ['zone_acces', 'acces', 'Accès'],
    ['creneau', null, null],
  ])
  assert.equal(lignes[0].message, 'Créez sa carte.', 'la phrase du serveur passe telle quelle')
})

test('la fiche s’ouvre sur le premier onglet incomplet, dans l’ordre affiché, sinon sur Vente', () => {
  assert.equal(ongletInitial([], ONGLETS), 'vente')
  assert.equal(ongletInitial(null, ONGLETS), 'vente', 'pas encore lu : Vente')
  assert.equal(ongletInitial([{ code: 'zone_acces' }, { code: 'prix' }], ONGLETS), 'vente')
  assert.equal(ongletInitial([{ code: 'zone_acces' }], ONGLETS), 'acces')
  assert.equal(ongletInitial([{ code: 'libelle' }, { code: 'prix' }], ONGLETS), 'vitrine')
  assert.equal(ongletInitial([{ code: 'creneau' }], ONGLETS), 'vente', 'onglet absent : on ne l’invente pas')
})

test('le type dit ce qu’il faut créer avec le produit', () => {
  assert.equal(complementDuType({ facettes: ['carnet', 'consommateur'] }), 'carte')
  assert.equal(complementDuType({ facettes: ['formule', 'acces'] }), 'formule')
  assert.equal(complementDuType({ facettes: ['billet'] }), null)
  assert.equal(complementDuType(undefined), null)
})

test('la création refuse des entrées non entières et un jour de prélèvement hors de 1 à 28', () => {
  const carte = { facettes: ['carnet'] }
  const abo = { facettes: ['formule'] }
  assert.equal(complementValide(carte, { carte: { nbPaye: '10', nbCredite: '12' } }), true)
  assert.equal(complementValide(carte, { carte: { nbPaye: '2.5', nbCredite: '3' } }), false)
  assert.equal(complementValide(carte, { carte: { nbPaye: '', nbCredite: '3' } }), false)
  assert.equal(complementValide(abo, { formule: { sepaActif: true, jourPrelevement: '30' } }), false)
  assert.equal(complementValide(abo, { formule: { sepaActif: true, jourPrelevement: '5' } }), true)
  assert.equal(complementValide(abo, { formule: { sepaActif: false, jourPrelevement: '30' } }), true, 'sans prélèvement, le jour ne compte pas')
  assert.equal(complementValide({ facettes: ['billet'] }, {}), true)
})

test('la carte et la formule partent dans le même appel que le produit', () => {
  const base = { libelle: ' Carte 10 ', typeId: 't1', canaux: ['guichet'] }
  assert.deepEqual(corpsCreation({ ...base, type: { facettes: ['carnet'] }, carte: { nbPaye: '10', nbCredite: '12' } }), {
    libelle: { fr: 'Carte 10' }, type: '/api/type_produits/t1', canaux: ['guichet'],
    carte: { nbPaye: 10, nbCredite: 12 },
  })
  assert.deepEqual(
    corpsCreation({ ...base, type: { facettes: ['formule'] }, formule: { periodicite: 'annuel', sepaActif: true, jourPrelevement: '5' } }).formule,
    { periodicite: 'annuel', sepaActif: true, jourPrelevement: 5 },
  )
  assert.equal(
    corpsCreation({ ...base, type: { facettes: ['formule'] }, formule: { periodicite: 'mensuel', sepaActif: false, jourPrelevement: '5' } }).formule.jourPrelevement,
    null,
    'sans prélèvement, pas de jour de prélèvement',
  )
  const entree = corpsCreation({ ...base, type: { facettes: ['billet'] }, carte: { nbPaye: 1, nbCredite: 1 } })
  assert.equal('carte' in entree || 'formule' in entree, false, 'un type qui n’en porte pas n’en reçoit pas')
})
