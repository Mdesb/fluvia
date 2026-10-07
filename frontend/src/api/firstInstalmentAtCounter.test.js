// L'encaissement au comptoir de la première échéance, contre un serveur en mémoire.
// Lancer : `cd frontend && node --test src/api/` (rien à installer : `node:test` est fourni par Node).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { payFirstInstalmentAtCounter } from './firstInstalmentAtCounter.js'

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
    annulerVente: () => repondre('annulerVente', {}),
    ajouterLigne: () => repondre('ajouterLigne', {}),
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
  abonnementId: 'a1', sessionId: 's1', payeurId: 'c1', produitId: 'p1', tarif: 't1', moyen: 'especes', montant: '39.90', prixForce: false,
}

test('tout aboutit : vente rattachée, réglée, validée, puis première échéance annulée', async () => {
  const api = serveur()
  assert.equal(await payFirstInstalmentAtCounter(api, parametres), null)
  assert.deepEqual(api.appels, [
    'creerVente', 'rattacherClientVente', 'ajouterLigne', 'payer', 'valider', 'echeancesSepaSport', 'annulerEcheanceSepa',
  ])
})

test('rattachement du client refusé : on s’arrête avant d’encaisser, la vente vide est annulée', async () => {
  const api = serveur({ rattacherClientVente: 'Délai dépassé' })
  const message = await payFirstInstalmentAtCounter(api, parametres)
  for (const geste of ['ajouterLigne', 'payer', 'valider']) assert.ok(!api.appels.includes(geste), geste)
  assert.ok(api.appels.includes('annulerVente'))
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
