// Logique PURE de la section « Paiement par PayFiP » (lot D2-B de #365, G-6). Aucun accès réseau, aucune
// dépendance React : tout ce qui se teste sans navigateur est ici, et `paiementPayFip.test.js` le lance avec
// `node --test`.
//
// Le numéro client PayFiP (NUMCLI) n'est PAS un secret : le serveur le rend avec le profil, et l'écran l'affiche
// pré-rempli (contrairement à la clé Stripe). Le serveur refuse tout ce qui n'est pas 6 chiffres ; l'écran le
// vérifie avant d'envoyer, sans jamais s'y fier.
import { peutConfigurerPaiement } from './paiementStripe.js'

// Le droit se teste avec LE test déjà partagé (garde-fou « verifier-droits ») : pas un second test de droit.
export { peutConfigurerPaiement }

const SIX_CHIFFRES = /^\d{6}$/

// Seule une régie directe encaisse par PayFiP : le serveur refuse (422) une saisie sur un autre type.
const TYPE_PAYFIP = 'regie_directe'

/** Vrai pour une régie directe seulement ; groupe privé, DSP et type inconnu n'ont pas PayFiP. */
export function profilEligiblePayFip(type) {
  return type === TYPE_PAYFIP
}

/** Six chiffres exactement, espaces de bord rognés. Le vide n'est pas une saisie valide (c'est un effacement). */
export function numeroClientValide(saisie) {
  return SIX_CHIFFRES.test(String(saisie ?? '').trim())
}

/**
 * Le corps du PATCH : `{ payfipClientNumber }` avec les 6 chiffres rognés, ou `null` pour effacer (saisie vide).
 * Un numéro invalide ne part jamais : on lève plutôt que d'envoyer.
 */
export function corpsNumeroClient(saisie) {
  const s = String(saisie ?? '').trim()
  if (s === '') return { payfipClientNumber: null }
  if (!numeroClientValide(s)) throw new Error('Le numéro client PayFiP compte exactement 6 chiffres.')

  return { payfipClientNumber: s }
}

/** Le message d'erreur de saisie : vide si la saisie est valide ou vide (effacement), explicite sinon. */
export function messageNumeroClient(saisie) {
  const s = String(saisie ?? '').trim()
  if (s === '' || numeroClientValide(s)) return ''

  return 'Le numéro client PayFiP compte exactement 6 chiffres.'
}

/** Ce que l'exploitant doit comprendre : PayFiP réel, ou le bouchon de démonstration. */
export function libelleEtat(numero) {
  return String(numero ?? '').trim() !== ''
    ? 'PayFiP actif pour cette régie.'
    : 'PayFiP non configuré pour cette régie : le bouchon de démonstration répond.'
}

/** Le message d'une erreur d'API : le texte du serveur (qui ne répète jamais la valeur), complété par statut. */
export function messageErreurNumeroClient(erreur) {
  const brut = erreur?.message || 'L’opération a échoué.'
  switch (erreur?.status) {
    case 403: return 'Vous n’avez pas le droit de modifier le numéro client PayFiP.'
    case 404: return 'Cet exploitant n’est plus accessible depuis cet établissement.'
    default: return brut
  }
}

/** Le texte de la confirmation de l'effacement : nomme l'objet et dit la conséquence. */
export function texteConfirmationEffacement() {
  return {
    titre: 'Effacer le numéro client PayFiP ?',
    texte: 'Cette régie n’aura plus PayFiP : le bouchon de démonstration répondra à la place.',
    consequence: 'Les paiements PayFiP déjà ouverts gardent le numéro avec lequel ils ont été initiés.',
    libelleOk: 'Effacer',
    danger: true,
  }
}
