import { membres } from './client.js'
import { idDe } from './iri.js'

// ── ENCAISSEMENT AU COMPTOIR DE LA PREMIÈRE ÉCHÉANCE (écran de souscription) ────────────────────
//
// Sorti du composant pour être testé (`firstInstalmentAtCounter.test.js`) : c'est un chemin
// d'argent, et l'ordre de ses appels est sa seule protection. L'abonnement est DÉJÀ souscrit quand
// on arrive ici. Rend `null` si tout a abouti, sinon ce qu'il faut dire à l'opérateur.
//
// ⚠ L'ORDRE DES APPELS EST LA SEULE CHOSE QUI PROTÈGE L'ADHÉRENT D'UN DOUBLE PRÉLÈVEMENT, ET IL SE
// LIT À L'ENVERS DE L'INTUITION.
//
// Encaisser la première échéance au comptoir veut dire qu'elle ne doit PAS être prélevée en plus.
// Il faut donc l'annuler — mais l'annuler AVANT d'avoir l'argent laisserait, au moindre refus de
// règlement, un abonnement dont la première échéance est annulée et jamais encaissée : une somme
// que le club ne réclamerait plus jamais, sans que personne ne s'en aperçoive.
//
// On annule donc EN DERNIER, une fois l'argent réellement encaissé et la vente validée. Si cette
// dernière étape échoue, l'échéance reste « à venir » : l'adhérent risque d'être prélevé deux
// fois, ce qui est visible, réclamable et réparable — au contraire du silence.
//
// ⚠ ET ON NE JETTE PAS. Chaque échec est raconté à l'opérateur avec l'endroit où ça s'est arrêté,
// parce que la réparation n'est pas la même selon l'étape.
export async function payFirstInstalmentAtCounter(
  api,
  { abonnementId, sessionId, payeurId, produitId, tarif, moyen, montant, prixForce },
) {
  if (!tarif) {
    return "L'abonnement est souscrit, mais ce produit n'a aucun tarif au guichet : rien n'a été "
      + "encaissé. Encaissez depuis la caisse, ou laissez la première échéance se prélever."
  }
  let vente
  try {
    vente = await api.creerVente({ session: sessionId })
  } catch (e) {
    return "L'abonnement est souscrit, mais la vente n'a pas pu être ouverte en caisse ("
      + (e?.message || 'refus du serveur')
      + ") : rien n'a été encaissé, et la première échéance sera prélevée normalement."
  }
  const numero = vente?.numero ? `n° ${vente.numero}` : `id ${vente?.id}`

  // ⚠ SANS CLIENT, ON N'ENCAISSE PAS. Une vente d'abonnement anonyme est scellée PUIS refusée
  // (pas de débiteur pour le mandat, G-5) : l'argent entre, l'écran voyait un échec, et un second
  // encaissement suivait (mesuré le 07/10, PR #276). On s'arrête donc ici, la vente encore vide
  // (elle reste ouverte : seule une vente validée s'annule).
  try {
    await api.rattacherClientVente(vente.id, { client: payeurId })
  } catch (e) {
    return `L'abonnement est souscrit, mais la vente ${numero} n'a pas pu être rattachée au client (`
      + (e?.message || 'refus du serveur')
      + `) : rien n'a été encaissé (elle reste ouverte, vide), et la première échéance sera `
      + `prélevée normalement.`
  }

  // Ce que le serveur a répondu APRÈS avoir validé (donc encaissé) la vente : un échec de l'appel
  // n'est pas un échec de l'encaissement.
  let apresValidation = null
  try {
    const ligne = { produit: produitId, typeTarif: tarif, quantite: 1 }
    if (prixForce) {
      ligne.prixForce = true
      ligne.prixUnitaire = montant
    }
    await api.ajouterLigne(vente.id, ligne)
    const reglement = await api.payer(vente.id, { moyen, montant })
    if (!reglement?.reglementEnregistre) {
      return `L'abonnement est souscrit. Le règlement a été refusé : la vente ${numero} reste `
        + `ouverte en caisse, et la première échéance sera prélevée normalement.`
    }
    try {
      await api.valider(vente.id)
    } catch (e) {
      // La vente peut être SCELLÉE malgré la réponse (refus postérieur au commit, réponse perdue) :
      // on la relit avant de dire quoi que ce soit, car « rien n'est fait » ferait réencaisser.
      const relue = await api.vente(vente.id).catch(() => null)
      if (!relue) {
        return `L'abonnement est souscrit, mais on ne sait pas si la vente ${numero} a été validée (`
          + (e?.message || 'refus du serveur')
          + `) : vérifiez-la dans la caisse AVANT tout nouvel encaissement.`
      }
      if (relue.statut !== 'validee') throw e
      apresValidation = e?.message || 'refus du serveur'
    }
  } catch (e) {
    return `L'abonnement est souscrit. L'encaissement s'est arrêté sur la vente ${numero} (`
      + (e?.message || 'refus du serveur')
      + `) : reprenez-la depuis la caisse. La première échéance reste programmée.`
  }

  // L'argent est encaissé. À partir d'ici, tout échec laisse une échéance de trop — jamais un
  // encaissement de moins.
  const encaisse = apresValidation
    ? `Encaissé au comptoir (vente ${numero}) : ne l'encaissez pas une seconde fois. L'abonnement `
      + `est souscrit, mais le serveur a répondu « ${apresValidation} » après avoir validé la vente : `
      + `signalez-la, pour vérifier qu'elle est bien reliée à l'abonnement.`
    : `Encaissé au comptoir (vente ${numero}).`
  let echeance = null
  try {
    const liste = membres(
      await api.echeancesSepaSport({ abonnement: abonnementId, statut: 'a_venir', order: 'asc' }),
    )
    echeance = Array.isArray(liste) && liste.length > 0 ? liste[0] : null
  } catch {
    /* dit par le message ci-dessous */
  }
  if (!echeance) {
    return `${encaisse} ⚠ La première échéance n'a pas pu être retrouvée : vérifiez l'échéancier `
      + `et annulez-la, sinon l'adhérent sera prélevé deux fois.`
  }
  try {
    await api.annulerEcheanceSepa(
      idDe(echeance),
      `Première échéance encaissée au comptoir (vente ${numero}).`,
    )
  } catch (e) {
    return `${encaisse} ⚠ La première échéance n'a pas pu être annulée (`
      + `${e?.message || 'refus du serveur'}) : annulez-la dans l'échéancier, sinon l'adhérent `
      + `sera prélevé deux fois.`
  }
  return apresValidation ? encaisse : null
}
