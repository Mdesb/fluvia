import { useCallback, useEffect, useState } from 'react'
import Modal from '../components/Modal.jsx'
import { confirmer } from '../components/Confirmation.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri.js'

// GROUPES — module transverse `App\Group`. Un groupe de participants (classe scolaire, comité
// d'entreprise, tour-opérateur, association) qu'un établissement reçoit, quel que soit son métier :
// piscine, patinoire, musée, séjours, restauration — et tout métier à venir (l'accrobranche est un
// `Activite` de plus, cet écran n'en sait rien et n'a pas à le savoir).
//
// ⚠ RIEN À VOIR avec le « Groupe » CRM (`App\Organisation\Entity\Groupe`), qui est le locataire.

const TYPES = {
  school: 'Scolaire',
  tour: 'Tour-opérateur',
  works_council: "Comité d'entreprise",
  association: 'Association',
  other: 'Autre',
}

const CATEGORIES = {
  child: 'Enfant',
  adult: 'Adulte',
  accompanist: 'Accompagnateur',
  other: 'Autre',
}

const STATUTS = {
  option: ['Option', 'warn'],
  confirmed: ['Confirmée', 'good'],
  cancelled: ['Annulée', 'mut'],
}

const PAIEMENTS = {
  pending: 'En attente',
  purchase_order: 'Bon de commande',
  invoiced: 'Facturé',
  paid: 'Réglé',
}

function badgeStatut(code) {
  const [libelle, classe] = STATUTS[code] || [code || '—', 'mut']
  return <span className={`badge ${classe}`}>{libelle}</span>
}

function creneauLabel(c) {
  if (!c) return '—'
  const debut = c.debut ? new Date(c.debut) : null
  const quand = debut && !Number.isNaN(debut.getTime())
    ? debut.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
    : idDe(c)
  return quand
}

