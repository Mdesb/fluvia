// `node --test src/api/menu.test.js` — le menu du back-office, hors du navigateur.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import * as menu from './menu.js'

const { NAV, filtrerMenu, ongletsConnus } = menu
const fr = JSON.parse(readFileSync(new URL('../i18n/fr.json', import.meta.url), 'utf8'))
const ids = (m) => m.flatMap((g) => g.items.map((it) => it.id))

// Ce que `/me` rend pour une piscine ouverte avec son métier : le preset `piscine` (serveur,
// `PresetVerticale`), sa verticale et les deux communes. Un administrateur porte le joker.
const PISCINE = ['casiers', 'comptabilite', 'controle_acces', 'encadrants', 'piscine', 'porte_monnaie', 'poss', 'recouvrement', 'reservation', 'sepa', 'stock']
const ADMIN = { droits: ['*.*'], estAdmin: true }

test('décision du 08/10 : une piscine ne voit que ce qu’elle vend', () => {
  const vus = ids(filtrerMenu(NAV, { capacites: PISCINE, ...ADMIN }))
  for (const id of ['caisse', 'piscine', 'reservation', 'casiers', 'sepa', 'comptabilite', 'stock', 'supervision']) {
    assert.ok(vus.includes(id), `${id} devrait être visible`)
  }
  for (const id of ['affaires', 'projets', 'finance', 'social', 'sejours', 'patinoire', 'padel', 'musee', 'sport', 'boutique']) {
    assert.ok(!vus.includes(id), `${id} devrait être masqué`)
  }
})

test('activer « affaires » pour l’établissement fait apparaître « Affaires »', () => {
  assert.ok(ids(filtrerMenu(NAV, { capacites: [...PISCINE, 'affaires'], ...ADMIN })).includes('affaires'))
})

test('un lien direct vers un module inactif est reconnu comme tel, sans toucher au reste', () => {
  assert.equal(menu.moduleInactif?.('affaires', PISCINE), true)
  assert.equal(menu.moduleInactif?.('affaires', [...PISCINE, 'affaires']), false)
  assert.equal(menu.moduleInactif?.('caisse', []), false, 'le socle n’est jamais inactif')
  assert.equal(menu.moduleInactif?.('ecran-inconnu', []), false, 'une adresse inconnue a son propre panneau')
})

test('l’écran des capacités sait quelles entrées une capacité fait apparaître', () => {
  assert.deepEqual(menu.entreesDe?.('controle_acces'), ['supervision', 'acces'])
  assert.deepEqual(menu.entreesDe?.('affaires'), ['affaires'])
  assert.deepEqual(menu.entreesDe?.('poss'), [])
})

test('chaque entrée et chaque section ont leur libellé dans fr.json', () => {
  // Les clés sont calculées (`nav.${id}`) : le garde-fou des chaînes ne les voit pas, ce test si.
  for (const grp of NAV) assert.ok(fr[`nav.section.${grp.id}`], `nav.section.${grp.id}`)
  for (const id of ongletsConnus()) assert.ok(fr[`nav.${id}`], `nav.${id}`)
})

test('un administrateur joker voit les entrées sans capacité, pas celles d’une capacité absente', () => {
  const vus = ids(filtrerMenu(NAV, { capacites: [], droits: ['*.*'], estAdmin: true }))
  assert.ok(vus.includes('caisse') && vus.includes('dashboard'))
  assert.ok(!vus.includes('reservation') && !vus.includes('supervision'))
})

test('plancher de sûreté : un menu filtré à vide retombe sur les entrées sans capacité ni admin', () => {
  const nav = [{ id: 's', items: [{ id: 'a', cap: 'c' }, { id: 'b', perm: 'x.lire' }, { id: 'd', admin: true }] }]
  assert.deepEqual(ids(filtrerMenu(nav, {})), ['b'])
  // Le vrai menu ne tombe jamais à vide : « Agenda » n'exige rien.
  assert.deepEqual(ids(filtrerMenu(NAV, {})), ['agenda'])
})
