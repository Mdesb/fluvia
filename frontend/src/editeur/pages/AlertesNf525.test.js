// Les alertes NF525 de l'éditeur : tout ce que le serveur renvoie est rendu en TEXTE.
// Lancer : `cd frontend && node --test src/editeur/pages/AlertesNf525.test.js`.
//
// `chainLabel` et `establishmentNames` viennent de noms saisis par un client d'un autre tenant ;
// `technicalDetail` et `note` sont du texte libre : une balise rendue telle quelle serait une XSS
// stockée dans la session de l'éditeur. Le dépôt n'a pas de navigateur de test : on vérifie le
// source (aucune insertion HTML) ET le rendu serveur d'une alerte piégée (JSX compilé par esbuild,
// déjà présent avec Vite).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import { build } from 'esbuild'

const ici = dirname(fileURLToPath(import.meta.url))
const memoire = () => {
  const m = new Map()
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }
}
globalThis.localStorage ??= memoire()
globalThis.sessionStorage ??= memoire()

test('le source n’insère jamais de HTML', () => {
  const src = readFileSync(join(ici, 'AlertesNf525.jsx'), 'utf8')
  assert.doesNotMatch(src, /dangerouslySetInnerHTML/)
  assert.doesNotMatch(src, /innerHTML|outerHTML|insertAdjacentHTML|document\.write/)
})

test('une alerte piégée est rendue échappée, sans balise brute', async () => {
  const piege = '<img src=x onerror=alert(1)>'
  const sortie = join(mkdtempSync(join(tmpdir(), 'nf525-')), 'rendu.cjs')
  await build({
    stdin: {
      contents: `
        import React from 'react'
        import { renderToStaticMarkup } from 'react-dom/server'
        import { CarteAlerte } from './AlertesNf525.jsx'
        export const rendre = (a) => renderToStaticMarkup(React.createElement(CarteAlerte, { alerte: a }))`,
      resolveDir: ici,
      loader: 'js',
    },
    bundle: true,
    platform: 'node',
    format: 'cjs',
    outfile: sortie,
    jsx: 'automatic',
    loader: { '.js': 'jsx' },
    define: { 'process.env.NODE_ENV': '"production"', 'import.meta.env': '{}' },
    logLevel: 'silent',
  })
  const { rendre } = createRequire(import.meta.url)(sortie)
  const html = rendre({
    id: 'a1', reference: 'NF525-ABCD1234', chainType: 'sale', chainLabel: piege, cause: 'invalid_signature',
    causeLabel: 'Signature invalide', status: 'open', firstEventAt: '2026-11-01T10:00:00+00:00',
    lastEventAt: '2026-11-02T10:00:00+00:00', refusalCount: 3, technicalDetail: piege, detectionCount: 1,
    origin: 'refusal', establishmentNames: [piege], note: piege, noteAt: null, noteBy: null,
  })
  assert.doesNotMatch(html, /<img/i)
  assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'))
  // Les quatre champs : libellé de chaîne, établissement, détail technique, note (textarea).
  assert.equal(html.split('&lt;img').length - 1, 4)
})
