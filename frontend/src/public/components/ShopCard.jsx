import { libelleProduit, libellePrix } from '../lib/format.js'

// LA CARTE D'UN BILLET DANS LA BOUTIQUE EN LIGNE — rendue par la vitrine publique ET par l'aperçu de la
// fiche produit (back-office). Extraite de `Vitrine.jsx` le 04/11 pour que l'aperçu montre la carte
// réelle, pas une imitation qui divergerait au premier correctif.
//
// `produit` a la forme d'une ligne du catalogue public (`CatalogueVitrineProvider`).
// Sans `onNaviguer`, la carte est un aperçu : le bouton devient un simple libellé, rien ne s'ouvre.
export default function ShopCard({ produit, langue, onNaviguer }) {
  const nom = libelleProduit(produit, langue)
  const enRupture = typeof produit.disponibilite === 'number' && produit.disponibilite <= 0
  const dispoConnue = typeof produit.disponibilite === 'number'
  const prix = libellePrix(produit.prix)
  const appel = enRupture ? 'Indisponible' : produit.timedEntry ? 'Choisir un horaire' : 'Voir le billet'

  return (
    <article className="bq-carte">
      <div className="bq-carte-img">
        {produit.visuel ? (
          <img src={produit.visuel} alt={nom} loading="lazy" />
        ) : (
          <span aria-hidden="true">{nom.slice(0, 1).toUpperCase()}</span>
        )}
      </div>
      <div className="bq-carte-b">
        <h2 className="bq-carte-t">{nom}</h2>
        {produit.code && <p className="bq-carte-code">{produit.code}</p>}
        {prix && <p className="bq-carte-prix">{prix}</p>}
        <div className="bq-carte-tags">
          {produit.timedEntry && <span className="badge info">Horaire à choisir</span>}
          {enRupture ? (
            <span className="badge crit">Épuisé</span>
          ) : dispoConnue ? (
            <span className="badge good">{produit.disponibilite} dispo.</span>
          ) : (
            <span className="badge mut">Disponible</span>
          )}
        </div>
        {onNaviguer ? (
          <button
            type="button"
            className="btn primary bq-carte-cta"
            disabled={enRupture}
            onClick={() => onNaviguer({ vue: 'produit', produitId: produit.produit })}
          >
            {appel}
          </button>
        ) : (
          <span className="btn primary bq-carte-cta" aria-hidden="true">{appel}</span>
        )}
      </div>
    </article>
  )
}
