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
import { adosseAuServeur, annonceRouteAVenir, routesDeclarees } from './lib/ecart.mjs'

// ⚠ CE QUE `ld: true` FAIT, ET CE QU'IL NE FAIT PAS — MESURÉ, PAS DÉDUIT.
//
// Ce fichier a longtemps affirmé, en quatre endroits, qu'une opération sans corps REFUSE
// `application/ld+json` en 415. C'est faux. API Platform ne contrôle le Content-Type que s'il doit
// désérialiser : quand l'opération ne désérialise pas, le contrôle est sauté et le type n'a aucun
// effet — le drapeau y est inerte, ni exigé ni refusé.
//
// Deux mesures, le 04/09 :
//   - le registre lui-même, noyau démarré : sur 540 opérations d'écriture, 296 exigent `ld+json`
//     et 244 ne contrôlent rien ;
//   - une requête sur le banc : `POST /calendar/ics-subscription/regenerate`, en
//     `deserialize: false`, rend 404 pour `application/json` COMME pour `application/ld+json`.
//
// Le contrôle ne change pas pour autant : il réclame `ld: true` exactement là où le serveur
// l'exige. Seule la justification écrite était fausse — et une justification fausse dans un
// garde-fou se recopie. Deux des quatre phrases venaient d'être recopiées des deux autres.

const CLIENT = new URL('../src/api/client.js', import.meta.url).pathname
const SERVEUR = new URL('../../app/src/', import.meta.url).pathname

function php(dir) {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n)
    if (statSync(p).isDirectory()) return php(p)
    return n.endsWith('.php') ? [p] : []
  })
}

// Le texte complet de `new Xxx( ... )`, en équilibrant les parenthèses.
//
// ⚠ UNE FENÊTRE DE TAILLE FIXE NE SUFFIT PAS. La première version de cette mesure lisait 400
// caractères après `new Post(` ; un `input: false` écrit plus bas lui échappait, et elle rendait
// alors deux faux positifs — c'est-à-dire l'ordre d'ajouter un `ld: true` dont la route n'a
// que faire. Le sens de l'erreur compte : ici, mal lire fait poser un drapeau inutile et,
// pire, fait CROIRE que l'appel est vérifié alors qu'il ne l'est pas.
function declarationComplete(texte, depart) {
  const i = texte.indexOf('(', depart)
  if (i === -1) return ''
  let profondeur = 0
  for (let j = i; j < texte.length; j += 1) {
    if (texte[j] === '(') profondeur += 1
    else if (texte[j] === ')') {
      profondeur -= 1
      if (profondeur === 0) return texte.slice(i, j + 1)
    }
  }
  return ''
}

// Les chemins déclarés « sur mesure » côté serveur — EN DEUX ENSEMBLES, PAS UN.
//
// ⚠ L'ANGLE MORT QUI A COÛTÉ NEUF BOUTONS MORTS, TROUVÉ PAR `claude-C0` LE 04/09.
//
// Ce contrôle sautait en bloc TOUTE route à `uriTemplate` sur mesure, au motif — écrit dans son
// propre message d'aide — que « les opérations déclarées avec un `uriTemplate` sur mesure portent
// `input: false` ». C'est vrai de 222 d'entre elles. Ça ne l'est pas des 38 autres : celles-là
// désérialisent le corps comme n'importe quelle opération standard, et n'acceptent donc que
// `application/ld+json`.
//
// Ces 38 routes étaient le seul endroit du client que RIEN ne surveillait. Neuf écritures du
// frontal y partaient en `application/json` et recevaient 415 — toujours, pour tout le monde,
// depuis leur écriture. Dont `creerEditorPlan` : l'écran « Offres » n'a jamais pu créer une
// formule, `subscription_plan` est resté vide, et le tunnel de souscription refusait en
// conséquence toute composition. Le défaut visible était à trois écrans de sa cause.
//
// Un garde-fou qui saute une famille entière ne rend pas un vert prudent : il rend un vert qui ne
// mesure rien, et il le rend avec l'autorité d'un vert. On lit donc désormais l'opération plutôt
// que de supposer ce qu'elle contient.
const surMesure = new Set()
const deserialisent = new Set()

