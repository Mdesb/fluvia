#!/usr/bin/env node
// Vérifie qu'un appel de création part dans un format que le serveur accepte.
//
// Le défaut qui a bloqué Maxime : `api_platform.yaml` ne déclare aucun format, donc une opération API
// Platform **standard** — celle qui désérialise le corps — n'accepte que `application/ld+json`. Le
// client envoyait `application/json` et recevait un 415.
//
// Treize appels de création étaient cassés, dont sept écrits avant mon arrivée. Personne ne l'avait
// signalé, ou alors sous la forme « je n'arrive pas à créer un client ».
//
// POURQUOI CE CONTRÔLE PLUTÔT QU'UNE DÉCLARATION GLOBALE DE FORMATS. Ajouter `json` aux formats du
// serveur réparerait l'écriture et changerait la négociation en **lecture** : les collections
// répondent aujourd'hui en JSON-LD, et tout le front lit la clé `member`. On réparerait 236 écritures
// en risquant toutes les listes. Le contrôle, lui, ne touche à rien et rend la faute impossible à
// livrer.
//
// LE DISCRIMINANT est lisible dans le code serveur : une opération déclarée avec un `uriTemplate` sur
// mesure porte `input: false`, son processor lit le corps brut et se moque du type. Une opération
// standard désérialise et exige le format.

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const CLIENT = new URL('../src/api/client.js', import.meta.url).pathname
const SERVEUR = new URL('../../app/src/', import.meta.url).pathname

function php(dir) {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n)
    if (statSync(p).isDirectory()) return php(p)
    return n.endsWith('.php') ? [p] : []
  })
}

// Les chemins déclarés « sur mesure » côté serveur.
const surMesure = new Set()
for (const f of php(SERVEUR)) {
  for (const m of readFileSync(f, 'utf8').matchAll(/uriTemplate:\s*'([^']+)'/g)) surMesure.add(m[1])
}

const src = readFileSync(CLIENT, 'utf8')
const anomalies = []

// `request('/api/...', { ... method: 'POST' ... })`
for (const m of src.matchAll(/request\((`|')(\/api\/[^`']*)\1,\s*\{([^}]*)\}/g)) {
  const [, , chemin, options] = m
  if (!/method:\s*'POST'/.test(options)) continue
  if (/\bld:\s*true/.test(options)) continue

  const normalise = chemin.replace(/\$\{[^}]+\}/g, '{id}').replace(/^\/api/, '')
  if (surMesure.has(normalise)) continue

  const ligne = src.slice(0, m.index).split('\n').length
  anomalies.push(
    `api/client.js:${ligne} — POST ${chemin} sans \`ld: true\`. Cette opération est standard : elle ` +
      "désérialise le corps et n'accepte que `application/ld+json`. Sans le drapeau, le serveur " +
      'répondra 415 et la création échouera sans que rien ne le laisse prévoir.',
  )
}

if (anomalies.length === 0) {
  console.log('✓ Formats : toutes les créations standard partent en ld+json.')
  process.exit(0)
}

console.error(`✗ Formats : ${anomalies.length} appel(s) qui échoueront en 415.\n`)
anomalies.forEach((a) => console.error('  - ' + a))
process.exit(1)
