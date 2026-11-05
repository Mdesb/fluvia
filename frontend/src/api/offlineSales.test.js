// La file des ventes hors connexion (#338), contre un serveur en mémoire qui applique les mêmes
// règles que `SynchroOperationsProcessor` : anti-doublon par clé, refus en bloc d'un lot à trou.
// Lancer : `cd frontend && node --test src/api/`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { memoryStore, createMutex } from './offlineStore.js'
import { createOfflineSales, suites } from './offlineSales.js'
import { ApiError } from './client.js'

let compteur = 0
const uuid = () => `00000000-0000-4000-8000-${String(++compteur).padStart(12, '0')}`
const horloge = (iso = '2026-11-05T10:00:00.000Z') => () => new Date(iso)

function file(store = memoryStore()) {
  return createOfflineSales({ store, now: horloge(), uuid, mutex: createMutex(null) })
}

const venteDe = (session = 's1', etablissement = 'e1') => ({
  etablissement,
  session,
  lignes: [{ produit: 'p1', typeTarif: 't1', quantite: 1, libelle: 'Entrée', prix: '5.00' }],
  paiements: [{ moyen: 'especes', montant: '5.00' }],
  total: '5.00',
})

// Le serveur : ce que `SynchroOperationsProcessor` fait, réduit à ce que la file doit respecter.
function serveur({ quarantaine = new Set(), coupureAuRetour = 0 } = {}) {
  const base = new Map() // cle → vente
  const lots = []
  let coupures = coupureAuRetour
  return {
    base,
    lots,
    async envoyer(corps) {
      lots.push(corps)
      const ops = [...corps.operations].sort((a, b) => a.sequenceLocale - b.sequenceLocale)
      for (let i = 1; i < ops.length; i++) {
        if (ops[i].sequenceLocale !== ops[i - 1].sequenceLocale + 1) throw new ApiError('Trou de séquence', 422, null)
      }
      const rep = { inseres: [], doublons: [], quarantaine: [], correspondances: [], reportees: [] }
      for (const op of ops) {
        if (base.has(op.cleIdempotence)) {
          rep.doublons.push(op.cleIdempotence)
          rep.correspondances.push({ cle: op.cleIdempotence, numeroLocal: op.numeroLocal, vente: base.get(op.cleIdempotence).id, numero: base.get(op.cleIdempotence).numero })
          continue
        }
        if (quarantaine.has(op.sequenceLocale)) {
          rep.quarantaine.push({ cle: op.cleIdempotence, raison: 'produit inconnu' })
          continue
        }
        const v = { id: `v${base.size + 1}`, numero: `S1-T${String(base.size + 1).padStart(5, '0')}`, op }
        base.set(op.cleIdempotence, v)
        rep.inseres.push(v.id)
        rep.correspondances.push({ cle: op.cleIdempotence, numeroLocal: op.numeroLocal, vente: v.id, numero: v.numero })
      }
      // La coupure tombe PENDANT le retour : le serveur a tout écrit, le poste ne lit rien.
      if (coupures > 0) {
        coupures -= 1
        throw new ApiError('Connexion perdue.', 0, { code: 'network_unreachable' })
      }
      return rep
    },
  }
}

test('le témoin : trois ventes sans réseau, le réseau revient, trois ventes en base une seule fois', async () => {
  const hl = file()
  const tickets = []
  for (let i = 0; i < 3; i++) tickets.push(await hl.record(venteDe()))

  // Numérotation : un numéro de ticket provisoire par vente, consécutif, propre au poste.
  const tag = await hl.poste()
  assert.deepEqual(tickets.map((t) => t.numeroLocal), [`HL-${tag}-00001`, `HL-${tag}-00002`, `HL-${tag}-00003`])
  assert.equal(new Set(tickets.map((t) => t.cleIdempotence)).size, 3, 'une clé par vente')
  assert.equal((await hl.pending('e1')).length, 3)

  // Premier retour du réseau : la réponse se perd en route. Le serveur a écrit, le poste ne le sait pas.
  const s = serveur({ coupureAuRetour: 1 })
  const perdu = await hl.sync('e1', s.envoyer)
  assert.ok(perdu.erreur, 'la coupure est remontée à l’écran')
  assert.equal((await hl.pending('e1')).length, 3, 'sans accusé, rien ne quitte la file')

  // Second essai : le MÊME lot, mêmes clés. Le serveur reconnaît les trois, n'en crée aucune.
  const bilan = await hl.sync('e1', s.envoyer)
  assert.equal(bilan.erreur, null)
  assert.equal(bilan.remontees, 3)
  assert.equal(s.base.size, 3, 'trois ventes en base, pas six')
  assert.deepEqual(s.lots[1].operations.map((o) => o.cleIdempotence), s.lots[0].operations.map((o) => o.cleIdempotence), 'les clés ne sont jamais régénérées')
  assert.deepEqual(s.lots[0].operations.map((o) => o.sequenceLocale), [1, 2, 3], 'dans l’ordre du comptoir')
  assert.equal((await hl.pending('e1')).length, 0)

  // Le poste sait ce qu'est devenu chaque ticket provisoire.
  const devenus = await hl.recentlySynced()
  assert.deepEqual(devenus.map((d) => d.numero).sort(), ['S1-T00001', 'S1-T00002', 'S1-T00003'])

  // Un troisième envoi n'a rien à envoyer.
  await hl.sync('e1', s.envoyer)
  assert.equal(s.lots.length, 2)
})

