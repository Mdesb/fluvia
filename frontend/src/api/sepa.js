// LES DEUX ÉTATS DU PRÉLÈVEMENT : LE MANDAT, ET CHAQUE ÉCHÉANCE.
//
// ⚠ SOURCES DE VÉRITÉ : `App\Sepa\Enum\StatutMandatSepa` (deux cas) et
// `App\Sport\Enum\StatutEcheanceSepa` (cinq cas). Les deux enums sont entièrement atteignables :
//
//   mandat    `actif`    — souscription au guichet et en ligne, réengagement, entonnoir Subscription
//             `revoque`  — `RevoquerMandatSepaProcessor`, et la résiliation d'un abonnement
//                          (`DemanderResiliationHandler`, seulement si aucun autre abonnement ne
//                          tient encore à ce mandat)
//
//   échéance  `a_venir`  — `GenerateurEcheancierHandler`, `SubscriptionTermHandler`
//             `prelevee` — `SportEcheanceSepaSource` à la remise
//             `rejetee`  — retour de rejet bancaire
//             `gelee`    — `DemanderPauseHandler`, `ScheduledDebitReductionHandler`
//             `annulee`  — `CancelScheduledDebitProcessor`, `DemanderResiliationHandler`
//
// POURQUOI UN FICHIER. Trois écrans montrent ces états — la fiche d'un abonnement, le module Sport,
// le bloc « Prélèvements SEPA » — et ils ne les montraient pas de la même façon :
//
//   — `FicheAbonnement.jsx` affichait les DEUX en code brut (« revoque », « rejetee », « gelee »),
//     et surtout peignait CHAQUE échéance du même `badge mut`. Un prélèvement rejeté s'y lisait
//     exactement comme un prélèvement encaissé : la couleur disait « rien à signaler » sur la seule
//     ligne qui appelait un geste.
//   — `Sport.jsx` portait la seule table correcte des échéances, et le test `actif ? good : mut` du
//     mandat était recopié à l'identique dans deux fichiers.
//
// ⚠ `gelee` SE DIT « EN PAUSE », ET LE MOT COMPTE — c'est l'arbitrage déjà posé dans `Sport.jsx`,
// repris ici tel quel. `gelee` veut dire que l'adhérent a demandé une suspension : l'échéance
// REVIENDRA à la reprise. `annulee` veut dire qu'elle ne sera jamais collectée. Les afficher pareil
// ferait croire qu'un abonné en pause a perdu son échéancier. Elles diffèrent par la couleur ET par
// le mot ; le mot est dans `vocabulaire.js`, qui traduit `gelee` par « En pause » et non par
// « Gelée » — le mot du modèle ne dit pas au lecteur ce qui va se passer.
//
// Seules `.badge.good`, `.warn`, `.crit`, `.info` et `.mut` existent dans `styles.css`. Le repli est
// `mut` dans les deux cas : un code imprévu ne reçoit pas une gravité qu'on lui inventerait.

const TON_MANDAT = { actif: 'good', revoque: 'mut' }

export function tonStatutMandat(code) {
  if (code === null || code === undefined) return 'mut'
  return TON_MANDAT[String(code).toLowerCase()] || 'mut'
}

/** Les cinq statuts d'échéance, dans l'ordre du cycle de vie. */
export const STATUTS_ECHEANCE = ['a_venir', 'prelevee', 'rejetee', 'gelee', 'annulee']

const TON_ECHEANCE = {
  a_venir: 'info',
  prelevee: 'good',
  rejetee: 'crit',
  gelee: 'warn',
  annulee: 'mut',
}

export function tonEcheance(code) {
  if (code === null || code === undefined) return 'mut'
  return TON_ECHEANCE[String(code).toLowerCase()] || 'mut'
}
