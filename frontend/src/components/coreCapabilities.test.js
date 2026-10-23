// Recentrage, dernière relecture de #317 — l'écran « Modules en service » ne propose pas d'éteindre
// une capacité du cœur. Lancer : `cd frontend && node --test src/components/`
//
// Le serveur refuse (422) d'éteindre les capacités du cœur ; le catalogue le dit par `estDuCoeur`
// (même source que le refus : `ModuleGuard::coreCapabilities()`, vérifié par
// `CoreCapabilitiesInCatalogueTest`). Ce test tient le côté écran : pour une capacité du cœur en
// service, pas de bouton « Mettre hors service », mais « Toujours en service ».
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const FRONT = new URL('../../', import.meta.url).pathname
const ecran = () => {
  const src = readFileSync(join(FRONT, 'src/pages/Parametres.jsx'), 'utf8')
  const debut = src.indexOf('function Capacites(')
  assert.ok(debut > 0, 'témoin : le composant des capacités est trouvé')
  return src.slice(debut, src.indexOf('\nfunction ', debut + 10))
}

test('une capacité du cœur en service affiche « Toujours en service », sans bouton', () => {
  assert.match(ecran(), /c\.estDuCoeur && c\.active\s*\?\s*\(?\s*<span[^>]*>Toujours en service<\/span>/)
})

test('le texte de l’écran ne promet plus qu’on désactive tout', () => {
  const src = ecran()
  assert.doesNotMatch(src, /on active, on essaie, on désactive/)
  assert.match(src, /cœur/)
})
