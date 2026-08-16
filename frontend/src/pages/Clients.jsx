import { useEffect, useState, useCallback } from 'react'
import { api } from '../api/client.js'
import { euros } from '../api/produit.js'

// Nom d'affichage d'un client (physique ou personne morale).
function nomClient(c) {
  if (!c) return '—'
  if (c.raisonSociale) return c.raisonSociale
  const nom = [c.prenom, c.nom].filter(Boolean).join(' ').trim()
  return nom || c.email || 'Client'
}

function dateFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

// Écran Clients (CRM, lecture seule) : liste + recherche et fiche client 360°.
export default function Clients({ etabActif }) {
  const [q, setQ] = useState('')
  const [items, setItems] = useState([])
  const [total, setTotal] = useState(0)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const [selId, setSelId] = useState(null)
  const [fiche, setFiche] = useState(null)
  const [ficheLoading, setFicheLoading] = useState(false)
  const [ficheErr, setFicheErr] = useState(null)

  const rechercher = useCallback(
    async (terme) => {
      setChargement(true)
      setErreur(null)
      try {
        const res = await api.rechercheClients({ q: terme || '', itemsPerPage: 30 })
        setItems(res.items || [])
        setTotal(res.total ?? (res.items || []).length)
      } catch (e) {
        setErreur(e.message)
        setItems([])
      } finally {
        setChargement(false)
      }
    },
    [],
  )

  // Recherche initiale + à chaque changement d'établissement.
  useEffect(() => {
    setSelId(null)
    setFiche(null)
    rechercher('')
  }, [etabActif, rechercher])

  // Recherche différée à la frappe.
  useEffect(() => {
    const t = setTimeout(() => rechercher(q), 300)
    return () => clearTimeout(t)
  }, [q, rechercher])

  async function ouvrirFiche(id) {
    setSelId(id)
    setFiche(null)
    setFicheErr(null)
    setFicheLoading(true)
    try {
      const f = await api.ficheClient(id)
      setFiche(f)
    } catch (e) {
      setFicheErr(e.message || 'Fiche indisponible.')
    } finally {
      setFicheLoading(false)
    }
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Clients</h1>
          <p>{total} fiche(s) · CRM lecture seule</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="clients-grid">
        {/* Liste + recherche */}
        <section className="card">
          <div className="card-h">
            <h3>Rechercher</h3>
          </div>
          <div className="card-b">
            <div className="field" style={{ marginBottom: 12 }}>
              <input
                className="input"
                placeholder="Nom, prénom, raison sociale, e-mail, téléphone…"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                autoFocus
              />
            </div>
            {chargement ? (
              <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead>
                    <tr>
                      <th>Client</th>
                      <th>Type</th>
                      <th>Statut</th>
                      <th>PMV</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((c) => (
                      <tr
                        key={c.id}
                        onClick={() => ouvrirFiche(c.id)}
                        className={`row-click${selId === c.id ? ' row-active' : ''}`}
                      >
                        <td>
                          <span className="nm">{nomClient(c)}</span>
                          {c.estMineur && <span className="badge warn" style={{ marginLeft: 6 }}>mineur</span>}
                        </td>
                        <td>{c.type === 'morale' ? 'Personne morale' : 'Particulier'}</td>
                        <td>
                          <span className={`badge ${c.statut === 'actif' ? 'good' : 'mut'}`}>{c.statut}</span>
                        </td>
                        <td>{c.avecPmv ? <span className="badge info">●</span> : '—'}</td>
                      </tr>
                    ))}
                    {items.length === 0 && (
                      <tr><td colSpan={4} className="empty">Aucun client trouvé.</td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </section>

        {/* Fiche 360 */}
        <section className="card">
          <div className="card-h">
            <h3>Fiche 360°</h3>
            {fiche?.client && <span className="sub" style={{ marginLeft: 'auto' }}>{nomClient(fiche.client)}</span>}
          </div>
          <div className="card-b">
            {!selId ? (
              <div className="empty">Sélectionnez un client pour afficher sa fiche.</div>
            ) : ficheLoading ? (
              <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
            ) : ficheErr ? (
              <div className="banner banner-error">{ficheErr}</div>
            ) : fiche ? (
              <FicheContenu fiche={fiche} />
            ) : null}
          </div>
        </section>
      </div>
    </div>
  )
}

function FicheContenu({ fiche }) {
  const c = fiche.client || {}
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
      {/* Coordonnées */}
      <div>
        <div className="fiche-sec">Coordonnées</div>
        <dl className="deflist">
          <div><dt>Nom</dt><dd>{nomClient(c)}</dd></div>
          {c.email && <div><dt>E-mail</dt><dd>{c.email}</dd></div>}
          {c.telephone && <div><dt>Téléphone</dt><dd>{c.telephone}</dd></div>}
          {c.adresse && <div><dt>Adresse</dt><dd>{typeof c.adresse === 'string' ? c.adresse : [c.adresse?.voie, c.adresse?.codePostal, c.adresse?.ville].filter(Boolean).join(', ')}</dd></div>}
          <div><dt>Statut</dt><dd><span className={`badge ${c.statut === 'actif' ? 'good' : 'mut'}`}>{c.statut}</span></dd></div>
          <div><dt>Dernière visite</dt><dd>{dateFr(c.dateDerniereVisite)}</dd></div>
          <div><dt>CA cumulé</dt><dd className="num">{euros(c.caCumule)}</dd></div>
        </dl>
      </div>

      {/* Porte-monnaie PMV */}
      <div>
        <div className="fiche-sec">Porte-monnaie (PMV)</div>
        {fiche.pmv ? (
          <div className="pmv-box">
            <div>
              <div className="pmv-solde">{euros(fiche.pmv.solde)}</div>
              <div className="hint" style={{ margin: 0 }}>
                {fiche.pmv.statut}
                {fiche.pmv.dateEcheance ? ` · échéance ${dateFr(fiche.pmv.dateEcheance)}` : ''}
              </div>
            </div>
          </div>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun porte-monnaie.</div>
        )}
      </div>

      {/* Famille / bénéficiaires */}
      <div>
        <div className="fiche-sec">Famille / bénéficiaires</div>
        {(fiche.famille || []).length > 0 ? (
          <div>
            {fiche.famille.map((f, i) => (
              <span key={i} className="chip">
                {f.libelle} · {f.role}{f.actif === false ? ' (inactif)' : ''}
              </span>
            ))}
          </div>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun rattachement familial.</div>
        )}
      </div>

      {/* Consentements RGPD */}
      {(fiche.consentements || []).length > 0 && (
        <div>
          <div className="fiche-sec">Consentements</div>
          <div>
            {fiche.consentements.map((c2, i) => (
              <span key={i} className={`badge ${c2.exploitable ? 'good' : 'mut'}`} style={{ marginRight: 6 }}>
                {c2.canal} : {c2.etat}
              </span>
            ))}
          </div>
        </div>
      )}

      {/* Historique d'achats */}
      <div>
        <div className="fiche-sec">Historique ({(fiche.historique || []).length})</div>
        {(fiche.historique || []).length > 0 ? (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr><th>Date</th><th>Ticket</th><th className="num">Montant</th></tr>
              </thead>
              <tbody>
                {fiche.historique.map((h, i) => (
                  <tr key={i}>
                    <td>{dateFr(h.date)}</td>
                    <td className="mono">{h.numero || '—'}</td>
                    <td className="num">{euros(h.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun achat enregistré.</div>
        )}
      </div>
    </div>
  )
}
