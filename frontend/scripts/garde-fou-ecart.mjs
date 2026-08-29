// GARDE-FOU — UNE OPÉRATION NEUVE A UN ÉCRAN, OU DIT POURQUOI ELLE N'EN A PAS.
//
// CE QUI A CHANGÉ, ET POURQUOI ÇA COMPTE.
//
// `mesurer-ecart.mjs` constate depuis une semaine : 1 086 opérations exposées, 300 atteignables.
// Un constat n'arrête rien. Le nombre est passé de 1 042 à 1 086 pendant qu'on le regardait, parce
// que mesurer et empêcher ne sont pas le même geste.
//
//   > Une mesure qui ne refuse rien ne fait que documenter la dérive.
//
// Ce contrôle gèle l'écart d'aujourd'hui et refuse qu'il grandisse. Il ne demande à personne de
// rattraper les centaines d'opérations existantes : c'est exactement le mécanisme qui a stabilisé
// les treize autres dettes de ce dépôt, dont aucune n'a été résorbée d'un coup.
//
// DEUX SORTIES, ET C'EST VOULU QU'IL Y EN AIT DEUX.
//
//   1. Brancher l'opération à un écran — un appel dans `client.js` ET un usage dans une page.
//   2. Déclarer l'absence d'écran, avec sa raison, par `@sans-ecran:` dans le fichier PHP.
//
// La seconde n'est pas une échappatoire : c'est la réponse honnête pour une surface destinée à des
// intégrateurs, à une machine, ou à un module encore sans interface. Ce qui est refusé n'est pas
// l'absence d'écran — c'est l'absence de décision. Aujourd'hui rien ne distingue « volontairement
// sans écran » de « oublié », et c'est ce silence qui laisse l'écart croître.
//
// POURQUOI UN PLAFOND GLOBAL PLUTÔT QU'UNE LISTE NOMMÉE.
//
// La mesure compte des déclarations (`new Get(`) et des chemins d'appel : les deux ne portent pas la
// même identité, et prétendre les apparier une à une donnerait une liste fausse qu'on croirait
// exacte. Le plafond, lui, est vrai — c'est la forme qu'ont déjà les treize autres cliquets.

