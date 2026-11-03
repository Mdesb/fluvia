// La création guidée d'un produit (refonte, lot 1). Lancer : `cd frontend && node --test src/api/`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  categorieParDefaut, corpsPrix, corpsProduit, descriptionDuType, manquesEtape, prixLu,
  recapitulatif, saisieInitiale, tarifParDefaut, toutEstPret,
} from './productCreation.js'

const ENTREE = { id: 't1', code: 'entree_unitaire', libelle: 'Entrée unitaire', facettes: ['billet'] }
const CARTE = { id: 't2', code: 'carte', libelle: 'Carte multi-entrées', facettes: ['carnet'] }
const ABO = { id: 't3', code: 'abonnement', libelle: 'Abonnement', facettes: ['formule', 'acces'] }
const TARIFS = [
  { id: 'r', nom: 'Réduit', ordreAffichage: 0 },
  { id: 'p', nom: 'Plein tarif', ordreAffichage: 5 },
]
const CANAUX = [{ valeur: 'guichet', libelle: 'Au guichet' }, { valeur: 'en_ligne', libelle: 'En ligne' }]

test('le tarif proposé est « Plein tarif », sinon le premier affiché ; jamais un tarif inactif', () => {
  assert.equal(tarifParDefaut(TARIFS).id, 'p')
  assert.equal(tarifParDefaut([{ id: 'b', nom: 'B', ordreAffichage: 2 }, { id: 'a', nom: 'A', ordreAffichage: 1 }]).id, 'a')
  assert.equal(tarifParDefaut([{ id: 'p', nom: 'Plein tarif', actif: false }]), null)
  assert.equal(tarifParDefaut([]), null)
})

test('la catégorie de recette n’est proposée que si elle est la seule de l’axe comptable', () => {
  assert.equal(categorieParDefaut([{ id: 'c', axe: 'comptable' }, { id: 'm', axe: 'marketing' }]).id, 'c')
  assert.equal(categorieParDefaut([{ id: 'c1', axe: 'comptable' }, { id: 'c2', axe: 'comptable' }]), null)
  assert.equal(categorieParDefaut([]), null)
})

test('la saisie part avec le guichet, le site courant et le plein tarif cochés', () => {
  const s = saisieInitiale({ etabActif: 'e1', typesTarif: TARIFS, categories: [] })
  assert.deepEqual(s.canaux, ['guichet'])
  assert.deepEqual(s.etablissements, ['e1'])
  assert.equal(s.typeTarifId, 'p')
  assert.equal(s.categorieId, '')
})

test('un prix s’écrit avec une virgule ou un point, deux décimales au plus, jamais négatif', () => {
  assert.equal(prixLu('12,50'), 12.5)
  assert.equal(prixLu(' 8 '), 8)
  assert.equal(prixLu('0'), 0)
  for (const faux of ['', '-3', '12,555', 'abc', '1 000', null]) assert.equal(prixLu(faux), null, String(faux))
})

test('chaque étape dit en phrases ce qui l’empêche d’avancer', () => {
  const s = saisieInitiale({ etabActif: 'e1', typesTarif: TARIFS })
  assert.deepEqual(manquesEtape('quoi', s, null), ['Donnez-lui un nom.', 'Choisissez ce que c’est.'])
  const carte = { ...s, libelle: 'Carte 10', typeId: 't2', carte: { nbPaye: '2,5', nbCredite: '10' } }
  assert.deepEqual(manquesEtape('quoi', carte, CARTE), ['Indiquez des nombres d’entrées entiers, d’au moins 1.'])
  const abo = { ...s, libelle: 'Abo', typeId: 't3', formule: { periodicite: 'mensuel', sepaActif: true, jourPrelevement: '30' } }
  assert.deepEqual(manquesEtape('quoi', abo, ABO), ['Le jour du prélèvement va de 1 à 28.'])
  assert.deepEqual(manquesEtape('prix', s, ENTREE), ['Indiquez un prix, par exemple 12,50.'])
  assert.deepEqual(manquesEtape('ou', { ...s, canaux: [], etablissements: [] }, ENTREE),
    ['Cochez au moins un endroit où le vendre.', 'Cochez au moins un site.'])
})

test('une entrée complète est prête ; il suffit d’une étape incomplète pour qu’elle ne le soit plus', () => {
  const s = { ...saisieInitiale({ etabActif: 'e1', typesTarif: TARIFS }), libelle: 'Entrée adulte', typeId: 't1', prix: '6,50' }
  assert.equal(toutEstPret(s, ENTREE), true)
  assert.equal(toutEstPret({ ...s, prix: '' }, ENTREE), false)
})

test('le produit part en un appel : libellé, type, canaux, sites, catégorie et la carte du type', () => {
  const s = {
    ...saisieInitiale({ etabActif: 'e1', typesTarif: TARIFS }),
    libelle: ' Carte 10 ', typeId: 't2', categorieId: 'c9', canaux: ['guichet', 'en_ligne'],
    carte: { nbPaye: '10', nbCredite: '11' },
  }
  assert.deepEqual(corpsProduit(s, CARTE), {
    libelle: { fr: 'Carte 10' },
    type: '/api/type_produits/t2',
    canaux: ['guichet', 'en_ligne'],
    carte: { nbPaye: 10, nbCredite: 11 },
    etablissements: ['/api/etablissements/e1'],
    categories: ['/api/categories/c9'],
  })
  // Sans catégorie choisie, le champ n'est pas envoyé : il se règle ensuite sur la fiche.
  assert.equal('categories' in corpsProduit({ ...s, categorieId: '' }, CARTE), false)
})

test('le prix part sans saison — toute l’année — et en deux décimales', () => {
  const s = { ...saisieInitiale({ typesTarif: TARIFS }), prix: '6,5' }
  assert.deepEqual(corpsPrix('x1', s), { produit: '/api/produits/x1', typeTarif: '/api/type_tarifs/p', prix: '6.50' })
})

test('le récapitulatif reprend chaque choix en mots, formule comprise', () => {
  const s = {
    ...saisieInitiale({ etabActif: 'e1', typesTarif: TARIFS }),
    libelle: 'Abo', typeId: 't3', prix: '30', canaux: ['guichet', 'en_ligne'],
    formule: { periodicite: 'mensuel', sepaActif: true, jourPrelevement: '5' },
  }
  const r = Object.fromEntries(recapitulatif(s, {
    type: ABO, typesTarif: TARIFS, categories: [], canaux: CANAUX, etablissements: [{ id: 'e1', nom: 'Piscine A' }],
  }))
  assert.equal(r.Formule, 'Mensuelle, prélevée automatiquement le 5')
  assert.match(r.Prix, /30,00\s€ · Plein tarif · toute l’année/)
  assert.equal(r['Catégorie de recette'], 'à choisir sur la fiche')
  assert.equal(r.Vendu, 'Au guichet, En ligne')
  assert.equal(r.Sites, 'Piscine A')
})

test('chaque type connu a sa phrase et son exemple ; un type inconnu n’en invente pas', () => {
  // Les cinq codes de `off_type_produit` relevés le 03/11 (GET /api/type_produits).
  for (const t of [ENTREE, CARTE, ABO, { code: 'prestation' }, { code: 'boutique_stock' }]) assert.ok(descriptionDuType(t)?.exemple, t.code)
  assert.equal(descriptionDuType({ code: 'nouveau_type' }), null)
})
