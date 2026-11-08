// CLORE UN MOIS OU UNE ANNÉE : LA QUESTION AVANT LE GESTE (#362).
//
// Une clôture de période est définitive : elle entre dans la chaîne d'inaltérabilité et ne se refait
// pas. Le bouton partait pourtant au premier clic. Le serveur refuse un mois incomplet, mais sur un
// mois complet, un clic involontaire (mauvais point de vente, mauvais mois) scellait la clôture.
//
// La décision « demander, puis appeler seulement sur un oui » vit ici, hors de l'écran, pour qu'un
// test la tienne sans navigateur : `confirm` et `api` sont injectés. L'écran passe `confirmer`
// (components/Confirmation.jsx, la modale commune) et le client HTTP.
//
// Lancer : `cd frontend && node --test src/api/periodClosing.test.js`.

/** Rendu quand l'exploitant a répondu non : rien n'a été envoyé au serveur. */
export const CANCELLED = Symbol('closing-cancelled')

const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']

/** « 2026-10 » → « octobre 2026 ». Une saisie inattendue se rend telle quelle plutôt qu'inventée. */
export function monthLabel(value) {
  const m = /^(\d{4})-(\d{2})$/.exec(String(value || ''))
  if (!m || Number(m[2]) < 1 || Number(m[2]) > 12) return String(value || '')
  return `${MONTHS[Number(m[2]) - 1]} ${m[1]}`
}

/** La période en toutes lettres, telle que la modale la rappelle. */
export function periodLabel(kind, period) {
  return kind === 'annual' ? `l’année ${period}` : `le mois de ${monthLabel(period)}`
}

/** La demande passée à `confirmer` : la période, le point de vente, et le caractère définitif. */
export function closingConfirmation({ kind, period, pointOfSaleLabel }) {
  const what = periodLabel(kind, period)
  const where = pointOfSaleLabel ? ` pour le point de vente « ${pointOfSaleLabel} »` : ''
  return {
    titre: kind === 'annual' ? `Clore l’année ${period} ?` : `Clore le mois de ${monthLabel(period)} ?`,
    texte: `Vous allez clore ${what}${where}.`,
    consequence: 'Cette clôture est définitive : elle est scellée dans la chaîne d’inaltérabilité et ne pourra être ni annulée ni refaite.',
    libelleOk: kind === 'annual' ? 'Clore l’année définitivement' : 'Clore le mois définitivement',
    danger: true,
  }
}

/**
 * Demande confirmation, puis clôt. Rend `CANCELLED` sur un non, sans aucun appel au serveur ;
 * sinon la réponse du serveur (une erreur du serveur remonte telle quelle).
 */
export async function closePeriod({ kind, period, pointOfSaleId, pointOfSaleLabel }, { confirm, api }) {
  if (!(await confirm(closingConfirmation({ kind, period, pointOfSaleLabel })))) return CANCELLED
  return kind === 'annual'
    ? api.cloturerAnnee(pointOfSaleId, period)
    : api.cloturerMois(pointOfSaleId, period)
}
