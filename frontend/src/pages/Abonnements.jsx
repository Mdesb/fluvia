import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import ClientPicker, { nomClient } from '../components/ClientPicker.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'
import { idDe } from '../api/iri.js'

// Onglet Exploitation « Abonnements » — vue de gestion transverse des abonnements clients.
//
// Lot livrable (Issue #14) : surface l'API AbonnementFitness cloisonnée existante. Voir / suspendre /
// résilier / créer passent par les opérations sur mesure du serveur (le prix vient de la formule du
// catalogue, « pas de prix libre »). La création couvre pour l'instant le mandat « IBAN saisi » ; les
// cas « mandat existant » et « en attente » arrivent avec le chantier caisse→abonnement (backend).
//
// ⚠ Discipline des états : `null` = on lit ; `undefined` = on n'a PAS PU lire (refus) ; un tableau =
// lu. Jamais « — » là où rien n'a été mesuré, jamais de `.find`/accès sur `null`.

const LABEL_STATUT = {
  actif: 'Actif',
  suspendu: 'Suspendu',
  en_pause: 'En pause',
  resilie: 'Résilié',
  expire: 'Expiré',
}

function classeStatut(s) {
  if (s === 'actif') return 'good'
  if (s === 'resilie' || s === 'expire') return 'crit'
  return 'warn'
}

function texteBeneficiaire(b) {
  if (!b) return null
  if (typeof b === 'string') return null
  const nom = [b.prenom, b.nom].filter(Boolean).join(' ').trim()
  return nom || b.nomComplet || b.libelle || null
}

function dateFr(v) {
  if (!v) return '—'
  try {
    return new Date(v).toLocaleDateString('fr-FR')
  } catch {
    return '—'
  }
}

