// Lecture des droits effectifs rendus par `/me`.
//
// POURQUOI CE FICHIER EXISTE, ET CE QU'IL A COÛTÉ. J'ai ajouté des contraintes de droits sur les
// entrées de menu en testant l'égalité stricte : `droits.includes('caisse.lire')`. C'était faux, et
// ça a vidé la colonne de gauche d'un utilisateur qui avait pourtant tous les droits.
//
// La raison : le socle accorde des permissions JOKER. `SocleFixtures` crée une permission
// `*` × `lire`, dont le code effectif est `*.lire`. Un administrateur porte donc `*.lire` et non
// `caisse.lire` — l'égalité stricte ne pouvait pas la voir. Le serveur, lui, l'interprète
// correctement, si bien que l'API répondait pendant que le menu prétendait que rien n'existait.
//
// La leçon est plus large que le correctif : quand le front rejoue une règle d'autorisation du
// serveur, il doit rejouer la RÈGLE ENTIÈRE, pas la moitié qu'on a en tête. À défaut, il vaut mieux
// ne rien filtrer du tout — un menu trop permissif se corrige par un refus d'API, un menu vide
// enferme l'utilisateur hors de son propre logiciel.

// Un code accordé couvre-t-il le code demandé ? Gère `*.lire`, `caisse.*` et `*` / `*.*`.
function couvre(accorde, demande) {
  if (accorde === demande || accorde === '*' || accorde === '*.*') return true
  const [mA, aA] = accorde.split('.')
  const [mD, aD] = demande.split('.')
  return (mA === '*' || mA === mD) && (aA === '*' || aA === aD)
}

// ⚠ CETTE FONCTION NE SAIT PAS DIRE « JE NE SAIS PAS ENCORE », ET C'EST UN CHOIX QU'IL FAUT TENIR.
//
// `droits || []` fait rendre `false` aussi bien pour « on me l'a refusé » que pour « le profil n'est
// pas encore chargé ». Un appelant ne peut donc pas distinguer les deux. Le 29/08, ce défaut a
// coûté un lien profond : la garde d'onglet de `App.jsx` lisait `droits = []` pendant le chargement,
// concluait « permission absente » et renvoyait à la caisse avant que le serveur ait répondu.
//
// L'INVARIANT QUI REND CE COMPORTEMENT SUPPORTABLE, ET QUI SE VÉRIFIE :
//
//   AUCUN ÉCRAN N'EST MONTÉ TANT QUE `me` N'EST PAS CHARGÉ.
//
// `App.jsx` retourne un `spinner` tant que `booting` est vrai, et `booting` ne tombe qu'après
// `setMe(await api.me())`. Les trois autres écritures de `me` le REMPLACENT sans jamais le vider ;
// la seule qui pose `null` est `deconnexion()`, qui bascule aussi `authed` à faux et fait rendre
// l'écran de connexion. Vérifié site par site le 29/08, pas supposé — c'est ce qui distingue les
// soixante-quinze appels des écrans, qui ne peuvent PAS voir de droits vides, des effets de
// `App.jsx`, qui tournent hors de cette garde et qui l'ont vu.
//
// CE QUI SE PASSE SI QUELQU'UN CASSE L'INVARIANT. Les boutons disparaissent une fraction de seconde
// puis reviennent : désagréable, jamais dangereux. Mais une REDIRECTION, une SUPPRESSION ou une
// écriture déclenchée sur cette même valeur ne se rattrape pas. D'où la règle :
//
//   ON PEUT MASQUER SUR « FAUX ». ON NE REDIRIGE JAMAIS, ON NE DÉTRUIT JAMAIS SUR « FAUX ».
//
// Un écran trop permissif se corrige par un refus d'API ; un écran qui vous éjecte vous enferme
// dehors. C'est la même règle que celle du haut de ce fichier, appliquée à l'autre bout du
// problème : là c'était l'égalité stricte, ici c'est l'ignorance présentée comme une réponse.
export function aLeDroit(droits, code) {
  if (!code) return true
  return (droits || []).some((d) => couvre(d, code))
}

export function aUnDesDroits(droits, codes) {
  if (!codes || codes.length === 0) return true
  return codes.some((c) => aLeDroit(droits, c))
}
