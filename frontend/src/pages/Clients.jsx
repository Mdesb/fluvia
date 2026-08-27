import { useEffect, useState, useCallback } from 'react'
import ActivitesClient from '../components/ActivitesClient.jsx'
import ContactsClient from '../components/ContactsClient.jsx'
import { api } from '../api/client.js'
import { euros } from '../api/produit.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import ClientEditionModal from '../components/ClientEditionModal.jsx'

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

function dateHeureFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// Adresse structurée côté back : { rue, complement, cp, ville, pays }.
function formatAdresse(a) {
  if (!a) return null
  if (typeof a === 'string') return a
  const l1 = [a.rue, a.complement].filter(Boolean).join(', ')
  const l2 = [a.cp, a.ville].filter(Boolean).join(' ')
  return [l1, l2, a.pays].filter(Boolean).join(' · ') || null
}

// Écran Clients (CRM) : liste + recherche et fiche client 360° enrichie.
export default function Clients({ etabActif, cible = null, onCibleConsommee, droits = [] }) {
  const [edition, setEdition] = useState(false)
  const [q, setQ] = useState('')
  const [items, setItems] = useState([])
  const [total, setTotal] = useState(0)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const [selId, setSelId] = useState(null)
  const [fiche, setFiche] = useState(null)
  const [mouvements, setMouvements] = useState(null) // null = non chargé, [] = vide
  const [ficheLoading, setFicheLoading] = useState(false)
  const [ficheErr, setFicheErr] = useState(null)

  const rechercher = useCallback(async (terme) => {
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
  }, [])

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
    setMouvements(null)
    setFicheErr(null)
    setFicheLoading(true)
    try {
      const f = await api.ficheClient(id)
      const complet = await api.client(id).catch(() => null)
      setFiche(complet ? { ...f, client: { ...(f.client || {}), ...complet } } : f)
      // Relevé PMV chargé séparément (US-L5-04) uniquement si un porte-monnaie existe.
      if (f?.pmv) {
        try {
          const mv = await api.pmvMouvements(id)
          setMouvements(mv?.mouvements || [])
        } catch {
          setMouvements([])
        }
      } else {
        setMouvements([])
      }
    } catch (e) {
      setFicheErr(e.message || 'Fiche indisponible.')
    } finally {
      setFicheLoading(false)
    }
  }

  // Fiche demandee par la recherche globale. On la consomme immediatement : la garder ferait rouvrir
  // la meme fiche a chaque retour sur l'onglet, sans moyen de l'en empecher.
  useEffect(() => {
    if (cible?.type !== 'client') return
    ouvrirFiche(cible.id)
    onCibleConsommee?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cible])

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Clients</h1>
          <p>{total} fiche(s) · CRM</p>
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
            <h3>Fiche client</h3>
            {fiche?.client && <span className="sub" style={{ marginLeft: 'auto' }}>{nomClient(fiche.client)}</span>}
            {fiche?.client && aLeDroit(droits, 'crm.modifier') && (
              <div className="r">
                <button className="btn ghost sm" type="button" onClick={() => setEdition(true)}>Modifier</button>
              </div>
            )}
          </div>
          <div className="card-b">
            {!selId ? (
              <div className="empty">Sélectionnez un client pour afficher sa fiche.</div>
            ) : ficheLoading ? (
              <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
            ) : ficheErr ? (
              <div className="banner banner-error">{ficheErr}</div>
            ) : fiche ? (
              <FicheContenu fiche={fiche} mouvements={mouvements} />
            ) : null}
          </div>
        </section>
      </div>

      <ClientEditionModal
        open={edition}
        clientId={selId}
        onClose={() => setEdition(false)}
        onEnregistre={() => ouvrirFiche(selId)}
      />
    </div>
  )
}

