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

export function aLeDroit(droits, code) {
  if (!code) return true
  return (droits || []).some((d) => couvre(d, code))
}

export function aUnDesDroits(droits, codes) {
  if (!codes || codes.length === 0) return true
  return codes.some((c) => aLeDroit(droits, c))
}
