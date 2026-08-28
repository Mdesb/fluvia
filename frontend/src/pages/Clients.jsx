import { useEffect, useState, useCallback } from 'react'
import ActivitesClient from '../components/ActivitesClient.jsx'
import ContactsClient from '../components/ContactsClient.jsx'
import { api } from '../api/client.js'
import { euros } from '../api/produit.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import ClientEditionModal from '../components/ClientEditionModal.jsx'
import DevisModal from '../components/DevisModal.jsx'

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

  const [fidelite, setFidelite] = useState(null)

  // Le solde se relit APRÈS chaque geste : il se recalcule côté serveur, et le recopier ici ferait
  // diverger l'écran de la vérité au premier arrondi.
  const rechargerFidelite = useCallback(async (id) => {
    if (!id || !aLeDroit(droits, 'fidelite.lire')) { setFidelite(null); return }
    try {
      setFidelite(await api.fidelite(id))
    } catch {
      // Sans programme de fidélité, ou sans droit : la fiche vit très bien sans ce bloc.
      setFidelite(null)
    }
  }, [droits])

  async function ouvrirFiche(id) {
    setSelId(id)
    setFiche(null)
    setMouvements(null)
    setFicheErr(null)
    setFicheLoading(true)
    try {
      rechargerFidelite(id)
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
              <FicheContenu
                fiche={fiche}
                mouvements={mouvements}
                fidelite={fidelite}
                droits={droits}
                onMouvement={() => rechargerFidelite(selId)}
              />
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

function FicheContenu({ fiche, mouvements, fidelite, droits, onMouvement }) {
  const c = fiche.client || {}
  const [devis, setDevis] = useState(false)
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

        {/* FACTURER SE DÉCIDE ICI, EN REGARDANT LE CLIENT — demande de Maxime, 28/08.
            On facture QUELQU'UN : on regarde ce qu'il a acheté, ce qu'il doit, et on part de là.
            Jusqu'ici il fallait ouvrir l'écran Facturation et RETAPER son nom en texte libre — le
            devis n'était alors rattaché à aucune fiche, et n'apparaissait dans l'historique de
            personne. La modale est la même que celle de l'écran Facturation ; partant d'ici, elle
            envoie en plus `clientRef`, ce qui rattache la pièce à ce client. */}
        {aLeDroit(droits, 'facturation.gerer') && (
          <div style={{ marginLeft: 'auto' }}>
            <button className="btn sm" type="button" onClick={() => setDevis(true)}>
              Établir un devis
            </button>
          </div>
        )}
      </div>

      <DevisModal
        open={devis}
        client={c}
        onClose={() => setDevis(false)}
        onCree={() => setDevis(false)}
      />

      <BlocFidelite
        fidelite={fidelite}
        clientId={c.id}
        droits={droits}
        onMouvement={onMouvement}
      />

      <BlocParrainage clientId={c.id} droits={droits} onMouvement={onMouvement} />

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

/**
 * LA FIDÉLITÉ AU COMPTOIR.
 *
 * Trois chiffres, dans cet ordre, parce que ce sont trois questions différentes :
 *
 *   - le **solde** — ce que le client peut échanger maintenant, la seule chose qu'il demande ;
 *   - le **palier** — ce qu'il est, qui ne baisse pas quand il dépense ;
 *   - ce qui **manque au palier suivant** — le seul chiffre qui fasse revenir. « Il vous manque
 *     40 points » agit ; « vous avez 260 points » n'agit pas.
 *
 * Et une phrase que l'écran doit dire tout haut : les points **n'expirent pas**. Une expiration
 * silencieuse se découvre au comptoir, et c'est ce jour-là qu'on perd le client qu'on voulait
 * fidéliser.
 */
function BlocFidelite({ fidelite, clientId, droits, onMouvement }) {
  const [ouvert, setOuvert] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [points, setPoints] = useState('')
  const [motif, setMotif] = useState('')
  const [sens, setSens] = useState('depense')

  if (!fidelite) return null

  const peutGerer = aLeDroit(droits, 'fidelite.gerer')

  async function enregistrer() {
    setBusy(true)
    setErr(null)
    try {
      await api.mouvementFidelite({
        customerRef: clientId,
        points: Number(points),
        movement: sens,
        reason: motif,
      })
      setOuvert(false)
      setPoints('')
      setMotif('')
      onMouvement?.()
    } catch (e) {
      setErr(e.message || 'Le mouvement n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card">
      <div className="card-h">
        <span>Fidélité</span>
        {fidelite.baremeCourant ? (
          <span className="sub" style={{ marginLeft: 'auto' }}>
            {fidelite.baremeCourant.pointsParEuro} point(s) par euro
          </span>
        ) : (
          <span className="sub" style={{ marginLeft: 'auto' }}>Aucun barème défini</span>
        )}
      </div>

      <div className="fiche-stats">
        <div className="stat-tile">
          <div className="st-val num">{fidelite.solde}</div>
          <div className="st-lbl">Points disponibles</div>
        </div>
        <div className="stat-tile">
          <div className="st-val">{fidelite.palier?.libelle || '—'}</div>
          <div className="st-lbl">Palier</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">
            {fidelite.palierSuivant ? fidelite.palierSuivant.pointsManquants : '—'}
          </div>
          <div className="st-lbl">
            {fidelite.palierSuivant ? `Pour « ${fidelite.palierSuivant.libelle} »` : 'Palier maximal'}
          </div>
        </div>
      </div>

      {!fidelite.expirationDesPoints && (
        <div className="sub" style={{ marginTop: 6 }}>
          Ces points <strong>n’expirent pas</strong>. Une expiration que le client découvrirait au
          comptoir coûterait davantage que les points qu’elle économise.
        </div>
      )}

      {peutGerer && !ouvert && (
        <button
          className="btn ghost sm"
          type="button"
          style={{ marginTop: 10 }}
          onClick={() => setOuvert(true)}
        >
          Dépenser ou ajuster
        </button>
      )}

      {ouvert && (
        <div style={{ display: 'grid', gap: 8, marginTop: 10 }}>
          {err && <div className="banner banner-error">{err}</div>}
          <div className="seg">
            {[['depense', 'Dépense'], ['ajustement', 'Ajustement']].map(([k, l]) => (
              <button key={k} className={sens === k ? 'on' : ''} onClick={() => setSens(k)}>{l}</button>
            ))}
          </div>
          <div className="field">
            <label>Points</label>
            <input type="number" value={points} onChange={(e) => setPoints(e.target.value)} />
          </div>
          <div className="field">
            <label>Motif — le client demandera</label>
            <input
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              placeholder="Entrée offerte, geste commercial…"
            />
          </div>
          <div className="r" style={{ gap: 8 }}>
            <button className="btn ghost sm" type="button" onClick={() => setOuvert(false)}>Annuler</button>
            <button className="btn primary sm" type="button" disabled={busy} onClick={enregistrer}>
              Enregistrer
            </button>
          </div>
        </div>
      )}

      {(fidelite.historique || []).length > 0 && (
        <div style={{ overflowX: 'auto', marginTop: 10 }}>
          <table className="tbl">
            <thead><tr><th>Date</th><th>Mouvement</th><th>Motif</th><th className="num">Points</th></tr></thead>
            <tbody>
              {fidelite.historique.map((m, i) => (
                <tr key={`${m.le}-${i}`}>
                  <td>{dateFr(m.le)}</td>
                  <td>{m.mouvement}</td>
                  <td>{m.motif}</td>
                  <td className="num">{m.points > 0 ? `+${m.points}` : m.points}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

/**
 * LE PARRAINAGE, AU COMPTOIR.
 *
 * Le code s'affiche à la demande — le charger d'office créerait un code de parrainage à tous les
 * clients dont on ouvre la fiche, y compris ceux qui ne parraineront jamais.
 *
 * Deux chiffres suffisent : combien de filleuls, et combien sont **à récompenser**. Le second est
 * le seul qui fasse agir ; « 47 parrainages » se regarde, « 3 à récompenser » se traite.
 *
 * L'écran dit aussi ce qu'il ne fait pas : le code ne part par aucun canal automatique. L'agent le
 * donne. Le taire laisserait croire que le filleul l'a reçu.
 */
function BlocParrainage({ clientId, droits, onMouvement }) {
  const [code, setCode] = useState(null)
  const [liste, setListe] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [codeParrain, setCodeParrain] = useState('')

  const peutLire = aLeDroit(droits, 'fidelite.lire')
  const peutGerer = aLeDroit(droits, 'fidelite.gerer')

  const charger = useCallback(async () => {
    if (!clientId || !peutLire) { setListe(null); return }
    try {
      setListe(await api.parrainages(clientId))
    } catch {
      setListe(null)
    }
  }, [clientId, peutLire])

  useEffect(() => { setCode(null); setErr(null); charger() }, [charger])

  if (!peutLire || !liste) return null

  async function afficherCode() {
    setBusy(true)
    setErr(null)
    try {
      setCode((await api.codeParrainage(clientId)).code)
    } catch (e) {
      setErr(e.message || 'Le code n’a pas pu être obtenu.')
    } finally {
      setBusy(false)
    }
  }

  async function recompenser(id) {
    setBusy(true)
    setErr(null)
    try {
      await api.recompenserParrainage(id)
      await charger()
      onMouvement?.()
    } catch (e) {
      setErr(e.message || 'La récompense n’a pas pu être versée.')
    } finally {
      setBusy(false)
    }
  }

  async function declarer() {
    setBusy(true)
    setErr(null)
    try {
      await api.declarerParrainage({ code: codeParrain.trim().toUpperCase(), refereeRef: clientId })
      setCodeParrain('')
      await charger()
    } catch (e) {
      setErr(e.message || 'Ce parrainage n’a pas pu être déclaré.')
    } finally {
      setBusy(false)
    }
  }

  const filleuls = liste.parrainages || []

  return (
    <div className="card">
      <div className="card-h">
        <span>Parrainage</span>
        {liste.aRecompenser > 0 && (
          <span className="badge warn" style={{ marginLeft: 'auto' }}>
            {liste.aRecompenser} à récompenser
          </span>
        )}
      </div>

      <div className="r" style={{ gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
        {code ? (
          <>
            <span className="sub">Son code :</span>
            <strong style={{ fontFamily: 'monospace', fontSize: 18, letterSpacing: 2 }}>{code}</strong>
          </>
        ) : (
          <button className="btn ghost sm" type="button" disabled={busy} onClick={afficherCode}>
            Afficher son code de parrainage
          </button>
        )}
      </div>

      {code && (
        <div className="sub" style={{ marginTop: 6 }}>
          Ce code n’est envoyé par <strong>aucun canal automatique</strong> : donnez-le au client. Le
          jour où la boutique en ligne saura le porter, elle appellera la même adresse.
        </div>
      )}

      {err && <div className="banner banner-error" style={{ marginTop: 8 }}>{err}</div>}

      {filleuls.length > 0 && (
        <div style={{ overflowX: 'auto', marginTop: 10 }}>
          <table className="tbl">
            <thead>
              <tr><th>Filleul</th><th>Depuis</th><th>État</th><th className="num">Points</th><th /></tr>
            </thead>
            <tbody>
              {filleuls.map((p) => (
                <tr key={p.id}>
                  <td style={{ fontFamily: 'monospace', fontSize: 12 }}>{p.filleul.slice(0, 8)}…</td>
                  <td>{dateFr(p.le)}</td>
                  <td>
                    <span className={`badge ${p.etat === 'eligible' ? 'warn' : p.etat === 'recompense' ? 'good' : 'mut'}`}>
                      {p.libelle}
                    </span>
                  </td>
                  <td className="num">{p.pointsVerses || '—'}</td>
                  <td>
                    {p.etat === 'eligible' && peutGerer && (
                      <button
                        className="btn primary sm"
                        type="button"
                        disabled={busy}
                        onClick={() => recompenser(p.id)}
                      >
                        Récompenser
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {peutGerer && (
        <div className="r" style={{ gap: 8, marginTop: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Ce client a été parrainé — code du parrain</label>
            <input
              value={codeParrain}
              onChange={(e) => setCodeParrain(e.target.value)}
              placeholder="ABCD2345"
              style={{ fontFamily: 'monospace', letterSpacing: 2 }}
            />
          </div>
          <button
            className="btn ghost sm"
            type="button"
            disabled={busy || codeParrain.trim().length < 4}
            onClick={declarer}
          >
            Déclarer
          </button>
        </div>
      )}
    </div>
  )
}
