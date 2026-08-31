import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres, ApiError } from '../../api/client.js'

// La facturation des abonnements (ED-7).
//
// CE QUE CET ÉCRAN MONTRE EN PREMIER : CE QUI N'A PAS EU LIEU. Un abonnement actif qu'on a oublié de
// facturer ne produit aucun signal — pas d'erreur, pas d'alerte, juste de l'argent jamais prélevé
// qu'on découvre en rapprochant les comptes trois mois plus tard. C'est le même raisonnement que la
// liste d'abonnements, qui remonte les provisionnements échoués : le manque est plus intéressant que
// la réussite, et il est le seul à ne pas savoir se signaler tout seul.
//
// LE TAUX DE TVA EST DEMANDÉ, JAMAIS DEVINÉ. Un profil français porte couramment quatre taux actifs.
// En choisir un à la place de l'exploitant reviendrait à facturer au mauvais taux, ce qui se corrige
// par un avoir et se voit sur une déclaration.

const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' })
const prix = (c) => euros.format((c || 0) / 100)

function moisCourant() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

function moisLisible(ym) {
  const [a, m] = ym.split('-')
  return new Date(Number(a), Number(m) - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
}

export default function Facturation({ onRefus }) {
  const [mois, setMois] = useState(moisCourant())
  const [lignes, setLignes] = useState(null)
  const [taux, setTaux] = useState([])
  const [tauxChoisi, setTauxChoisi] = useState('')
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(null)
  const [refus, setRefus] = useState(null)

  const charger = useCallback(async () => {
    try {
      setLignes(membres(await api.editorBilling(mois)))
      setErreur(null)
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) {
        onRefus?.()
        return
      }
      setErreur(e)
    }
  }, [mois, onRefus])

  useEffect(() => {
    charger()
  }, [charger])

  useEffect(() => {
    api
      .tauxTvas()
      .then((c) => setTaux(membres(c).filter((t) => t.actif !== false)))
      .catch(() => setTaux([]))
  }, [])

  const aFacturer = useMemo(() => (lignes || []).filter((l) => !l.invoiced), [lignes])
  const total = useMemo(
    () => aFacturer.reduce((s, l) => s + (l.expectedCents || 0), 0),
    [aFacturer],
  )

  async function emettre(ligne) {
    setRefus(null)
    setEnCours(ligne.subscriptionId)
    try {
      await api.emettreFactureAbonnement({
        subscriptionId: ligne.subscriptionId,
        month: mois,
        tauxTvaId: tauxChoisi || undefined,
      })
      await charger()
    } catch (e) {
      // Le serveur écrit ses refus pour l'exploitant : « précisez le taux », « configurez-en un ».
      // On les affiche tels quels plutôt que de les traduire en « une erreur est survenue ».
      setRefus(e?.payload?.detail || e?.message || 'La facture n\'a pas pu être émise.')
    } finally {
      setEnCours(null)
    }
  }

  if (erreur) return <div className="banner crit">La facturation n'a pas pu être chargée.</div>
  if (!lignes) return <div className="center"><div className="spinner" /></div>

  return (
    <>
      {aFacturer.length > 0 && (
        <div className="banner warn">
          <strong>
            {aFacturer.length === 1
              ? '1 abonnement n’est pas encore facturé'
              : `${aFacturer.length} abonnements ne sont pas encore facturés`}
          </strong>{' '}
          pour {moisLisible(mois)} — {prix(total)} à émettre.
        </div>
      )}

      {refus && <div className="banner crit">{refus}</div>}

      <div className="card">
        <div className="card-h">
          <h2>Facturation</h2>
          <div className="editeur-compte">
            <div className="field">
              <label htmlFor="fact-mois">Mois</label>
              <input id="fact-mois" type="month" value={mois} onChange={(e) => setMois(e.target.value)} />
            </div>
            <div className="field">
              <label htmlFor="fact-taux">Taux de TVA</label>
              <select id="fact-taux" value={tauxChoisi} onChange={(e) => setTauxChoisi(e.target.value)}>
                <option value="">À choisir…</option>
                {taux.map((t) => (
                  <option key={t.id} value={String(t.id).split('/').pop()}>
                    {t.libelle}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>

        <div className="card-b" style={{ overflowX: 'auto' }}>
          {lignes.length === 0 ? (
            <div className="empty">
              <p>Aucun abonnement à facturer ce mois-ci. Les abonnements actifs apparaissent ici dès qu'ils existent.</p>
            </div>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Formule</th>
                  <th style={{ textAlign: 'right' }}>Attendu</th>
                  <th>Facture</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {lignes.map((l) => (
                  <tr key={l.subscriptionId} className={!l.invoiced ? 'ligne-alerte' : undefined}>
                    <td><strong>{l.customerName}</strong></td>
                    <td>{l.planLabel || '—'}</td>
                    <td style={{ textAlign: 'right' }}>{prix(l.expectedCents)}</td>
                    <td>
                      {l.invoiced ? (
                        <>
                          <span className="badge good">{l.invoiceNumber || 'Émise'}</span>
                          {/* L'écart entre l'attendu et le facturé n'est pas une erreur : une facture
                              émise avant un changement de tarif porte l'ancien montant. On le montre
                              plutôt que de le masquer. */}
                          {l.invoicedCents !== null && l.invoicedCents !== l.expectedCents && (
                            <div className="hint">Facturé {prix(l.invoicedCents)}</div>
                          )}
                        </>
                      ) : (
                        <span className="badge crit">À émettre</span>
                      )}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {!l.invoiced && (
                        <button
                          type="button"
                          className="btn primary sm"
                          disabled={enCours === l.subscriptionId}
                          onClick={() => emettre(l)}
                        >
                          {enCours === l.subscriptionId ? 'Émission…' : 'Émettre'}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </>
  )
}
