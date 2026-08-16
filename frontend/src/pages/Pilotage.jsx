import { useEffect, useState, useCallback } from 'react'
import { api } from '../api/client.js'
import { euros } from '../api/produit.js'

// Petit graphe SVG « maison » (pas de lib externe) : occupation FMI par espace vs seuil.
function GrapheJauges({ jauges }) {
  if (!jauges.length) return <div className="empty">Aucune jauge à représenter.</div>
  const W = 520
  const rowH = 34
  const padL = 150
  const padR = 56
  const H = jauges.length * rowH + 10
  const maxi = Math.max(1, ...jauges.map((j) => Math.max(j.valeurCourante || 0, j.seuil || 0)))
  const larg = W - padL - padR
  return (
    <div style={{ overflowX: 'auto' }}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ maxWidth: W, display: 'block' }} role="img" aria-label="Occupation des jauges FMI">
        {jauges.map((j, i) => {
          const y = i * rowH + 8
          const val = j.valeurCourante || 0
          const seuil = j.seuil || 0
          const wVal = (val / maxi) * larg
          const xSeuil = padL + (seuil / maxi) * larg
          const alerte = seuil > 0 && val >= seuil
          const proche = seuil > 0 && val / seuil >= 0.8
          const couleur = alerte ? 'var(--crit)' : proche ? 'var(--warn)' : 'var(--accent)'
          return (
            <g key={j.espace || j.libelle || i}>
              <text x={padL - 10} y={y + 15} textAnchor="end" fontSize="11" fill="var(--ink-soft)">
                {(j.libelle || 'Espace').slice(0, 22)}
              </text>
              <rect x={padL} y={y + 4} width={larg} height={16} rx="8" fill="var(--panel-2)" />
              <rect x={padL} y={y + 4} width={Math.max(2, wVal)} height={16} rx="8" fill={couleur} />
              {seuil > 0 && (
                <line x1={xSeuil} y1={y} x2={xSeuil} y2={y + 24} stroke="var(--crit)" strokeWidth="2" strokeDasharray="3 2" />
              )}
              <text x={padL + larg + 6} y={y + 15} fontSize="11" fill="var(--ink)" fontWeight="700">{val}</text>
            </g>
          )
        })}
      </svg>
      <div className="hint" style={{ marginTop: 4 }}>Barre = occupation courante · trait rouge = seuil FMI.</div>
    </div>
  )
}

function Kpi({ label, valeur, accent }) {
  return (
    <div className="kpi">
      <div className="lbl">{label}</div>
      <div className="val" style={accent ? { color: accent } : undefined}>{valeur}</div>
    </div>
  )
}

