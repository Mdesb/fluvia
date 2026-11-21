// Alertes NF525 « chaîne fiscale bloquée » : fonctions pures, partagées par l'écran de l'exploitant
// et celui de l'éditeur. Aucun champ technique ne passe par ici : seuls des libellés en clair.

// Les causes que le serveur sait émettre (`SealRefusalCause`).
const CAUSES = {
  invalid_signature: 'Signature invalide',
  unknown_key: 'Clé de scellement inconnue',
  key_regression: 'Clé de scellement antérieure à la dernière utilisée',
  unreadable_order: 'Ordre de la chaîne illisible',
  chain_broken: 'Chaîne rompue détectée à la vérification',
}
export const CAUSE_INCONNUE = 'Cause non référencée'

const STATUTS = { open: 'Ouverte', resolved: 'Résolue' }
export const STATUT_INCONNU = 'Statut inconnu'

export const NOTE_MAX = 2000

export function libelleCause(cause) {
  return (typeof cause === 'string' && Object.hasOwn(CAUSES, cause)) ? CAUSES[cause] : CAUSE_INCONNUE
}

export function libelleStatut(statut) {
  return (typeof statut === 'string' && Object.hasOwn(STATUTS, statut)) ? STATUTS[statut] : STATUT_INCONNU
}

// `resolvedAt` est OMIS par l'API d'établissement quand il est nul : absent, null et vide donnent null.
export function dateResolution(alerte) {
  const v = alerte?.resolvedAt
  return typeof v === 'string' && v !== '' ? v : null
}

const instant = (v) => {
  const t = Date.parse(v)
  return Number.isNaN(t) ? 0 : t
}

// Ouvertes d'abord, puis la plus récente d'abord (dernier événement). Ne modifie pas l'entrée.
export function trierAlertes(alertes) {
  const rang = (a) => (a?.status === 'open' ? 0 : 1)
  return [...(alertes ?? [])].sort(
    (a, b) => rang(a) - rang(b) || instant(b?.lastEventAt) - instant(a?.lastEventAt),
  )
}

// `filtre` : 'open', 'resolved' ou autre chose (= toutes).
export function filtrerAlertes(alertes, filtre) {
  const liste = alertes ?? []
  return filtre === 'open' || filtre === 'resolved' ? liste.filter((a) => a?.status === filtre) : [...liste]
}

// Le serveur compte en points de code (mb_strlen) : `length` compterait des unités UTF-16 et
// ferait valoir deux pour un émoji.
export function longueurNote(note) {
  return [...String(note ?? '')].length
}

export function noteValide(note) {
  return longueurNote(note) <= NOTE_MAX
}
