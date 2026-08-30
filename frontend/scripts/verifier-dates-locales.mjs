#!/usr/bin/env node
/*
 * AUCUNE DATE DU JOUR N'EST CALCULÉE EN UTC (n°31).
 *
 * ── CE QUE CE CONTRÔLE INTERDIT ─────────────────────────────────────────────────────────────────
 *
 *     new Date().toISOString().slice(0, 10)
 *
 * `toISOString()` rend de l'UTC. À Paris en été, entre minuit et deux heures du matin, cette
 * expression rend LA VEILLE. Elle est correcte vingt-deux heures sur vingt-quatre, ce qui est
 * exactement ce qui la rend durable : personne ne la relit, et le défaut ne se manifeste que sur
 * une saisie de nuit — dont on conclut à une faute de frappe de l'opérateur.
 *
 * ── CE QUE ÇA A COÛTÉ, MESURÉ ───────────────────────────────────────────────────────────────────
 *
 * Le 30/08/2026, DOUZE occurrences vivantes dans le frontal, sur des champs qui datent des faits :
 * la date d'une facture fournisseur, la date de signature d'un mandat SEPA, la date d'exécution
 * d'un prélèvement, la date d'un rejet bancaire, la date d'entrée d'un employé. Sur un mandat SEPA,
 * la date de signature est opposable ; sur une facture, elle décide de l'exercice comptable.
 *
 * Le remède existait DÉJÀ : `jourLocal()` dans `components/Liste.jsx`, écrite après trois
 * occurrences du même défaut, avec le commentaire qui l'explique. Le savoir était posé à un endroit
 * et douze autres l'ignoraient — c'est précisément ce qu'un garde-fou attrape et qu'un commentaire
 * ne peut pas.
 *
 * ── CE QUI RESTE AUTORISÉ ───────────────────────────────────────────────────────────────────────
 *
 * `toISOString()` seule reste permise : envoyer un INSTANT complet en UTC est correct, et c'est
 * même la bonne façon de transmettre un horodatage. Ce qui est interdit, c'est de le TRONQUER à
 * une date : c'est à ce moment-là que le fuseau cesse d'être porté par la valeur.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const RACINE = resolve(dirname(fileURLToPath(import.meta.url)), '../src')

// `slice(0, 10)` et `substring(0, 10)`, avec ou sans espace — et `split('T')[0]`, qui fait la même
// troncature par un autre chemin.
const INTERDIT = /\.toISOString\(\)\s*\.\s*(?:slice|substring)\(\s*0\s*,\s*10\s*\)|\.toISOString\(\)\s*\.\s*split\(\s*['"]T['"]\s*\)\s*\[\s*0\s*\]/g

function fichiers(dossier) {
  const sortie = []
  for (const nom of readdirSync(dossier)) {
    const chemin = join(dossier, nom)
    if (statSync(chemin).isDirectory()) sortie.push(...fichiers(chemin))
    else if (/\.(jsx?|mjs)$/.test(nom)) sortie.push(chemin)
  }
  return sortie
}

const trouves = []
for (const chemin of fichiers(RACINE)) {
  const contenu = readFileSync(chemin, 'utf8')
  const lignes = contenu.split('\n')
  lignes.forEach((ligne, i) => {
    // Un commentaire qui CITE l'expression pour l'interdire n'est pas une occurrence. C'est le cas
    // du commentaire de `jourLocal()`, qui explique justement pourquoi on ne l'écrit pas.
    const nu = ligne.trim()
    if (nu.startsWith('*') || nu.startsWith('//')) return
    INTERDIT.lastIndex = 0
    if (INTERDIT.test(ligne)) {
      trouves.push(`${relative(RACINE, chemin)}:${i + 1}  ${nu.slice(0, 96)}`)
    }
  })
}

if (trouves.length > 0) {
  console.error(`Dates locales : ${trouves.length} date(s) du jour calculée(s) en UTC.\n`)
  for (const t of trouves) console.error(`    ${t}`)
  console.error('\n`toISOString()` rend de l\'UTC : tronquée à 10 caractères, elle date de LA VEILLE')
  console.error('entre minuit et deux heures du matin à Paris en été.\n')
  console.error("Remplacer par `jourLocal()` — `import { jourLocal } from './Liste.jsx'`, qui rend")
  console.error("la date locale via `toLocaleDateString('sv-SE')`.\n")
  console.error('Envoyer un INSTANT complet en UTC reste correct : seule la troncature est interdite.')
  process.exit(1)
}

console.log('✓ Dates locales : aucune date du jour calculée en UTC (troncature de `toISOString`).')
