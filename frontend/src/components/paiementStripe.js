// Logique PURE de la section « Paiement par carte (Stripe) » (lot D3-B de #365, G-6 et G-9).
// Aucun accès réseau, aucune dépendance React : tout ce qui se teste sans navigateur est ici, et
// `paiementStripe.test.js` le lance avec `node --test`.
//
// ⚠ LE SECRET NE REVIENT JAMAIS DU SERVEUR, ET L'ÉCRAN NE LE REMONTE JAMAIS DANS UN CHAMP. Le serveur
// ne rend que `hasSecretKey` / `hasWebhookSecret` (des booléens), le mode et des dates : `corpsEnregistrement`
// n'a donc rien à « renvoyer », et il OMET tout champ laissé vide (un PATCH sans la valeur la laisse intacte).
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri.js'

export const DROIT_CONFIGURER_PAIEMENT = 'payment.configure'

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

// Une régie directe encaisse par PayFiP : le serveur refuse (422) qu'elle porte une clé Stripe.
const TYPES_SANS_STRIPE = ['regie_directe']

/** Le droit exigé par le serveur, rejoué en entier par `aLeDroit` (jokers `*.*`, `payment.*`). */
export function peutConfigurerPaiement(droits) {
  return aLeDroit(droits, DROIT_CONFIGURER_PAIEMENT)
}

/** Faux pour une régie directe (PayFiP, aucune clé) ; vrai pour tout autre type, inconnu compris. */
export function profilEligible(type) {
  return !TYPES_SANS_STRIPE.includes(type)
}

/**
 * Le corps d'une saisie. Un champ vide (ou fait d'espaces) est OMIS, jamais envoyé vide : à la
 * création le profil est désigné, à la mise à jour seul ce qui est saisi change.
 */
export function corpsEnregistrement(cle, secret, profilId) {
  const corps = {}
  const c = String(cle ?? '').trim()
  const s = String(secret ?? '').trim()
  if (c) corps.secretKeyPlain = c
  if (s) corps.webhookSecretPlain = s
  if (profilId) corps.businessProfile = `/api/profil_exploitants/${profilId}`

  return corps
}

/** Une saisie vaut-elle d'être envoyée ? (au moins une des deux valeurs ; la création exige la clé). */
export function saisieEnvoyable({ cle, secret, existe }) {
  const c = String(cle ?? '').trim()
  const s = String(secret ?? '').trim()

  return existe ? Boolean(c || s) : Boolean(c)
}

/** « Test » / « Live » en français d'exploitant ; `null` pour une valeur inconnue (rien n'est inventé). */
export function libelleMode(mode) {
  if (mode === 'test') return 'Mode test (aucun paiement réel)'
  if (mode === 'live') return 'Mode live (paiements réels)'

  return null
}

/**
 * L'adresse à déclarer chez Stripe pour CE profil. Refuse (null) un identifiant qui n'est pas un UUID :
 * on n'affiche jamais une adresse que le serveur rejetterait. L'origine est celle de la page ; elle doit
 * être mesurée en préprod (derrière un proxy, ce peut ne pas être l'adresse publique que Stripe appelle).
 */
export function adresseNotification(origin, profilId) {
  const hote = String(origin ?? '').replace(/\/+$/, '')
  if (!hote || !UUID.test(String(profilId ?? ''))) return null

  return `${hote}/api/boutique/paiement-en-ligne/notification/stripe/${profilId}`
}

/** La ligne de configuration du profil parmi la collection (chaque ligne désigne son profil par IRI). */
export function ligneDuProfil(lignes, profilId) {
  if (!profilId) return null

  return (lignes ?? []).find((l) => {
    return idDe(l?.businessProfile) === String(profilId)
  }) ?? null
}

/** Le texte de la confirmation du retrait : nomme l'objet et dit la conséquence. */
export function texteConfirmationRetrait(raisonSociale) {
  const nom = String(raisonSociale ?? '').trim() || 'cet exploitant'

  return {
    titre: `Retirer la clé Stripe de « ${nom} » ?`,
    texte: 'La clé et le secret de signature enregistrés seront supprimés.',
    consequence:
      'Les paiements par carte repasseront sur la clé globale de la plateforme, ou seront indisponibles s’il n’y en a pas. '
      + 'Ne retirez pas la clé tant que des paiements par carte sont en cours : une commande ouverte ne pourra plus être vérifiée '
      + 'et devra être confirmée à la main.',
    libelleOk: 'Retirer',
    danger: true,
  }
}

/** Le message à afficher pour une erreur d'API : le texte du serveur (sans valeur), complété par statut. */
export function messageErreur(erreur) {
  const brut = erreur?.message || 'L’opération a échoué.'
  switch (erreur?.status) {
    case 409: return `${brut} Rechargez l’écran : une clé existe déjà pour cet exploitant.`
    case 503: return `${brut} Rien n’a été enregistré.`
    case 404: return 'Cet exploitant n’est plus accessible depuis cet établissement.'
    default: return brut
  }
}
