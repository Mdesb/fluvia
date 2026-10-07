// L'intention de règlement (G-2 du ticket opposable), contre un serveur en mémoire.
// Lancer : `cd frontend && node --test src/api/` (rien à installer : `node:test` est fourni par Node).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { declareOutcome, forgetIntent, intentFor, newKey, pendingIntent, pendingIntents, settle } from './paymentIntent.js'
import { api as vraiClient, ApiError } from './client.js'

// Un `sessionStorage` en mémoire : ce que l'onglet garde au F5.
function stockage() {
  const m = new Map()
  return {
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => m.set(k, String(v)),
    removeItem: (k) => m.delete(k),
  }
}

// Le serveur répond, dans l'ordre, ce qu'on lui a dicté : un objet (réponse) ou une ApiError (refus).
function serveur(...reponses) {
  const appels = []
  return {
    appels,
    payer(vente, corps, entetes) {
      appels.push({ vente, corps, entetes })
      const r = reponses.shift()
      return r instanceof Error ? Promise.reject(r) : Promise.resolve(r)
    },
    declarerReglement(vente, corps) {
      appels.push({ vente, corps, declaration: true })
      const r = reponses.shift()
      return r instanceof Error ? Promise.reject(r) : Promise.resolve(r)
    },
  }
}

const sansAttente = { pause: async () => {} }
const vente = { saleId: 'v1', saleNumber: 'T1', establishment: 'e1', body: { moyen: 'cb', montant: '45.00' }, headers: { 'X-Tpe-Simule': 'accepte' } }
const encaisse = { reglementEnregistre: true, montant: '45.00', resteAPayer: '0.00' }
const tentative = { id: 't1', moyen: 'cb', montant: '45.00' }
const inconnue = () => new ApiError('Le terminal n\'a pas rendu d\'issue.', 409, { code: 'payment_outcome_unknown', tentative })
const enCours = () => new ApiError('En cours.', 409, { code: 'payment_in_progress' })

test('une intention écrite avant l\'envoi survit au rechargement : même clé, même corps', () => {
  const s = stockage()
  const premiere = intentFor(s, vente)
  // Le F5 : un nouvel écran relit le même stockage.
  const apresF5 = pendingIntents(s, 'e1')
  assert.equal(apresF5.length, 1)
  assert.equal(apresF5[0].body.cleIdempotence, premiere.body.cleIdempotence)
  assert.deepEqual(apresF5[0].body, { moyen: 'cb', montant: '45.00', cleIdempotence: premiere.body.cleIdempotence })
  assert.deepEqual(pendingIntents(s, 'autre-etablissement'), [])
})

test('pendant l\'attente, « Régler » rejoue l\'intention en attente, quel que soit ce qui est saisi', () => {
  const s = stockage()
  const premiere = intentFor(s, vente)
  const seconde = intentFor(s, { ...vente, body: { moyen: 'especes', montant: '50.00' } })
  assert.deepEqual(seconde, premiere)
})

test('une issue définitive oublie la clé : un second règlement identique (paiement scindé) prend une nouvelle clé', async () => {
  const s = stockage()
  const api = serveur(encaisse, encaisse)
  const premiere = intentFor(s, vente)
  assert.equal((await settle(api, s, premiere, sansAttente)).outcome, 'paid')
  assert.equal(pendingIntent(s, 'v1'), null)
  const seconde = intentFor(s, vente)
  assert.notEqual(seconde.body.cleIdempotence, premiere.body.cleIdempotence)
  await settle(api, s, seconde, sansAttente)
  assert.equal(api.appels.length, 2)
})

test('un refus du terminal est définitif : la clé est oubliée, aucun rejeu', async () => {
  const s = stockage()
  const api = serveur({ reglementEnregistre: false, statutTPE: 'refuse' })
  const issue = await settle(api, s, intentFor(s, vente), sansAttente)
  assert.equal(issue.outcome, 'refused')
  assert.match(issue.message, /refuse/)
  assert.equal(pendingIntent(s, 'v1'), null)
  assert.equal(api.appels.length, 1)
})

test('un refus de la demande (422) n\'a rien encaissé : la clé est oubliée', async () => {
  const s = stockage()
  const api = serveur(new ApiError('Montant invalide.', 422, {}))
  const issue = await settle(api, s, intentFor(s, vente), sansAttente)
  assert.deepEqual([issue.outcome, issue.message], ['rejected', 'Montant invalide.'])
  assert.equal(pendingIntent(s, 'v1'), null)
})

test('sans réponse (délai, réseau, 502) ou « en cours », le même corps repart avec la même clé, jusqu\'à l\'issue', async () => {
  const s = stockage()
  const api = serveur(new ApiError('Délai.', 0, null), new ApiError('Passerelle.', 502, null), enCours(), encaisse)
  const intention = intentFor(s, vente)
  const relances = []
  const issue = await settle(api, s, intention, { ...sansAttente, onRetry: (n) => relances.push(n) })
  assert.equal(issue.outcome, 'paid')
  assert.equal(api.appels.length, 4)
  for (const appel of api.appels) {
    assert.deepEqual(appel.corps, intention.body)
    assert.deepEqual(appel.entetes, vente.headers)
  }
  assert.deepEqual(relances, [1, 2, 3])
})

