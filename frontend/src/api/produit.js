// Helpers de lecture des produits (structure API Platform).

export function libelleProduit(p) {
  const l = p?.libelle
  if (!l) return p?.code || 'Produit'
  if (typeof l === 'string') return l
  return l.fr || Object.values(l)[0] || p?.code || 'Produit'
}

// Première grille tarifaire exploitable (prix + typeTarif) pour la vente au guichet.
export function premiereGrille(p) {
  const grilles = p?.grilles || []
  return grilles.find((g) => g?.typeTarif?.id && g?.prix != null) || grilles[0] || null
}

export function prixIndicatif(p) {
  const g = premiereGrille(p)
  return g?.prix != null ? g.prix : null
}

// Toutes les lignes tarifaires exploitables au guichet, pas seulement la premiere.
//
// `premiereGrille` existait et suffisait tant que l'ecran n'offrait aucun choix. C'est precisement ce
// « suffisait » qui a force les exploitants a dupliquer leurs produits.
export function grillesVendables(p) {
  return (p?.grilles || []).filter((g) => g?.typeTarif?.id && g?.prix != null)
}

export function libelleTarif(g) {
  return g?.typeTarif?.nom || g?.typeTarif?.code || 'Tarif'
}

export function typeTarifId(p) {
  const g = premiereGrille(p)
  return g?.typeTarif?.id || null
}

// Un produit est vendable au guichet s'il a une grille tarifaire et n'est pas en rupture.
export function estVendable(p) {
  if (!typeTarifId(p)) return false
  const stock = p?.stock
  if (stock && typeof stock.disponibilite === 'number' && stock.disponibilite <= 0) return false
  return true
}

export function raisonNonVendable(p) {
  if (!typeTarifId(p)) return 'Pas de tarif au guichet'
  const stock = p?.stock
  if (stock && typeof stock.disponibilite === 'number' && stock.disponibilite <= 0) return 'Rupture de stock'
  return null
}

