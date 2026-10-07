// LES NOMS D'UN CRÉNEAU : SON ACTIVITÉ ET SA RESSOURCE, EMBARQUÉES DANS LE CRÉNEAU LU.
//
// Une règle, un endroit. Trois écrans la tenaient chacun à sa façon : Places libérées taisait
// l'activité, Réservation taisait une activité absente et nommait « Ressource » une ressource
// absente, la vue semaine n'affichait que l'une ou l'autre.
//
// ⚠ UN CHAMP NUL N'EST PAS ÉCRIT PAR L'API : LA CLÉ DISPARAÎT. Les 7 créneaux sans activité de
// Piscine A n'ont pas de clé `activite` (mesuré le 15/09/2026 : 7 absentes, 0 à null) — un test
// `=== null` n'en voyait aucun.
//
//   absente ou nulle          → « sans activité » / « sans ressource » : c'est un fait lu ;
//   objet sans libellé, IRI   → null : on ne sait pas la nommer, l'appelant décide (l'omettre, ou
//                               la chercher dans une liste qu'il a lue).

function partie(objet, absent) {
  if (objet === undefined || objet === null) return absent
  if (typeof objet !== 'object') return null
  return objet.libelle || null
}

export function activiteDuCreneau(creneau) {
  return creneau && typeof creneau === 'object' ? partie(creneau.activite, 'sans activité') : null
}

export function ressourceDuCreneau(creneau) {
  return creneau && typeof creneau === 'object' ? partie(creneau.ressource, 'sans ressource') : null
}

/** « Padel 90 min · Terrain padel n°1 (indoor) » — une partie qu'on ne sait pas nommer est omise. */
export function nomsDuCreneau(creneau) {
  return [activiteDuCreneau(creneau), ressourceDuCreneau(creneau)].filter(Boolean).join(' · ')
}
