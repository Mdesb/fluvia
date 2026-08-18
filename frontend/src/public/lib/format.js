// Helpers d'affichage pour la boutique publique.

// Libellé produit : le back renvoie soit une chaîne, soit un objet i18n { fr, en... }.
export function libelleProduit(p, langue = 'fr') {
  const l = p?.libelle
  if (!l) return p?.code || 'Produit'
  if (typeof l === 'string') return l
  return l[langue] || l.fr || Object.values(l)[0] || p?.code || 'Produit'
}

// Extrait l'UUID final d'un IRI API Platform (« /api/produits/{id} ») ou renvoie la valeur telle quelle.
export function iriId(valeur) {
  if (!valeur) return null
  if (typeof valeur === 'object') return valeur.id ? iriId(valeur.id) : null
  const s = String(valeur)
  return s.includes('/') ? s.split('/').filter(Boolean).pop() : s
}

// Montant en euros à partir de centimes (le back renvoie `montantCentimes` au paiement).
export function eurosCentimes(centimes) {
  if (centimes == null) return '—'
  const n = Number(centimes) / 100
  if (Number.isNaN(n)) return '—'
  return n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

// Montant en euros à partir d'un total décimal (ex. vente.total).
export function euros(v) {
  if (v == null || v === '') return '—'
  const n = typeof v === 'number' ? v : parseFloat(v)
  if (Number.isNaN(n)) return String(v)
  return n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

// Créneau « lun. 12 mai, 14:00 → 15:00 ».
export function libelleCreneau(debut, fin) {
  if (!debut) return ''
  try {
    const d = new Date(debut)
    const jour = d.toLocaleDateString('fr-FR', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
    })
    const h1 = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
    if (!fin) return `${jour}, ${h1}`
    const h2 = new Date(fin).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
    return `${jour}, ${h1} → ${h2}`
  } catch {
    return String(debut)
  }
}

export function dateCourte(iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    })
  } catch {
    return String(iso)
  }
}

// Nom lisible d'un bénéficiaire (structure simple invité).
export function nomBeneficiaire(b) {
  if (!b) return ''
  const parts = [b.prenom, b.nom].filter(Boolean)
  return parts.join(' ').trim()
}
