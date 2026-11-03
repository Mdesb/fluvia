// L'attente du terminal de paiement. Lancer : `cd frontend && node --test src/api/terminalWait.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { attemptPhase, createPoller, isExternal } from './terminalWait.js'
import { settle } from './paymentIntent.js'

const memoire = () => {
  const m = new Map()
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }
}

test('les phases : attendre, encaissé, refusé, annulé, à rembourser, à déclarer', () => {
  assert.equal(attemptPhase({ statut: 'sent' }), 'waiting')
  assert.equal(attemptPhase({ statut: 'pending' }), 'waiting')
  assert.equal(attemptPhase({ statut: 'accepted' }), 'paid')
  assert.equal(attemptPhase({ statut: 'declared_accepted' }), 'paid')
  assert.equal(attemptPhase({ statut: 'refused' }), 'refused')
  assert.equal(attemptPhase({ statut: 'cancelled' }), 'cancelled')
  // L'acceptation arrivée après « Annuler », que la vente ne pouvait plus recevoir : l'écran doit le dire.
  assert.equal(attemptPhase({ statut: 'cancelled', raison: 'Accepté par le terminal (réf. X) après l’annulation, … client débité de 45,00 € sans règlement, à rembourser.' }), 'refund')
  assert.equal(attemptPhase({ statut: 'unresolved' }), 'unknown')
  assert.equal(attemptPhase(null), 'unknown', 'rien de lu : on ne dit jamais « pas passé »')
})

test('le terminal externe se reconnaît à son connecteur', () => {
  assert.equal(isExternal({ connecteur: 'externe' }), true)
  assert.equal(isExternal({ connecteur: 'simule' }), false)
  assert.equal(isExternal(null), false)
})

// Un ordonnanceur à la main : chaque `tick()` déclenche la prochaine lecture prévue.
function horloge() {
  const prevues = []
  return { schedule: (fn) => { prevues.push(fn); return prevues.length }, cancel: () => {}, tick: async () => { const fn = prevues.shift(); if (fn) await fn() }, prevues }
}

test('la scrutation s’arrête d’elle-même sur une issue, et ne recommence pas', async () => {
  const lus = [{ statut: 'sent' }, { statut: 'sent' }, { statut: 'accepted', montantDebite: '45.00' }]
  const vus = []
  const h = horloge()
  const poller = createPoller({ read: async () => lus.shift(), onResult: (t) => vus.push(t.statut), schedule: h.schedule, cancel: h.cancel })
  poller.start()
  await h.tick(); await h.tick(); await h.tick()
  assert.deepEqual(vus, ['sent', 'sent', 'accepted'])
  assert.equal(h.prevues.length, 0, 'plus rien de prévu après l’issue')
})

test('la veille après « Annuler » continue tant que c’est annulé, et voit l’acceptation tardive', async () => {
  const lus = [{ statut: 'cancelled' }, { statut: 'cancelled' }, { statut: 'accepted', raison: 'Accepté par le terminal (réf. X) après l’annulation' }]
  const vus = []
  const h = horloge()
  const poller = createPoller({
    read: async () => lus.shift(),
    onResult: (t) => vus.push(attemptPhase(t)),
    done: (t) => attemptPhase(t) !== 'cancelled',
    schedule: h.schedule,
    cancel: h.cancel,
  })
  poller.start()
  await h.tick(); await h.tick(); await h.tick()
  assert.deepEqual(vus, ['cancelled', 'cancelled', 'paid'])
  assert.equal(h.prevues.length, 0)
})

test('une coupure réseau ne vaut pas une issue : la scrutation continue', async () => {
  let n = 0
  const erreurs = []
  const vus = []
  const h = horloge()
  const poller = createPoller({
    read: async () => { n += 1; if (n === 1) throw new Error('Connexion perdue'); return { statut: 'refused' } },
    onResult: (t) => vus.push(t.statut),
    onError: (e) => erreurs.push(e.message),
    schedule: h.schedule,
    cancel: h.cancel,
  })
  poller.start()
  await h.tick(); await h.tick()
  assert.deepEqual([erreurs, vus], [['Connexion perdue'], ['refused']])
})

test('deux lectures ne se croisent jamais, et `stop()` coupe tout', async () => {
  let lectures = 0
  let liberer
  const h = horloge()
  const poller = createPoller({
    read: () => { lectures += 1; return new Promise((r) => { liberer = () => r({ statut: 'sent' }) }) },
    onResult: () => {},
    schedule: h.schedule,
    cancel: h.cancel,
  })
  poller.start()
  const enVol = h.tick() // la première lecture part, et reste en vol
  poller.poke() // un « vérifier maintenant » pendant une lecture en vol : ignoré
  assert.equal(lectures, 1)
  liberer()
  await enVol // elle rend « sent » : la suivante est prévue…
  poller.stop()
  await h.tick() // … et ne part pas après `stop()`
  assert.equal(lectures, 1)
})

test('settle : une carte qui attend le terminal rend « waiting » avec sa tentative, et garde l’intention', async () => {
  const storage = memoire()
  const intent = { saleId: 'v1', establishment: 'e1', body: { moyen: 'cb', cleIdempotence: 'k1' } }
  storage.setItem('fluvia.reglements-en-attente', JSON.stringify({ v1: intent }))
  const api = { payer: async () => ({ reglementEnregistre: false, enAttente: true, tentative: { id: 't1', statut: 'sent', connecteur: 'externe' } }) }

  const issue = await settle(api, storage, intent, { pause: async () => {}, tries: 1 })

  assert.equal(issue.outcome, 'waiting')
  assert.equal(issue.attempt.id, 't1')
  assert.ok(JSON.parse(storage.getItem('fluvia.reglements-en-attente')).v1, 'l’intention reste : un F5 retrouve l’attente')
})
