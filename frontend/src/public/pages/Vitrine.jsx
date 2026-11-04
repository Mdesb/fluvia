import { useMemo, useState } from 'react'
import { libelleProduit } from '../lib/format.js'
import ShopCard from '../components/ShopCard.jsx'
import { Vide } from '../components/Etats.jsx'

// Catalogue public d'une vitrine (GET /boutique/vitrines/{id}/catalogue).
// Le back renvoie par produit : { produit(id), code, libelle, timedEntry, disponibilite(int|null),
// visuel(string|null), prix({min,max}|null) }. On affiche le libellé, la disponibilité, le prix
// (« À partir de … » si fourchette) et le visuel (avec repli générique).
export default function Vitrine({ catalogue, langue, onNaviguer }) {
  const [q, setQ] = useState('')
  const [dispoSeule, setDispoSeule] = useState(false)

  const produits = catalogue?.produits || []

  const filtres = useMemo(() => {
    const texte = q.trim().toLowerCase()
    return produits.filter((p) => {
      const lib = libelleProduit(p, langue).toLowerCase()
      const code = String(p.code || '').toLowerCase()
      const okTexte = !texte || lib.includes(texte) || code.includes(texte)
      const enRupture = typeof p.disponibilite === 'number' && p.disponibilite <= 0
      const okDispo = !dispoSeule || !enRupture
      return okTexte && okDispo
    })
  }, [produits, q, dispoSeule, langue])

  return (
    <section aria-labelledby="bq-cat-titre">
      <div className="bq-view-head">
        <div>
          <h1 id="bq-cat-titre">Nos billets</h1>
          <p className="bq-sub">Choisissez votre offre et réservez en quelques minutes.</p>
        </div>
      </div>

      <div className="bq-filtres" role="search">
        <div className="field" style={{ margin: 0, flex: 1, minWidth: 220 }}>
          <label htmlFor="bq-recherche">Rechercher un billet</label>
          <input
            id="bq-recherche"
            className="input"
            type="search"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Nom, type de billet…"
          />
        </div>
        <label className="bq-check">
          <input
            type="checkbox"
            checked={dispoSeule}
            onChange={(e) => setDispoSeule(e.target.checked)}
          />
          <span>Disponibles uniquement</span>
        </label>
      </div>

      {produits.length === 0 ? (
        <Vide titre="Aucun billet en vente" texte="Cette boutique ne propose aucun billet pour le moment." />
      ) : filtres.length === 0 ? (
        <Vide titre="Aucun résultat" texte="Aucun billet ne correspond à votre recherche." />
      ) : (
        <ul className="bq-grille" aria-label="Liste des billets">
          {filtres.map((p) => (
            <li key={p.produit}>
              <ShopCard produit={p} langue={langue} onNaviguer={onNaviguer} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
