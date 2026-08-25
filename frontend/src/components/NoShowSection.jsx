import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { euros } from '../api/produit.js'
import { aLeDroit } from '../api/droits.js'

// No-show : facturer ou exonérer, et voir ce qu'il advient du crédit.
//
// Maxime a arbitré ce comportement (D27) et ne pouvait pas le voir : les deux opérations existaient
// côté serveur et n'étaient appelées de nulle part.
//
// LES DEUX DIMENSIONS SONT ORTHOGONALES, ET C'EST TOUT L'ENJEU DE CET ÉCRAN.
//
// « Facturé ou exonéré » répond à la question de l'argent. « Décompté, restitué, restitué avec
// report » répond à celle du crédit — la séance prépayée est-elle perdue ? Les deux se combinent
// librement : on peut exonérer et décompter, facturer et restituer. Les mêler dans une seule colonne
// d'état ferait croire qu'exonérer rend la séance, ce qui est faux.
//
// C'est précisément la décision que Maxime a prise : un no-show sur séance prépayée restitue le crédit
// ET propose un report. S'il ne voit qu'une colonne, il ne peut pas vérifier que sa règle s'applique.

const ETATS = {
  a_facturer: { libelle: 'À traiter', ton: 'warn' },
  facturee: { libelle: 'Facturé', ton: 'good' },
  exoneree: { libelle: 'Exonéré', ton: 'mut' },
  contestee: { libelle: 'Contesté', ton: 'crit' },
}

const ISSUES_CREDIT = {
  decremented: {
    libelle: 'Séance décomptée',
    aide: "La séance prépayée est consommée : le client l'a perdue.",
    ton: 'crit',
  },
  restored: {
    libelle: 'Séance restituée',
    aide: 'La séance est rendue au client, sans proposition de nouveau créneau.',
    ton: 'good',
  },
  restored_with_reschedule: {
    libelle: 'Restituée avec report',
    aide: 'La séance est rendue et un nouveau créneau est proposé au client.',
    ton: 'good',
  },
}

const MODES = {
  vente_differee_agent: 'Vente différée en caisse',
  debit_pmv: 'Débit du porte-monnaie',
  prelevement_differe: 'Prélèvement différé',
  facture_a_encaisser: 'Facture à encaisser',
}

export default function NoShowSection({ etabActif, droits = [], session }) {
  const [lignes, setLignes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [exoneration, setExoneration] = useState(null) // { ligne, motif }
  const [enCours, setEnCours] = useState(null)

  const peutFacturer = aLeDroit(droits, 'reservation.facturer')
  const peutExonerer = aLeDroit(droits, 'reservation.exonerer')

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setLignes(membres(await api.facturationsNoShow()))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [etabActif, recharger])

  async function facturer(l) {
    setErreur(null)
    setSucces(null)
    setEnCours(l.id)
    try {
      // Le mode « vente différée en caisse » exige une session de caisse ouverte : le serveur la lit
      // dans le corps. On l'envoie quand on en a une ; sinon le serveur refusera en disant pourquoi,
      // et c'est son message qu'on affichera.
      const corps = session?.id ? { session: `/api/session_caisses/${session.id}` } : {}
      await api.emettreVenteNoShow(l.id, corps)
      setSucces('Facturation émise.')
      await recharger()
    } catch (e) {
      setErreur(e.message || "La facturation n'a pas abouti.")
    } finally {
      setEnCours(null)
    }
  }

  async function exonerer(e) {
    e.preventDefault()
    setErreur(null)
    setSucces(null)
    setEnCours(exoneration.ligne.id)
    try {
      await api.exonererNoShow(exoneration.ligne.id, { motif: exoneration.motif.trim() })
      setSucces('Exonération enregistrée.')
      setExoneration(null)
      await recharger()
    } catch (err) {
      setErreur(err.message || "L'exonération n'a pas abouti.")
    } finally {
      setEnCours(null)
    }
  }

  if (!peutFacturer && !peutExonerer && lignes.length === 0) return null

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Absences non prévenues</h3>
        <span className="sub">facturation et crédit</span>
      </div>
      <div className="card-b">
        <p className="hint" style={{ marginTop: 0 }}>
          Deux décisions indépendantes : ce qu'on facture, et ce qu'il advient de la séance prépayée.
          Exonérer ne rend pas la séance, et facturer ne la retire pas forcément.
        </p>

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {exoneration && (
          <form onSubmit={exonerer} className="card" style={{ marginBottom: 12 }}>
            <div className="card-b">
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="ns-motif">Motif de l'exonération *</label>
                <input
                  id="ns-motif"
                  className="input"
                  required
                  autoFocus
                  value={exoneration.motif}
                  placeholder="Justificatif médical, erreur de créneau…"
                  onChange={(ev) => setExoneration((s) => ({ ...s, motif: ev.target.value }))}
                />
                <div className="hint">
                  Obligatoire, et conservé : c'est ce qui justifiera la décision si le client la
                  conteste plus tard.
                </div>
              </div>
              <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 10 }}>
                <button className="btn" type="button" onClick={() => setExoneration(null)}>Annuler</button>
                <button className="btn primary" type="submit" disabled={enCours !== null}>
                  Confirmer l'exonération
                </button>
              </div>
            </div>
          </form>
        )}

        {chargement ? (
          <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
        ) : lignes.length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>
            Aucune absence non prévenue à traiter. Elles apparaîtront ici automatiquement lorsqu'un
            client ne se présente pas à un créneau réservé.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Montant</th>
                  <th>Comment c'est facturé</th>
                  <th>Facturation</th>
                  <th title="Ce qu'il advient de la séance prépayée. Indépendant du montant.">Séance</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {lignes.map((l) => {
                  const etat = ETATS[l.statut] || { libelle: l.statut, ton: 'mut' }
                  const issue = ISSUES_CREDIT[l.issueCreditNoShow]
                  const aTraiter = l.statut === 'a_facturer'
                  return (
                    <tr key={l.id}>
                      <td className="num"><span className="nm">{euros(l.montant)}</span></td>
                      <td>{MODES[l.regleAppliquee?.modeFacturation] || '—'}</td>
                      <td>
                        <span className={`badge ${etat.ton}`}>{etat.libelle}</span>
                        {l.motifExoneration && (
                          <div className="hint" style={{ margin: 0 }}>{l.motifExoneration}</div>
                        )}
                      </td>
                      {/* La seconde dimension, dans sa propre colonne : c'est la décision de Maxime,
                          et elle ne se déduit pas de l'état de facturation. */}
                      <td>
                        {issue ? (
                          <>
                            <span className={`badge ${issue.ton}`} title={issue.aide}>{issue.libelle}</span>
                            {!l.creditActionne && (
                              <div className="hint" style={{ margin: 0 }}>en attente d'application</div>
                            )}
                          </>
                        ) : (
                          <span className="sub" title="Cette réservation ne repose pas sur une séance prépayée.">
                            sans séance
                          </span>
                        )}
                      </td>
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          {aTraiter && peutFacturer && (
                            <button
                              className="btn primary sm"
                              type="button"
                              disabled={enCours !== null}
                              onClick={() => facturer(l)}
                            >
                              Facturer
                            </button>
                          )}
                          {aTraiter && peutExonerer && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={enCours !== null}
                              onClick={() => setExoneration({ ligne: l, motif: '' })}
                            >
                              Exonérer
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  )
}
