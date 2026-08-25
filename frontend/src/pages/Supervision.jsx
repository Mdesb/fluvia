import { useEffect, useRef, useState, useCallback } from 'react'
import { api, membres } from '../api/client.js'
import { mot, GLOSSAIRE } from '../api/vocabulaire.js'
import RechercheBilletModal from '../components/RechercheBilletModal.jsx'

function heure(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function libelleDe(v) {
  if (!v) return '—'
  return typeof v === 'object' ? v.libelle || v.id || '—' : v
}

// Niveau d'alerte d'une jauge FMI vis-à-vis de son seuil.
function niveauJauge(valeur, seuil) {
  if (!seuil || seuil <= 0) return { cls: 'mut', txt: 'sans seuil', pct: 0 }
  const pct = Math.round((valeur / seuil) * 100)
  if (valeur >= seuil) return { cls: 'crit', txt: 'seuil atteint', pct: Math.min(100, pct) }
  if (pct >= 80) return { cls: 'warn', txt: 'proche seuil', pct }
  return { cls: 'good', txt: 'normal', pct }
}

const RESULTAT_CLS = { autorise: 'good', accepte: 'good', refuse: 'crit', bloque: 'crit', hors_ligne: 'warn' }

// Écran Supervision accès (FMI, M3) : état temps réel des jauges, contrôleurs, incidents et
// derniers passages. Rafraîchissement automatique (poll court).
export default function Supervision({ etabActif }) {
  const [sup, setSup] = useState(null)
  const [verifBillet, setVerifBillet] = useState(false)
  const [passages, setPassages] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [maj, setMaj] = useState(null)
  const [auto, setAuto] = useState(true)
  const timer = useRef(null)

  const recharger = useCallback(async (silencieux = false) => {
    if (!silencieux) setChargement(true)
    try {
      const [s, p] = await Promise.all([api.supervisionAcces(), api.passages().catch(() => null)])
      setSup(s)
      if (p) setPassages(membres(p))
      setMaj(new Date())
      setErreur(null)
    } catch (e) {
      setErreur(e.message || 'Supervision indisponible.')
    } finally {
      if (!silencieux) setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [etabActif, recharger])

  // Poll toutes les 12 s tant que l'auto-rafraîchissement est actif.
  useEffect(() => {
    if (!auto) return
    timer.current = setInterval(() => recharger(true), 12000)
    return () => clearInterval(timer.current)
  }, [auto, recharger])

  const jauges = sup?.jauges || []
  const controleurs = sup?.controleurs || []
  const incidents = sup?.incidents || []
  const enAlerte = jauges.filter((j) => j.seuil > 0 && j.valeurCourante >= j.seuil).length
  const enLigne = controleurs.filter((c) => c.etat === 'en_ligne').length
  const frequentation = jauges.reduce((s, j) => s + (j.cumulJour || 0), 0)

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Supervision accès</h1>
          <p>Jauges FMI en temps réel · fréquentation &amp; passages</p>
        </div>
        <div className="actions">
          <span className="hint" style={{ margin: 0 }}>
            {maj ? `Actualisé à ${heure(maj)}` : ''}
          </span>
          <button className={`btn${auto ? ' primary' : ''}`} onClick={() => setAuto((v) => !v)}>
            {auto ? '⏸ Auto' : '▶ Auto'}
          </button>
          <button className="btn" onClick={() => setVerifBillet(true)}>Vérifier un billet</button>
          <button className="btn" onClick={() => recharger()}>↻ Rafraîchir</button>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      {chargement && !sup ? (
        <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
      ) : (
        <>
          {/* Indicateurs */}
          <div className="grid g4" style={{ marginBottom: 16 }}>
            <div className="kpi">
              <div className="lbl">Jauges suivies</div>
              <div className="val">{jauges.length}</div>
            </div>
            <div className="kpi">
              <div className="lbl">En alerte</div>
              <div className="val" style={{ color: enAlerte ? 'var(--crit)' : 'var(--good)' }}>{enAlerte}</div>
            </div>
            <div className="kpi">
              <div className="lbl">Contrôleurs en ligne</div>
              <div className="val">{enLigne}<span style={{ fontSize: 15, color: 'var(--ink-faint)' }}> / {controleurs.length}</span></div>
            </div>
            <div className="kpi">
              <div className="lbl">Fréquentation (jour)</div>
              <div className="val">{frequentation.toLocaleString('fr-FR')}</div>
            </div>
          </div>

          {incidents.length > 0 && (
            <div className="banner banner-error">
              {incidents.length} incident(s) en cours : {incidents.map((i) => i.libelle || i.message || 'incident').join(', ')}
            </div>
          )}

          <div className="resa-grid">
            {/* Jauges FMI */}
            <section className="card">
              <div className="card-h"><h3>Jauges FMI</h3><span className="sub">valeur / seuil</span></div>
              <div className="card-b">
                {jauges.length === 0 ? (
                  <div className="empty">Aucune jauge configurée.</div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                    {jauges.map((j) => {
                      const n = niveauJauge(j.valeurCourante, j.seuil)
                      return (
                        <div key={j.espace || j.libelle}>
                          <div className="jauge-h">
                            <span className="nm">{j.libelle}</span>
                            <span className={`badge ${n.cls}`}>{n.txt}</span>
                          </div>
                          <div className="bar" style={{ height: 12 }}>
                            <i style={{ width: `${n.pct}%`, background: `var(--${n.cls === 'mut' ? 'accent' : n.cls})` }} />
                          </div>
                          <div className="jauge-f">
                            <span><b style={{ color: 'var(--ink)' }}>{j.valeurCourante}</b> / {j.seuil || '∞'}</span>
                            <span>cumul jour : {j.cumulJour ?? 0}</span>
                            <span className="badge mut">{j.mode}</span>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>
            </section>

            {/* Contrôleurs */}
            <section className="card">
              <div className="card-h"><h3>Contrôleurs</h3></div>
              <div className="card-b" style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead><tr>
                    <th>Contrôleur</th>
                    <th>État</th>
                    <th title={GLOSSAIRE.heartbeat}>Dernier signe de vie</th>
                  </tr></thead>
                  <tbody>
                    {controleurs.map((c) => (
                      <tr key={c.id}>
                        <td><span className="nm">{c.libelle}</span></td>
                        <td><span className={`badge ${c.etat === 'en_ligne' ? 'good' : 'crit'}`}>{mot(c.etat)}</span></td>
                        <td>{c.dernierHeartbeat ? heure(c.dernierHeartbeat) : '—'}</td>
                      </tr>
                    ))}
                    {controleurs.length === 0 && <tr><td colSpan={3} className="empty">Aucun contrôleur.</td></tr>}
                  </tbody>
                </table>
              </div>
            </section>
          </div>

          {/* Derniers passages */}
          <section className="card" style={{ marginTop: 16 }}>
            <div className="card-h"><h3>Derniers passages</h3><span className="sub">{passages.length} récents</span></div>
            <div className="card-b" style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr><th>Heure</th><th>Résultat</th><th>Sens</th><th>Espace</th><th>Contrôleur</th><th>Motif</th></tr>
                </thead>
                <tbody>
                  {passages.map((p) => (
                    <tr key={p.id}>
                      <td className="mono">{heure(p.horodatage)}</td>
                      <td><span className={`badge ${RESULTAT_CLS[p.resultat] || 'mut'}`}>{mot(p.resultat)}</span></td>
                      <td>{mot(p.sens)}</td>
                      <td>{libelleDe(p.espace)}</td>
                      <td>{libelleDe(p.controleur)}</td>
                      <td>{p.motif || '—'}</td>
                    </tr>
                  ))}
                  {passages.length === 0 && (
                    <tr><td colSpan={6} className="empty">Aucun passage enregistré pour le moment.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}

      <RechercheBilletModal open={verifBillet} onClose={() => setVerifBillet(false)} />
    </div>
  )
}
