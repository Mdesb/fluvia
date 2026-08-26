// Les signaux que le serveur calcule et qu'aucun écran n'affiche.
//
// POURQUOI CE SECOND INSTRUMENT EXISTE.
//
// `mesurer-ecart` compte des opérations. C'est son angle mort déclaré, et il m'a coûté cher : une
// opération peut être branchée pendant que l'information qu'elle transporte n'atteint personne.
//
// Cinq fois en une journée, dans cinq modules sans rapport :
//
//   - les ventes que la génération d'écritures saute pour mapping incomplet — renvoyées, jamais lues ;
//   - `nbAccesBloques` du recouvrement — derrière chaque impayé, quelqu'un ne peut plus entrer ;
//   - `messageAgent` des salles de musée — une phrase écrite POUR l'agent d'accueil ;
//   - le seuil POSS de la piscine — une limite réglementaire de surveillance ;
//   - `alerteCouverture`, trouvé le premier jour et jamais affiché.
//
// Ce n'est plus une série de trouvailles, c'est une caractéristique du produit : les calculs ont été
// écrits avant les écrans, et personne n'est revenu fermer la boucle. Cinq anecdotes ne se traitent
// pas ; un relevé, si.
//
// CE QUE CE SCRIPT MESURE EXACTEMENT.
//
// Il ne regarde QUE les modèles de lecture — les classes des dossiers `ApiResource/`. Ces classes
// n'existent que pour être affichées : elles ne portent aucune donnée, elles rendent un calcul. Une
// propriété publique qui n'apparaît nulle part dans le front est donc un signal muet avec très peu
// d'ambiguïté.
//
// CE QU'IL NE MESURE PAS, ET QU'IL FAUT LIRE AVEC :
//
//   - Les champs calculés portés par les ENTITÉS (un `getQuantiteDisponible()` sur une entité
//     métier) ne sont pas vus. Les inclure noierait le relevé sous des propriétés de persistance.
//   - Un champ affiché sous un autre nom — déstructuré, renommé — est compté comme muet à tort.
//     C'est pourquoi la sortie s'appelle « candidats » et non « manquants » : chaque ligne demande
//     une vérification humaine, et le script le dit plutôt que de compter pour vrai.

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, dirname, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const ICI = dirname(fileURLToPath(import.meta.url))
const RACINE = join(ICI, '..', '..')

function fichiers(repertoire, extension, filtre) {
  const trouves = []
  for (const entree of readdirSync(repertoire)) {
    const chemin = join(repertoire, entree)
    if (statSync(chemin).isDirectory()) trouves.push(...fichiers(chemin, extension, filtre))
    else if (entree.endsWith(extension) && (!filtre || filtre(chemin))) trouves.push(chemin)
  }
  return trouves
}

// Le front entier : c'est là qu'un champ doit apparaître pour ne pas être muet.
const front = fichiers(join(RACINE, 'frontend', 'src'), '.js')
  .concat(fichiers(join(RACINE, 'frontend', 'src'), '.jsx'))
  .map((f) => readFileSync(f, 'utf8'))
  .join('\n')

function citeDansLeFront(nom) {
  let i = front.indexOf(nom)
  while (i !== -1) {
    const avant = front[i - 1] || ' '
    const apres = front[i + nom.length] || ' '
    if (!/[A-Za-z0-9_$]/.test(avant) && !/[A-Za-z0-9_$]/.test(apres)) return true
    i = front.indexOf(nom, i + 1)
  }
  return false
}

// Littérales, jamais construites : une expression assemblée dans un gabarit transforme `\s` en `s`.
const PROPRIETE = /^\s*public\s+(?:readonly\s+)?[?\w|\\]+\s+\$([a-zA-Z][a-zA-Z0-9_]*)/gm
const NOM_CLASSE = /(?:final\s+)?class\s+([A-Za-z][A-Za-z0-9_]*)/

// Ces noms sont des identifiants techniques, pas des informations pour l'exploitant : les compter
// gonflerait le relevé de lignes que personne ne veut voir affichées.
const TECHNIQUES = new Set(['id', 'iri', 'type', 'context'])

const modeles = fichiers(join(RACINE, 'app', 'src'), '.php', (c) => c.includes('ApiResource'))

let champs = 0
const muets = []

for (const fichier of modeles) {
  const texte = readFileSync(fichier, 'utf8')
  const classe = NOM_CLASSE.exec(texte)?.[1] || '?'
  const chemin = relative(RACINE, fichier).replace(/\\/g, '/')

  let m = PROPRIETE.exec(texte)
  const absents = []
  while (m !== null) {
    const nom = m[1]
    if (!TECHNIQUES.has(nom)) {
      champs += 1
      if (!citeDansLeFront(nom)) absents.push(nom)
    }
    m = PROPRIETE.exec(texte)
  }

  if (absents.length > 0) muets.push({ classe, chemin, absents })
}

const total = muets.reduce((s, x) => s + x.absents.length, 0)

console.log(`Modèles de lecture examinés : ${modeles.length}`)
console.log(`Champs publiés              : ${champs}`)
console.log(`Candidats muets             : ${total}   (${((100 * total) / (champs || 1)).toFixed(1)} %)`)
console.log('')

if (total === 0) {
  console.log('Aucun champ de modèle de lecture ne manque au front.')
} else {
  console.log('Candidats — chaque ligne demande une vérification : un champ affiché sous un autre')
  console.log("nom est compté ici à tort. C'est une piste de travail, pas un verdict.")
  console.log('')
  for (const x of muets.sort((a, b) => b.absents.length - a.absents.length)) {
    console.log(`  ${x.classe}`)
    console.log(`    ${x.chemin}`)
    console.log(`    ${x.absents.join(', ')}`)
  }
}
