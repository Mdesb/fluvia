// LES CINQ STATUTS D'UN ABONNEMENT, DANS LE SEUL ENDROIT QUI LES CONNAISSE TOUS.
//
// ⚠ SOURCE DE VÉRITÉ : `App\Sport\Enum\StatutAbonnementFitness` — cinq cas, pas un de plus, et tous
// les cinq sont réellement posés par le serveur :
//
//   `actif`   — souscription (`SouscriptionAbonnementHandler`), réengagement (`ReengagementHandler`),
//               et retour au vert après régularisation (`SynchroniserImpayeFitnessListener`)
//   `pause`   — `DemanderPauseHandler`
//   `impaye`  — `SynchroniserImpayeFitnessListener` sur rejet de prélèvement
//   `resilie` — `DemanderResiliationHandler::executerEffet`
//   `echu`    — `SubscriptionTermHandler` : l'engagement est arrivé à son terme, rien ne l'a reconduit
//
// POURQUOI UN FICHIER, ET PAS UNE TABLE PAR ÉCRAN. Quatre écrans lisent ce statut — la liste
// « Abonnements », le module Sport, la fiche d'un abonnement, le bloc « abonnements » d'une fiche
// client. Chacun portait sa table, et les quatre ne disaient pas la même chose :
//
//   — `Abonnements.jsx` nommait `actif`, `suspendu`, `en_pause`, `resilie`, `expire`. Trois de ces
//     clés ne correspondent à AUCUNE valeur que le serveur produise, et trois valeurs qu'il produit
//     (`pause`, `impaye`, `echu`) n'avaient pas de libellé : elles s'affichaient en code brut. Le
//     filtre « Statut » étant construit depuis cette même table, il proposait « Suspendu »,
//     « En pause » et « Expiré » — trois choix qui ne ramenaient jamais aucune ligne — et n'offrait
//     aucun moyen de filtrer sur les trois statuts qui existent vraiment.
//   — `resilie` valait `crit` ici, `warn` là, `mut` ailleurs ; `impaye` valait `warn` ou `crit`
//     selon l'écran. La même ligne changeait de gravité en changeant d'onglet.
//
// LA COULEUR SUIT CE QUE LE STATUT EXIGE DE L'EXPLOITANT, pas l'ordre de l'énumération :
//
//   `actif`   → `good` : rien à faire.
//   `pause`   → `warn` : suspendu à la demande de l'adhérent, l'échéancier revient à la reprise.
//               C'est une attention, pas un problème.
//   `impaye`  → `crit` : de l'argent manque et l'accès est coupé. Quelqu'un doit agir.
//   `echu`    → `crit` : c'est le défaut même qui a fait naître ce cas dans l'énumération — les
//               échéances s'arrêtent, l'accès reste valide, l'adhérent continue d'entrer
//               gratuitement. Le peindre comme une simple alerte le noierait dans la liste.
//   `resilie` → `mut` : c'est fini, réglé, il n'y a plus de geste à poser. Le peindre en rouge
//               attirait l'œil sur des lignes closes, au détriment des deux qui appellent une action.
//
// LE REPLI NE MENT PAS. Un code que le serveur émettrait sans qu'on l'ait prévu ici reste `mut` :
// on ne sait pas ce qu'il veut dire, donc on ne lui invente pas une gravité. Son LIBELLÉ suit la même
// règle par `mot()` — humanisé, jamais remplacé par le mot connu le plus proche.

// ⚠ CE QUI ATTEND LE LOT 1 DU MODULE `Membership`, ET LE PIÈGE QU'IL PORTE.
//
// Le lot 0 (#78) a posé `App\Membership\Enum\MembershipStatus`, miroir en anglais de l'énumération
// Sport : `active`, `paused`, `unpaid`, `terminated`, `expired`. Quand le lot 1 branchera l'écran
// dessus, quatre de ces cinq codes seront inconnus de `vocabulaire.js` et s'afficheront « Paused »,
// « Unpaid », « Terminated » — visiblement non traduits, donc corrigés dans l'heure.
//
// `expired` est le seul dangereux : il EXISTE déjà dans `vocabulaire.js`, où il vaut « Périmé »
// pour un devis. `mot('expired')` rendra donc un mot français plausible et faux, que personne ne
// signalera. C'est le défaut `cancelled` à l'identique, et il se répare ici — pas dans la carte
// globale, qui a raison pour les quatre autres énumérations qui émettent `expired`.
//
// La table à écrire au lot 1, et le mot déjà tranché pour chacun :
//
//     active -> Actif · paused -> En pause · unpaid -> Impayé · terminated -> Résilié
//     expired -> Au terme   (surtout PAS « Périmé »)

/** Les codes tels que le serveur les émet, dans l'ordre où on les propose au filtre. */
export const STATUTS_ABONNEMENT = ['actif', 'pause', 'impaye', 'resilie', 'echu']

// Seules `.badge.good`, `.warn`, `.crit`, `.info` et `.mut` existent dans `styles.css` : une classe
// hors de cette liste ne peint rien, et le garde-fou des classes CSS ne peut pas l'attraper puisque
// le nom est calculé (`badge ${tonStatutAbonnement(...)}`).
const TON = { actif: 'good', pause: 'warn', impaye: 'crit', resilie: 'mut', echu: 'crit' }

export function tonStatutAbonnement(code) {
  if (code === null || code === undefined) return 'mut'
  return TON[String(code).toLowerCase()] || 'mut'
}