for (const f of php(SERVEUR)) {
  const texte = readFileSync(f, 'utf8')
  for (const m of texte.matchAll(/uriTemplate:\s*'([^']+)'/g)) surMesure.add(m[1])

  for (const m of texte.matchAll(/new (Post|Put|Patch)\s*\(/g)) {
    const bloc = declarationComplete(texte, m.index)
    const modele = /uriTemplate:\s*'([^']+)'/.exec(bloc)
    if (modele === null) continue

    // ⚠ DEUX IDIOMES DISENT « NE DÉSÉRIALISE PAS », PAS UN SEUL.
    //
    // `input: false` (266 occurrences) et `deserialize: false` (4). Ne connaître que le premier
    // faisait signaler le téléversement de photo, qui porte le second — un faux positif, c'est-à-
    // dire l'ordre d'ajouter un `ld: true` dont la route n'a que faire. Trouvé en faisant tourner
    // ce contrôle, dès sa première exécution.
    //
    // CE QUE LA RÈGLE VAUT, MESURÉ PLUTÔT QUE SUPPOSÉ. En interrogeant le registre d'API Platform
    // — l'autorité sur le 415, plutôt qu'un grep qui réimplémente sa règle — on compte 540
    // opérations d'écriture sur mesure : 296 exigent `ld+json`, 244 sont indifférentes, et AUCUNE
    // route portant l'un de ces deux marqueurs n'exige `ld+json`. Les deux marqueurs classent donc
    // exactement, et ce contrôle bon marché rend le même verdict que le démarrage du noyau.
    if (/input:\s*false/.test(bloc)) continue
    if (/deserialize:\s*false/.test(bloc)) continue

    // `inputFormats:` : l'opération choisit elle-même ses types, on ne conclut rien.
    if (/inputFormats:/.test(bloc)) continue

    deserialisent.add(modele[1])
  }
}

// ⚠ On reutilise le calcul de `lib/ecart.mjs` plutot que d'en ecrire un second : deux
// definitions de « cette route existe » divergeraient au premier correctif.
const { gabarits, noms } = routesDeclarees()