function FicheContenu({ fiche, mouvements }) {
  const c = fiche.client || {}
  const historique = fiche.historique || []
  const famille = fiche.famille || []
  const consentements = fiche.consentements || []
  const adresse = formatAdresse(c.adresse)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
      {/* En-tête identité */}
      <div className="fiche-ident">
        <div className="fiche-avatar" aria-hidden="true">
          {(nomClient(c)[0] || '?').toUpperCase()}
        </div>
        <div style={{ minWidth: 0 }}>
          <div className="fiche-nom">{nomClient(c)}</div>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 4 }}>
            <span className="badge mut">{c.type === 'morale' ? 'Personne morale' : 'Particulier'}</span>
            <span className={`badge ${c.statut === 'actif' ? 'good' : 'mut'}`}>{c.statut || '—'}</span>
            {c.estMineur && <span className="badge warn">mineur</span>}
          </div>
        </div>
      </div>

      {/* Indicateurs clés */}
      <div className="fiche-stats">
        <div className="stat-tile">
          <div className="st-val num">{euros(c.caCumule)}</div>
          <div className="st-lbl">CA cumulé</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">{historique.length}</div>
          <div className="st-lbl">Achats</div>
        </div>
        <div className="stat-tile">
          <div className="st-val">{dateFr(c.dateDerniereVisite)}</div>
          <div className="st-lbl">Dernière visite</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">{fiche.pmv ? euros(fiche.pmv.solde) : '—'}</div>
          <div className="st-lbl">Solde PMV</div>
        </div>
      </div>

      {/* Coordonnées (toutes les lignes, état vide propre) */}
      <div>
        <div className="fiche-sec">Coordonnées</div>
        <dl className="deflist">
          <div><dt>Type</dt><dd>{c.type === 'morale' ? 'Entreprise ou association' : 'Particulier'}</dd></div>
          {c.type === 'morale' ? (
            <>
              <div><dt>Raison sociale</dt><dd>{c.raisonSociale || '—'}</dd></div>
              <div><dt>SIRET</dt><dd>{c.siret || '—'}</dd></div>
            </>
          ) : (
            <>
              <div><dt>Civilité</dt><dd>{c.civilite || '—'}</dd></div>
              <div>
                <dt title="Sert aux tarifs liés à l'âge, quand vous en proposez.">Date de naissance</dt>
                <dd>{c.dateNaissance ? dateFr(c.dateNaissance) : '—'}</dd>
              </div>
            </>
          )}
          <div><dt>E-mail</dt><dd>{c.email || '—'}</dd></div>
          <div><dt>Téléphone</dt><dd>{c.telephone || '—'}</dd></div>
          <div><dt>Adresse</dt><dd>{adresse || '—'}</dd></div>
        </dl>
      </div>

      {/* LES CONTACTS, JUSTE APRES LES COORDONNEES.
          Une societe avait une raison sociale, un SIRET, UN courriel et UN telephone. Une entreprise
          n'est pas une personne : c'est une directrice, une comptabilite, quelqu'un qui signe -- et
          ils n'ont pas la meme adresse. Le bloc ne s'affiche que pour un client moral. */}
      <ContactsClient client={c} peutModifier />

      {/* LES ECHANGES, JUSTE APRES LES CONTACTS.
          Savoir A QUI parler ne sert a rien si l'on ne sait plus CE QU'ON S'EST DIT. Le bloc porte
          aussi la relance en attente -- sans case a cocher : on ne coche pas une relance, on la
          remplace en notant l'echange suivant. */}
      <ActivitesClient client={c} peutModifier />

      {/* Porte-monnaie PMV + mouvements */}
      <div>
        <div className="fiche-sec">Porte-monnaie (PMV)</div>
        {fiche.pmv ? (
          <>
            <div className="pmv-box" style={{ marginBottom: 12 }}>
              <div>
                <div className="pmv-solde">{euros(fiche.pmv.solde)}</div>
                <div className="hint" style={{ margin: 0 }}>
                  {fiche.pmv.statut}
                  {fiche.pmv.dateEcheance ? ` · échéance ${dateFr(fiche.pmv.dateEcheance)}` : ''}
                </div>
              </div>
            </div>
            {mouvements === null ? (
              <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
            ) : mouvements.length > 0 ? (
              <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead>
                    <tr><th>Date</th><th>Type</th><th className="num">Montant</th><th className="num">Solde</th></tr>
                  </thead>
                  <tbody>
                    {mouvements.map((m) => (
                      <tr key={m.id}>
                        <td>{dateHeureFr(m.dateMouvement)}</td>
                        <td>
                          <span className="badge mut">{m.type}</span>
                          {m.motif ? <span className="hint" style={{ margin: 0, marginLeft: 6 }}>{m.motif}</span> : null}
                        </td>
                        <td className="num">{euros(m.montant)}</td>
                        <td className="num">{euros(m.soldeApres)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="empty" style={{ padding: 12 }}>Aucun mouvement enregistré.</div>
            )}
          </>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun porte-monnaie.</div>
        )}
      </div>

      {/* Famille / bénéficiaires */}
      <div>
        <div className="fiche-sec">Famille / bénéficiaires</div>
        {famille.length > 0 ? (
          <div>
            {famille.map((f, i) => (
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
      <div>
        <div className="fiche-sec">Consentements RGPD</div>
        {consentements.length > 0 ? (
          <div>
            {consentements.map((c2, i) => (
              <span key={i} className={`badge ${c2.exploitable ? 'good' : 'mut'}`} style={{ marginRight: 6, marginBottom: 4 }}>
                {c2.canal} : {c2.etat}
              </span>
            ))}
          </div>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun consentement enregistré.</div>
        )}
      </div>

      {/* Historique d'achats */}
      <div>
        <div className="fiche-sec">Historique d'achats ({historique.length})</div>
        {historique.length > 0 ? (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr><th>Date</th><th>Ticket</th><th className="num">Montant</th></tr>
              </thead>
              <tbody>
                {historique.map((h, i) => (
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