export default function Groupes({ etabActif, droits }) {
  const peutGerer = aLeDroit(droits, 'group.manage')

  const [groupes, setGroupes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [selection, setSelection] = useState(null) // groupe détaillé
  const [membresSel, setMembresSel] = useState([])
  const [reservations, setReservations] = useState([])
  const [creneaux, setCreneaux] = useState([])
  const [taux, setTaux] = useState([])
  const [forfaits, setForfaits] = useState([])
  const [produits, setProduits] = useState([])

  const [modale, setModale] = useState(null) // { type, ... }

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setGroupes(membres(await api.groupesParticipants()))
    } catch (e) {
      setErreur(e?.message || 'Impossible de lire les groupes.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    charger()
    setSelection(null)
  }, [charger, etabActif])

  // Créneaux de l'établissement, chargés une fois : ils servent à affecter une réservation.
  useEffect(() => {
    let vivant = true
    api.reservationCreneaux()
      .then((r) => { if (vivant) setCreneaux(membres(r)) })
      .catch(() => { if (vivant) setCreneaux([]) })
    return () => { vivant = false }
  }, [etabActif])

  // Taux de TVA de l'exploitant, pour la génération de devis.
  useEffect(() => {
    let vivant = true
    api.tauxTvas()
      .then((r) => { if (vivant) setTaux(membres(r)) })
      .catch(() => { if (vivant) setTaux([]) })
    return () => { vivant = false }
  }, [etabActif])

  // Produits du catalogue, pour composer forfaits et paniers.
  useEffect(() => {
    let vivant = true
    api.produits({ itemsPerPage: 200 })
      .then((r) => { if (vivant) setProduits(membres(r)) })
      .catch(() => { if (vivant) setProduits([]) })
    return () => { vivant = false }
  }, [etabActif])

  // Forfaits groupe, rechargeables après édition.
  const chargerForfaits = useCallback(async () => {
    try {
      setForfaits(membres(await api.groupProducts()))
    } catch {
      setForfaits([])
    }
  }, [])
  useEffect(() => { chargerForfaits() }, [chargerForfaits, etabActif])

  const ouvrir = useCallback(async (id) => {
    setErreur(null)
    try {
      // GET item : on relit le groupe frais plutôt que de se fier à la ligne de liste.
      const detail = await api.groupeParticipant(id)
      setSelection(detail)
      setMembresSel(membres(await api.membresGroupe(id)))
      const toutes = membres(await api.reservationsGroupe())
      setReservations(toutes.filter((b) => idDe(b.group) === id))
    } catch (e) {
      setErreur(e?.message || "Impossible d'ouvrir ce groupe.")
    }
  }, [])

  async function geste(fn, message) {
    setErreur(null)
    setSucces(null)
    try {
      await fn()
      setSucces(message)
      await charger()
      if (selection) await ouvrir(idDe(selection))
    } catch (e) {
      setErreur(e?.message || "L'opération a échoué.")
    }
  }

  if (!aLeDroit(droits, 'group.read')) {
    return <p className="empty">Vous n’avez pas accès aux groupes.</p>
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h2>Groupes</h2>
          <p className="sub">Groupes de participants, transverses à tous les métiers de l’établissement.</p>
        </div>
        {peutGerer && (
          <div className="actions">
            <button className="btn ghost" type="button" onClick={() => setModale({ type: 'forfaits' })}>
              Forfaits groupe
            </button>
            <button className="btn primary" type="button" onClick={() => setModale({ type: 'groupe' })}>
              Nouveau groupe
            </button>
          </div>
        )}
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : groupes.length === 0 ? (
        <p className="empty">Aucun groupe pour l’instant.</p>
      ) : (
        <div className="grid grid-2" style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', alignItems: 'start' }}>
          <div className="card">
            <div className="card-h"><h3>Les groupes</h3></div>
            <div className="card-b" style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr><th>Nom</th><th>Type</th><th className="num">Effectif</th></tr>
                </thead>
                <tbody>
                  {groupes.map((g) => (
                    <tr key={g.id} style={{ fontWeight: selection && idDe(selection) === g.id ? 600 : 400 }}>
                      <td>
                        <button type="button" className="btn ghost sm" onClick={() => ouvrir(g.id)}>{g.label}</button>
                      </td>
                      <td className="sub">{TYPES[g.type] || g.type}</td>
                      <td className="num">{g.headcount}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {selection && (
            <DetailGroupe
              groupe={selection}
              membres={membresSel}
              reservations={reservations}
              creneaux={creneaux}
              peutGerer={peutGerer}
              onGeste={geste}
              onModale={setModale}
            />
          )}
        </div>
      )}

      {modale?.type === 'groupe' && (
        <FormGroupe
          initial={modale.groupe}
          onFermer={() => setModale(null)}
          onValider={async (corps) => {
            const edition = Boolean(modale.groupe)
            await geste(
              () => edition
                ? api.modifierGroupeParticipant(idDe(modale.groupe), corps)
                : api.creerGroupeParticipant(corps),
              edition ? 'Groupe modifié.' : 'Groupe créé.',
            )
            setModale(null)
          }}
        />
      )}

      {modale?.type === 'membre' && (
        <FormMembre
          groupe={selection}
          membreId={modale.membreId}
          onFermer={() => setModale(null)}
          onValider={async (corps, membreId) => {
            await geste(
              () => membreId
                ? api.modifierMembre(membreId, { category: corps.category })
                : api.ajouterMembre(corps),
              membreId ? 'Participant modifié.' : 'Participant ajouté.',
            )
            setModale(null)
          }}
        />
      )}

      {modale?.type === 'reservation' && (
        <FormReservation
          groupe={selection}
          onFermer={() => setModale(null)}
          onValider={async (corps) => {
            await geste(() => api.creerReservationGroupe(corps), 'Réservation créée.')
            setModale(null)
          }}
        />
      )}

      {modale?.type === 'affecter' && (
        <FormAffecter
          reservationId={modale.reservationId}
          creneaux={creneaux}
          onFermer={() => setModale(null)}
          onValider={async (creneauId) => {
            await geste(
              () => api.affecterReservationGroupe(modale.reservationId, { creneau: creneauId }),
              'Réservation affectée.',
            )
            setModale(null)
          }}
        />
      )}

      {modale?.type === 'paiement' && (
        <FormPaiement
          reservationId={modale.reservationId}
          valeur={modale.paymentStatus}
          onFermer={() => setModale(null)}
          onValider={async (paymentStatus) => {
            await geste(
              () => api.modifierReservationGroupe(modale.reservationId, { paymentStatus }),
              'Paiement mis à jour.',
            )
            setModale(null)
          }}
        />
      )}

      {modale?.type === 'facturer' && (
        <FormFacture
          taux={taux}
          onFermer={() => setModale(null)}
          onValider={async (corps) => {
            await geste(() => api.facturerReservationGroupe(modale.reservationId, corps), 'Devis créé.')
            setModale(null)
          }}
        />
      )}

      {modale?.type === 'forfaits' && (
        <FormForfaits produits={produits} taux={taux} onFermer={() => setModale(null)} onChange={chargerForfaits} />
      )}

      {modale?.type === 'panier' && (
        <FormPanier reservationId={modale.reservationId} forfaits={forfaits} produits={produits} taux={taux} onFermer={() => setModale(null)} />
      )}

      {modale?.type === 'detailReservation' && (
        <DetailReservation reservationId={modale.reservationId} onFermer={() => setModale(null)} />
      )}
    </div>
  )
}

function DetailGroupe({ groupe, membres: liste, reservations, creneaux, peutGerer, onGeste, onModale }) {
  return (
    <div className="card">
      <div className="card-h">
        <h3>{groupe.label}</h3>
        {peutGerer && (
          <button className="btn ghost sm" type="button" onClick={() => onModale({ type: 'groupe', groupe })}>
            Modifier
          </button>
        )}
      </div>
      <div className="card-b">
        <div className="deflist">
          <div><span>Type</span><span>{TYPES[groupe.type] || groupe.type}</span></div>
          <div><span>Organisateur</span><span>{groupe.organizerName || '—'}</span></div>
          <div><span>Courriel</span><span>{groupe.organizerEmail || '—'}</span></div>
          <div><span>Téléphone</span><span>{groupe.organizerPhone || '—'}</span></div>
          <div><span>Effectif prévu</span><span>{groupe.headcount}</span></div>
          {groupe.notes && <div><span>Notes</span><span>{groupe.notes}</span></div>}
        </div>

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h4>Participants ({liste.length})</h4>
          {peutGerer && (
            <button className="btn sm" type="button" onClick={() => onModale({ type: 'membre' })}>Ajouter</button>
          )}
        </div>
        {liste.length === 0 ? (
          <p className="empty">Effectif renseigné sans liste nominative.</p>
        ) : (
          <table className="tbl">
            <thead><tr><th>Nom</th><th>Catégorie</th>{peutGerer && <th />}</tr></thead>
            <tbody>
              {liste.map((m) => (
                <tr key={m.id}>
                  <td>{m.firstName} {m.lastName}</td>
                  <td className="sub">{CATEGORIES[m.category] || m.category}</td>
                  {peutGerer && (
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => onModale({ type: 'membre', membreId: m.id })}>Modifier</button>
                      {' '}
                      <button
                        className="btn danger sm"
                        type="button"
                        onClick={async () => {
                          if (!await confirmer(`Retirer ${m.firstName} ${m.lastName} du groupe ?`)) return
                          await onGeste(() => api.supprimerMembre(m.id), 'Participant retiré.')
                        }}
                      >
                        Retirer
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h4>Réservations ({reservations.length})</h4>
          {peutGerer && (
            <button className="btn sm" type="button" onClick={() => onModale({ type: 'reservation' })}>Nouvelle réservation</button>
          )}
        </div>
        {reservations.length === 0 ? (
          <p className="empty">Aucune réservation pour ce groupe.</p>
        ) : (
          <table className="tbl">
            <thead><tr><th>Statut</th><th className="num">Effectif</th><th>Créneau</th><th>Paiement</th><th /></tr></thead>
            <tbody>
              {reservations.map((r) => (
                <tr key={r.id}>
                  <td>{badgeStatut(r.status)}</td>
                  <td className="num">{r.effectif}{r.accompagnateurs ? ` (+${r.accompagnateurs})` : ''}</td>
                  <td className="sub">{creneauLabel(creneaux.find((c) => idDe(c) === idDe(r.creneau)) || r.creneau)}</td>
                  <td className="sub">{PAIEMENTS[r.paymentStatus] || r.paymentStatus}</td>
                  <td className="num">
                    <button className="btn ghost sm" type="button" onClick={() => onModale({ type: 'detailReservation', reservationId: r.id })}>Détail</button>
                    {peutGerer && r.status !== 'cancelled' && (
                      <>
                        {' '}
                        <button className="btn ghost sm" type="button" onClick={() => onModale({ type: 'panier', reservationId: r.id })}>Panier</button>
                        {' '}
                        <button className="btn ghost sm" type="button" onClick={() => onModale({ type: 'affecter', reservationId: r.id })}>Affecter</button>
                        {' '}
                        <button className="btn ghost sm" type="button" onClick={() => onModale({ type: 'paiement', reservationId: r.id, paymentStatus: r.paymentStatus })}>Paiement</button>
                        {' '}
                        {r.commercialDocument
                          ? <span className="badge good">Devis</span>
                          : <button className="btn primary sm" type="button" onClick={() => onModale({ type: 'facturer', reservationId: r.id })}>Facturer</button>}
                        {r.status === 'option' && (
                          <>
                            {' '}
                            <button className="btn primary sm" type="button" onClick={() => onGeste(() => api.confirmerReservationGroupe(r.id), 'Réservation confirmée.')}>Confirmer</button>
                          </>
                        )}
                        {' '}
                        <button
                          className="btn danger sm"
                          type="button"
                          onClick={async () => {
                            if (!await confirmer('Annuler cette réservation ? C’est définitif.')) return
                            await onGeste(() => api.annulerReservationGroupe(r.id), 'Réservation annulée.')
                          }}
                        >
                          Annuler
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

function FormGroupe({ initial, onFermer, onValider }) {
  const [label, setLabel] = useState(initial?.label || '')
  const [type, setType] = useState(initial?.type || 'school')
  const [organizerName, setOrganizerName] = useState(initial?.organizerName || '')
  const [organizerEmail, setOrganizerEmail] = useState(initial?.organizerEmail || '')
  const [organizerPhone, setOrganizerPhone] = useState(initial?.organizerPhone || '')
  const [headcount, setHeadcount] = useState(String(initial?.headcount ?? ''))
  const [notes, setNotes] = useState(initial?.notes || '')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      await onValider({
        label: label.trim(),
        type,
        organizerName: organizerName.trim(),
        organizerEmail: organizerEmail.trim() || null,
        organizerPhone: organizerPhone.trim() || null,
        headcount: headcount === '' ? 0 : Math.max(0, parseInt(headcount, 10) || 0),
        notes: notes.trim() || null,
      })
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre={initial ? 'Modifier le groupe' : 'Nouveau groupe'}>
      <form onSubmit={soumettre}>
        <div className="field">
          <label htmlFor="g-label">Nom du groupe *</label>
          <input id="g-label" className="input" value={label} onChange={(e) => setLabel(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="g-type">Type</label>
          <select id="g-type" className="input" value={type} onChange={(e) => setType(e.target.value)}>
            {Object.entries(TYPES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="g-org">Organisateur *</label>
          <input id="g-org" className="input" value={organizerName} onChange={(e) => setOrganizerName(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="g-mail">Courriel</label>
          <input id="g-mail" type="email" className="input" value={organizerEmail} onChange={(e) => setOrganizerEmail(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="g-tel">Téléphone</label>
          <input id="g-tel" className="input" value={organizerPhone} onChange={(e) => setOrganizerPhone(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="g-eff">Effectif prévu</label>
          <input id="g-eff" type="number" min="0" className="input num" value={headcount} onChange={(e) => setHeadcount(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="g-notes">Notes</label>
          <textarea id="g-notes" className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>{initial ? 'Enregistrer' : 'Créer'}</button>
        </div>
      </form>
    </Modal>
  )
}

function FormMembre({ groupe, membreId, onFermer, onValider }) {
  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [category, setCategory] = useState('adult')
  const [envoi, setEnvoi] = useState(false)
  const [chargement, setChargement] = useState(Boolean(membreId))

  useEffect(() => {
    if (!membreId) return
    let vivant = true
    // GET item : relire le participant avant de l'éditer.
    api.membreGroupe(membreId)
      .then((m) => {
        if (!vivant) return
        setFirstName(m.firstName || '')
        setLastName(m.lastName || '')
        setCategory(m.category || 'adult')
      })
      .finally(() => { if (vivant) setChargement(false) })
    return () => { vivant = false }
  }, [membreId])

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      await onValider({
        group: `/api/participant_groups/${idDe(groupe)}`,
        firstName: firstName.trim(),
        lastName: lastName.trim(),
        category,
      }, membreId)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre={membreId ? 'Modifier le participant' : 'Ajouter un participant'}>
      {chargement ? (
        <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
      ) : (
        <form onSubmit={soumettre}>
          {!membreId && (
            <>
              <div className="field">
                <label htmlFor="m-prenom">Prénom *</label>
                <input id="m-prenom" className="input" value={firstName} onChange={(e) => setFirstName(e.target.value)} required />
              </div>
              <div className="field">
                <label htmlFor="m-nom">Nom *</label>
                <input id="m-nom" className="input" value={lastName} onChange={(e) => setLastName(e.target.value)} required />
              </div>
            </>
          )}
          <div className="field">
            <label htmlFor="m-cat">Catégorie</label>
            <select id="m-cat" className="input" value={category} onChange={(e) => setCategory(e.target.value)}>
              {Object.entries(CATEGORIES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div className="modal-actions">
            <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
            <button className="btn primary" type="submit" disabled={envoi}>{membreId ? 'Enregistrer' : 'Ajouter'}</button>
          </div>
        </form>
      )}
    </Modal>
  )
}

function FormReservation({ groupe, onFermer, onValider }) {
  const [effectif, setEffectif] = useState(String(groupe?.headcount ?? ''))
  const [accompagnateurs, setAccompagnateurs] = useState('0')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      await onValider({
        group: `/api/participant_groups/${idDe(groupe)}`,
        effectif: effectif === '' ? 0 : Math.max(0, parseInt(effectif, 10) || 0),
        accompagnateurs: accompagnateurs === '' ? 0 : Math.max(0, parseInt(accompagnateurs, 10) || 0),
      })
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre="Nouvelle réservation de groupe">
      <form onSubmit={soumettre}>
        <p className="sub">Pour le groupe « {groupe?.label} ». La réservation part en option ; on l’affecte à un créneau ensuite.</p>
        <div className="field">
          <label htmlFor="r-eff">Effectif</label>
          <input id="r-eff" type="number" min="0" className="input num" value={effectif} onChange={(e) => setEffectif(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="r-acc">Accompagnateurs</label>
          <input id="r-acc" type="number" min="0" className="input num" value={accompagnateurs} onChange={(e) => setAccompagnateurs(e.target.value)} />
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>Créer</button>
        </div>
      </form>
    </Modal>
  )
}

function FormAffecter({ creneaux, onFermer, onValider }) {
  const [creneau, setCreneau] = useState('')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    if (!creneau) return
    setEnvoi(true)
    try {
      await onValider(creneau)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre="Affecter à un créneau">
      <form onSubmit={soumettre}>
        <div className="field">
          <label htmlFor="a-creneau">Créneau *</label>
          <select id="a-creneau" className="input" value={creneau} onChange={(e) => setCreneau(e.target.value)} required>
            <option value="">— choisir —</option>
            {creneaux.map((c) => (
              <option key={idDe(c)} value={idDe(c)}>{creneauLabel(c)}</option>
            ))}
          </select>
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi || !creneau}>Affecter</button>
        </div>
      </form>
    </Modal>
  )
}

function FormPaiement({ valeur, onFermer, onValider }) {
  const [paymentStatus, setPaymentStatus] = useState(valeur || 'pending')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      await onValider(paymentStatus)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre="État de paiement">
      <form onSubmit={soumettre}>
        <div className="field">
          <label htmlFor="p-statut">Paiement</label>
          <select id="p-statut" className="input" value={paymentStatus} onChange={(e) => setPaymentStatus(e.target.value)}>
            {Object.entries(PAIEMENTS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>Enregistrer</button>
        </div>
      </form>
    </Modal>
  )
}

function FormFacture({ taux, onFermer, onValider }) {
  const [tauxTva, setTauxTva] = useState('')
  const [prix, setPrix] = useState('')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      // Champs optionnels : s'il y a un panier, le devis le reprend et ces valeurs sont ignorées.
      // Sinon (facturation « à la tête »), le taux est requis côté serveur (422 explicite).
      const corps = {}
      if (tauxTva) corps.tauxTva = tauxTva
      if (prix.trim() !== '') corps.prixUnitaireHT = prix.trim()
      await onValider(corps)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre="Facturer — générer un devis">
      <form onSubmit={soumettre}>
        <p className="sub">Le devis part au payeur du groupe et entre dans la chaîne devis → bon de commande → facture (NF525, comptabilité incluse).</p>
        <p className="sub">Si un panier est composé, laissez ces champs vides : le devis reprend le panier, chaque ligne avec sa TVA. Sinon, choisissez un taux (le prix par défaut est le tarif de l’activité).</p>
        <div className="field">
          <label htmlFor="f-tva">Taux de TVA</label>
          <select id="f-tva" className="input" value={tauxTva} onChange={(e) => setTauxTva(e.target.value)}>
            <option value="">— aucun (panier) —</option>
            {taux.map((t) => <option key={idDe(t)} value={idDe(t)}>{t.libelle} ({t.taux} %)</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="f-prix">Prix unitaire HT (facturation à la tête)</label>
          <input id="f-prix" className="input num" value={prix} onChange={(e) => setPrix(e.target.value)} placeholder="ex. 12.00" />
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>Générer le devis</button>
        </div>
      </form>
    </Modal>
  )
}

function DetailReservation({ reservationId, onFermer }) {
  const [resa, setResa] = useState(null)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    let vivant = true
    // GET item : la fiche complète d'une réservation.
    api.reservationGroupe(reservationId)
      .then((r) => { if (vivant) setResa(r) })
      .catch((e) => { if (vivant) setErreur(e?.message || 'Lecture impossible.') })
    return () => { vivant = false }
  }, [reservationId])

  return (
    <Modal open onClose={onFermer} titre="Réservation de groupe">
      {erreur ? (
        <p className="empty">{erreur}</p>
      ) : !resa ? (
        <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
      ) : (
        <div className="deflist">
          <div><span>Statut</span><span>{badgeStatut(resa.status)}</span></div>
          <div><span>Effectif</span><span>{resa.effectif}</span></div>
          <div><span>Accompagnateurs</span><span>{resa.accompagnateurs}</span></div>
          <div><span>Paiement</span><span>{PAIEMENTS[resa.paymentStatus] || resa.paymentStatus}</span></div>
          <div><span>Créneau</span><span>{resa.creneau ? idDe(resa.creneau) : '—'}</span></div>
          <div><span>Option jusqu’au</span><span>{resa.optionExpiresAt ? new Date(resa.optionExpiresAt).toLocaleDateString('fr-FR') : '—'}</span></div>
        </div>
      )}
    </Modal>
  )
}

// ── Forfaits groupe : gestion des produits composites réutilisables ─────────────────────────────
function FormForfaits({ produits, taux, onFermer, onChange }) {
  const [liste, setListe] = useState([])
  const [chargement, setChargement] = useState(true)
  const [creation, setCreation] = useState(false)
  const [erreur, setErreur] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    try { setListe(membres(await api.groupProducts())) } catch (e) { setErreur(e?.message || 'Lecture impossible.') } finally { setChargement(false) }
  }, [])
  useEffect(() => { recharger() }, [recharger])

  async function supprimer(id) {
    if (!await confirmer('Supprimer ce forfait ?')) return
    setErreur(null)
    try { await api.supprimerGroupProduct(id); await recharger(); onChange?.() } catch (e) { setErreur(e?.message || 'Suppression impossible.') }
  }
  async function basculer(f) {
    setErreur(null)
    try { await api.modifierGroupProduct(idDe(f), { actif: !f.actif }); await recharger(); onChange?.() } catch (e) { setErreur(e?.message || 'Modification impossible.') }
  }

  return (
    <Modal open onClose={onFermer} titre="Forfaits groupe" taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {creation ? (
        <FormForfait
          produits={produits}
          taux={taux}
          onAnnuler={() => setCreation(false)}
          onEnregistre={async () => { setCreation(false); await recharger(); onChange?.() }}
        />
      ) : (
        <>
          <p className="sub">Un forfait est un mix de produits réutilisable (p. ex. 3 entrées + 5 audioguides + 5 visites) qu’on applique à une réservation.</p>
          <div className="actions">
            <button className="btn primary sm" type="button" onClick={() => setCreation(true)}>Nouveau forfait</button>
          </div>
          {chargement ? (
            <div className="center"><div className="spinner" /></div>
          ) : liste.length === 0 ? (
            <p className="empty">Aucun forfait pour l’instant.</p>
          ) : (
            <table className="tbl">
              <thead><tr><th>Forfait</th><th className="num">Lignes</th><th>État</th><th /></tr></thead>
              <tbody>
                {liste.map((f) => (
                  <tr key={f.id}>
                    <td>{f.label}</td>
                    <td className="num">{(f.lines || []).length}</td>
                    <td>{f.actif ? <span className="badge good">Actif</span> : <span className="badge mut">Inactif</span>}</td>
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => basculer(f)}>{f.actif ? 'Désactiver' : 'Activer'}</button>
                      {' '}
                      <button className="btn danger sm" type="button" onClick={() => supprimer(idDe(f))}>Supprimer</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </>
      )}
    </Modal>
  )
}

function FormForfait({ produits, taux, onAnnuler, onEnregistre }) {
  const ligneVide = { produit: '', quantite: '1', prixUnitaireHT: '', tauxTva: '' }
  const [label, setLabel] = useState('')
  const [lignes, setLignes] = useState([{ ...ligneVide }])
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)

  const majLigne = (i, champ, val) => setLignes((ls) => ls.map((l, k) => (k === i ? { ...l, [champ]: val } : l)))

  async function soumettre(e) {
    e.preventDefault()
    const valides = lignes.filter((l) => l.produit && l.tauxTva)
    if (label.trim() === '' || valides.length === 0) { setErreur('Un nom et au moins une ligne (produit + TVA) sont requis.'); return }
    setEnvoi(true); setErreur(null)
    try {
      await api.creerGroupProduct({
        label: label.trim(),
        lines: valides.map((l) => ({
          produit: `/api/produits/${l.produit}`,
          quantite: Math.max(1, parseInt(l.quantite, 10) || 1),
          prixUnitaireHT: l.prixUnitaireHT.trim() === '' ? '0.00' : l.prixUnitaireHT.trim(),
          tauxTva: `/api/taux_tvas/${l.tauxTva}`,
        })),
      })
      await onEnregistre()
    } catch (e) { setErreur(e?.message || 'Création impossible.') } finally { setEnvoi(false) }
  }

  return (
    <form onSubmit={soumettre}>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <div className="field">
        <label htmlFor="ff-label">Nom du forfait *</label>
        <input id="ff-label" className="input" value={label} onChange={(e) => setLabel(e.target.value)} required />
      </div>
      <h4>Lignes du forfait</h4>
      <div style={{ overflowX: 'auto' }}>
        <table className="tbl">
          <thead><tr><th>Produit</th><th className="num">Qté</th><th className="num">PU HT</th><th>TVA</th><th /></tr></thead>
          <tbody>
            {lignes.map((l, i) => (
              <tr key={i}>
                <td>
                  <select className="input" value={l.produit} onChange={(e) => majLigne(i, 'produit', e.target.value)}>
                    <option value="">— produit —</option>
                    {produits.map((p) => <option key={idDe(p)} value={idDe(p)}>{p.libelleRecherche || idDe(p)}</option>)}
                  </select>
                </td>
                <td className="num"><input className="input num" type="number" min="1" value={l.quantite} onChange={(e) => majLigne(i, 'quantite', e.target.value)} /></td>
                <td className="num"><input className="input num" value={l.prixUnitaireHT} onChange={(e) => majLigne(i, 'prixUnitaireHT', e.target.value)} placeholder="0.00" /></td>
                <td>
                  <select className="input" value={l.tauxTva} onChange={(e) => majLigne(i, 'tauxTva', e.target.value)}>
                    <option value="">— TVA —</option>
                    {taux.map((t) => <option key={idDe(t)} value={idDe(t)}>{t.taux} %</option>)}
                  </select>
                </td>
                <td className="num"><button className="btn danger sm" type="button" onClick={() => setLignes((ls) => ls.filter((_, k) => k !== i))}>×</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="actions">
        <button className="btn ghost sm" type="button" onClick={() => setLignes((ls) => [...ls, { ...ligneVide }])}>Ajouter une ligne</button>
      </div>
      <div className="modal-actions">
        <button className="btn ghost" type="button" onClick={onAnnuler}>Annuler</button>
        <button className="btn primary" type="submit" disabled={envoi}>Créer le forfait</button>
      </div>
    </form>
  )
}

// ── Panier d'une réservation : forfait appliqué et/ou lignes à la carte ─────────────────────────
function FormPanier({ reservationId, forfaits, produits, taux, onFermer }) {
  const vide = { produit: '', quantite: '1', prixUnitaireHT: '', tauxTva: '' }
  const [items, setItems] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [forfaitChoisi, setForfaitChoisi] = useState('')
  const [nouveau, setNouveau] = useState({ ...vide })

  const recharger = useCallback(async () => {
    setChargement(true)
    try { setItems(membres(await api.articlesReservation(reservationId))) } catch (e) { setErreur(e?.message || 'Lecture impossible.') } finally { setChargement(false) }
  }, [reservationId])
  useEffect(() => { recharger() }, [recharger])

  const nomProduit = (iri) => produits.find((p) => idDe(p) === idDe(iri))?.libelleRecherche || '—'
  const libTaux = (iri) => { const t = taux.find((x) => idDe(x) === idDe(iri)); return t ? `${t.taux} %` : '—' }

  async function appliquer() {
    if (!forfaitChoisi) return
    setErreur(null)
    try { await api.appliquerForfait(reservationId, { groupProduct: `/api/group_products/${forfaitChoisi}` }); setForfaitChoisi(''); await recharger() } catch (e) { setErreur(e?.message || 'Application impossible.') }
  }
  async function ajouter() {
    if (!nouveau.produit || !nouveau.tauxTva) { setErreur('Produit et TVA requis.'); return }
    setErreur(null)
    try {
      await api.ajouterArticle({
        booking: `/api/group_bookings/${reservationId}`,
        produit: `/api/produits/${nouveau.produit}`,
        quantite: Math.max(1, parseInt(nouveau.quantite, 10) || 1),
        prixUnitaireHT: nouveau.prixUnitaireHT.trim() === '' ? '0.00' : nouveau.prixUnitaireHT.trim(),
        tauxTva: `/api/taux_tvas/${nouveau.tauxTva}`,
      })
      setNouveau({ ...vide })
      await recharger()
    } catch (e) { setErreur(e?.message || 'Ajout impossible.') }
  }

  return (
    <Modal open onClose={onFermer} titre="Panier de la réservation" taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <div className="field">
        <label htmlFor="pa-forfait">Appliquer un forfait</label>
        <div className="actions">
          <select id="pa-forfait" className="input" value={forfaitChoisi} onChange={(e) => setForfaitChoisi(e.target.value)}>
            <option value="">— choisir un forfait —</option>
            {forfaits.filter((f) => f.actif).map((f) => <option key={idDe(f)} value={idDe(f)}>{f.label}</option>)}
          </select>
          <button className="btn sm" type="button" onClick={appliquer} disabled={!forfaitChoisi}>Appliquer</button>
        </div>
      </div>

      <h4>Articles ({items.length})</h4>
      {chargement ? (
        <div className="center"><div className="spinner" /></div>
      ) : items.length === 0 ? (
        <p className="empty">Panier vide. Appliquez un forfait ou ajoutez des produits ci-dessous.</p>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead><tr><th>Produit</th><th className="num">Qté</th><th className="num">PU HT</th><th>TVA</th><th>Origine</th><th /></tr></thead>
            <tbody>
              {items.map((it) => (
                <tr key={it.id}>
                  <td>{nomProduit(it.produit)}</td>
                  <td className="num">
                    <input
                      className="input num" type="number" min="1" defaultValue={it.quantite} style={{ width: '4.5rem' }}
                      onBlur={async (e) => { try { await api.modifierArticle(it.id, { quantite: Math.max(1, parseInt(e.target.value, 10) || 1) }); await recharger() } catch (err) { setErreur(err?.message || 'Modification impossible.') } }}
                    />
                  </td>
                  <td className="num">{it.prixUnitaireHT}</td>
                  <td>{libTaux(it.tauxTva)}</td>
                  <td className="sub">{it.source ? 'forfait' : 'à la carte'}</td>
                  <td className="num"><button className="btn danger sm" type="button" onClick={async () => { try { await api.supprimerArticle(it.id); await recharger() } catch (err) { setErreur(err?.message || 'Suppression impossible.') } }}>Retirer</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <h4>Ajouter un produit</h4>
      <div style={{ overflowX: 'auto' }}>
        <table className="tbl">
          <tbody>
            <tr>
              <td>
                <select className="input" value={nouveau.produit} onChange={(e) => setNouveau((n) => ({ ...n, produit: e.target.value }))}>
                  <option value="">— produit —</option>
                  {produits.map((p) => <option key={idDe(p)} value={idDe(p)}>{p.libelleRecherche || idDe(p)}</option>)}
                </select>
              </td>
              <td className="num"><input className="input num" type="number" min="1" value={nouveau.quantite} onChange={(e) => setNouveau((n) => ({ ...n, quantite: e.target.value }))} /></td>
              <td className="num"><input className="input num" value={nouveau.prixUnitaireHT} onChange={(e) => setNouveau((n) => ({ ...n, prixUnitaireHT: e.target.value }))} placeholder="0.00" /></td>
              <td>
                <select className="input" value={nouveau.tauxTva} onChange={(e) => setNouveau((n) => ({ ...n, tauxTva: e.target.value }))}>
                  <option value="">— TVA —</option>
                  {taux.map((t) => <option key={idDe(t)} value={idDe(t)}>{t.taux} %</option>)}
                </select>
              </td>
              <td className="num"><button className="btn sm" type="button" onClick={ajouter}>Ajouter</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="modal-actions">
        <button className="btn ghost" type="button" onClick={onFermer}>Fermer</button>
      </div>
    </Modal>
  )
}
