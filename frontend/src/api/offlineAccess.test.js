// Le contrôle manuel hors connexion (#338) et la détection de la coupure.
// Lancer : `cd frontend && node --test src/api/`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { memoryStore, createMutex } from './offlineStore.js'
import { createOfflineAccess, sha256Hex } from './offlineAccess.js'
import { createConnectivity, EN_LIGNE, HORS_LIGNE } from './connectivity.js'
import { ApiError } from './client.js'

let n = 0
const uuid = () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`
const SEL = '0123456789abcdef0123456789abcdef'

// Le serveur de liste : il ne rend que des empreintes, comme `OfflineTicketCheckListProvider`.
async function serveurDeListe(billets, parPage = 2) {
  const entrees = []
  for (const b of billets) entrees.push({ empreinte: await sha256Hex(SEL + b.code), credit: b.credit ?? null, dejaControleLe: b.dejaControleLe ?? null })
  return async ({ page, sel }) => {
    if (page > 1) assert.equal(sel, SEL, 'les pages suivantes demandent le sel de la première')
    const tranche = entrees.slice((page - 1) * parPage, page * parPage)
    return { sel: SEL, genereeLe: '2026-11-05T08:00:00+00:00', page, pageSuivante: page * parPage < entrees.length, entrees: tranche }
  }
}

function acces(store = memoryStore()) {
  return createOfflineAccess({ store, now: () => new Date('2026-11-05T10:00:00.000Z'), uuid, mutex: createMutex(null) })
}

test('la liste est téléchargée en entier (toutes les pages), et le poste ne garde aucun code en clair', async () => {
  const store = memoryStore()
  const a = acces(store)
  const info = await a.refreshList('e1', await serveurDeListe([{ code: 'QR-AAA' }, { code: 'QR-BBB' }, { code: 'CARTE-1', credit: 3 }]))
  assert.equal(info.taille, 3)
  const brut = JSON.stringify(await store.list(''))
  assert.ok(!brut.includes('QR-AAA') && !brut.includes('CARTE-1'), 'aucun code de billet dans la base du poste')
})

test('entrée unique : valide la première fois, « déjà contrôlé » la seconde — sans réseau', async () => {
  const a = acces()
  await a.refreshList('e1', await serveurDeListe([{ code: 'QR-AAA' }]))
  const premier = await a.check('e1', 'QR-AAA')
  assert.equal(premier.resultat, 'valide')
  assert.equal(premier.horsLigne, true)
  const second = await a.check('e1', 'QR-AAA')
  assert.equal(second.resultat, 'refuse')
  assert.equal(second.codeMotif, 'deja_consomme')
  assert.equal(second.dejaControleLe, '2026-11-05T10:00:00.000Z', 'l’heure du premier passage, pour que l’agent décide')
})

test('carte à crédit : décomptée localement jusqu’à zéro, puis « crédit épuisé »', async () => {
  const a = acces()
  await a.refreshList('e1', await serveurDeListe([{ code: 'CARTE-1', credit: 1 }]))
  assert.equal((await a.check('e1', 'CARTE-1')).credit.restant, 0)
  assert.equal((await a.check('e1', 'CARTE-1')).codeMotif, 'credit_epuise')
})

test('un code absent de la liste est refusé comme inconnu DE LA LISTE — et sans liste, rien n’est décidé', async () => {
  const sans = acces()
  assert.equal((await sans.check('e1', 'QR-AAA')).codeMotif, 'liste_absente')

  const a = acces()
  await a.refreshList('e1', await serveurDeListe([{ code: 'QR-AAA' }]))
  const r = await a.check('e1', 'QR-ZZZ')
  assert.equal(r.resultat, 'refuse')
  assert.match(r.libelleMotif, /liste locale/)
})

test('la remontée vide la file sur accusé, et la garde sur erreur réseau', async () => {
  const a = acces()
  await a.refreshList('e1', await serveurDeListe([{ code: 'QR-AAA' }, { code: 'QR-BBB' }]))
  await a.check('e1', 'QR-AAA')
  await a.check('e1', 'QR-BBB')
  assert.equal((await a.pendingChecks('e1')).length, 2)

  const panne = await a.syncChecks('e1', async () => { throw new ApiError('Connexion perdue.', 0, { code: 'network_unreachable' }) })
  assert.ok(panne.erreur)
  assert.equal((await a.pendingChecks('e1')).length, 2)

  const recus = []
  const bilan = await a.syncChecks('e1', async (corps) => {
    recus.push(...corps.controles)
    return { resultats: corps.controles.map((c, i) => ({ cleIdempotence: c.cleIdempotence, statut: 'accepte', enConflit: i === 1 })) }
  })
  assert.equal(bilan.remontes, 2)
  assert.equal(bilan.conflits, 1)
  assert.equal((await a.pendingChecks('e1')).length, 0)
  assert.deepEqual(recus.map((c) => c.verdictLocal), ['valide', 'valide'])
})

test('une liste rafraîchie avant la remontée garde la consommation locale : le billet ne repasse pas', async () => {
  const a = acces()
  const lirePage = await serveurDeListe([{ code: 'QR-AAA' }])
  await a.refreshList('e1', lirePage)
  await a.check('e1', 'QR-AAA')
  await a.refreshList('e1', lirePage) // le serveur ne sait pas encore que QR-AAA a servi
  assert.equal((await a.check('e1', 'QR-AAA')).codeMotif, 'deja_consomme')
})

test('la coupure se détecte sur une erreur RÉSEAU (pas sur un refus), et le retour par la sonde', async () => {
  let joignable = false
  const minuteurs = []
  const c = createConnectivity({
    probe: async () => joignable,
    win: null,
    timers: { set: (f) => { minuteurs.push(f); return minuteurs.length }, clear: () => {} },
  })
  const vus = []
  c.subscribe((e) => vus.push(e))
  assert.equal(c.state, EN_LIGNE)

  c.reportFailure(new ApiError('Interdit', 403, null))
  assert.equal(c.state, EN_LIGNE, 'un refus prouve que le serveur répond')
  c.reportFailure(new ApiError('Délai dépassé', 0, null))
  assert.equal(c.state, EN_LIGNE, 'un serveur lent n’est pas un serveur absent')

  c.reportFailure(new ApiError('Connexion perdue.', 0, { code: 'network_unreachable' }))
  assert.equal(c.state, HORS_LIGNE)
  assert.equal(minuteurs.length, 1, 'la sonde démarre pendant la coupure seulement')

  await minuteurs[0]()
  assert.equal(c.state, HORS_LIGNE)
  joignable = true
  await c.check()
  assert.equal(c.state, EN_LIGNE)
  assert.deepEqual(vus, [HORS_LIGNE, EN_LIGNE])
})
