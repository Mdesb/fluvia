import { libelleProduit, libelleCreneau, iriId } from '../lib/format.js'
import { Erreur, Vide } from '../components/Etats.jsx'

// Panier : récap des lignes, modification de quantité, retrait, total.
// Le back n'expose pas de prix par ligne ni de total sur le panier (cf. rapport) : le montant
// définitif est affiché et confirmé à l'étape de paiement. On présente donc le détail article/quantité.
export default function Panier({
  panier,
  metaProduits,
  metaCreneaux,
  langue,
  busy,
  erreur,
  onRetirer,
  onModifier,
  onNaviguer,
}) {
  const lignes = panier?.lignes || []
  const nbArticles = lignes.reduce((n, l) => n + (l.quantite || 1), 0)

  if (lignes.length === 0) {
    return (
      <section aria-labelledby="pub-panier-titre">
        <h1 id="pub-panier-titre">Votre panier</h1>
        <Vide titre="Votre panier est vide" texte="Parcourez la boutique pour ajouter des billets." />
        <button type="button" className="btn primary" onClick={() => onNaviguer({ vue: 'vitrine' })}>
          Voir les billets
        </button>
      </section>
    )
  }

  return (
    <section aria-labelledby="pub-panier-titre">
      <h1 id="pub-panier-titre">Votre panier</h1>
      <p className="pub-sub">{nbArticles} article(s)</p>

      <Erreur message={erreur} />

      <div className="pub-panier">
        <ul className="pub-panier-lignes" aria-label="Articles du panier">
          {lignes.map((l) => {
            const meta = metaProduits?.[iriId(l.produit)]
            const nom = meta ? libelleProduit(meta, langue) : 'Billet'
            const cr = l.creneau ? metaCreneaux?.[iriId(l.creneau)] : null
            return (
              <li key={l.id} className="pub-panier-ligne">
                <div className="pub-panier-media" aria-hidden="true">
                  <span>{nom.slice(0, 1).toUpperCase()}</span>
                </div>
                <div className="pub-panier-info">
                  <p className="pub-panier-nom">{nom}</p>
                  {cr && <p className="pub-panier-cr">{libelleCreneau(cr.debut, cr.fin)}</p>}
                  {l.autorisationParentaleRequise && (
                    <span className="badge warn">Autorisation parentale requise</span>
                  )}
                </div>
                <div className="pub-panier-qty" role="group" aria-label={`Quantité pour ${nom}`}>
                  <button
                    type="button"
                    aria-label="Diminuer la quantité"
                    disabled={busy || (l.quantite || 1) <= 1}
                    onClick={() => onModifier(l, -1)}
                  >
                    −
                  </button>
                  <span aria-live="polite">{l.quantite || 1}</span>
                  <button
                    type="button"
                    aria-label="Augmenter la quantité"
                    disabled={busy}
                    onClick={() => onModifier(l, 1)}
                  >
                    +
                  </button>
                </div>
                <button
                  type="button"
                  className="pub-panier-rm"
                  aria-label={`Retirer ${nom} du panier`}
                  disabled={busy}
                  onClick={() => onRetirer(l.id)}
                >
                  Retirer
                </button>
              </li>
            )
          })}
        </ul>

        <aside className="pub-panier-recap card" aria-label="Récapitulatif">
          <div className="card-b">
            <div className="pub-recap-row">
              <span>Articles</span>
              <strong>{nbArticles}</strong>
            </div>
            <p className="hint" style={{ marginTop: 4 }}>
              Le montant total sera calculé et affiché à l'étape de paiement.
            </p>
            <button
              type="button"
              className="btn primary lg"
              style={{ marginTop: 14 }}
              disabled={busy}
              onClick={() => onNaviguer({ vue: 'tunnel' })}
            >
              Passer la commande
            </button>
            <button
              type="button"
              className="btn lg"
              style={{ marginTop: 8 }}
              onClick={() => onNaviguer({ vue: 'vitrine' })}
            >
              Continuer mes achats
            </button>
          </div>
        </aside>
      </div>
    </section>
  )
}
