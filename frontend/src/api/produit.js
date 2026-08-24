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

export function statutProduit(p) {
  return STATUTS_PRODUIT[p?.statut] || { libelle: p?.statut || '—', ton: 'mut', aide: '' }
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
