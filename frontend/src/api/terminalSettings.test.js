import { test } from 'node:test'
import assert from 'node:assert/strict'
import { CONNECTEURS_TPE, avecTerminal } from './terminalSettings.js'

test('les trois champs se replient en { connecteur, marque, ref }, et ne partent pas seuls', () => {
  const corps = avecTerminal({ libelle: 'Accueil', tpe: 'externe', tpeMarque: ' Ingenico ', tpeRef: 'TPE-1' })
  assert.deepEqual(corps, { libelle: 'Accueil', tpe: { connecteur: 'externe', marque: 'Ingenico', ref: 'TPE-1' } })
})

test('sans choix, le terminal externe ; marque et référence vides ne partent pas', () => {
  assert.deepEqual(avecTerminal({ tpe: '', tpeMarque: '  ', tpeRef: '' }), { tpe: { connecteur: 'externe' } })
  assert.deepEqual(avecTerminal({}), { tpe: { connecteur: 'externe' } })
})

test('jamais le booléen d’avant : le serveur le refuse', () => {
  const { tpe } = avecTerminal({ tpe: 'simule' })
  assert.equal(typeof tpe, 'object')
  assert.equal(tpe.connecteur, 'simule')
})

test('modifier un point de vente garde ce que le formulaire ne montre pas (terminaux, ancien, paramètres)', () => {
  const existant = { connecteur: 'externe', marque: 'ingenico', ref: 'TPE-A1', terminaux: [{ marque: 'ingenico', ref: 'TPE-A1' }], ancien: true, delai: 30 }
  const { tpe } = avecTerminal({ tpe: 'externe', tpeMarque: 'Pax', tpeRef: '' }, existant)
  assert.deepEqual(tpe, { connecteur: 'externe', marque: 'Pax', terminaux: [{ marque: 'ingenico', ref: 'TPE-A1' }], ancien: true, delai: 30 },
    'les clés inconnues restent ; la référence vidée part ; la marque change')
})

test('un réglage d’avant ce lot (liste ou booléen) n’est pas recopié comme paramètres', () => {
  assert.deepEqual(avecTerminal({ tpe: 'externe' }, [{ marque: 'pax' }]).tpe, { connecteur: 'externe' })
  assert.deepEqual(avecTerminal({ tpe: 'externe' }, true).tpe, { connecteur: 'externe' })
})

test('le premier connecteur proposé est l’externe (présélectionné à la création)', () => {
  assert.equal(CONNECTEURS_TPE[0].valeur, 'externe')
})

test('le pont de caisse (#340) : clé, adresse et tiroir se replient dans le réglage ; vidés, ils partent', () => {
  const { tpe } = avecTerminal({ tpe: 'caisse_ap_pont', tpeClePont: ' QUJD ', tpePont: 'http://127.0.0.1:8765', tpeTiroir: true })
  assert.deepEqual(tpe, { connecteur: 'caisse_ap_pont', clePont: 'QUJD', pont: 'http://127.0.0.1:8765', tiroir: true })
  const existant = { connecteur: 'caisse_ap_pont', clePont: 'QUJD', pont: 'http://127.0.0.1:9000', tiroir: true }
  assert.deepEqual(avecTerminal({ tpe: 'caisse_ap_pont', tpeClePont: 'QUJD', tpePont: '', tpeTiroir: false }, existant).tpe, { connecteur: 'caisse_ap_pont', clePont: 'QUJD' })
  assert.deepEqual(avecTerminal({ tpe: 'caisse_ap_pont' }, existant).tpe, existant, 'absents du formulaire : gardés')
  assert.ok(CONNECTEURS_TPE.some((c) => c.valeur === 'caisse_ap_pont'))
})
