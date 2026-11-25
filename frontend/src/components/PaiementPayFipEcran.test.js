// Section « Paiement par PayFiP » — contrats lus dans la SOURCE (aucun test de rendu dans le dépôt) :
// G-6 du lot D2-B de #365. Nom distinct de `paiementPayFip.test.js` (collision de casse sur un système de
// fichiers insensible à la casse, relevée en D3-B).
// Lancer : `cd frontend && node --test src/components/*.test.js`
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const lire = (f) => readFileSync(new URL(f, import.meta.url), 'utf8')
const composant = lire('./PaiementPayFip.jsx')
const hote = lire('./IdentiteVendeur.jsx')

test('l’hôte ne monte la section que sur le profil chargé, avec le droit et pour une régie directe', () => {
  assert.match(
    hote,
    /profil\?\.id && peutConfigurerPaiement\(droits\) && profilEligiblePayFip\(profil\.type\) \? <PaiementPayFip /,
  )
  assert.match(hote, /import PaiementPayFip from '\.\/PaiementPayFip\.jsx'/)
  // Le composant se protège aussi lui-même : droit et type.
  assert.match(composant, /if \(!autorise \|\| !profilId \|\| !eligible\) return null/)
  // Le droit vient du test partagé (réimporté), pas d'un second test de droit.
  assert.match(composant, /peutConfigurerPaiement\(droits\)/)
  assert.doesNotMatch(composant, /aLeDroit\(|'payment\.configure'/)
})

test('le champ a un label lié, est numérique et borné à 6 caractères', () => {
  assert.match(composant, /<label htmlFor="ppf-numero"/)
  const champ = composant.match(/<input\b[^>]*?id="ppf-numero"[\s\S]*?\/>/)?.[0]
  assert.ok(champ, 'témoin : le champ est trouvé')
  assert.match(champ, /inputMode="numeric"/)
  assert.match(champ, /maxLength=\{6\}/)
  assert.match(champ, /autoComplete="off"/)
  assert.match(composant, /Numéro client PayFiP \(6 chiffres\)/)
})

test('le champ est pré-rempli par le profil (la valeur n’est pas secrète)', () => {
  assert.match(composant, /useState\(profil\?\.payfipClientNumber \?\? ''\)/)
})

test('l’identifiant du profil passe par idDe (garde-fou n°53) et l’écriture par majNumeroClientPayFip', () => {
  assert.match(composant, /import \{ idDe \} from '\.\.\/api\/iri\.js'/)
  assert.match(composant, /idDe\(profil\)/)
  assert.match(composant, /api\.majNumeroClientPayFip\(profilId, /)
  assert.equal(composant.split('api.majNumeroClientPayFip(').length - 1, 2, 'enregistrer et effacer, rien d’autre')
})

test('l’effacement n’appelle l’API qu’après la confirmation (hôte Confirmation.jsx, pas window.confirm)', () => {
  const corps = composant.slice(composant.indexOf('async function effacer'))
  const iConfirm = corps.indexOf('await confirmer(')
  const iAppel = corps.indexOf('api.majNumeroClientPayFip(')
  assert.ok(iConfirm > 0 && iAppel > 0, 'témoin : les deux appels existent')
  assert.ok(iConfirm < iAppel)
  assert.match(corps, /if \(!\(await confirmer\(.*\)\)\) return/)
  assert.match(composant, /import \{ confirmer \} from '\.\/Confirmation\.jsx'/)
  assert.doesNotMatch(composant, /window\.confirm/)
})

test('la validation à la saisie : alerte annoncée et bouton inactif tant que le numéro est invalide', () => {
  assert.match(composant, /messageNumeroClient\(numero\)/)
  assert.match(composant, /role="alert"/)
  assert.match(composant, /disabled=\{enCours \|\| !numeroClientValide\(numero\)\}/)
})

test('la phrase d’aide cite la DDFiP, et l’écran dit que mode et adresse de notification sont communs', () => {
  assert.match(composant, /correspondant moyens de paiement de la DDFiP/)
  assert.match(composant, /communs à toutes les régies/)
  assert.match(composant, /libelleEtat\(/)
})

test('les erreurs 403/404/422 sont annoncées avec le message du serveur, sans écho de la saisie', () => {
  assert.match(composant, /messageErreurNumeroClient\(err\)/)
  assert.doesNotMatch(composant, /setErreur\([^)]*numero/)
})
