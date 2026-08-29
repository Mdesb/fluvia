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

// Un chemin du client correspond-il a un `uriTemplate` declare ?
//
// LE FAUX POSITIF QUE CETTE FONCTION CORRIGE, TROUVE PAR `claude-D`.
//
// La premiere version remplacait TOUTE interpolation par `{id}`. Un chemin a deux variables —
// `/billing/documents/${'$'}{id}/${'$'}{geste}` — devenait donc `/billing/documents/{id}/{id}`, qui ne
// correspond a aucun `uriTemplate` declare. Le controle reclamait un `ld: true` dont ces routes
// n'ont que faire : elles sont sur mesure et `input: false`, exactement le cas que le message d'aide
// dit de ne PAS marquer. Le controle contredisait sa propre explication.
//
// On compare donc segment par segment. Un segment dynamique cote client accepte n'importe quel
// segment cote serveur ; un segment `{...}` cote serveur accepte n'importe quoi cote client.
//
// LE COMPROMIS, ECRIT PLUTOT QUE TU.
//
// Un segment dynamique cote client peut ainsi correspondre a une route sur mesure qu'il ne vise pas
// reellement, et taire un avertissement legitime. C'est le sens d'erreur que je choisis : un
// controle qui crie au loup finit desactive, et le silence occasionnel coute moins qu'un garde-fou
// que personne ne lit plus.
function correspond(chemin, modele) {
  const a = chemin.split('/')
  const b = modele.split('/')
  if (a.length !== b.length) return false
  return a.every((segment, i) => {
    if (segment.includes('${')) return true
    if (b[i].startsWith('{') && b[i].endsWith('}')) return true
    return segment === b[i]
  })
}

// LES OPTIONS SE LISENT EN COMPTANT LES ACCOLADES, PAS AVEC `[^}]*`.
//
// La capture s'arrêtait à la PREMIÈRE accolade fermante — donc au milieu des options dès qu'elles
// en contiennent une : `{ method: 'POST', body: {}, ld: true }` se lisait `{ method: 'POST',
// body: {`. Le `ld: true` tombait hors de la capture, et le contrôle réclamait un drapeau qui
// était déjà là.
//
// Le faux positif est le pire cas pour un garde-fou : on ajoute ce qu'il demande, il refuse encore,
// et on finit par le contourner. `body: {}` est pourtant l'idiome de toutes les opérations sans
// corps — il y en a une douzaine dans ce client.
function optionsDe(source, depart) {
  const i = source.indexOf('{', depart)
  if (i === -1) return ''
  let profondeur = 0
  for (let j = i; j < source.length; j += 1) {
    if (source[j] === '{') profondeur += 1
    else if (source[j] === '}') {
      profondeur -= 1
      if (profondeur === 0) return source.slice(i, j + 1)
    }
  }
  return source.slice(i)
}

// `request('/api/...', { ... method: 'POST' ... })`
for (const m of src.matchAll(/request\((`|')(\/api\/[^`']*)\1,/g)) {
  const [, , chemin] = m
  const options = optionsDe(src, m.index + m[0].length)
  if (!/method:\s*'POST'/.test(options)) continue
  if (/\bld:\s*true/.test(options)) continue

  if ([...surMesure].some((modele) => correspond(chemin.replace(/^\/api/, ''), modele))) continue

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
console.error(
  "\nN'ajoutez PAS `ld: true` partout : les opérations déclarées avec un `uriTemplate` sur mesure\n" +
    "portent `input: false`, leur processor lit le corps brut et se moque du type. Ce contrôle ne\n" +
    'signale que les opérations standard — celles qu\'il liste ci-dessus, et elles seules.',
)
process.exit(1)
