import { useCallback, useEffect, useMemo, useState } from 'react'
import { nomClient } from '../components/ClientPicker.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'
import { idDe } from '../api/iri.js'

// Onglet Exploitation « Abonnements » — vue de gestion transverse des abonnements clients.
//
// Lot livrable (Issue #14) : surface l'API AbonnementFitness qui existe déjà, cloisonnée par
// établissement, sans réécrire le back. Voir / suspendre / résilier passent par les opérations sur
// mesure du serveur. La création (souscription : formule du catalogue + mandat SEPA) est un
// formulaire à part, livré ensuite — on ne pose pas un bouton qui ne marche pas.
//
// ⚠ Discipline des états : `null` = on lit ; `undefined` = on n'a PAS PU lire (refus) ; un tableau
// = lu. On n'écrit jamais « — » là où on n'a rien mesuré. Et jamais de `.find`/accès sur `null`.

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

// Nom lisible d'un bénéficiaire (adhérent), que l'API rende un objet ou une IRI nue. Jamais de
// plantage sur `null`.
function texteBeneficiaire(b) {
  if (!b) return null
  if (typeof b === 'string') return null // IRI nue : pas nommable sans lecture séparée
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
  const [liste, setListe] = useState(null) // null = chargement, undefined = refus, [] = vide
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(null) // id de l'abonnement en cours d'action
  const peutGerer = aLeDroit(droits, 'sport.gerer_abonnement')

  const charger = useCallback(async () => {
    setErreur(null)
    try {
      const rep = await api.listerAbonnements()
      setListe(membres(rep))
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
      // Le serveur exige un motif (422 si vide) : une résiliation révoque le mandat, et la seule
      // question posée six mois plus tard sera « pourquoi ».
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
      </div>

      {liste !== undefined && erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="card">
        <div className="card-b">{corps}</div>
      </div>
    </div>
  )
}
