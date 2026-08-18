import { useMemo, useState } from 'react'
import { libelleProduit, libellePrix } from '../lib/format.js'
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
    <section aria-labelledby="pub-cat-titre">
      <div className="pub-view-head">
        <div>
          <h1 id="pub-cat-titre">Nos billets</h1>
          <p className="pub-sub">Choisissez votre offre et réservez en quelques minutes.</p>
        </div>
      </div>

      <div className="pub-filtres" role="search">
        <div className="field" style={{ margin: 0, flex: 1, minWidth: 220 }}>
          <label htmlFor="pub-recherche">Rechercher un billet</label>
          <input
            id="pub-recherche"
            className="input"
            type="search"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Nom, type de billet…"
          />
        </div>
        <label className="pub-check">
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
        <ul className="pub-grille" aria-label="Liste des billets">
          {filtres.map((p) => (
            <li key={p.produit}>
              <ProduitCarte produit={p} langue={langue} onNaviguer={onNaviguer} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function ProduitCarte({ produit, langue, onNaviguer }) {
  const nom = libelleProduit(produit, langue)
  const enRupture = typeof produit.disponibilite === 'number' && produit.disponibilite <= 0
  const dispoConnue = typeof produit.disponibilite === 'number'
  const prix = libellePrix(produit.prix)

  return (
    <article className="pub-carte">
      <div className="pub-carte-img">
        {produit.visuel ? (
          <img src={produit.visuel} alt={nom} loading="lazy" />
        ) : (
          <span aria-hidden="true">{nom.slice(0, 1).toUpperCase()}</span>
        )}
      </div>
      <div className="pub-carte-b">
        <h2 className="pub-carte-t">{nom}</h2>
        {produit.code && <p className="pub-carte-code">{produit.code}</p>}
        {prix && <p className="pub-carte-prix">{prix}</p>}
        <div className="pub-carte-tags">
          {produit.timedEntry && <span className="badge info">Horaire à choisir</span>}
          {enRupture ? (
            <span className="badge crit">Épuisé</span>
          ) : dispoConnue ? (
            <span className="badge good">{produit.disponibilite} dispo.</span>
          ) : (
            <span className="badge mut">Disponible</span>
          )}
        </div>
        <button
          type="button"
          className="btn primary pub-carte-cta"
          disabled={enRupture}
          onClick={() => onNaviguer({ vue: 'produit', produitId: produit.produit })}
        >
          {enRupture ? 'Indisponible' : produit.timedEntry ? 'Choisir un horaire' : 'Voir le billet'}
        </button>
      </div>
    </article>
  )
}
