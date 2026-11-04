// Les aperçus de la fiche produit, refonte lot 3.
// Lancer : `cd frontend && node --test src/api/*.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { shopCardPreview, tilePreview } from './productPreview.js'

const tarif = (prix) => ({ typeTarif: { id: 't' + prix }, prix })
const produit = { id: 'p1', code: 'ENT-01', libelle: { fr: 'Ancien nom' }, grilles: [tarif('12.00'), tarif('8.50')] }

test('la tuile suit le brouillon : le nom et la couleur en cours de saisie, pas ceux enregistrés', () => {
  const t = tilePreview({ libelle: 'Entrée adulte', couleurCaisse: '#336699' }, produit)
  assert.equal(t.libelle, 'Entrée adulte')
  assert.equal(t.couleur, '#336699')
  assert.equal(t.code, 'ENT-01')
  assert.equal(t.prix, '12.00', 'le prix que la caisse affiche : celui de la première grille vendable')
  assert.equal(t.raison, null)
})

test('un nom effacé ne laisse pas une tuile vide, et sans couleur la tuile n’en invente pas', () => {
  const t = tilePreview({ libelle: '   ', couleurCaisse: '' }, produit)
  assert.equal(t.libelle, 'Sans nom')
  assert.equal(t.couleur, null)
})

test('sans tarif, la tuile dit ce que dira la caisse', () => {
  assert.equal(tilePreview({ libelle: 'X' }, { ...produit, grilles: [] }).raison, 'Pas de tarif au guichet')
})

test('la carte en ligne : première photo, fourchette de prix, et le canal décide si elle existe', () => {
  const { enLigne, carte } = shopCardPreview(
    { libelle: 'Entrée adulte', canaux: ['guichet', 'en_ligne'] },
    produit,
    [{ url: '/photos/a.jpg' }, { url: '/photos/b.jpg' }],
  )
  assert.equal(enLigne, true)
  assert.equal(carte.visuel, '/photos/a.jpg')
  assert.deepEqual(carte.prix, { min: 8.5, max: 12 })
  assert.equal(carte.libelle, 'Entrée adulte')

  assert.equal(shopCardPreview({ libelle: 'X', canaux: ['guichet'] }, produit, []).enLigne, false)
})

test('sans photo ni tarif, la carte garde sa forme : pas de visuel, pas de prix', () => {
  const { carte } = shopCardPreview({ libelle: 'X', canaux: ['en_ligne'] }, { ...produit, grilles: [] }, undefined)
  assert.equal(carte.visuel, null)
  assert.equal(carte.prix, null)
})