import { readFileSync, writeFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { mesurer } from './lib/ecart.mjs'

const ICI = dirname(fileURLToPath(import.meta.url))
const LIGNE_DE_BASE = join(ICI, 'ecart.ligne-de-base.json')

const mesure = mesurer()
const base = JSON.parse(readFileSync(LIGNE_DE_BASE, 'utf8'))
const plafond = base.plafond

// `--geler` réécrit la ligne de base. Réservé à une BAISSE : un plafond qu'on relève n'est plus un
// cliquet, c'est un compteur. Le refus est explicite plutôt que documenté — une consigne écrite dans
// un commentaire ne protège que ceux qui l'ont lue.
if (process.argv.includes('--geler')) {
  if (mesure.inatteignables > plafond) {
    console.error('✗ Refus de geler : l’écart a AUGMENTÉ.')
    console.error(`  ${mesure.inatteignables} inatteignable(s) pour un plafond de ${plafond}.`)
    console.error('  Un cliquet ne remonte pas. Branche l’opération, ou déclare-la « @sans-ecran: ».')
    process.exit(1)
  }
  base.plafond = mesure.inatteignables
  base.releve = mesure
  writeFileSync(LIGNE_DE_BASE, `${JSON.stringify(base, null, 4)}\n`)
  console.log(`✓ Plafond abaissé à ${mesure.inatteignables}.`)
  process.exit(0)
}

// UN FRONT QU'ON N'A PAS DÉCLARÉ FAUSSE TOUT LE RESTE.
//
// Le 27/08, `public/api/boutiqueClient.js` — vingt-quatre appels, tout l'achat en ligne — n'était
// lu par personne : ses opérations comptaient comme inatteignables alors qu'elles sont ce qu'un
// client final déclenche en payant. Le chiffre restait plausible, donc invisible. Ce contrôle est
// là pour que le prochain front ajouté au produit ne puisse pas disparaître en silence.
if (mesure.clientsNonDeclares.length > 0) {
  console.error('✗ Fichier(s) qui ressemblent à un client d’API et qu’aucun front ne déclare :')
  for (const c of mesure.clientsNonDeclares) console.error(`    ${c}`)
  console.error('')
  console.error('  Tant qu’ils ne sont pas déclarés dans FRONTS (frontend/scripts/lib/ecart.mjs),')
  console.error('  leurs appels sont comptés comme inexistants — et la mesure ment sans le dire.')
  process.exit(1)
}

// UN APPEL VERS UNE ROUTE QUE PERSONNE NE DÉCLARE : LE SEUL CAS SANS DETTE GELÉE.
//
// Le compte d'atteignables se laissait baisser par du vide — trois helpers vers des opérations pas
// encore ouvertes ont fait descendre l'écart de trois, et le cliquet proposait de geler dessus. Le
// sens était même inversé : un frontal qui appelle une route inexistante est un défaut, la mesure
// en faisait un progrès.
//
// Ces appels ne comptent plus (voir `lib/ecart.mjs`), et ils échouent ici. Pas de ligne de base :
// il n'y en avait AUCUN le jour où ce contrôle a été écrit — mesuré sur les 374 appels du produit,
// zéro faux positif — donc le premier qui apparaît est nouveau, et se corrige tout de suite.
if (mesure.appelsSansServeur.length > 0) {
  console.error('✗ Appel(s) du front vers une route que rien ne déclare côté serveur :')
  for (const a of mesure.appelsSansServeur) console.error(`    ${a}`)
  console.error('')
  console.error('  Soit le chemin est faux — une faute de frappe, un pluriel inventé, un préfixe')
  console.error('  oublié — et l’écran échouera en 404 devant l’utilisateur. Soit l’opération')
  console.error('  reste à ouvrir côté serveur, et l’appel est en avance sur elle.')
  console.error('')
  console.error('  Dans les deux cas il ne rend RIEN atteignable : ne le compte pas comme couvert.')
  process.exit(1)
}

if (mesure.marqueursSansRaison.length > 0) {
  console.error('✗ Marqueur « @sans-ecran: » sans raison :')
  for (const f of mesure.marqueursSansRaison) console.error(`    ${f}`)
  console.error('')
  console.error('  Un marqueur nu ne déclare rien, il tait. Écris pourquoi cette surface n’a pas')
  console.error('  d’écran — dans six mois, c’est la seule chose qui distinguera un choix d’un oubli.')
  process.exit(1)
}

if (mesure.inatteignables > plafond) {
  console.error('=== ÉCHEC — l’écart client/serveur a grandi ===')
  console.error('')
  console.error(`  Opérations exposées            : ${mesure.exposees}`)
  console.error(`  Déclarées sans écran           : ${mesure.sansEcran}`)
  console.error(`  Attendues avec écran           : ${mesure.attendues}`)
  console.error(`  Atteignables depuis un écran   : ${mesure.atteignables}`)
  console.error(`  INATTEIGNABLES                 : ${mesure.inatteignables}   (plafond ${plafond})`)
  console.error('')
  console.error('Une opération que personne ne peut déclencher n’existe pas pour l’utilisateur, et')
  console.error('coûte pourtant tout ce qu’une opération coûte : un cloisonnement à tenir, une')
  console.error('charge utile à faire évoluer, un test à maintenir.')
  console.error('')
  console.error('Deux sorties :')
  console.error('  1. Branche-la — un appel dans frontend/src/api/client.js ET un usage dans un écran.')
  console.error('     Un helper défini et appelé par aucune page ne compte pas : il n’est pas')
  console.error('     atteignable, et c’est bien ce qu’on mesure.')
  console.error('  2. Déclare-la volontairement sans écran, dans le fichier PHP :')
  console.error('')
  console.error('         * @sans-ecran: consommée par les intégrateurs, aucune interface prévue.')
  console.error('')
  console.error('Si tu viens au contraire d’en brancher, abaisse le plafond :')
  console.error('  node frontend/scripts/garde-fou-ecart.mjs --geler')
  process.exit(1)
}

const marge = plafond - mesure.inatteignables
console.log(
  `Écart client/serveur : OK — ${mesure.inatteignables} opération(s) inatteignable(s), plafond ${plafond}.`
  + (marge > 0 ? ` (${marge} de marge : pense à geler.)` : '')
)
console.log(
  `  ${mesure.atteignables} atteignable(s) sur ${mesure.attendues} attendue(s)`
  + ` · ${mesure.sansEcran} déclarée(s) sans écran dans ${mesure.declares.length} fichier(s).`
)
