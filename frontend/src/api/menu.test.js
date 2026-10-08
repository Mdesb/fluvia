// `node --test src/api/menu.test.js` — le menu du back-office, hors du navigateur.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { NAV, filtrerMenu, ongletsConnus } from './menu.js'

const fr = JSON.parse(readFileSync(new URL('../i18n/fr.json', import.meta.url), 'utf8'))
const ids = (menu) => menu.flatMap((g) => g.items.map((it) => it.id))

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
