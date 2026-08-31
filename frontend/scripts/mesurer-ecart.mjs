// Combien d'opérations l'API expose, combien un utilisateur peut réellement en déclencher.
//
// POURQUOI CE SCRIPT EXISTE PLUTÔT QU'UN CHIFFRE DANS UN RAPPORT.
//
// J'ai annoncé « 135 sur 1042 », puis « 169 sur 1068 », en recomptant chaque fois à la main. Deux
// mesures qui ne se comparent pas ne mesurent rien : la seconde peut être plus haute parce qu'on a
// branché des écrans, ou parce qu'on a compté autrement, et personne — moi compris — ne peut dire
// laquelle. Un chiffre qu'on ne sait pas reproduire est une opinion.
//
// LE CALCUL N'EST PLUS ICI, ET C'EST LE POINT.
//
// Il vit dans `lib/ecart.mjs`, partagé avec `garde-fou-ecart.mjs` qui refuse les commits sur le même
// chiffre. Recopier la méthode dans les deux garantissait qu'un jour la mesure affichée et la mesure
// contrôlée divergeraient — et qu'on croirait la mauvaise.
//
//   > Un seul calcul, deux appelants.
//
// Ce script AFFICHE ; le garde-fou REFUSE. C'est la seule différence entre eux.

import { mesurer } from './lib/ecart.mjs'

const m = mesurer()
const part = ((100 * m.atteignables) / m.attendues).toFixed(1)

console.log(`Opérations exposées par l'API   : ${m.exposees}`)
if (m.sansEcran > 0) {
  console.log(`Déclarées sans écran            : ${m.sansEcran}   (@sans-ecran:, ${m.declares.length} fichier(s))`)
  console.log(`Attendues avec écran            : ${m.attendues}`)
}
console.log(`Appelées, tous fronts confondus  : ${m.appelees}`)
console.log(`Atteignables depuis un écran    : ${m.atteignables}   <-- la mesure qui compte`)
console.log(`Part atteignable                : ${part} %`)
console.log('')
console.log(`Restent ${m.inatteignables} opérations qu'aucun utilisateur ne peut déclencher.`)

// PAR FRONT — parce qu'un total masque lequel des trois est en retard.
// Le back-office et l'éditeur partagent `api/client.js` ; la boutique en ligne a le sien.
console.log('')
console.log('Par application :')
for (const f of m.parFront) {
  console.log(`  ${String(f.atteignables).padStart(3)} atteignable(s) sur ${String(f.appelees).padStart(3)} appelée(s)   ${f.nom}`)
}

if (m.clientsNonDeclares.length > 0) {
  console.log('')
  console.log('⚠ Fichier(s) qui ressemblent à un client d’API et qu’aucun front ne déclare :')
  for (const c of m.clientsNonDeclares) console.log(`    ${c}`)
  console.log('  Leurs appels sont comptés comme inexistants. Déclare le front dans lib/ecart.mjs.')
}

if (m.declares.length > 0) {
  console.log('')
  console.log('Surfaces déclarées volontairement sans écran :')
  for (const d of m.declares) {
    console.log(`  ${String(d.operations).padStart(3)}  ${d.fichier}`)
    console.log(`       ${d.raison}`)
  }
}

if (m.appelsSansServeur.length > 0) {
  console.log('')
  console.log(`${m.appelsSansServeur.length} appel(s) vers une route que rien ne déclare côté serveur :`)
  for (const a of m.appelsSansServeur) console.log(`  ${a}`)
  console.log('  (ils ne comptent pas comme atteignables : ils ne rendent rien atteignable.)')
}

if (m.orphelins.length > 0) {
  console.log('')
  console.log(`${m.orphelins.length} appel(s) définis dans un client et utilisés par aucun écran :`)
  for (const nom of m.orphelins) console.log(`  ${nom}`)
}

// `--par-module` : où l'écart se creuse, pour choisir le lot suivant sur une mesure et non au flair.
if (process.argv.includes('--par-module')) {
  console.log('')
  console.log('Opérations appelées, par préfixe de chemin :')
  for (const [prefixe, n] of m.parPrefixe) {
    console.log(`  ${String(n).padStart(3)}  ${prefixe}`)
  }
}