const src = readFileSync(CLIENT, 'utf8')
const anomalies = []
const introuvables = []

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

  const sansPrefixe = chemin.replace(/^\/api/, '')
  const correspondantes = [...surMesure].filter((modele) => correspond(sansPrefixe, modele))

  if (correspondantes.length > 0) {
    // ⚠ LA PLUS SPÉCIFIQUE L'EMPORTE — COMME DANS LE ROUTEUR LUI-MÊME.
    //
    // `correspond` accepte n'importe quel segment en face d'un `{...}` du serveur : un chemin
    // touche donc souvent plusieurs déclarations à la fois. `/marketing/fidelite/mouvements`
    // correspond ainsi à la sienne ET à `/marketing/fidelite/{id}`.
    //
    // Une première version refusait de trancher dans ce cas. Elle taisait alors une anomalie
    // légitime — écart trouvé en confrontant ce contrôle au registre d'API Platform sur un client
    // privé de tous ses `ld: true` : 84 anomalies en commun, et celle-là vue par l'autorité seule.
    //
    // Il n'y a pourtant rien à trancher : un segment littéral est plus spécifique qu'un joker.
    // On garde donc les candidates les plus littérales, et on ne renonce que si plusieurs restent
    // à égalité — cas où le contrôle se tait plutôt que de risquer un faux positif, qui ferait
    // poser le 415 qu'il prétend prévenir.
    const specificite = (modele) => modele.split('/').filter((s) => !s.startsWith('{')).length
    const meilleure = Math.max(...correspondantes.map(specificite))
    const retenues = correspondantes.filter((modele) => specificite(modele) === meilleure)

    if (retenues.length > 1 || !deserialisent.has(retenues[0])) continue

    const ligneSurMesure = src.slice(0, m.index).split('\n').length
    anomalies.push(
      `api/client.js:${ligneSurMesure} — POST ${chemin} sans \`ld: true\`. La route sur mesure ` +
        `\`${retenues[0]}\` ne porte PAS \`input: false\` : elle désérialise le corps comme ` +
        "une opération standard, et n'accepte donc que `application/ld+json`. Le serveur répondra " +
        '415 — toujours, pas seulement dans certains cas — et la création échouera en silence.',
    )
    continue
  }

  // ⚠ UNE ROUTE ANNONCÉE N'EST PAS UNE ROUTE STANDARD.
  //
  // Sans ce test, le contrôle déduisait de l'ABSENCE d'une route qu'elle désérialise le corps, et
  // réclamait un `ld: true` dont la route, écrite ensuite en `input: false`, n'a que faire. Il ne
  // signalait pas un défaut : il faisait poser un drapeau trompeur, puis redevenait vert.
  //
  // Le marqueur ne dit pas « c'est branché », il dit « c'est voulu et voici pourquoi ». Il devient
  // sans objet dès que la route existe — elle tombe alors dans `surMesure` juste au-dessus.

  const ligne = src.slice(0, m.index).split('\n').length

  // ⚠ TROISIEME CAS : LE CHEMIN NE CORRESPOND A RIEN DU TOUT.
  //
  // Ne pas trouver une route n'est pas la meme chose que trouver une route standard. Le controle
  // concluait la seconde de la premiere, et affirmait « elle deserialise le corps » sur une donnee
  // manquante — conseil FAUX pour une route sur mesure a venir, ou le drapeau n'a aucun sens.
  //
  // On garde le signal (une faute de frappe cote client reste vue) et on change le diagnostic.
  if (!adosseAuServeur(chemin, gabarits, noms)) {
    // ⚠ L'INDEX DE LA CLE DU HELPER, PAS CELUI DE L'APPEL.
    //
    // `annonceRouteAVenir` remonte le bloc de commentaires contigu au-dessus de la CLE. Passer
    // l'index de `request(` — une ligne plus bas — fait buter la remontee sur la ligne de la
    // cle elle-meme, qui n'est pas un commentaire : le marqueur n'etait jamais trouve, et une
    // route dument annoncee etait refusee quand meme.
    const cle = [...src.slice(0, m.index).matchAll(/^ {2}([a-zA-Z][a-zA-Z0-9]*):\s/gm)].pop()
    if (annonceRouteAVenir(src, cle ? cle.index : m.index) === null) {
      introuvables.push(
        `api/client.js:${ligne} — POST ${chemin} : aucune route de ce nom cote serveur. ` +
          "Ce n'est PAS un defaut de format : le controle ne sait pas si cette operation est " +
          'standard ou sur mesure, et ne conclut donc rien. Soit le chemin est faux, soit la route ' +
          "n'est pas encore ouverte — dans ce cas, annonce-la par `@route-a-venir: <raison>` " +
          'au-dessus du helper.',
      )
    }
    continue
  }

  anomalies.push(
    `api/client.js:${ligne} — POST ${chemin} sans \`ld: true\`. Cette opération est standard : elle ` +
      "désérialise le corps et n'accepte que `application/ld+json`. Sans le drapeau, le serveur " +
      'répondra 415 et la création échouera sans que rien ne le laisse prévoir.',
  )
}

if (introuvables.length > 0) {
  console.error(`✗ Formats : ${introuvables.length} chemin(s) sans route correspondante.\n`)
  for (const a of introuvables) console.error(`  ${a}\n`)
  process.exit(1)
}

if (anomalies.length === 0) {
  console.log('✓ Formats : toutes les créations standard partent en ld+json.')
  process.exit(0)
}

console.error(`✗ Formats : ${anomalies.length} appel(s) qui échoueront en 415.\n`)
anomalies.forEach((a) => console.error('  - ' + a))
console.error(
  "\nN'ajoutez PAS `ld: true` partout. Une opération qui ne désérialise pas — `input: false` ou\n" +
    "`deserialize: false` — ne contrôle pas le Content-Type du tout : le drapeau y est inerte, et\n" +
    "l'écrire fait croire que l'appel est vérifié alors qu'il ne l'est pas.\n" +
    'Ce contrôle lit la déclaration de chaque opération pour trancher, et ne signale que celles\n' +
    'qui désérialisent réellement — celles listées ci-dessus, et elles seules.',
)
process.exit(1)
