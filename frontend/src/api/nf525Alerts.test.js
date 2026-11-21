// Alertes NF525. Lancer : `cd frontend && node --test src/api/nf525Alerts.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  libelleCause, libelleStatut, trierAlertes, filtrerAlertes, noteValide, longueurNote,
  dateResolution, CAUSE_INCONNUE, NOTE_MAX,
} from './nf525Alerts.js'

const CONNUES = ['invalid_signature', 'unknown_key', 'key_regression', 'unreadable_order', 'chain_broken']

test('chaque cause connue a un libellé en clair, sans identifiant technique', () => {
  const vus = new Set()
  for (const c of CONNUES) {
    const l = libelleCause(c)
    assert.ok(l && l !== CAUSE_INCONNUE, c)
    assert.ok(!l.includes('_') && !l.includes(c), `aucun champ technique : ${l}`)
    vus.add(l)
  }
  assert.equal(vus.size, CONNUES.length)
})

test('une cause inconnue ou absente donne le repli, jamais undefined', () => {
  for (const c of ['nouvelle_cause', '', undefined, null, 42, 'constructor', '__proto__']) {
    assert.equal(libelleCause(c), CAUSE_INCONNUE)
  }
  assert.ok(!libelleCause('nouvelle_cause').includes('nouvelle_cause'))
})

test('libellé de statut, avec repli', () => {
  assert.equal(libelleStatut('open'), 'Ouverte')
  assert.equal(libelleStatut('resolved'), 'Résolue')
  assert.equal(libelleStatut('x'), 'Statut inconnu')
  assert.equal(libelleStatut(undefined), 'Statut inconnu')
})

test('tri : ouvertes d’abord, puis la plus récente d’abord ; entrée intacte', () => {
  const entree = [
    { id: 'a', status: 'resolved', lastEventAt: '2026-11-10T10:00:00+00:00' },
    { id: 'b', status: 'open', lastEventAt: '2026-11-01T10:00:00+00:00' },
    { id: 'c', status: 'resolved', lastEventAt: '2026-11-15T10:00:00+00:00' },
    { id: 'd', status: 'open', lastEventAt: '2026-11-05T10:00:00+00:00' },
  ]
  assert.deepEqual(trierAlertes(entree).map((a) => a.id), ['d', 'b', 'c', 'a'])
  assert.deepEqual(entree.map((a) => a.id), ['a', 'b', 'c', 'd'])
  assert.deepEqual(trierAlertes(undefined), [])
})

test('filtre ouvertes / résolues / toutes', () => {
  const l = [{ id: 1, status: 'open' }, { id: 2, status: 'resolved' }]
  assert.deepEqual(filtrerAlertes(l, 'open').map((a) => a.id), [1])
  assert.deepEqual(filtrerAlertes(l, 'resolved').map((a) => a.id), [2])
  assert.equal(filtrerAlertes(l, 'all').length, 2)
  assert.deepEqual(filtrerAlertes(null, 'open'), [])
})

test('note : 2000 caractères acceptés, 2001 refusés, vide acceptée', () => {
  assert.equal(NOTE_MAX, 2000)
  assert.equal(noteValide('a'.repeat(2000)), true)
  assert.equal(noteValide('a'.repeat(2001)), false)
  assert.equal(noteValide(''), true)
  assert.equal(noteValide(null), true)
})

test('note : un émoji compte pour un caractère, comme mb_strlen', () => {
  assert.equal(longueurNote('😀'), 1)
  assert.equal(noteValide('😀'.repeat(2000)), true)
  assert.equal(noteValide('😀'.repeat(2001)), false)
  assert.equal(noteValide('é'.repeat(2000)), true)
})

test('resolvedAt absent, null ou vide : null ; présent : rendu tel quel', () => {
  assert.equal(dateResolution({ id: 'x' }), null)
  assert.equal(dateResolution({ resolvedAt: null }), null)
  assert.equal(dateResolution({ resolvedAt: '' }), null)
  assert.equal(dateResolution(undefined), null)
  assert.equal(dateResolution({ resolvedAt: '2026-11-02T08:00:00+00:00' }), '2026-11-02T08:00:00+00:00')
})
