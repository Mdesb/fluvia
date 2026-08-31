import { libelleProduit, libelleCreneau, iriId, euros } from '../lib/format.js'
import { Erreur, Vide } from '../components/Etats.jsx'

// Panier : récap des lignes, modification de quantité, retrait, total.
// Le back enrichit désormais le panier : `total` (string) et, par ligne, `prixUnitaire` +
// `montantLigne`. On affiche le prix unitaire, le montant par ligne et le total en euros.
export default function Panier({
  panier,
  metaProduits,
  metaCreneaux,
  langue,
  busy,
  erreur,
  onRetirer,
  onVider,
  onModifier,
  onNaviguer,
}) {
  const lignes = panier?.lignes || []
  const nbArticles = lignes.reduce((n, l) => n + (l.quantite || 1), 0)
  const total = panier?.total

  if (lignes.length === 0) {
    return (
      <section aria-labelledby="bq-panier-titre">
        <h1 id="bq-panier-titre">Votre panier</h1>
        <Vide titre="Votre panier est vide" texte="Parcourez la boutique pour ajouter des billets." />
        <button type="button" className="btn primary" onClick={() => onNaviguer({ vue: 'vitrine' })}>
          Voir les billets
        </button>
      </section>
    )
  }

  return (
    <section aria-labelledby="bq-panier-titre">
      <h1 id="bq-panier-titre">Votre panier</h1>
      <p className="bq-sub">{nbArticles} article(s)</p>

      <Erreur message={erreur} />

      <div className="bq-panier">
        <ul className="bq-panier-lignes" aria-label="Articles du panier">
          {lignes.map((l) => {
            const meta = metaProduits?.[iriId(l.produit)]
            const nom = meta ? libelleProduit(meta, langue) : 'Billet'
            const cr = l.creneau ? metaCreneaux?.[iriId(l.creneau)] : null
            return (
              <li key={l.id} className="bq-panier-ligne">
                <div className="bq-panier-media" aria-hidden="true">
                  <span>{nom.slice(0, 1).toUpperCase()}</span>
                </div>
                <div className="bq-panier-info">
                  <p className="bq-panier-nom">{nom}</p>
                  {cr && <p className="bq-panier-cr">{libelleCreneau(cr.debut, cr.fin)}</p>}
                  {l.prixUnitaire != null && (
                    <p className="bq-panier-pu">{euros(l.prixUnitaire)} l'unité</p>
                  )}
                  {l.autorisationParentaleRequise && (
                    <span className="badge warn">Autorisation parentale requise</span>
                  )}
                </div>
                <div className="bq-panier-qty" role="group" aria-label={`Quantité pour ${nom}`}>
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
                {l.montantLigne != null && (
                  <p className="bq-panier-montant" aria-label={`Montant pour ${nom}`}>
                    {euros(l.montantLigne)}
                  </p>
                )}
                <button
                  type="button"
                  className="bq-panier-rm"
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

        <aside className="bq-panier-recap card" aria-label="Récapitulatif">
          <div className="card-b">
            <div className="bq-recap-row">
              <span>Articles</span>
              <strong>{nbArticles}</strong>
            </div>
            {total != null ? (
              <div className="bq-recap-row bq-recap-total" style={{ marginTop: 8 }}>
                <span>Total</span>
                <strong>{euros(total)}</strong>
              </div>
            ) : (
              <p className="hint" style={{ marginTop: 4 }}>
                Le montant total sera calculé et affiché à l'étape de paiement.
              </p>
            )}
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
            {onVider && lignes.length > 1 && (
              // LE BOUTON N'APPARAIT QU'A PARTIR DE DEUX ARTICLES.
              //
              // Avec un seul, << retirer >> est deja le geste juste : offrir les deux cote a cote
              // obligerait a choisir entre deux boutons qui font la meme chose. Et il est place en
              // DERNIER, en retrait : c'est le geste qu'on regrette, pas celui qu'on cherche.
              <button
                type="button"
                className="btn lg"
                style={{ marginTop: 8 }}
                disabled={busy}
                onClick={() => {
                  if (window.confirm('Vider le panier — tous les articles seront retirés. Continuer ?')) onVider()
                }}
              >
                Vider le panier
              </button>
            )}
          </div>
        </aside>
      </div>
    </section>
  )
}
