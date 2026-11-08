// Clore un mois ou une année demande confirmation (#362). Lancer : `cd frontend && node --test src/api/periodClosing.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { CANCELLED, closePeriod, closingConfirmation, monthLabel } from './periodClosing.js'

// Un serveur qui note chaque appel, et une modale qui répond ce qu'on lui dit en gardant la question.
function server() {
  const calls = []
  return {
    calls,
    cloturerMois: (pdv, mois) => { calls.push(['mois', pdv, mois]); return Promise.resolve({ id: 'c1' }) },
    cloturerAnnee: (pdv, annee) => { calls.push(['annee', pdv, annee]); return Promise.resolve({ id: 'c2' }) },
  }
}
function modal(answer) {
  const asked = []
  return { asked, confirm: (demande) => { asked.push(demande); return Promise.resolve(answer) } }
}

for (const [kind, period, expected] of [['monthly', '2026-10', ['mois', 'pdv1', '2026-10']], ['annual', '2026', ['annee', 'pdv1', '2026']]]) {
  test(`${kind} : annuler la modale n’appelle pas le serveur`, async () => {
    const api = server()
    const m = modal(false)
    const r = await closePeriod({ kind, period, pointOfSaleId: 'pdv1', pointOfSaleLabel: 'Caisse piscine' }, { confirm: m.confirm, api })
    assert.equal(r, CANCELLED)
    assert.equal(m.asked.length, 1, 'la question a bien été posée')
    assert.deepEqual(api.calls, [])
  })

  test(`${kind} : confirmer appelle le serveur une fois, sur la période et le point de vente choisis`, async () => {
    const api = server()
    const m = modal(true)
    const r = await closePeriod({ kind, period, pointOfSaleId: 'pdv1', pointOfSaleLabel: 'Caisse piscine' }, { confirm: m.confirm, api })
    assert.notEqual(r, CANCELLED)
    assert.deepEqual(api.calls, [expected])
  })
}

test('la modale rappelle la période, le point de vente et le caractère définitif', () => {
  const mois = closingConfirmation({ kind: 'monthly', period: '2026-10', pointOfSaleLabel: 'Caisse piscine' })
  assert.match(mois.titre, /octobre 2026/)
  assert.match(mois.texte, /octobre 2026/)
  assert.match(mois.texte, /Caisse piscine/)
  assert.match(mois.consequence, /définitive/)
  assert.equal(mois.danger, true)

  const annee = closingConfirmation({ kind: 'annual', period: '2026', pointOfSaleLabel: 'Caisse piscine' })
  assert.match(annee.titre, /2026/)
  assert.match(annee.consequence, /définitive/)
})

test('le mois se dit en toutes lettres, et une saisie inattendue n’est pas inventée', () => {
  assert.equal(monthLabel('2026-01'), 'janvier 2026')
  assert.equal(monthLabel('2026-12'), 'décembre 2026')
  assert.equal(monthLabel('2026-13'), '2026-13')
  assert.equal(monthLabel(''), '')
})
