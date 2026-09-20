import { useCallback, useEffect, useMemo, useState } from 'react'
import ClientPicker, { nomClient } from '../components/ClientPicker.jsx'
import SouscriptionAbonnement from '../components/SouscriptionAbonnement.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros, libelleProduit } from '../api/produit.js'
import { idDe } from '../api/iri.js'
import { mot } from '../api/vocabulaire.js'
import { STATUTS_ABONNEMENT, tonStatutAbonnement } from '../api/abonnement.js'

// Onglet Exploitation « Abonnements » — gestion transverse des abonnements clients.
//
// La souscription est une PAGE (pas une modale) : on choisit un produit d'abonnement (le catalogue est
// filtré aux seuls produits prélevés en SEPA), on en voit les détails (prix, cadence, jour de
// prélèvement), on désigne le PAYEUR et — facultativement — l'ADHÉRENT. Les deux se recherchent ou se
// créent comme en caisse (même `ClientPicker`) : « adhérent » n'est plus une liste de bénéficiaires
// pré-attachés mais un CLIENT, que le serveur résout en bénéficiaire (`forPurchase`). Adhérent absent :
// le payeur est l'adhérent.
//
// Le prix et la cadence NE SONT PAS envoyés — le serveur les résout depuis la formule du produit
// (arbitrage « pas de prix libre »).
//
// ⚠ Discipline des états : `null` = on lit ; `undefined` = on n'a PAS PU lire (refus) ; un tableau =
// lu. Jamais « — » là où rien n'a été mesuré, jamais de `.find`/accès sur `null`.

// LES STATUTS NE SONT PLUS NOMMÉS ICI : `../api/abonnement.js` porte la liste, `mot()` porte les
// mots. La table locale qui vivait à cette place nommait `suspendu`, `en_pause` et `expire` — trois
// clés qu'aucun producteur serveur n'émet — et ignorait `pause`, `impaye` et `echu`, que la colonne
// Statut affichait donc en code brut. Le filtre ci-dessous étant construit depuis cette même table,
// il proposait trois choix qui ne ramenaient jamais rien.

const LABEL_PERIODICITE = {
  mensuel: 'Mensuel',
  annuel: 'Annuel',
  personnalise: 'Personnalisé',
}

