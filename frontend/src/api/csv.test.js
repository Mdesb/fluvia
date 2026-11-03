// Export CSV pour Excel. Lancer : `cd frontend && node --test src/api/csv.test.js`.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { toCsv } from './csv.js'

const colonnes = [
  { titre: 'Article', valeur: (l) => l.article },
  { titre: 'Quantité', valeur: (l) => l.quantite, nombre: true },
  { titre: 'CA TTC (€)', valeur: (l) => l.ttc, nombre: true },
]

test('UTF-8 avec BOM, point-virgule, CRLF : Excel l’ouvre en colonnes, accents compris', () => {
  const csv = toCsv(colonnes, [{ article: 'Entrée', quantite: 3, ttc: '0.00' }])
  assert.ok(csv.startsWith('﻿'), 'BOM en tête')
  assert.equal(csv, '﻿Article;Quantité;CA TTC (€)\r\nEntrée;3;0\r\n')
})

test('les décimales s’écrivent avec une virgule, comme Excel en français les lit', () => {
  const csv = toCsv(colonnes, [{ article: 'Livre', quantite: 1, ttc: '22.50' }])
  assert.match(csv, /Livre;1;22,5\r\n/)
})

test('un point-virgule ou un guillemet dans un libellé ne décale pas les colonnes', () => {
  const csv = toCsv(colonnes, [{ article: 'Carte « Casa » ; lot de 2 "spécial"', quantite: 2, ttc: 3 }])
  assert.match(csv, /"Carte « Casa » ; lot de 2 ""spécial""";2;3/)
})

test('un libellé qui commence par = ne devient pas une formule', () => {
  const csv = toCsv(colonnes, [{ article: '=HYPERLINK("x")', quantite: 1, ttc: 1 }])
  assert.match(csv, /\r\n"'=HYPERLINK\(""x""\)";1;1/)
})

test('une valeur absente reste une cellule vide, pas « null » ni « NaN »', () => {
  const csv = toCsv(colonnes, [{ article: null, quantite: null, ttc: 'n/a' }])
  assert.match(csv, /\r\n;;\r\n$/)
})
