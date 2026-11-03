// Les mots de la caisse quand quelque chose ne passe pas : écrits pour l'agent au guichet, pas
// pour un développeur.

/** Code porté par `ApiError.payload` quand le serveur n'a pas pu être joint du tout. */
export const NETWORK_UNREACHABLE = 'network_unreachable'

export const isNetworkLost = (e) => e?.payload?.code === NETWORK_UNREACHABLE

/**
 * Le message d'un échec pendant la vente.
 *
 * ⚠ « N'A PAS ÉTÉ ENREGISTRÉE » N'EST VRAI QU'AVANT LA VALIDATION. Pendant l'ouverture du panier, une
 * coupure laisse au pire une vente en cours, jamais une vente validée ni un encaissement. Pendant la
 * validation, la requête a pu arriver au serveur et la réponse se perdre au retour : affirmer « pas
 * enregistrée » ferait refaire une vente qui existe. On dit alors ce qu'on sait, et où vérifier.
 *
 * @param {unknown} e     l'erreur levée par l'appel
 * @param {'open'|'validate'} etape
 * @param {string} repli  message quand l'erreur n'en porte pas
 */
export function saleErrorMessage(e, etape, repli) {
  if (isNetworkLost(e)) {
    return etape === 'validate'
      ? 'Connexion perdue. Vérifiez internet ; la validation n’a pas pu être confirmée. '
        + 'Ouvrez l’historique des ventes avant de recommencer : elle a peut-être abouti.'
      : 'Connexion perdue. Vérifiez internet ; la vente n’a pas été enregistrée.'
  }
  return e?.message || repli
}