function labelPeriodicite(v) {
  if (!v) return '—'
  return LABEL_PERIODICITE[v] || v.charAt(0).toUpperCase() + v.slice(1)
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

export default function Abonnements({ droits, session }) {
  const [vue, setVue] = useState('liste') // 'liste' | 'creation'
  const [liste, setListe] = useState(null)
  const [produits, setProduits] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(null)
  const [filtreStatut, setFiltreStatut] = useState('')
  const [recherche, setRecherche] = useState('')
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

  // Les produits servent à DEUX choses : le nom du produit dans la liste (via `formuleId`), et le
  // choix filtré à la création. On les lit une fois. Un refus (`undefined`) n'empêche pas la liste
  // des abonnements : elle affichera « produit inconnu » plutôt que rien.
  const chargerProduits = useCallback(async () => {
    try {
      setProduits(membres(await api.produits({ itemsPerPage: 200 })))
    } catch {
      setProduits(undefined)
    }
  }, [])

  useEffect(() => {
    charger()
    chargerProduits()
  }, [charger, chargerProduits])

  // formule id -> produit, pour retrouver le nom du produit d'un abonnement (qui n'expose que
  // `formuleId`, la formule n'étant pas une ressource indépendante).
  const produitParFormule = useMemo(() => {
    const m = new Map()
    if (Array.isArray(produits)) {
      for (const p of produits) {
        const fid = p?.formule ? idDe(p.formule) : null
        if (fid) m.set(fid, p)
      }
    }
    return m
  }, [produits])

  const nomProduit = useCallback(
    (a) => {
      const p = a?.formuleId ? produitParFormule.get(a.formuleId) : null
      return p ? libelleProduit(p) : null
    },
    [produitParFormule],
  )

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

  const listeFiltree = useMemo(() => {
    if (!Array.isArray(liste)) return liste
    const q = recherche.trim().toLowerCase()
    return liste.filter((a) => {
      if (filtreStatut && (a.statut || '').toLowerCase() !== filtreStatut) return false
      if (!q) return true
      const foin = [nomClient(a.payeur), texteBeneficiaire(a.adherent), nomProduit(a)]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return foin.includes(q)
    })
  }, [liste, filtreStatut, recherche, nomProduit])

  if (vue === 'creation') {
    return (
      <SouscriptionAbonnement
        produits={produits}
        session={session}
        droits={droits}
        onAnnuler={() => setVue('liste')}
        onCree={async () => {
          setVue('liste')
          await charger()
        }}
      />
    )
  }

  const corps = () => {
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
    if (Array.isArray(listeFiltree) && listeFiltree.length === 0)
      return <p className="empty">Aucun abonnement ne correspond aux filtres.</p>

    return (
      <div style={{ overflowX: 'auto' }}>
        <table className="tbl">
          <thead>
            <tr>
              <th>Produit</th>
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
            {listeFiltree.map((a) => {
              const s = (a.statut || '').toLowerCase()
              const nomAdherent = texteBeneficiaire(a.adherent)
              const produitNom = nomProduit(a)
              const occupe = enCours === idDe(a)
              return (
                <tr key={idDe(a)}>
                  <td>
                    {produitNom || (
                      <span className="sub">{produits === undefined ? 'produit non lu' : 'produit inconnu'}</span>
                    )}
                  </td>
                  <td>{nomClient(a.payeur) || <span className="sub">—</span>}</td>
                  <td>{nomAdherent || <span className="sub">—</span>}</td>
                  <td>
                    <span className={`badge ${tonStatutAbonnement(s)}`}>{mot(s)}</span>
                  </td>
                  <td>{labelPeriodicite((a.periodicite || '').toLowerCase())}</td>
                  <td className="num">
                    {typeof a.montantCentimes === 'number' ? euros(a.montantCentimes / 100) : '—'}
                  </td>
                  <td>{dateFr(a.dateFinEngagement)}</td>
                  {peutGerer && (
                    <td style={{ whiteSpace: 'nowrap' }}>
                      {s === 'actif' && (
                        <button type="button" className="btn sm" disabled={occupe} onClick={() => suspendre(a)}>
                          Suspendre
                        </button>
                      )}
                      {s !== 'resilie' && (
                        <button
                          type="button"
                          className="btn sm danger"
                          disabled={occupe}
                          onClick={() => resilier(a)}
                          style={{ marginLeft: 'var(--esp-serre)' }}
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
  }

  const total = Array.isArray(liste) ? liste.length : null

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h2>Abonnements</h2>
          <p className="sub">Gestion des abonnements clients de l'établissement.</p>
        </div>
        {peutGerer && (
          <button type="button" className="btn primary" onClick={() => setVue('creation')}>
            Nouvel abonnement
          </button>
        )}
      </div>

      {liste !== undefined && erreur && <div className="banner banner-error">{erreur}</div>}

      {Array.isArray(liste) && liste.length > 0 && (
        <div className="row" style={{ gap: 'var(--esp-serre)', flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <label className="field" style={{ margin: 0 }}>
            <span className="sub">Statut</span>
            <select className="select" value={filtreStatut} onChange={(e) => setFiltreStatut(e.target.value)}>
              <option value="">Tous</option>
              {STATUTS_ABONNEMENT.map((v) => (
                <option key={v} value={v}>
                  {mot(v)}
                </option>
              ))}
            </select>
          </label>
          <label className="field" style={{ margin: 0, flex: 1, minWidth: 220 }}>
            <span className="sub">Rechercher</span>
            <input
              className="input"
              value={recherche}
              onChange={(e) => setRecherche(e.target.value)}
              placeholder="Payeur, adhérent, produit…"
            />
          </label>
          {total != null && (
            <span className="sub">
              {Array.isArray(listeFiltree) ? listeFiltree.length : total} / {total}
            </span>
          )}
        </div>
      )}

      <div className="card">
        <div className="card-b">{corps()}</div>
      </div>
    </div>
  )
}
