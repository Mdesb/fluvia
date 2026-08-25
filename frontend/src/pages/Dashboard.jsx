import { useEffect, useState, useCallback } from 'react'
import { api, membres } from '../api/client.js'
import { euros } from '../api/produit.js'
import { euroCentimes } from '../components/Liste.jsx'
import PretAVendre from '../components/PretAVendre.jsx'

function Kpi({ label, valeur, accent, sous }) {
  return (
    <div className="kpi">
      <div className="lbl">{label}</div>
      <div className="val" style={accent ? { color: accent } : undefined}>{valeur}</div>
      {sous && <div className="hint" style={{ marginTop: 2 }}>{sous}</div>}
    </div>
  )
}

// Tableau de bord d'arrivée pour un profil administrateur : agrège des indicateurs du jour et des
// alertes d'exploitation à partir des endpoints existants (Reporting / Compta / Caisse). Chaque
// source est isolée : un périmètre manquant (403) dégrade proprement la carte concernée sans casser
// le reste du tableau.
export default function Dashboard({ etabActif, etablissements, droits = [], onNav }) {
  const [dash, setDash] = useState(null)
  const [dashInfo, setDashInfo] = useState(null)
  const [sessions, setSessions] = useState([])
  const [regies, setRegies] = useState([])
  const [regieInfo, setRegieInfo] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const nomEtab = etablissements?.find((e) => e.id === etabActif)?.nom || 'Établissement'

  const charger = useCallback(async () => {
    if (!etabActif) return
    setChargement(true)
    setErreur(null)
    setDashInfo(null)
    setRegieInfo(null)

    // Dashboard Reporting (CA jour, fréquentation, jauges FMI, fond de caisse) — peut être 403 si
    // aucun périmètre Reporting n'est rattaché au compte.
    const pDash = api
      .dashboardEtablissement(etabActif)
      .then((d) => setDash(d))
      .catch((e) => {
        setDash(null)
        if (e.status === 403) setDashInfo("Aucun périmètre Reporting rattaché à ce compte : CA et fréquentation indisponibles.")
        else if (e.status === 404) setDashInfo('Tableau de bord Reporting indisponible pour cet établissement.')
        else setErreur(e.message || 'Chargement du tableau de bord impossible.')
      })

    // Sessions de caisse (état ouverte).
    const pSessions = api
      .sessionsCaisse()
      .then((r) => setSessions(membres(r)))
      .catch(() => setSessions([]))

    // Régies (solde vs plafond d'encaisse) — nécessite compta.lire.
    const pRegies = api
      .regieRecettes()
      .then((r) => setRegies(membres(r)))
      .catch((e) => {
        setRegies([])
        if (e.status === 403) setRegieInfo('Régies indisponibles (droit compta.lire requis).')
      })

    await Promise.all([pDash, pSessions, pRegies])
    setChargement(false)
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  const sessionsOuvertes = sessions.filter((s) => s.etat === 'ouverte' || s.etat === 'Ouverte')
  const jauges = dash?.jaugesFmi || []
  const jaugesAlerte = jauges.filter((j) => j.seuil > 0 && j.valeurCourante >= j.seuil)
  const regiesAuPlafond = regies.filter(
    (r) => (r.plafondEncaisseCentimes || 0) > 0 && (r.soldeEncaisseCentimes || 0) >= (r.plafondEncaisseCentimes || 0),
  )
  const nbAlertes = jaugesAlerte.length + regiesAuPlafond.length

  if (chargement) {
    return (
      <div className="view">
        <div className="view-head"><div className="ttl"><h1>Tableau de bord</h1><p>{nomEtab}</p></div></div>
        <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
      </div>
    )
  }

  return (
    <div className="view">
      {/* Avant tout le reste : quelqu'un qui ne peut pas encore vendre doit l'apprendre ici, pas en
          cherchant dans les Parametres qu'il n'a aucune raison d'ouvrir. Disparait des que les trois
          conditions sont remplies. */}
      <PretAVendre etabActif={etabActif} droits={droits} onAller={() => onNav?.('parametres')} masquerSiComplet />
      <div className="view-head">
        <div className="ttl">
          <h1>Tableau de bord</h1>
          <p>{dash?.etablissementNom || nomEtab} · indicateurs du jour</p>
        </div>
        <div className="actions">
          <button className="btn" onClick={charger}>↻ Rafraîchir</button>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {dashInfo && <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>{dashInfo}</div>}

      <div className="grid g4" style={{ marginBottom: 16 }}>
        <Kpi label="CA encaissé (jour)" valeur={dash ? euros(dash.caJour) : 'n/d'} accent="var(--accent-2)" />
        <Kpi
          label="Fréquentation (jour)"
          valeur={dash ? Number(dash.entreesJour || 0).toLocaleString('fr-FR') : 'n/d'}
        />
        <Kpi
          label="Sessions de caisse ouvertes"
          valeur={sessionsOuvertes.length}
          accent={sessionsOuvertes.length ? 'var(--good)' : undefined}
        />
        <Kpi
          label="Alertes"
          valeur={nbAlertes}
          accent={nbAlertes ? 'var(--crit)' : 'var(--good)'}
          sous={nbAlertes ? `${jaugesAlerte.length} jauge(s) · ${regiesAuPlafond.length} régie(s)` : 'aucune'}
        />
      </div>

      <div className="grid g2" style={{ marginBottom: 16 }}>
        <Kpi label="Fond de caisse théorique" valeur={dash ? euros(dash.fondDeCaisse) : 'n/d'} />
        <Kpi label="Espaces suivis (FMI)" valeur={jauges.length} />
      </div>

      <div className="resa-grid">
        {/* Alertes d'exploitation */}
        <section className="card">
          <div className="card-h"><h3>Alertes</h3><span className="sub">jauges FMI &amp; régies</span></div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            {nbAlertes === 0 ? (
              <div className="empty">Aucune alerte en cours.</div>
            ) : (
              <table className="tbl">
                <thead>
                  <tr><th>Source</th><th>Objet</th><th className="num">Valeur</th><th className="num">Seuil / plafond</th></tr>
                </thead>
                <tbody>
                  {jaugesAlerte.map((j, i) => (
                    <tr key={`j-${j.espace || j.libelle || i}`}>
                      <td><span className="badge crit">Jauge FMI</span></td>
                      <td><span className="nm">{j.libelle || '—'}</span></td>
                      <td className="num">{j.valeurCourante ?? 0}</td>
                      <td className="num">{j.seuil || '—'}</td>
                    </tr>
                  ))}
                  {regiesAuPlafond.map((r) => (
                    <tr key={`r-${r.id}`}>
                      <td><span className="badge crit">Régie</span></td>
                      <td><span className="nm">{r.libelle || '—'}</span></td>
                      <td className="num">{euroCentimes(r.soldeEncaisseCentimes)}</td>
                      <td className="num">{euroCentimes(r.plafondEncaisseCentimes)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>

        {/* Sessions de caisse ouvertes */}
        <section className="card">
          <div className="card-h"><h3>Sessions de caisse ouvertes</h3></div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            {sessionsOuvertes.length === 0 ? (
              <div className="empty">Aucune session ouverte.</div>
            ) : (
              <table className="tbl">
                <thead>
                  <tr><th>N°</th><th>Point de vente</th><th>Caisse</th><th>Opérateur</th></tr>
                </thead>
                <tbody>
                  {sessionsOuvertes.map((s) => (
                    <tr key={s.id}>
                      <td><span className="mono">{s.numero || '—'}</span></td>
                      <td>{s.pointDeVente?.libelle || '—'}</td>
                      <td>{s.caisse?.libelle || '—'}</td>
                      <td>{s.operateur?.nom || s.regisseur?.nom || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>
      </div>

      {regieInfo && (
        <p className="hint" style={{ marginTop: 12 }}>{regieInfo}</p>
      )}
    </div>
  )
}
