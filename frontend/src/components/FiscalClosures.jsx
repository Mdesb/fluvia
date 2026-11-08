import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri.js'
import { CANCELLED, closePeriod } from '../api/periodClosing.js'
import { confirmer } from './Confirmation.jsx'
import { dateFr, euroCentimes } from './Liste.jsx'

// CLÔTURES MENSUELLE ET ANNUELLE, ARCHIVE FISCALE (#342, #343).
//
// Trois gestes, dans l'ordre où ils s'enchaînent, et c'est l'ordre que l'écran montre :
//   1. clore le mois, une fois chaque journée arrêtée (la clôture journalière tourne la nuit) ;
//   2. clore l'année, une fois chaque mois clos ;
//   3. archiver l'année, une fois l'année close sur chaque point de vente.
//
// Chaque refus du serveur nomme ce qui manque (« la journée du 12 n'est pas arrêtée ») : l'écran
// l'affiche tel quel, parce que c'est la seule phrase qui dit quoi faire ensuite.
//
// Les montants arrivent en décimal (« 1234.50 »), pas en centimes : convertis pour le formateur commun.

const euros = (v) => euroCentimes(Math.round(Number(v || 0) * 100))

function moisPrecedent() {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const anneePrecedente = () => String(new Date().getFullYear() - 1)

function telecharger(nom, base64) {
  // `globalThis.` : le garde-fou de portée (n40) ne connaît pas `Uint8Array` comme global nu.
  const octets = globalThis.Uint8Array.from(atob(base64), (c) => c.charCodeAt(0))
  const url = URL.createObjectURL(new Blob([octets], { type: 'application/zip' }))
  const a = document.createElement('a')
  a.href = url
  a.download = nom
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export default function FiscalClosures({ etabActif, droits = [] }) {
  // Un seul droit pour clore et archiver : le même geste de fin de période, le même profil.
  const peutClore = aLeDroit(droits, 'vente.fiscal_closing')
  const peutArchiver = peutClore

  const [points, setPoints] = useState([])
  const [pdv, setPdv] = useState('')
  const [mois, setMois] = useState(moisPrecedent())
  const [annee, setAnnee] = useState(anneePrecedente())
  const [anneeArchive, setAnneeArchive] = useState(anneePrecedente())
  const [clotures, setClotures] = useState(null)
  const [archives, setArchives] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [busy, setBusy] = useState(false)
  const [rapports, setRapports] = useState({})

  const charger = useCallback(() => {
    setErreur(null)
    api.pointDeVentes()
      .then((r) => {
        const l = membres(r)
        setPoints(l)
        setPdv((p) => p || l[0]?.id || '')
      })
      .catch((e) => setErreur(e.message || 'Les points de vente n’ont pas pu être lus.'))
    // ⚠ UNE LECTURE QUI ÉCHOUE N'EST PAS UNE LISTE VIDE : « aucune clôture » laisserait croire
    // qu'il reste tout à faire, ou rien.
    api.cloturesPeriode()
      .then((r) => setClotures(membres(r)))
      .catch((e) => { setErreur(e.message || 'Les clôtures n’ont pas pu être lues.'); setClotures([]) })
    api.archivesFiscales()
      .then((r) => setArchives(membres(r)))
      .catch((e) => { setErreur(e.message || 'Les archives n’ont pas pu être lues.'); setArchives([]) })
  }, [])

  useEffect(() => { charger() }, [etabActif, charger])

  async function agir(geste, message) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      // Un non à la modale de confirmation (#362) : rien n'a été envoyé, rien à annoncer.
      if ((await geste()) === CANCELLED) return
      setSucces(message)
      charger()
    } catch (e) {
      setErreur(e.message || 'L’opération a été refusée.')
    } finally {
      setBusy(false)
    }
  }

  async function verifier(a) {
    setErreur(null)
    try {
      const r = await api.verifierArchiveFiscale(a.id)
      setRapports((x) => ({ ...x, [a.id]: r }))
    } catch (e) {
      // 409 = archive NON intacte : c'est un résultat, pas une panne. On affiche le rapport.
      if (e.status === 409 && e.payload?.checks) {
        setRapports((x) => ({ ...x, [a.id]: e.payload }))
      } else {
        setErreur(e.message || 'La vérification n’a pas pu être faite.')
      }
    }
  }

  async function recuperer(a) {
    setErreur(null)
    try {
      const r = await api.telechargerArchiveFiscale(a.id)
      telecharger(r.fileName || 'archive-fiscale.zip', r.contentBase64 || '')
    } catch (e) {
      setErreur(e.message || 'Le téléchargement a échoué.')
    }
  }

  // #362 : une clôture est définitive, le bouton ne part donc qu'après la modale commune.
  function clore(kind) {
    const period = kind === 'annual' ? annee : mois
    const pointOfSaleLabel = points.find((p) => String(p.id) === String(pdv))?.libelle || ''
    return agir(
      () => closePeriod({ kind, period, pointOfSaleId: pdv, pointOfSaleLabel }, { confirm: confirmer, api }),
      kind === 'annual' ? `Année ${period} close et scellée.` : `Mois ${period} clos et scellé.`,
    )
  }

  const libellePdv = (reference) => {
    const id = idDe(reference)
    return points.find((p) => String(p.id) === String(id))?.libelle || '—'
  }

  return (
    <div className="resa-grid">
      <section className="card">
        <div className="card-h">
          <h3>Clôtures mensuelle et annuelle</h3>
          <span className="sub">par point de vente, scellées</span>
        </div>
        <div className="card-b">
          {erreur && <div className="banner banner-error">{erreur}</div>}
          {succes && <div className="banner banner-ok">{succes}</div>}

          <p className="hint">
            Le mois se clôt quand chacune de ses journées de ventes est arrêtée ; l’année, quand
            chacun de ses mois est clos. Une clôture est définitive : elle entre dans la chaîne
            d’inaltérabilité et ne se refait pas.
          </p>

          {peutClore ? (
            <>
              <label className="field">
                <span className="field-lbl">Point de vente</span>
                <select className="select" value={pdv} onChange={(e) => setPdv(e.target.value)}>
                  {points.map((p) => <option key={p.id} value={p.id}>{p.libelle}</option>)}
                </select>
              </label>
              <div className="row actions">
                <input className="input" type="month" value={mois} onChange={(e) => setMois(e.target.value)} aria-label="Mois à clore" />
                <button
                  type="button"
                  className="btn primary"
                  disabled={busy || !pdv || !mois}
                  onClick={() => clore('monthly')}
                >
                  Clore le mois
                </button>
              </div>
              <div className="row actions">
                <input className="input" type="number" min="2000" max="2999" value={annee} onChange={(e) => setAnnee(e.target.value)} aria-label="Année à clore" />
                <button
                  type="button"
                  className="btn"
                  disabled={busy || !pdv || !annee}
                  onClick={() => clore('annual')}
                >
                  Clore l’année
                </button>
              </div>
            </>
          ) : (
            <div className="hint">Clore un mois ou une année demande le droit « clôtures fiscales ».</div>
          )}

          {clotures === null && <div className="empty">Chargement…</div>}
          {clotures?.length === 0 && <div className="empty">Aucune clôture de période pour l’instant.</div>}
          {clotures?.length > 0 && (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Période</th>
                  <th>Point de vente</th>
                  <th className="num">Ventes</th>
                  <th className="num">Total</th>
                  <th className="num">Cumul exercice</th>
                  <th className="num">Cumul perpétuel</th>
                </tr>
              </thead>
              <tbody>
                {clotures.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <b>{c.type === 'annuelle' ? String(c.periodStart).slice(0, 4) : String(c.periodStart).slice(0, 7)}</b>{' '}
                      <span className="badge mut">{c.type}</span>{' '}
                      {/* L'écart se dit en clair : la table des ventes ne dit plus ce que les
                          journées ont scellé, c'est-à-dire qu'une vente a bougé après son arrêté. */}
                      {c.discrepancy && <span className="badge crit" title={`Ventes relues : ${euros(c.recomputedTotal)}`}>écart</span>}
                    </td>
                    <td>{libellePdv(c.pointDeVente)}</td>
                    <td className="num">{c.salesCount}</td>
                    <td className="num mono">{euros(c.periodTotal)}</td>
                    <td className="num mono">{euros(c.fiscalYearTotal)}</td>
                    <td className="num mono">{euros(c.grandTotal)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>

      <section className="card">
        <div className="card-h">
          <h3>Archives annuelles</h3>
          <span className="sub">ventes et fréquentation, figées</span>
        </div>
        <div className="card-b">
          <p className="hint">
            En début d’année, une fois l’année close sur chaque point de vente : l’archive fige les
            ventes, la chaîne scellée, les clôtures et la fréquentation dans un fichier séparé,
            signé, à conserver six ans. Les données actives restent consultables.
          </p>

          {peutArchiver ? (
            <div className="row actions">
              <input className="input" type="number" min="2000" max="2999" value={anneeArchive} onChange={(e) => setAnneeArchive(e.target.value)} aria-label="Année à archiver" />
              <button
                type="button"
                className="btn primary"
                disabled={busy || !anneeArchive}
                onClick={() => agir(() => api.creerArchiveFiscale(anneeArchive), `Année ${anneeArchive} archivée.`)}
              >
                Archiver l’année
              </button>
            </div>
          ) : (
            <div className="hint">Archiver demande le droit « clôtures fiscales ».</div>
          )}

          {archives === null && <div className="empty">Chargement…</div>}
          {archives?.length === 0 && <div className="empty">Aucune archive pour l’instant.</div>}
          {(archives || []).map((a) => {
            const rapport = rapports[a.id]
            const moisActifs = (a.summary?.months || []).filter((m) => m.salesCount || m.entries || m.countedEntries)
            return (
              <div key={a.id} className="sup-acces">
                <div className="sup-acces-t">
                  <span className="nm">Exercice {a.fiscalYear}</span>
                  {rapport && (rapport.intact
                    ? <span className="badge good">intacte</span>
                    : <span className="badge crit">altérée</span>)}
                </div>
                <div className="hint">
                  {a.salesCount} vente(s) · <span className="mono">{euros(a.salesTotal)}</span> · {a.attendanceCount} passage(s) ·
                  archivée le {dateFr(a.createdAt)} · empreinte <span className="mono">{String(a.sha256).slice(0, 16)}…</span>
                </div>
                {moisActifs.length > 0 && (
                  <table className="tbl">
                    <thead>
                      <tr><th>Mois</th><th className="num">Ventes</th><th className="num">Total</th><th className="num">Entrées</th></tr>
                    </thead>
                    <tbody>
                      {moisActifs.map((m) => (
                        <tr key={m.month}>
                          <td>{String(m.month).padStart(2, '0')}/{a.fiscalYear}</td>
                          <td className="num">{m.salesCount}</td>
                          <td className="num mono">{euros(m.salesTotal)}</td>
                          <td className="num">{(m.entries || 0) + (m.countedEntries || 0)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
                <div className="row actions">
                  <button type="button" className="btn sm" onClick={() => verifier(a)}>Vérifier</button>
                  {peutArchiver && <button type="button" className="btn sm" onClick={() => recuperer(a)}>Télécharger</button>}
                </div>
                {rapport && (
                  <ul className="hint">
                    {(rapport.checks || []).map((c) => (
                      <li key={c.check}>{c.ok ? '✓' : '✗'} {c.detail}</li>
                    ))}
                  </ul>
                )}
              </div>
            )
          })}
        </div>
      </section>
    </div>
  )
}
