import { useEffect, useMemo, useState, useCallback } from 'react'
import { api, membres } from '../api/client.js'
import { euros } from '../api/produit.js'

// --- Helpers de lecture (structures API Platform / module Réservation) ---

function idDepuisIri(v) {
  if (!v) return null
  if (typeof v === 'object') return v.id || idDepuisIri(v['@id'])
  const parts = String(v).split('/')
  return parts[parts.length - 1] || null
}

function court(id) {
  return id ? String(id).slice(0, 8) : '—'
}

function jourCle(v) {
  if (!v) return ''
  return new Date(v).toISOString().slice(0, 10)
}

function jourLabel(cle) {
  const d = new Date(cle + 'T00:00:00')
  return d.toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' })
}

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function labelBeneficiaire(b) {
  const role = b.role ? b.role.charAt(0).toUpperCase() + b.role.slice(1) : 'Bénéficiaire'
  return `${role} · client ${court(idDepuisIri(b.client))}`
}

// Écran Réservation / Planning (M5) : ressources, créneaux (capacité vs réservations) et
// réservation d'un créneau (unique écriture de cet écran).
export default function Reservation({ etabActif }) {
  const [ressources, setRessources] = useState([])
  const [creneaux, setCreneaux] = useState([])
  const [reservations, setReservations] = useState([])
  const [beneficiaires, setBeneficiaires] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [jour, setJour] = useState('')
  const [reserverPour, setReserverPour] = useState(null) // id du créneau en cours de réservation
  const [organisateur, setOrganisateur] = useState('')
  const [enCours, setEnCours] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [rc, cc, rvc, bc] = await Promise.all([
        api.reservationRessources(),
        api.reservationCreneaux(),
        api.reservations(),
        api.beneficiaires(),
      ])
      setRessources(membres(rc))
      setCreneaux(membres(cc))
      setReservations(membres(rvc))
      setBeneficiaires(membres(bc))
    } catch (e) {
      setErreur(e.message || 'Chargement du planning impossible.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    setSucces(null)
    setReserverPour(null)
    recharger()
  }, [etabActif, recharger])

  // Réservations non annulées par créneau -> occupation.
  const occupation = useMemo(() => {
    const m = {}
    for (const r of reservations) {
      if (r.statut === 'annulee') continue
      const cid = idDepuisIri(r.creneau)
      if (cid) m[cid] = (m[cid] || 0) + 1
    }
    return m
  }, [reservations])

  // Jours distincts présents dans les créneaux.
  const jours = useMemo(() => {
    const set = new Set(creneaux.map((c) => jourCle(c.debut)).filter(Boolean))
    return [...set].sort()
  }, [creneaux])

  useEffect(() => {
    if (jours.length && !jours.includes(jour)) setJour(jours[0])
  }, [jours, jour])

  const creneauxJour = useMemo(
    () =>
      creneaux
        .filter((c) => jourCle(c.debut) === jour)
        .sort((a, b) => new Date(a.debut) - new Date(b.debut)),
    [creneaux, jour],
  )

  async function reserver(creneau) {
    if (!organisateur) return
    setEnCours(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.reserverCreneau({
        creneau: creneau['@id'] || `/api/reservation_creneaus/${creneau.id}`,
        organisateur: `/api/beneficiaires/${organisateur}`,
      })
      setSucces(`Réservation confirmée sur « ${creneau.ressource?.libelle || 'créneau'} » à ${heure(creneau.debut)}.`)
      setReserverPour(null)
      setOrganisateur('')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'La réservation a échoué.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Réservation</h1>
          <p>{ressources.length} ressource(s) · {creneaux.length} créneau(x)</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
      ) : (
        <div className="resa-grid">
          {/* Agenda / créneaux */}
          <section className="card">
            <div className="card-h">
              <h3>Planning</h3>
              {jours.length > 0 && (
                <div className="r seg" role="tablist">
                  {jours.map((j) => (
                    <button key={j} className={jour === j ? 'on' : ''} onClick={() => { setJour(j); setReserverPour(null) }}>
                      {jourLabel(j)}
                    </button>
                  ))}
                </div>
              )}
            </div>
            <div className="card-b">
              {creneauxJour.length === 0 ? (
                <div className="empty">Aucun créneau {jours.length ? 'ce jour' : 'planifié'}.</div>
              ) : (
                <div className="grid g2">
                  {creneauxJour.map((c) => {
                    const cap = c.capacite ?? 0
                    const pris = occupation[c.id] || 0
                    const reste = Math.max(0, cap - pris)
                    const pct = cap > 0 ? Math.min(100, Math.round((pris / cap) * 100)) : 0
                    const complet = cap > 0 && reste <= 0
                    const annulable = c.statut !== 'annule'
                    return (
                      <div key={c.id} className="creneau">
                        <div className="creneau-h">
                          <div>
                            <div className="nm">{c.ressource?.libelle || 'Ressource'}</div>
                            <div className="creneau-sub">
                              {c.ressource?.codeType || '—'}
                              {c.activite?.libelle ? ` · ${c.activite.libelle}` : ''}
                            </div>
                          </div>
                          <span className={`badge ${c.statut === 'planifie' ? 'info' : c.statut === 'annule' ? 'crit' : 'mut'}`}>
                            {c.statut}
                          </span>
                        </div>
                        <div className="creneau-time">
                          <span>◷ {heure(c.debut)} – {heure(c.fin)}</span>
                          {c.activite?.tarifReferenceMontant != null && (
                            <span className="creneau-tarif">{euros(c.activite.tarifReferenceMontant)}</span>
                          )}
                        </div>
                        <div className="creneau-places">
                          <div className="bar"><i style={{ width: `${pct}%`, background: complet ? 'var(--crit)' : 'var(--accent)' }} /></div>
                          <span className={`places ${complet ? 'full' : ''}`}>{pris}/{cap} · {reste} place(s)</span>
                        </div>

                        {reserverPour === c.id ? (
                          <div className="creneau-resa">
                            <select
                              className="select"
                              value={organisateur}
                              onChange={(e) => setOrganisateur(e.target.value)}
                            >
                              <option value="">Organisateur…</option>
                              {beneficiaires.map((b) => (
                                <option key={b.id} value={b.id}>{labelBeneficiaire(b)}</option>
                              ))}
                            </select>
                            <div className="creneau-resa-act">
                              <button className="btn" onClick={() => { setReserverPour(null); setOrganisateur('') }} disabled={enCours}>Annuler</button>
                              <button className="btn primary" onClick={() => reserver(c)} disabled={enCours || !organisateur}>
                                {enCours ? 'Envoi…' : 'Confirmer'}
                              </button>
                            </div>
                          </div>
                        ) : (
                          <button
                            className="btn primary creneau-btn"
                            disabled={complet || !annulable}
                            onClick={() => { setReserverPour(c.id); setOrganisateur(''); setSucces(null) }}
                          >
                            {complet ? 'Complet' : !annulable ? 'Indisponible' : '＋ Réserver'}
                          </button>
                        )}
                      </div>
                    )
                  })}
                </div>
              )}
            </div>
          </section>

          {/* Catalogue des ressources */}
          <section className="card">
            <div className="card-h"><h3>Ressources</h3></div>
            <div className="card-b" style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr><th>Ressource</th><th>Type</th><th className="num">Capacité</th><th>Occ.</th><th>Accès</th></tr>
                </thead>
                <tbody>
                  {ressources.map((r) => (
                    <tr key={r.id}>
                      <td>
                        <span className="nm">{r.libelle}</span>
                        {r.partageable && <span className="badge mut" style={{ marginLeft: 6 }}>partageable</span>}
                      </td>
                      <td>{r.codeType || '—'}</td>
                      <td className="num">{r.capacitePropre ?? '—'}</td>
                      <td className="num">{r.occupationCourante ?? 0}</td>
                      <td>{r.ouvreAcces ? <span className="badge good">ouvre</span> : <span className="badge mut">—</span>}</td>
                    </tr>
                  ))}
                  {ressources.length === 0 && (
                    <tr><td colSpan={5} className="empty">Aucune ressource.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      )}
    </div>
  )
}
