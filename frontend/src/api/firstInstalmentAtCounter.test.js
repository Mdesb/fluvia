// L'encaissement au comptoir de la première échéance, contre un serveur en mémoire.
// Lancer : `cd frontend && node --test src/api/` (rien à installer : `node:test` est fourni par Node).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { payFirstInstalmentAtCounter } from './firstInstalmentAtCounter.js'
import { pendingIntent } from './paymentIntent.js'
import { ApiError } from './client.js'

// Chaque appel est noté ; `pannes[nom]` le fait échouer. `/valider` scelle AVANT de pouvoir répondre
// en erreur (G-5 : l'abonnement est créé après le commit) — sauf `scelle: false`.
function serveur(pannes = {}, { scelle = true } = {}) {
  const appels = []
  let statut = 'en_cours'
  const repondre = (nom, valeur) => {
    appels.push(nom)
    return pannes[nom] ? Promise.reject(new Error(pannes[nom])) : Promise.resolve(valeur)
  }
  return {
    appels,
    creerVente: () => repondre('creerVente', { id: 'v1', numero: 'T1' }),
    rattacherClientVente: () => repondre('rattacherClientVente', {}),
    ajouterLigne(vente, ligne) {
      this.ligne = ligne
      return repondre('ajouterLigne', {})
    },
    payer: () => repondre('payer', { reglementEnregistre: true }),
    valider: () => {
      if (scelle) statut = 'validee'
      return repondre('valider', {})
    },
    vente: () => repondre('vente', { id: 'v1', statut }),
    echeancesSepaSport: () => repondre('echeancesSepaSport', { member: [{ id: 'e1' }] }),
    annulerEcheanceSepa: () => repondre('annulerEcheanceSepa', {}),
  }
}

const parametres = {
  abonnementId: 'a1', sessionId: 's1', payeurId: 'c1', beneficiaireId: 'c2', produitId: 'p1', tarif: 't1', moyen: 'especes', montant: '39.90', prixForce: false,
}

test('tout aboutit : vente rattachée, réglée, validée, puis première échéance annulée', async () => {
  const api = serveur()
  assert.equal(await payFirstInstalmentAtCounter(api, parametres), null)
  assert.deepEqual(api.appels, [
    'creerVente', 'rattacherClientVente', 'ajouterLigne', 'payer', 'valider', 'echeancesSepaSport', 'annulerEcheanceSepa',
  ])
})

test('la ligne nomme le bénéficiaire : sans lui, une formule nominative est refusée (422)', async () => {
  const api = serveur()
  await payFirstInstalmentAtCounter(api, parametres)
  assert.equal(api.ligne?.beneficiaire, 'c2')
})