export default function Abonnements({ droits }) {
  const [liste, setListe] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(null)
  const [creation, setCreation] = useState(false)
  const peutGerer = aLeDroit(droits, 'sport.gerer_abonnement')

  const charger = useCallback(async () => {
    setErreur(null)
    try {
      setListe(membres(await api.listerAbonnements()))
    } catch (e) {
      setListe(undefined)
      setErreur(e?.message || "La liste n'a pas pu être lue.")
    }
  }, [])

  useEffect(() => {
    charger()
  }, [charger])

  const suspendre = useCallback(
    async (a) => {
      const motif = window.prompt('Motif de la suspension (facultatif) :', '')
      if (motif === null) return
      setEnCours(idDe(a))
      try {
        await api.pauserAbonnement(idDe(a), { motif })
        await charger()
      } catch (e) {
        setErreur('Suspension refusée : ' + (e?.message || 'erreur'))
      } finally {
        setEnCours(null)
      }
    },
    [charger],
  )

  const resilier = useCallback(
    async (a) => {
      const motif = window.prompt('Motif de la résiliation (obligatoire) :', '')
      if (motif === null) return
      if (!motif.trim()) {
        setErreur('La résiliation exige un motif.')
        return
      }
      setEnCours(idDe(a))
      try {
        await api.resilierAbonnement(idDe(a), { motif: motif.trim() })
        await charger()
      } catch (e) {
        setErreur('Résiliation refusée : ' + (e?.message || 'erreur'))
      } finally {
        setEnCours(null)
      }
    },
    [charger],
  )

  const corps = useMemo(() => {
    if (liste === null)
      return (
        <div className="center" style={{ minHeight: 140 }}>
          <div className="spinner" />
        </div>
      )
    if (liste === undefined)
      return (
        <div className="banner banner-error">
          Les abonnements n'ont pas pu être lus{erreur ? ` (${erreur})` : ''}. Vérifie tes droits
          (sport.lire).
        </div>
      )
    if (liste.length === 0)
      return <p className="empty">Aucun abonnement pour cet établissement.</p>

    return (
      <div style={{ overflowX: 'auto' }}>
        <table className="tbl">
          <thead>
            <tr>
              <th>Payeur</th>
              <th>Adhérent</th>
              <th>Statut</th>
              <th>Périodicité</th>
              <th className="num">Montant</th>
              <th>Fin d'engagement</th>
              {peutGerer && <th>Actions</th>}
            </tr>
          </thead>
          <tbody>
            {liste.map((a) => {
              const s = (a.statut || '').toLowerCase()
              const nomAdherent = texteBeneficiaire(a.adherent)
              const occupe = enCours === idDe(a)
              return (
                <tr key={idDe(a)}>
                  <td>{nomClient(a.payeur) || <span className="sub">—</span>}</td>
                  <td>{nomAdherent || <span className="sub">—</span>}</td>
                  <td>
                    <span className={`badge ${classeStatut(s)}`}>
                      {LABEL_STATUT[s] || a.statut || '—'}
                    </span>
                  </td>
                  <td>{a.periodicite || <span className="sub">—</span>}</td>
                  <td className="num">
                    {typeof a.montantCentimes === 'number' ? euros(a.montantCentimes / 100) : '—'}
                  </td>
                  <td>{dateFr(a.dateFinEngagement)}</td>
                  {peutGerer && (
                    <td style={{ whiteSpace: 'nowrap' }}>
                      {s === 'actif' && (
                        <button type="button" className="btn" disabled={occupe} onClick={() => suspendre(a)}>
                          Suspendre
                        </button>
                      )}
                      {s !== 'resilie' && (
                        <button
                          type="button"
                          className="btn danger"
                          disabled={occupe}
                          onClick={() => resilier(a)}
                          style={{ marginLeft: 6 }}
                        >
                          Résilier
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    )
  }, [liste, erreur, peutGerer, enCours, suspendre, resilier])

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h2>Abonnements</h2>
          <p className="sub">Gestion des abonnements clients de l'établissement.</p>
        </div>
        {peutGerer && (
          <button type="button" className="btn primary" onClick={() => setCreation(true)}>
            Nouvel abonnement
          </button>
        )}
      </div>

      {liste !== undefined && erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="card">
        <div className="card-b">{corps}</div>
      </div>

      {creation && (
        <ModalCreation
          onFerme={() => setCreation(false)}
          onCree={async () => {
            setCreation(false)
            await charger()
          }}
        />
      )}
    </div>
  )
}

// Création (souscription). Le prix et la cadence NE SONT PAS envoyés — le serveur les résout depuis la
// formule du produit du catalogue (arbitrage « pas de prix libre »). On choisit un produit-abonnement
// (→ sa formule), le payeur, l'adhérent, et on saisit le mandat SEPA (IBAN + titulaire).
function ModalCreation({ onFerme, onCree }) {
  const [produits, setProduits] = useState(null)
  const [produitId, setProduitId] = useState('')
  const [payeur, setPayeur] = useState(null)
  const [beneficiaires, setBeneficiaires] = useState(null)
  const [adherent, setAdherent] = useState('')
  const [iban, setIban] = useState('')
  const [titulaire, setTitulaire] = useState('')
  const [dureeMois, setDureeMois] = useState(12)
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    let vivant = true
    api
      .produits({ itemsPerPage: 200 })
      .then((r) => vivant && setProduits(membres(r)))
      .catch(() => vivant && setProduits(undefined))
    return () => {
      vivant = false
    }
  }, [])

  useEffect(() => {
    let vivant = true
    setBeneficiaires(null)
    setAdherent('')
    if (!payeur) return
    api
      .beneficiairesDuClient(idDe(payeur))
      .then((r) => vivant && setBeneficiaires(membres(r)))
      .catch(() => vivant && setBeneficiaires(undefined))
    return () => {
      vivant = false
    }
  }, [payeur])

  const soumettre = useCallback(async () => {
    setErreur(null)
    if (!produitId || !payeur || !adherent || !iban.trim() || !titulaire.trim()) {
      setErreur('Produit, payeur, adhérent, IBAN et titulaire du mandat sont requis.')
      return
    }
    setEnvoi(true)
    try {
      // La formule vit dans le produit : on la lit sur le détail du produit choisi.
      const produit = await api.produit(produitId.replace('/api/produits/', ''))
      const formule = produit?.formule ? idDe(produit.formule) : null
      if (!formule) {
        setErreur("Ce produit n'est pas un abonnement (aucune formule attachée).")
        setEnvoi(false)
        return
      }
      await api.souscrireAbonnement({
        payeur: idDe(payeur),
        adherent,
        formule,
        iban: iban.trim(),
        titulaireMandat: titulaire.trim(),
        dureeEngagementMois: Number(dureeMois) || 12,
      })
      await onCree()
    } catch (e) {
      setErreur(e?.message || 'La souscription a été refusée.')
    } finally {
      setEnvoi(false)
    }
  }, [produitId, payeur, adherent, iban, titulaire, dureeMois, onCree])

  return (
    <Modal open onClose={onFerme} titre="Nouvel abonnement">
      <div style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
        <label className="field">
          <span className="sub">Produit-abonnement (catalogue)</span>
          <select className="select" value={produitId} onChange={(e) => setProduitId(e.target.value)}>
            <option value="">Sélectionner…</option>
            {Array.isArray(produits) &&
              produits.map((p) => (
                <option key={idDe(p)} value={idDe(p)}>
                  {p.libelleRecherche || p.code || idDe(p)}
                </option>
              ))}
          </select>
          {produits === undefined && <small className="crit">Produits non lisibles.</small>}
          <small className="sub">Le prix et la cadence viennent de la formule du produit.</small>
        </label>

        <label className="field">
          <span className="sub">Payeur</span>
          <ClientPicker onSelect={setPayeur} />
          {payeur && <small className="sub">Choisi : {nomClient(payeur)}</small>}
        </label>

        <label className="field">
          <span className="sub">Adhérent</span>
          <select
            className="select"
            value={adherent}
            onChange={(e) => setAdherent(e.target.value)}
            disabled={!payeur}
          >
            <option value="">{!payeur ? 'Choisis un payeur d’abord' : 'Sélectionner…'}</option>
            {Array.isArray(beneficiaires) &&
              beneficiaires.map((b) => (
                <option key={idDe(b)} value={idDe(b)}>
                  {texteBeneficiaire(b) || idDe(b)}
                </option>
              ))}
          </select>
          {beneficiaires === undefined && <small className="crit">Bénéficiaires non lisibles.</small>}
        </label>

        <label className="field">
          <span className="sub">IBAN (mandat SEPA)</span>
          <input className="input" value={iban} onChange={(e) => setIban(e.target.value)} placeholder="FR76 …" />
        </label>

        <label className="field">
          <span className="sub">Titulaire du mandat</span>
          <input className="input" value={titulaire} onChange={(e) => setTitulaire(e.target.value)} />
        </label>

        <label className="field">
          <span className="sub">Durée d'engagement (mois)</span>
          <input
            className="input"
            type="number"
            min="0"
            value={dureeMois}
            onChange={(e) => setDureeMois(e.target.value)}
          />
        </label>

        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <button type="button" className="btn" onClick={onFerme} disabled={envoi}>
            Annuler
          </button>
          <button type="button" className="btn primary" onClick={soumettre} disabled={envoi}>
            {envoi ? 'Souscription…' : 'Souscrire'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
