// Section « Paiement par carte (Stripe) » — contrats lus dans la SOURCE (aucun test de rendu dans le
// dépôt, comme `moduleMenu.test.js`) : G-6 et G-9 du lot D3-B de #365.
// Lancer : `cd frontend && node --test src/components/`
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const lire = (f) => readFileSync(new URL(f, import.meta.url), 'utf8')
const composant = lire('./PaiementStripe.jsx')
const hote = lire('./IdentiteVendeur.jsx')
const page = lire('../pages/Facturation.jsx')

const balises = (src, nom) => [...src.matchAll(new RegExp(`<${nom}\\b[\\s\\S]*?/?>(?=\\s*(?:<|\\{|$))`, 'g'))].map((m) => m[0])

test('l’hôte ne monte la section que sur le profil chargé et avec le droit, reçu en propriété', () => {
  assert.match(hote, /profil\?\.id && peutConfigurerPaiement\(droits\) \? <PaiementStripe /)
  assert.match(hote, /IdentiteVendeur\(\{ peutModifier, droits \}\)/)
  assert.match(page, /<IdentiteVendeur peutModifier=\{peutGerer\} droits=\{droits\} \/>/)
  assert.match(composant, /if \(!autorise \|\| !profilId\) return null/)
  assert.doesNotMatch(hote + composant, /paiement\.configurer/)
})

test('les deux champs sont masqués, sans autocomplétion, et liés à l’état LOCAL seulement', () => {
  const champs = [...composant.matchAll(/<input\b[^>]*?id="ps-(cle|secret)"[\s\S]*?\/>/g)]
  assert.equal(champs.length, 2, 'témoin : les deux champs sont trouvés')
  for (const [bloc, nom] of champs) {
    assert.match(bloc, /type="password"/)
    assert.match(bloc, /autoComplete="new-password"/)
    const valeur = bloc.match(/value=\{([^}]+)\}/)?.[1]
    assert.equal(valeur, nom === 'cle' ? 'cle' : 'secret', 'value = état local de saisie, jamais une donnée du serveur')
  }
  // Aucun `value=` ne lit `ligne` (la réponse du serveur), et rien ne remplit l'état de saisie depuis elle.
  assert.doesNotMatch(composant, /value=\{[^}]*ligne/)
  assert.doesNotMatch(composant, /setCle\([^)]*ligne|setSecret\([^)]*ligne/)
})

test('chaque champ a son label lié', () => {
  for (const id of ['ps-cle', 'ps-secret', 'ps-adresse']) {
    assert.match(composant, new RegExp(`<label htmlFor="${id}"`), id)
    assert.match(composant, new RegExp(`id="${id}"`), id)
  }
})

test('« Retirer » ne supprime qu’après la confirmation', () => {
  const corps = composant.slice(composant.indexOf('async function retirer'))
  const iConfirm = corps.indexOf('await confirmer(')
  const iRetrait = corps.indexOf('api.retirerStripeConfig(')
  assert.ok(iConfirm > 0 && iRetrait > 0, 'témoin : les deux appels existent')
  assert.ok(iConfirm < iRetrait)
  assert.match(corps, /if \(!\(await confirmer\(.*\)\)\) return/)
  assert.equal(composant.split('api.retirerStripeConfig(').length - 1, 1)
})

test('l’état, le mode, l’adresse et le bouton de copie sont rendus ; les erreurs sont annoncées', () => {
  assert.match(composant, /Clé configurée/)
  assert.match(composant, /Aucune clé enregistrée/)
  assert.match(composant, /libelleMode\(ligne\?\.keyMode\)/)
  assert.match(composant, /adresseNotification\(window\.location\.origin, profilId\)/)
  assert.match(composant, /navigator\.clipboard\.writeText\(adresse\)/)
  assert.match(composant, /<button type="button" className="btn ghost sm" onClick=\{copier\}/)
  assert.match(composant, /role="alert"/)
  assert.match(composant, /Cette structure encaisse par PayFiP/)
})

test('les valeurs saisies sont vidées après un enregistrement réussi', () => {
  const corps = composant.slice(composant.indexOf('async function enregistrer'), composant.indexOf('async function retirer'))
  assert.match(corps, /setCle\(''\)/)
  assert.match(corps, /setSecret\(''\)/)
})

test('une erreur sans enregistrement (404, 409, 503) vide les champs saisis', () => {
  const corps = composant.slice(composant.indexOf("async function enregistrer"), composant.indexOf("async function retirer"))
  const capture = corps.slice(corps.indexOf('catch (err)'))
  assert.ok(capture.length > 0)
  assert.match(capture, /\[404, 409, 503\]\.includes\(err\?\.status\)[\s\S]*setCle\(''\)[\s\S]*setSecret\(''\)/)
})
