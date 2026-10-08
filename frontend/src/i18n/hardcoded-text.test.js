// `node --test src/i18n/*.test.js` — le détecteur de `verifier-chaines-traduites.mjs` : ce qu'il voit,
// et surtout ce qu'il laisse (un faux positif fait contourner un garde-fou).

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { findHardcodedText } from '../../scripts/lib/hardcoded-text.mjs'

test('le détecteur voit le texte en dur d’un écran', () => {
  const found = (code) => findHardcodedText(code).map((f) => f.text)
  assert.deepEqual(found('<h1>Bonjour</h1>'), ['Bonjour'])
  assert.deepEqual(found('<div\n  className="x"\n>\n  Texte long ici\n</div>'), ['Texte long ici'])
  assert.deepEqual(found('<input placeholder="Votre nom" />'), ['Votre nom'])
  assert.deepEqual(found("{x ? 'Vérification…' : 'Valider'}"), ['Vérification…', 'Valider'])
  assert.deepEqual(found("d.toLocaleDateString('fr-FR')"), ["'fr-FR'"])
  assert.deepEqual(found("<p>{n} billets</p>"), ['billets'])
})

test('le détecteur laisse le code, les clés, les marques et les lignes « i18n-ignore »', () => {
  const found = (code) => findHardcodedText(code)
  assert.deepEqual(found("<p className=\"login-hint\">{t('login.demo_hint')}</p>"), [])
  assert.deepEqual(found("<button onClick={() => setX(1)}>{t('a.b')}</button>"), [])
  assert.deepEqual(found('if (count > max) {\n  return (\n    <div>{x}</div>\n  )\n}'), [])
  assert.deepEqual(found("<div className={ok ? 'banner banner-ok' : 'banner'} style={{ marginTop: 'var(--esp-normal)' }} />"), [])
  assert.deepEqual(found('<img alt="" /> Fluvia\n</div>'), [])
  assert.deepEqual(found("const exemple = 'Piscine des Trois Fontaines' // i18n-ignore"), [])
  assert.deepEqual(found("// « Texte en commentaire »\nconst email = 'admin@itcotation.com'"), [])
})