test('la file survit au rechargement de la page : un nouvel écran relit le même stockage', async () => {
  const store = memoryStore()
  await file(store).record(venteDe())
  const apresF5 = file(store)
  assert.equal((await apresF5.pending('e1')).length, 1)
  const t = await apresF5.record(venteDe())
  assert.equal(t.sequenceLocale, 2, 'la numérotation reprend, elle ne recommence pas')
})

test('la numérotation ne redescend jamais, même file vidée : deux tickets ne portent jamais le même numéro', async () => {
  const hl = file()
  await hl.record(venteDe())
  await hl.sync('e1', serveur().envoyer)
  const suivant = await hl.record(venteDe())
  assert.equal(suivant.sequenceLocale, 2)
})

test('une erreur réseau laisse tout en place ; un serveur qui répond 409 aussi (verrou pris)', async () => {
  const hl = file()
  await hl.record(venteDe())
  const reseau = await hl.sync('e1', async () => { throw new ApiError('Connexion perdue.', 0, { code: 'network_unreachable' }) })
  assert.ok(reseau.erreur)
  const occupe = await hl.sync('e1', async () => { throw new ApiError('Remontée déjà en cours', 409, null) })
  assert.ok(occupe.erreur)
  const restantes = await hl.pending('e1')
  assert.equal(restantes.length, 1)
  assert.equal(restantes[0].etat, 'en_attente')
})

test('une vente en quarantaine reste visible, et ne bloque pas les suivantes par un trou de séquence', async () => {
  const hl = file()
  for (let i = 0; i < 4; i++) await hl.record(venteDe())
  const s = serveur({ quarantaine: new Set([2]) })
  await hl.sync('e1', s.envoyer)
  const restantes = await hl.pending('e1')
  assert.equal(restantes.length, 1)
  assert.equal(restantes[0].etat, 'quarantaine')
  assert.equal(restantes[0].raison, 'produit inconnu')

  // Deux nouvelles ventes : 5 et 6. La 2 en quarantaine n'est pas renvoyée, et 5-6 partent ensemble.
  await hl.record(venteDe())
  await hl.record(venteDe())
  const bilan = await hl.sync('e1', s.envoyer)
  assert.equal(bilan.erreur, null)
  assert.deepEqual(s.lots.at(-1).operations.map((o) => o.sequenceLocale), [5, 6])
  assert.equal(s.base.size, 5)
})

test('un refus définitif du lot (422) bloque ses ventes au lieu de les renvoyer sans fin', async () => {
  const hl = file()
  await hl.record(venteDe())
  await hl.sync('e1', async () => { throw new ApiError('Session introuvable.', 422, null) })
  const [v] = await hl.pending('e1')
  assert.equal(v.etat, 'bloque')
  assert.equal(v.raison, 'Session introuvable.')
  // Un responsable la remet en file : elle repart.
  await hl.retry(v.cleIdempotence)
  assert.equal((await hl.pending('e1'))[0].etat, 'en_attente')
})

test('une vente sans panier ou sans session n’est pas enregistrée', async () => {
  const hl = file()
  await assert.rejects(() => hl.record({ ...venteDe(), lignes: [] }))
  await assert.rejects(() => hl.record({ ...venteDe(), session: '' }))
  assert.equal((await hl.pending()).length, 0)
})

test('les suites sont coupées aux trous et aux changements de session', () => {
  const v = (session, sequenceLocale) => ({ session, sequenceLocale })
  const r = suites([v('a', 1), v('a', 2), v('b', 3), v('b', 5), v('b', 6)])
  assert.deepEqual(r.map((s) => s.map((x) => x.sequenceLocale)), [[1, 2], [3], [5, 6]])
})

test('le ticket provisoire garde l’heure du comptoir, que le serveur retiendra', async () => {
  const hl = createOfflineSales({ store: memoryStore(), now: horloge('2026-11-05T09:12:34.000Z'), uuid, mutex: createMutex(null) })
  const t = await hl.record(venteDe())
  assert.equal(t.horodatageLocal, '2026-11-05T09:12:34.000Z')
  const s = serveur()
  await hl.sync('e1', s.envoyer)
  assert.equal(s.lots[0].operations[0].horodatageLocal, '2026-11-05T09:12:34.000Z')
  assert.equal(s.lots[0].operations[0].numeroLocal, t.numeroLocal)
  // Seuls produit, tarif et quantité partent : le prix est recalculé par le serveur.
  assert.deepEqual(Object.keys(s.lots[0].operations[0].lignes[0]).sort(), ['produit', 'quantite', 'typeTarif'])
})