export function euros(v) {
  if (v == null || v === '') return '—'
  const n = typeof v === 'number' ? v : parseFloat(v)
  if (Number.isNaN(n)) return String(v)
  return n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

/* ------------------------------------------------ Cycle de vie (StatutProduit, côté serveur) */

// Le libellé et l'aide sont explicites parce que « brouillon » ne dit pas ce qu'il EMPÊCHE. Un
// exploitant doit pouvoir comprendre, sans documentation et sans nous appeler, pourquoi le produit
// qu'il vient de créer n'apparaît nulle part.
export const STATUTS_PRODUIT = {
  brouillon: {
    libelle: 'Brouillon',
    ton: 'mut',
    aide: "Visible de vous seul. Le produit n'est en vente sur aucun canal tant qu'il n'est pas publié.",
  },
  publie: {
    libelle: 'Publié',
    ton: 'good',
    aide: 'En vente sur les canaux configurés pour ce produit.',
  },
  archive: {
    libelle: 'Archivé',
    ton: 'mut',
    aide: "Retiré de la vente. Les ventes passées et leur historique sont conservés.",
  },
}

// PUBLIE SANS AUCUN TARIF : L'ECRAN PROMETTAIT UNE MISE EN VENTE IMPOSSIBLE.
//
// `STATUTS_PRODUIT.publie` dit << En vente sur les canaux configures pour ce produit >>, en vert.
// Un produit sans grille tarifaire n'est vendable NULLE PART -- `estVendable` le dit vingt lignes
// plus haut -- et `actionsStatut('brouillon')` annonce meme que publier << exige un tarif >>.
// L'application enonce donc la regle, et l'affichage promet le contraire.
//
// Ce n'est pas un cas de bord : mesure du 30/08 sur les quatre etablissements, 3 produits publies
// sur 8 n'ont aucune grille -- PRD-AUDIOGUIDE, PRD-PASS-MUSEE, PRD-EXPO-EGYPTE, tous ouverts au
// guichet ET en ligne, tous sans le moindre prix. Le badge vert les declarait en vente.
//
// ⚠ TROIS ETATS, PAS DEUX : `null` VEUT DIRE << JE NE SAIS PAS >>.
//
// Si la charge utile ne porte pas `grilles` -- groupe de serialisation plus etroit, reponse
// partielle -- alors l'absence du champ ne dit RIEN de l'absence de tarif. Repondre `true` ferait
// crier au defaut sur des produits parfaitement tarifes : on remplacerait un mensonge par l'autre,
// dans l'autre sens. Champ absent n'est pas valeur absente.
export function sansTarifConnu(p) {
  if (!p || !Array.isArray(p.grilles)) return null
  return grillesVendables(p).length === 0
}

export function statutProduit(p) {
  const base = STATUTS_PRODUIT[p?.statut] || { libelle: p?.statut || '—', ton: 'mut', aide: '' }
  if (p?.statut === 'publie' && sansTarifConnu(p) === true) {
    return {
      // Le libelle NE CHANGE PAS : le statut serveur est bien << publie >>, et le renommer ferait
      // chercher un etat qui n'existe pas. C'est le TON et l'AIDE qui disent ce qui cloche.
      libelle: base.libelle,
      ton: 'warn',
      aide: "Publié, mais sans aucun tarif : ce produit n'est vendable sur aucun canal, "
        + 'ni au guichet ni en ligne. Ajoutez-lui une grille tarifaire.',
    }
  }
  return base
}

// Actions offertes pour un statut donné.
//
// Une action qui n'a pas de sens est ABSENTE, jamais grisée : on ne propose pas « publier » sur un
// produit déjà publié pour ensuite le refuser. Un bouton grisé fait chercher ce qui manque ; un
// bouton absent ne pose aucune question.
export function actionsStatut(statut) {
  switch (statut) {
    case 'brouillon':
      return [
        {
          id: 'publier',
          libelle: 'Publier',
          ton: 'primary',
          aide: 'Met le produit en vente sur ses canaux. Exige un tarif et un site de commercialisation.',
          confirme: 'produit publié.',
        },
      ]
    case 'publie':
      return [
        {
          id: 'depublier',
          libelle: 'Dépublier',
          ton: 'ghost',
          aide: 'Retire le produit de la vente et le repasse en brouillon. Réversible.',
          confirme: 'produit repassé en brouillon.',
        },
        {
          id: 'archiver',
          libelle: 'Archiver',
          ton: 'ghost',
          aide: "Retire le produit de la liste courante. L'historique est conservé.",
          confirmation: 'Archiver « %s » ? Il sortira de la vente et de la liste courante.',
          confirme: 'produit archivé.',
        },
      ]
    case 'archive':
      return [
        {
          id: 'reactiver',
          libelle: 'Réactiver',
          ton: 'primary',
          aide: 'Sort le produit des archives et le repasse en brouillon.',
          confirme: 'produit réactivé, en brouillon.',
        },
      ]
    default:
      return []
  }
}

// Pourquoi un produit ne peut pas être vendu, en disant quoi faire.
//
// « Pas de tarif au guichet » nomme ce qui manque sans dire où le corriger : quelqu'un qui découvre
// le logiciel cherche alors dans la caisse, où il n'y a rien à trouver. L'infobulle envoie au bon
// endroit. C'est la différence entre un message juste et un message utile.
export function expliqueNonVendable(p) {
  const raison = raisonNonVendable(p)
  if (!raison) return null
  if (raison === 'Pas de tarif au guichet') {
    return "Ce produit n'a pas de tarif pour le canal guichet. Ajoutez-lui une grille tarifaire depuis le Catalogue."
  }
  if (raison === 'Rupture de stock') {
    return 'La quantité disponible est épuisée. Réapprovisionnez depuis le module Stock, ou retirez le suivi de stock sur ce produit.'
  }
  return raison
}
