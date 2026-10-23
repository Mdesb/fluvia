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

test('le premier connecteur proposé est l’externe (présélectionné à la création)', () => {
  assert.equal(CONNECTEURS_TPE[0].valeur, 'externe')
})