test('rattachement du client refusé : on s’arrête avant d’encaisser', async () => {
  const api = serveur({ rattacherClientVente: 'Délai dépassé' })
  const message = await payFirstInstalmentAtCounter(api, parametres)
  for (const geste of ['ajouterLigne', 'payer', 'valider']) assert.ok(!api.appels.includes(geste), geste)
  assert.match(message, /rien n'a été encaissé/)
})

test('validation refusée APRÈS scellement : l’écran dit « encaissé », sans faire réencaisser', async () => {
  const api = serveur({ valider: 'Un abonnement exige un client payeur' })
  const message = await payFirstInstalmentAtCounter(api, parametres)
  assert.match(message, /^Encaissé au comptoir \(vente n° T1\)/)
  assert.match(message, /ne l'encaissez pas une seconde fois/)
  assert.doesNotMatch(message, /reprenez-la|s'est arrêté/)
  // L'argent est là : la première échéance ne doit plus être prélevée.
  assert.ok(api.appels.includes('annulerEcheanceSepa'))
})

test('validation refusée sans scellement : la vente est à reprendre, l’échéance reste due', async () => {
  const api = serveur({ valider: 'Reste dû' }, { scelle: false })
  const message = await payFirstInstalmentAtCounter(api, parametres)
  assert.match(message, /reprenez-la depuis la caisse/)
  assert.ok(!api.appels.includes('annulerEcheanceSepa'))
})

test('validation puis relecture en échec : l’écran ne conclut pas, il fait vérifier avant tout encaissement', async () => {
  const api = serveur({ valider: 'réseau', vente: 'réseau' })
  const message = await payFirstInstalmentAtCounter(api, parametres)
  assert.match(message, /AVANT tout nouvel encaissement/)
  assert.ok(!api.appels.includes('annulerEcheanceSepa'))
})

// ── LE RÈGLEMENT GARDE SA CLÉ JUSQU'À UNE ISSUE DÉFINITIVE (G-2 du ticket opposable) ───────────
//
// `payer` répond ce qu'on lui dicte, dans l'ordre ; les corps envoyés sont notés.
function serveurReglement(...reponses) {
  const api = serveur()
  api.corps = []
  api.payer = (vente, corps) => {
    api.appels.push('payer')
    api.corps.push(corps)
    const r = reponses.shift()
    return r instanceof Error ? Promise.reject(r) : Promise.resolve(r)
  }
  return api
}
const memoire = () => {
  const m = new Map()
  return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, v), removeItem: (k) => m.delete(k) }
}
const sansAttente = { pause: async () => {} }

test('un délai dépassé se rejoue avec la même clé : le premier mois n\'est encaissé qu\'une fois', async () => {
  const api = serveurReglement(new ApiError('Délai.', 0, null), { reglementEnregistre: true })
  const message = await payFirstInstalmentAtCounter(api, parametres, { storage: memoire(), settleOptions: sansAttente })
  assert.equal(message, null)
  assert.equal(api.corps.length, 2)
  assert.match(api.corps[0].cleIdempotence, /^[0-9a-f-]{36}$/)
  assert.deepEqual(api.corps[1], api.corps[0])
})

test('une issue inconnue ne fait pas reprendre l\'encaissement : rien validé, échéance gardée, la clé reste pour la caisse', async () => {
  const stockage = memoire()
  const api = serveurReglement(new ApiError('Le terminal n\'a pas rendu d\'issue.', 409, { code: 'payment_outcome_unknown', tentative: { id: 't1' } }))
  const message = await payFirstInstalmentAtCounter(api, parametres, { storage: stockage, settleOptions: sansAttente })
  assert.doesNotMatch(message, /reprenez-la/)
  assert.match(message, /ne l'encaissez pas une seconde fois/)
  for (const geste of ['valider', 'annulerEcheanceSepa']) assert.ok(!api.appels.includes(geste), geste)
  assert.equal(pendingIntent(stockage, 'v1')?.body.cleIdempotence, api.corps[0].cleIdempotence)
})

test('un refus reste un refus : l\'abonnement est souscrit, l\'échéance sera prélevée, la clé est oubliée', async () => {
  const stockage = memoire()
  const api = serveurReglement(new ApiError('Moyen non autorisé.', 422, {}))
  const message = await payFirstInstalmentAtCounter(api, parametres, { storage: stockage, settleOptions: sansAttente })
  assert.match(message, /Le règlement a été refusé/)
  assert.equal(pendingIntent(stockage, 'v1'), null)
})

test('un règlement sans issue attend dans l\'onglet : la souscription n\'en ouvre pas un second', async () => {
  const stockage = memoire()
  const ancien = serveurReglement(new ApiError('Le terminal n\'a pas rendu d\'issue.', 409, { code: 'payment_outcome_unknown' }))
  await payFirstInstalmentAtCounter(ancien, parametres, { storage: stockage, settleOptions: sansAttente })

  const api = serveurReglement({ reglementEnregistre: true })
  const message = await payFirstInstalmentAtCounter(api, parametres, { storage: stockage, settleOptions: sansAttente })
  assert.match(message, /attend encore son issue/)
  assert.deepEqual(api.appels, [])
})