test('un terminal muet n\'est jamais relancé : l\'écran reçoit la tentative à déclarer, et garde la clé', async () => {
  const s = stockage()
  const api = serveur(inconnue())
  const issue = await settle(api, s, intentFor(s, vente), sansAttente)
  assert.deepEqual([issue.outcome, issue.attempt], ['unknown', tentative])
  assert.notEqual(pendingIntent(s, 'v1'), null)
  assert.equal(api.appels.length, 1)
})

test('un timeout rendu par le serveur se relit une fois pour nommer la tentative, sans repartir au terminal', async () => {
  const s = stockage()
  const api = serveur({ reglementEnregistre: false, statutTPE: 'timeout' }, inconnue())
  const issue = await settle(api, s, intentFor(s, vente), sansAttente)
  assert.deepEqual([issue.outcome, issue.attempt], ['unknown', tentative])
  assert.equal(api.appels.length, 2)
})

test('les relances sont bornées : l\'issue reste « en attente », la clé est gardée, le message ne dit pas « réessayez »', async () => {
  const s = stockage()
  const api = serveur(enCours(), enCours(), enCours())
  const issue = await settle(api, s, intentFor(s, vente), { ...sansAttente, tries: 3 })
  assert.equal(issue.outcome, 'pending')
  assert.doesNotMatch(issue.message, /r[ée]essayez/i)
  assert.notEqual(pendingIntent(s, 'v1'), null)
  assert.equal(api.appels.length, 3)
})

test('la déclaration clôt l\'intention ; une issue déjà connue aussi ; une déclaration refusée la garde', async () => {
  const s = stockage()
  intentFor(s, vente)
  const api = serveur(new ApiError('Référence obligatoire.', 422, {}), { reglementEnregistre: true, issue: 'declared_accepted' })
  await assert.rejects(declareOutcome(api, s, 'v1', { attemptId: 't1', accepted: true, cardReference: '' }))
  assert.notEqual(pendingIntent(s, 'v1'), null)
  const res = await declareOutcome(api, s, 'v1', { attemptId: 't1', accepted: true, cardReference: 'CB-1' })
  assert.equal(res.issue, 'declared_accepted')
  assert.deepEqual(api.appels[1].corps, { tentative: 't1', issue: 'accepte', referenceCarte: 'CB-1' })
  assert.equal(pendingIntent(s, 'v1'), null)

  intentFor(s, vente)
  const deja = serveur(new ApiError('Issue connue.', 409, { code: 'payment_outcome_known' }))
  await assert.rejects(declareOutcome(deja, s, 'v1', { attemptId: 't1', accepted: false }))
  assert.deepEqual(deja.appels[0].corps, { tentative: 't1', issue: 'non_passe' })
  assert.equal(pendingIntent(s, 'v1'), null)
  forgetIntent(s, 'v1')
})

test('la clé est un UUID v4, même hors contexte sécurisé (sans `crypto.randomUUID`)', () => {
  const v4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
  assert.match(newKey(), v4)
  const sansRandomUuid = { getRandomValues: (t) => globalThis.crypto.getRandomValues(t) }
  const a = newKey(sansRandomUuid)
  assert.match(a, v4)
  assert.notEqual(a, newKey(sansRandomUuid))
})

test('un stockage illisible ou plein n\'empêche pas de régler : l\'intention vit le temps de l\'appel', async () => {
  const casse = { getItem: () => { throw new Error('refusé') }, setItem: () => { throw new Error('plein') }, removeItem: () => {} }
  const intention = intentFor(casse, vente)
  assert.match(intention.body.cleIdempotence, /^[0-9a-f-]{36}$/)
  assert.equal((await settle(serveur(encaisse), casse, intention, sansAttente)).outcome, 'paid')
})

test('le client : le coupe-circuit d\'un règlement ne dit plus « réessayez », et la déclaration a sa route', async () => {
  const s = stockage()
  globalThis.localStorage = s
  globalThis.sessionStorage = stockage()
  const fetchAvant = globalThis.fetch
  const minuteurAvant = globalThis.setTimeout
  try {
    // Le délai expire tout de suite : le signal arrive déjà annulé à `fetch`.
    globalThis.setTimeout = (fn) => { fn(); return 0 }
    globalThis.fetch = (url, { signal }) => (signal?.aborted ? Promise.reject(new Error('abort')) : Promise.resolve(null))
    const erreur = await vraiClient.payer('v1', { moyen: 'cb' }).catch((e) => e)
    assert.equal(erreur.status, 0)
    assert.doesNotMatch(erreur.message, /r[ée]essayez/i)
    assert.match(erreur.message, /inconnu/i)

    globalThis.setTimeout = minuteurAvant
    let vu = null
    globalThis.fetch = (url, init) => {
      vu = { url, init }
      return Promise.resolve(new Response('{"issue":"declared_not_processed"}', { status: 200 }))
    }
    await vraiClient.declarerReglement('v1', { tentative: 't1', issue: 'non_passe' })
    assert.equal(vu.url, '/api/ventes/v1/declarer-reglement')
    assert.equal(vu.init.method, 'POST')
    assert.deepEqual(JSON.parse(vu.init.body), { tentative: 't1', issue: 'non_passe' })
  } finally {
    globalThis.fetch = fetchAvant
    globalThis.setTimeout = minuteurAvant
  }
})
