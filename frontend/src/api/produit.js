// Helpers de lecture des produits (structure API Platform).

// Les canaux de vente d'un produit, tels que la fiche et l'écran de création les proposent.
export const CANAUX_PRODUIT = [
  { valeur: 'guichet', libelle: 'Au guichet' },
  { valeur: 'en_ligne', libelle: 'En ligne' },
  { valeur: 'borne', libelle: 'Sur borne' },
]

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
// ⚠ ON DEMANDE UNE GRILLE *EXPLOITABLE*, PAS UN `typeTarif` QUI TRAINE.
//
// Ces deux fonctions testaient `typeTarifId(p)`, qui passe par `premiereGrille` — laquelle SE
// REPLIE sur `grilles[0]` quand aucune grille ne porte a la fois un type et un prix. Un produit
// dont l'unique tarif n'a pas de prix etait donc declare VENDABLE : sa tuile s'affichait avec
// « — » a la place du montant, et le caissier pouvait cliquer dessus.
//
// Trouve en EXECUTANT la fonction sur sept formes de produit, pas en la relisant. Zero cas en base
// le 03/09 (les 16 grilles de la preprod portent un prix) : le defaut etait LATENT, et il suffisait
// d'une grille sans prix pour le reveiller — d'autant plus depuis que la caisse MASQUE ce qui n'est
// pas vendable (R2), ou une erreur de ce cote fait reapparaitre un produit qu'on croyait ecarte.
//
// `grillesVendables` dit deja « typeTarif ET prix ». Les deux fonctions s'appuient dessus, donc
// elles ne peuvent plus se contredire. `premiereGrille` et `prixIndicatif` gardent leur repli :
// il sert a afficher « — » plutot que rien, et ils ont d'autres appelants.
export function estVendable(p) {
  if (grillesVendables(p).length === 0) return false
  const stock = p?.stock
  if (stock && typeof stock.disponibilite === 'number' && stock.disponibilite <= 0) return false
  return true
}

export function raisonNonVendable(p) {
  if (grillesVendables(p).length === 0) return 'Pas de tarif au guichet'
  const stock = p?.stock
  if (stock && typeof stock.disponibilite === 'number' && stock.disponibilite <= 0) return 'Rupture de stock'
  return null
}

/**
 * Un montant en CENTIMES, formate en euros.
 *
 * ⚠ CETTE FONCTION EXISTE PARCE QUE CINQ ECRANS LA REECRIVAIENT CHACUN A SA FACON.
 *
 * Trois d'entre eux rendaient `(c / 100).toFixed(2).replace('.', ',') + ' €'` : pas de separateur
 * de milliers (`12345,60 €` au lieu de `12 345,60 €`), et surtout `0,00 €` pour un montant NUL,
 * `NaN €` pour un montant ABSENT. Or « je ne sais pas » et « c'est zero » ne veulent pas du tout
 * dire la meme chose sur un impaye — et se ressemblent a l'oeil.
 *
 * Le tiret cadratin de `euros()` dit l'inconnu. On delegue donc, plutot que de le reecrire.
 *
 * ⚠ ET L'UNITE EST DANS LE NOM. `euros(1234)` et `centimes(1234)` rendent deux montants differents
 * d'un facteur cent ; le seul moyen de ne pas s'y tromper est que l'appelant lise l'unite au moment
 * ou il choisit la fonction.
 */
export function centimes(c) {
  if (c == null || c === '') return '—'
  const n = typeof c === 'number' ? c : parseFloat(c)
  if (Number.isNaN(n)) return '—'
  return euros(n / 100)
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
          droit: 'offre.publier',
          libelle: 'Publier',
          ton: 'primary',
          aide: 'Met le produit en vente sur ses canaux. Exige un tarif et un site de commercialisation.',
          confirme: 'produit publié.',
        },
        // ⚠ ABSENT JUSQU'AU 01/09, ET LE SERVEUR L'ACCEPTAIT DEPUIS TOUJOURS.
        //
        // `TransitionProduitHandler::archiver()` n'exige aucun statut de départ. Sans cette entrée,
        // retirer un brouillon abandonné de la liste imposait de le PUBLIER d'abord — donc de le
        // mettre en vente — puis de l'archiver. Ce n'était pas une règle métier, c'était une ligne
        // manquante ici.
        //
        // Le bouton « Nouveau produit » rend la création immédiate : une hésitation laisse une
        // ligne, et il fallait un chemin pour l'enlever qui ne passe pas par la vitrine.
        {
          id: 'archiver',
          droit: 'offre.archiver',
          libelle: 'Archiver',
          ton: 'ghost',
          aide: "Retire le produit de la liste courante. Rien n'est supprimé, et il se réactive.",
          // ⚠ PAS LE MEME TEXTE QUE POUR UN PRODUIT PUBLIE. Celui-là dit « il sortira de la
          // vente » — dit d'un brouillon, il annonce une conséquence qui n'existe pas, puisqu'un
          // brouillon n'est en vente nulle part. Un message qui décrit autre chose que ce qui se
          // passe apprend à ne plus lire les messages.
          confirmation: 'Archiver « %s » ? Ce brouillon sortira de la liste courante ; vous pourrez le réactiver.',
          confirme: 'brouillon archivé.',
        },
      ]
    case 'publie':
      return [
        {
          id: 'depublier',
          droit: 'offre.publier',
          libelle: 'Dépublier',
          ton: 'ghost',
          aide: 'Retire le produit de la vente et le repasse en brouillon. Réversible.',
          confirme: 'produit repassé en brouillon.',
        },
        {
          id: 'archiver',
          droit: 'offre.archiver',
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
          droit: 'offre.modifier',
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
