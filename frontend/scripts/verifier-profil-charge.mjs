#!/usr/bin/env node
// AUCUN ÉCRAN NE SE MONTE AVANT QUE LE PROFIL SOIT CHARGÉ.
//
// CE QUE CE CONTRÔLE PROTÈGE, ET CE QU'IL A COÛTÉ DE NE PAS L'AVOIR.
//
// `aLeDroit(droits, code)` fait `droits || []` : « on me l'a refusé » et « je ne sais pas encore »
// rendent la même valeur, `false`. La fonction ne peut pas exprimer l'ignorance, donc aucun de ses
// soixante-quinze appelants ne peut la distinguer d'un refus.
//
// Le 29/08, ça a coûté un lien profond : la garde d'onglet de `App.jsx` lisait `droits = []` pendant
// le chargement, concluait « permission absente », et renvoyait à la caisse avant que le serveur ait
// répondu. Ouvrir `#facturation` ramenait sur `#caisse`.
//
// CE QUI REND LES 75 AUTRES APPELS SÛRS EST UN INVARIANT, PAS UNE PROPRIÉTÉ DE `aLeDroit` :
//
//   `App.jsx` rend un spinner tant que `booting`, et `booting` ne tombe qu'après `setMe(await
//   api.me())`. Les autres écritures de `me` le REMPLACENT sans jamais le vider ; la seule qui pose
//   `null` est la déconnexion, qui bascule aussi `authed` et fait rendre l'écran de connexion.
//
// C'est vrai aujourd'hui, et c'est la propriété d'un fichier — pas une garantie. Le jour où un écran
// sera rendu hors de cette garde (une page publique, une route nouvelle, un rendu partiel), la
// course revient, et personne ne s'en souviendra : elle ne se voit qu'au premier lien profond vers
// un écran protégé, sur une connexion lente.
//
// D'OÙ CE CONTRÔLE. Il ne vérifie pas une convention, il vérifie le fait dont tout le reste dépend.
// Il échoue le jour où l'invariant se brise, et il dit alors quoi faire.
//
// LA RÈGLE QUI VA AVEC, et qui borne le dommage si l'invariant tombe quand même :
//   ON PEUT MASQUER SUR « FAUX ». ON NE REDIRIGE JAMAIS, ON NE DÉTRUIT JAMAIS SUR « FAUX ».
// Un écran trop permissif se corrige par un refus d'API ; un écran qui vous éjecte vous enferme
// dehors.

import { readFileSync } from 'node:fs'

const APP = new URL('../src/App.jsx', import.meta.url).pathname
const source = readFileSync(APP, 'utf8')

const anomalies = []

// 1. La garde de démarrage existe et rend autre chose que l'application.
const garde = /if\s*\(\s*booting\s*\)\s*\{[\s\S]{0,200}?return\s*\(/.exec(source)
if (!garde) {
  anomalies.push(
    'App.jsx ne retourne plus tôt sur « booting ». Les écrans peuvent donc se monter avant que /me '
    + 'ait répondu, avec des droits vides lus comme des refus.',
  )
}

// 2. Elle se trouve AVANT le rendu de l'application. Un `return` d'écran placé plus haut annulerait
//    la protection sans supprimer la garde — le contrôle serait vert et l'invariant faux.
const posGarde = garde ? garde.index : -1
const posShell = source.indexOf('<AppShell')
if (posGarde >= 0 && posShell >= 0 && posGarde > posShell) {
  anomalies.push(
    'La garde « booting » est déclarée APRÈS le rendu de <AppShell> : elle ne protège plus rien.',
  )
}

// 3. `booting` ne retombe qu'après le chargement du contexte, lequel se termine par `setMe`.
if (!/setBooting\(false\)/.test(source) || !/setMe\(await api\.me\(\)\)/.test(source)) {
  anomalies.push(
    'App.jsx ne charge plus le profil par « setMe(await api.me()) » avant de lever « booting ». '
    + "L'ordre qui garantit que `me` est connu au premier rendu n'est plus lisible.",
  )
}

// 4. `me` n'est vidé que dans la déconnexion. Un `setMe(null)` ailleurs rouvrirait la course en
//    pleine session, écrans montés.
const misesANull = [...source.matchAll(/setMe\(\s*null\s*\)/g)]
if (misesANull.length > 1) {
  anomalies.push(
    `« setMe(null) » apparaît ${misesANull.length} fois. Il ne doit exister QUE dans la déconnexion, `
    + 'qui bascule aussi « authed » et fait rendre l’écran de connexion. Ailleurs, il monte les '
    + 'écrans avec des droits vides pendant une session ouverte.',
  )
}

if (anomalies.length === 0) {
  console.log(
    '✓ Profil chargé : aucun écran ne se monte avant que /me ait répondu (garde « booting » en '
    + 'place, profil jamais vidé hors déconnexion).',
  )
  process.exit(0)
}

console.error(`✗ Profil chargé : ${anomalies.length} rupture(s) de l’invariant.\n`)
for (const a of anomalies) console.error(`  - ${a}`)
console.error(`
Pourquoi ça compte : « aLeDroit » rend « false » aussi bien pour un refus que pour un profil pas
encore chargé. Tant que cet invariant tient, aucun écran ne voit la seconde forme. S'il tombe, les
soixante-quinze appels du frontal peuvent lire une ignorance comme un refus — et une REDIRECTION ou
une DESTRUCTION déclenchée là-dessus ne se rattrape pas.

Rétablissez la garde, ou — si le montage hors garde est voulu — rendez l'état « pas encore chargé »
représentable et faites choisir chaque appelant.`)
process.exit(1)
