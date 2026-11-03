// La fiche produit, refonte lot 2 : onglets selon le type, sauvegarde automatique.
// Lancer : `cd frontend && node --test src/api/`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { autosaveHold, changedFields, saveIndicator, sheetComplement, sheetTabs, stableForm } from './productSheet.js'
import { lignesManquantes } from './publication.js'

const cles = (onglets) => onglets.map(([c]) => c)

test('un abonnement a son onglet Abonnement, une carte le sien, une entrée aucun des deux', () => {
  assert.deepEqual(cles(sheetTabs([], 'formule')), ['vitrine', 'vente', 'formule', 'caisse'])
  assert.deepEqual(sheetTabs([], 'carte', "Carte d'entrées")[2], ['carte', "Carte d'entrées"])
  assert.deepEqual(cles(sheetTabs([], null)), ['vitrine', 'vente', 'caisse'])
})

test('les capacités composent toujours les onglets de module, après ceux du type', () => {
  assert.deepEqual(
    cles(sheetTabs(['comptabilite', 'controle_acces', 'reservation', 'stock'], 'formule')),
    ['vitrine', 'vente', 'formule', 'caisse', 'agenda', 'acces', 'stock', 'compta'],
  )
})

test('une formule déjà là reste réglable même si le type ne l’exige pas', () => {
  assert.equal(sheetComplement(null, { formule: { periodicite: 'mensuel' }, carte: null }), 'formule')
  assert.equal(sheetComplement(null, { formule: null, carte: { nbPaye: '10' } }), 'carte')
  assert.equal(sheetComplement('carte', { formule: null, carte: null }), 'carte')
  assert.equal(sheetComplement(null, { formule: null, carte: null }), null)
})

test('la carte ou la formule qui manque mène à son propre onglet, plus à Vente', () => {
  const lignes = lignesManquantes(
    [{ code: 'formule', message: 'Créez sa formule.' }, { code: 'prix', message: 'Un prix.' }],
    sheetTabs([], 'formule'),
  )
  assert.deepEqual(lignes.map((l) => [l.code, l.onglet, l.libelleOnglet]), [
    ['formule', 'formule', 'Abonnement'],
    ['prix', 'vente', 'Vente'],
  ])
  const carte = lignesManquantes([{ code: 'carte', message: 'Créez sa carte.' }], sheetTabs([], 'carte', 'Carte'))
  assert.equal(carte[0].onglet, 'carte')
})

test('le même contenu, recomposé dans un autre ordre, n’est pas une modification', () => {
  const a = { canaux: ['guichet', 'en_ligne'], formule: { periodicite: 'mensuel', sepaActif: false } }
  const b = { canaux: ['en_ligne', 'guichet'], formule: { sepaActif: false, periodicite: 'mensuel' } }
  assert.deepEqual(changedFields(a, b, ['canaux', 'formule']), [])
  assert.deepEqual(changedFields({ ...a, canaux: ['guichet'] }, b, ['canaux', 'formule']), ['canaux'])
  assert.equal(stableForm(null), stableForm(undefined))
})

test('un nom vide ne part pas tout seul', () => {
  assert.equal(autosaveHold({ libelle: '   ', etablissements: [] }, 'e1'), 'libelle')
  assert.equal(autosaveHold({ libelle: 'Entrée', etablissements: [] }, 'e1'), null)
})

test('décocher le site où l’on est attend une confirmation ; tout autre site part seul', () => {
  assert.equal(autosaveHold({ libelle: 'Entrée', etablissements: ['e2'] }, 'e1'), 'perimetre')
  assert.equal(autosaveHold({ libelle: 'Entrée', etablissements: ['e1', 'e2'] }, 'e1'), null)
  // Aucun site coché : la garde de publication le dit ; le produit ne sort pas du périmètre.
  assert.equal(autosaveHold({ libelle: 'Entrée', etablissements: [] }, 'e1'), null)
  assert.equal(autosaveHold({ libelle: 'Entrée', etablissements: ['e2'] }, null), null)
})

test('l’indicateur ne dit « Enregistré » qu’après une écriture, et dit pourquoi il attend', () => {
  assert.equal(saveIndicator({}), null, 'rien d’affirmé à l’ouverture')
  assert.equal(saveIndicator({ dejaEnregistre: true }).texte, 'Enregistré')
  assert.equal(saveIndicator({ enAttente: true, dejaEnregistre: true }).texte, 'Modifications en cours…')
  assert.equal(saveIndicator({ enCours: true, echec: true }).texte, 'Enregistrement…')
  assert.equal(saveIndicator({ echec: true, enAttente: true }).ton, 'crit')
  assert.match(saveIndicator({ retenue: 'libelle', enAttente: true }).texte, /nom/)
  assert.match(saveIndicator({ retenue: 'perimetre', enAttente: true }).texte, /confirmation/)
})
