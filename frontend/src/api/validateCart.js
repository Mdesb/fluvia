// « Valider la vente » au comptoir : ce que le bouton fait, sorti de l'écran pour être testé.
//
// ⚠ UN PANIER À 0 € N'OUVRAIT JAMAIS SA VENTE, ET LE BOUTON NE FAISAIT RIEN.
//
// Depuis #236, la vente s'ouvre au PREMIER règlement (« Régler »), pas avant. Un panier gratuit
// n'a rien à régler : le pavé affichait directement « Valider la vente », qui appelait la
// validation sans vente ouverte — et la validation sortait sur `if (!venteId) return`, sans un
// mot. Un billet gratuit (CIAP Casa Xanxo : 43 000 par an) ne pouvait donc pas être délivré.
//
// Désormais : pas de vente ouverte → on l'ouvre, et si le serveur confirme qu'il ne reste rien à
// payer, on la valide dans le même geste. S'il reste quelque chose (le prix serveur diffère de
// l'estimation de l'écran), la vente reste ouverte et le pavé demande le règlement, comme avant.

const RIEN = 0.0001

const montant = (x) => {
  const n = Number.parseFloat(x ?? '0')
  return Number.isFinite(n) ? n : 0
}

/**
 * @param {object} p
 * @param {{id: string}|null} p.sale       vente déjà ouverte, ou null
 * @param {number|string} p.remaining      reste à payer affiché
 * @param {() => Promise<{vObj: {id: string}, resteServeur: string}|undefined>} p.openSale
 * @param {(saleId: string, settlements: Array|null) => Promise<void>} p.finish
 * @returns {Promise<'validated'|'unpaid'|'not-opened'>}
 */
export async function validateCart({ sale, remaining, openSale, finish }) {
  if (sale?.id) {
    if (montant(remaining) > RIEN) return 'unpaid'
    // `null` : la validation reprend les règlements déjà connus de l'écran.
    await finish(sale.id, null)
    return 'validated'
  }
  const ctx = await openSale()
  if (!ctx?.vObj?.id) return 'not-opened'
  if (montant(ctx.resteServeur) > RIEN) return 'unpaid'
  await finish(ctx.vObj.id, [])
  return 'validated'
}

/**
 * Ce que l'écran montre après une validation, d'après `TicketProcessor`.
 *
 * Une vente gratuite n'a pas de ticket de caisse à justifier, mais elle a un billet à remettre : il
 * s'affiche et part à l'impression tout de suite (un geste délivre et imprime), sans l'étape
 * « Afficher le billet » qui le précédait.
 *
 * @returns {'pass'|'receipt'|'offer'}
 */
export function screenAfterValidation(ticketInfo) {
  if (ticketInfo?.venteGratuite) return 'pass'
  if (ticketInfo?.impressionAutomatique) return 'receipt'
  return 'offer'
}
