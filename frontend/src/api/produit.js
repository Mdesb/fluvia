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
