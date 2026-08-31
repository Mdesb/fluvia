import { useEffect, useState, useCallback } from 'react'
import { api } from '../api/client.js'
import Modal from './Modal.jsx'

// Nom d'affichage d'un client (physique ou personne morale).
export function nomClient(c) {
  if (!c) return '—'
  if (c.raisonSociale) return c.raisonSociale
  const nom = [c.prenom, c.nom].filter(Boolean).join(' ').trim()
  return nom || c.email || 'Client'
}

// Modale de sélection / création rapide d'un client, pour rattacher un bénéficiaire à une vente
// (produit nominatif, RG-M2-04 / CA-7). Deux onglets : rechercher un client existant
// (GET /api/crm/clients/recherche) ou en créer un (POST /api/clients). `onSelect` reçoit
// l'objet client retenu ({ id, nom, prenom, ... }).
//
// DEUX RÉGLAGES, PARCE QUE « DÉSIGNER QUELQU'UN » N'EST PAS TOUJOURS « LE RATTACHER À UNE VENTE ».
//
// `titre` : le libellé « Rattacher un client » décrit le premier usage, pas le composant. Sur
// l'écran des données personnelles, on ne rattache personne — on désigne la personne qui a écrit.
//
// `avecCreation` : l'onglet de création n'a pas sa place partout. Sur l'écran RGPD il proposerait
// de CRÉER une fiche là où l'on vient précisément d'en effacer une — et une fiche créée là serait
// aussitôt anonymisée, c'est-à-dire de la donnée personnelle collectée pour rien.
//
// Les deux gardent le comportement d'origine par défaut : les appelants existants ne changent pas.
export default function ClientPicker({
  open,
  onClose,
  onSelect,
  titre = 'Rattacher un client',
  avecCreation = true,
}) {
  const [mode, setMode] = useState('recherche') // 'recherche' | 'creer'

  return (
    <Modal open={open} onClose={onClose} titre={titre} taille="md">
      {avecCreation && (
        <div className="seg" style={{ marginBottom: 14 }}>
          <button type="button" className={mode === 'recherche' ? 'on' : ''} onClick={() => setMode('recherche')}>
            Rechercher
          </button>
          <button type="button" className={mode === 'creer' ? 'on' : ''} onClick={() => setMode('creer')}>
            Créer un client
          </button>
        </div>
      )}
      {mode === 'recherche' || !avecCreation ? (
        <RechercheClient
          onSelect={onSelect}
          onBasculerCreation={avecCreation ? () => setMode('creer') : null}
        />
      ) : (
        <CreationClient onCree={onSelect} />
      )}
    </Modal>
  )
}

function RechercheClient({ onSelect, onBasculerCreation }) {
  const [q, setQ] = useState('')
  const [items, setItems] = useState([])
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)

  const rechercher = useCallback(async (terme) => {
    setChargement(true)
    setErreur(null)
    try {
      const res = await api.rechercheClients({ q: terme || '', itemsPerPage: 20 })
      setItems(res.items || [])
    } catch (e) {
      setErreur(e.message)
      setItems([])
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    const t = setTimeout(() => rechercher(q), 300)
    return () => clearTimeout(t)
  }, [q, rechercher])

  return (
    <div>
      <div className="field" style={{ marginBottom: 12 }}>
        <label htmlFor="cp-q">Nom, prénom, raison sociale, e-mail, téléphone</label>
        <input
          id="cp-q"
          className="input"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Rechercher un client…"
          autoFocus
        />
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : (
        <div style={{ maxHeight: 320, overflowY: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr><th>Client</th><th>Type</th><th></th></tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.id}>
                  <td>
                    <span className="nm">{nomClient(c)}</span>
                    {c.estMineur && <span className="badge warn" style={{ marginLeft: 6 }}>mineur</span>}
                  </td>
                  <td>{c.type === 'morale' ? 'Personne morale' : 'Particulier'}</td>
                  <td className="num">
                    <button className="btn sm primary" type="button" onClick={() => onSelect(c)}>
                      Choisir
                    </button>
                  </td>
                </tr>
              ))}
              {items.length === 0 && (
                <tr>
                  <td colSpan={3} className="empty">
                    Aucun client trouvé.{' '}
                    {onBasculerCreation && (
                      <button className="btn ghost sm" type="button" onClick={onBasculerCreation}>
                        Créer un client
                      </button>
                    )}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function CreationClient({ onCree }) {
  const [nom, setNom] = useState('')
  const [prenom, setPrenom] = useState('')
  const [email, setEmail] = useState('')
  const [telephone, setTelephone] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  async function soumettre(e) {
    e.preventDefault()
    if (!nom.trim()) return
    setEnCours(true)
    setErreur(null)
    try {
      const client = await api.creerClient({
        type: 'physique',
        nom: nom.trim(),
        prenom: prenom.trim() || undefined,
        email: email.trim() || undefined,
        telephone: telephone.trim() || undefined,
      })
      onCree(client)
    } catch (err) {
      setErreur(err.message || 'Échec de la création du client.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <form onSubmit={soumettre}>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="cp-nom">Nom *</label>
          <input id="cp-nom" className="input" value={nom} onChange={(e) => setNom(e.target.value)} required autoFocus />
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="cp-prenom">Prénom</label>
          <input id="cp-prenom" className="input" value={prenom} onChange={(e) => setPrenom(e.target.value)} />
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="cp-email">E-mail</label>
          <input id="cp-email" className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="cp-tel">Téléphone</label>
          <input id="cp-tel" className="input" value={telephone} onChange={(e) => setTelephone(e.target.value)} />
        </div>
      </div>
      <div style={{ marginTop: 16, display: 'flex', justifyContent: 'flex-end' }}>
        <button className="btn primary" type="submit" disabled={enCours || !nom.trim()}>
          {enCours ? 'Création…' : 'Créer et rattacher'}
        </button>
      </div>
    </form>
  )
}