// Écran Reporting / Pilotage (M7) : dashboard établissement (lecture directe des modules
// producteurs). En l'absence de périmètre Reporting, repli sur la supervision accès accessible.
export default function Pilotage({ etabActif, etablissements }) {
  const [dash, setDash] = useState(null)
  const [repli, setRepli] = useState(null) // données de supervision si dashboard hors périmètre
  const [chargement, setChargement] = useState(true)
  const [info, setInfo] = useState(null) // message explicatif (403 périmètre, etc.)
  const [erreur, setErreur] = useState(null)

  const nomEtab = etablissements?.find((e) => e.id === etabActif)?.nom || 'Établissement'

  const charger = useCallback(async () => {
    if (!etabActif) return
    setChargement(true)
    setErreur(null)
    setInfo(null)
    setDash(null)
    setRepli(null)
    try {
      const d = await api.dashboardEtablissement(etabActif)
      setDash(d)
    } catch (e) {
      // Le dashboard M7 exige un périmètre Reporting (affectation dédiée). Repli lisible.
      if (e.status === 403) {
        setInfo("Aucun périmètre Reporting n'est rattaché à ce compte pour cet établissement (le tableau de bord M7 requiert une affectation « reporting »). Vue de repli à partir de la supervision accès.")
      } else if (e.status === 404) {
        setInfo('Tableau de bord Reporting indisponible pour cet établissement.')
      } else {
        setErreur(e.message || 'Chargement du pilotage impossible.')
      }
      // Repli : supervision accès (accessible) pour alimenter fréquentation + FMI.
      try {
        const s = await api.supervisionAcces()
        setRepli(s)
      } catch {
        /* repli indisponible : on laisse le message d'info */
      }
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  // --- Rendu ---
  if (chargement) {
    return (
      <div className="view">
        <div className="view-head"><div className="ttl"><h1>Pilotage</h1><p>{nomEtab}</p></div></div>
        <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
      </div>
    )
  }

  // Source des jauges + fréquentation : dashboard si dispo, sinon repli supervision.
  const jauges = dash?.jaugesFmi || repli?.jauges || []
  const fmiMax = jauges.reduce((m, j) => Math.max(m, j.valeurCourante || 0), 0)
  const frequentation = dash?.entreesJour ?? jauges.reduce((s, j) => s + (j.cumulJour || 0), 0)
  const enAlerte = jauges.filter((j) => j.seuil > 0 && j.valeurCourante >= j.seuil).length

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Pilotage</h1>
          <p>{dash?.etablissementNom || nomEtab} · indicateurs du jour</p>
        </div>
        <div className="actions">
          <span className={`badge ${dash ? 'good' : 'warn'}`}>{dash ? 'Temps réel (M7)' : 'Repli supervision'}</span>
          <button className="btn" onClick={charger}>↻ Rafraîchir</button>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {info && <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>{info}</div>}

      {/* KPIs */}
      <div className="grid g4" style={{ marginBottom: 16 }}>
        <Kpi label="CA encaissé (jour)" valeur={dash ? euros(dash.caJour) : 'n/d'} accent="var(--accent-2)" />
        <Kpi label="Fréquentation (jour)" valeur={Number(frequentation || 0).toLocaleString('fr-FR')} />
        <Kpi label="FMI max (occupation)" valeur={fmiMax.toLocaleString('fr-FR')} accent={enAlerte ? 'var(--crit)' : undefined} />
        <Kpi label="Fond de caisse" valeur={dash ? euros(dash.fondDeCaisse) : 'n/d'} />
      </div>

      <div className="grid g2" style={{ marginBottom: 16 }}>
        <Kpi label="Jauges en alerte" valeur={enAlerte} accent={enAlerte ? 'var(--crit)' : 'var(--good)'} />
        <Kpi label="Espaces suivis" valeur={jauges.length} />
      </div>

      <div className="resa-grid">
        {/* Graphe occupation FMI */}
        <section className="card">
          <div className="card-h"><h3>Occupation FMI par espace</h3></div>
          <div className="card-b"><GrapheJauges jauges={jauges} /></div>
        </section>

        {/* Détail jauges */}
        <section className="card">
          <div className="card-h"><h3>Détail des jauges</h3></div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr><th>Espace</th><th className="num">Courant</th><th className="num">Seuil</th><th className="num">Cumul jour</th><th>État</th></tr>
              </thead>
              <tbody>
                {jauges.map((j, i) => {
                  const alerte = j.seuil > 0 && j.valeurCourante >= j.seuil
                  const etat = dash ? j.etat : alerte ? 'alerte' : 'normal'
                  return (
                    <tr key={j.espace || j.libelle || i}>
                      <td><span className="nm">{j.libelle || '—'}</span></td>
                      <td className="num">{j.valeurCourante ?? 0}</td>
                      <td className="num">{j.seuil || '—'}</td>
                      <td className="num">{j.cumulJour ?? 0}</td>
                      <td><span className={`badge ${etat === 'alerte' ? 'crit' : 'good'}`}>{etat}</span></td>
                    </tr>
                  )
                })}
                {jauges.length === 0 && <tr><td colSpan={5} className="empty">Aucune jauge.</td></tr>}
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <p className="hint" style={{ marginTop: 14 }}>
        No-show et impayés sont issus des mesures pré-agrégées (M7 région/groupe) : non affichés ici tant
        qu'aucune mesure n'est générée pour le périmètre.
      </p>
    </div>
  )
}
