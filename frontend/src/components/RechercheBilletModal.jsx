import { useState } from 'react'
import { api, membres } from '../api/client.js'
import { libelleProduit } from '../api/produit.js'
import { mot } from '../api/vocabulaire.js'
import Modal from './Modal.jsx'

// Vue rapide d'un billet ou d'une carte, à partir du numéro imprimé dessus.
//
// « Le produit, le type et le nombre d'entrées restantes, ou alors les dates si c'est un abonnement. »
// Le serveur sait tout cela — `DroitAcces` porte `sourceType`, `produitRef`, `creditRestant` et la
// fenêtre de validité — et rien ne l'affichait.
//
// CE QUI EST PERTINENT DÉPEND DU TYPE, et c'est le cœur de cet écran. Une carte à quota se juge sur
// ses entrées restantes ; un abonnement sur ses dates. Afficher les deux à chaque fois obligerait le
// lecteur à savoir lequel regarder — c'est le même raisonnement que les deux colonnes du no-show,
// mais dans l'autre sens : là il fallait séparer, ici il faut choisir.
//
// UNE CARTE A UN SOLDE, PAS UN HISTORIQUE DE CARTES (D23). Une recharge incrémente le droit existant,
// elle n'en crée jamais un second. Si plusieurs droits actifs apparaissent pour un même support, ce
// n'est pas une richesse à afficher : c'est un défaut, et l'écran le dit au lieu de le maquiller.

export default function RechercheBilletModal({ open, onClose }) {
  const [numero, setNumero] = useState('')
  const [resultat, setResultat] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)

  async function chercher(e) {
    e.preventDefault()
    const q = numero.trim()
    if (!q) return
    setChargement(true)
    setErreur(null)
    setResultat(null)
    try {
      const supports = membres(await api.supports({ identifiant: q }))
      if (supports.length === 0) {
        setResultat({ introuvable: true })
        return
      }
      const support = supports[0]

      // `Appairage` n'expose aucun filtre côté serveur : on charge et on croise ici. C'est tenable
      // aujourd'hui et ça ne le restera pas — signalé à l'intégrateur, avec le filtre qui manque.
      const appairages = membres(await api.appairages()).filter(
        (a) => a.actif !== false && String(a.support?.id) === String(support.id),
      )

      let produits = {}
      if (appairages.some((a) => a.droit?.produitRef)) {
        produits = Object.fromEntries(
          membres(await api.produits().catch(() => null) || []).map((p) => [String(p.id), libelleProduit(p)]),
        )
      }

      setResultat({ support, appairages, produits })
    } catch (err) {
      setErreur(err.message || 'La recherche n a pas abouti.')
    } finally {
      setChargement(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Vérifier un billet ou une carte" taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}

      <form onSubmit={chercher} style={{ display: 'flex', gap: 10, alignItems: 'end', marginBottom: 14 }}>
        <div className="field" style={{ margin: 0, flex: 1 }}>
          <label htmlFor="rb-num">Numéro du support</label>
          <input
            id="rb-num"
            className="input"
            autoFocus
            value={numero}
            placeholder="Tel qu'il est imprimé sur le billet ou la carte"
            onChange={(e) => setNumero(e.target.value)}
          />
        </div>
        <button className="btn primary" type="submit" disabled={chargement}>Vérifier</button>
      </form>

      {chargement && <div className="center" style={{ minHeight: 100 }}><div className="spinner" /></div>}

      {resultat?.introuvable && (
        <div className="empty" style={{ padding: 18 }}>
          Aucun support ne porte le numéro « {numero.trim()} ». Vérifiez la saisie : le numéro doit être
          repris exactement, majuscules et tirets compris.
        </div>
      )}

      {resultat?.support && (
        <>
          <div className="fiche-ident" style={{ marginBottom: 12 }}>
            <div>
              <div className="fiche-nom">{resultat.support.identifiant}</div>
              <div className="sub">{mot(resultat.support.type)}</div>
            </div>
            <span
              className={`badge ${resultat.support.statut === 'actif' ? 'good' : 'crit'}`}
              style={{ marginLeft: 'auto' }}
              title={
                resultat.support.statut === 'actif'
                  ? 'Ce support est utilisable.'
                  : "Ce support est bloqué : il sera refusé au contrôle d'accès."
              }
            >
              {mot(resultat.support.statut)}
            </span>
          </div>

          {resultat.appairages.length === 0 ? (
            <div className="empty" style={{ padding: 18 }}>
              Ce support existe mais aucun droit actif n'y est rattaché : il ne donne accès à rien.
              C'est le cas d'une carte vendue et pas encore chargée, ou d'un droit révoqué.
            </div>
          ) : (
            <>
              {/* D23 : une recharge incrémente le droit existant. Plusieurs droits actifs sur un même
                  support n'est pas un cas normal — on le nomme au lieu de l'afficher comme une liste. */}
              {resultat.appairages.length > 1 && (
                <div className="banner banner-error">
                  Ce support porte {resultat.appairages.length} droits actifs. Une carte ne devrait en
                  avoir qu'un seul : une recharge incrémente le droit existant, elle n'en crée pas un
                  second. À signaler.
                </div>
              )}
              {resultat.appairages.map((a) => (
                <Droit key={a.id} droit={a.droit} produits={resultat.produits} />
              ))}
            </>
          )}
        </>
      )}
    </Modal>
  )
}

function Droit({ droit, produits }) {
  if (!droit) return null
  const type = droit.sourceType
  const produit = droit.produitRef ? produits[String(droit.produitRef)] : null
  const valide = droit.statutProjection !== 'devalide'

  return (
    <div className="card" style={{ marginBottom: 10 }}>
      <div className="card-b">
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 10, marginBottom: 8 }}>
          <b>{produit || 'Produit inconnu'}</b>
          <span className="badge info">{mot(type)}</span>
          {!valide && (
            <span className="badge crit" title="Ce droit a été dévalidé : il sera refusé au contrôle.">
              dévalidé
            </span>
          )}
        </div>

        {/* Ce qui compte dépend du type : un quota se juge sur ce qui reste, un abonnement sur ses
            dates. Montrer les deux ferait chercher lequel lire. */}
        {type === 'carte_quota' ? (
          <div className="fiche-stats">
            <div>
              <div className="st-lib">Entrées restantes</div>
              <div className="st-val num" style={droit.creditRestant === 0 ? { color: 'var(--crit)' } : undefined}>
                {droit.creditRestant ?? '—'}
              </div>
            </div>
            {droit.fenetreFin && (
              <div>
                <div className="st-lib" title="Au-delà de cette date, les entrées restantes ne sont plus utilisables.">
                  Utilisable jusqu'au
                </div>
                <div className="st-val">{dateFr(droit.fenetreFin)}</div>
              </div>
            )}
          </div>
        ) : (
          <div className="fiche-stats">
            <div>
              <div className="st-lib">Valable du</div>
              <div className="st-val">{dateFr(droit.fenetreDebut)}</div>
            </div>
            <div>
              <div className="st-lib">Jusqu'au</div>
              <div className="st-val">{dateFr(droit.fenetreFin)}</div>
            </div>
            {droit.creditRestant != null && (
              <div>
                <div className="st-lib">Entrées restantes</div>
                <div className="st-val num">{droit.creditRestant}</div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function dateFr(v) {
  if (!v) return 'sans limite'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('fr-FR')
}
