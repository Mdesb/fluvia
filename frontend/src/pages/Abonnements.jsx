import { useCallback, useEffect, useMemo, useState } from 'react'
import ClientPicker, { nomClient } from '../components/ClientPicker.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros, libelleProduit, prixIndicatif } from '../api/produit.js'
import { idDe } from '../api/iri.js'

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

const LABEL_STATUT = {
  actif: 'Actif',
  suspendu: 'Suspendu',
  en_pause: 'En pause',
  resilie: 'Résilié',
  expire: 'Expiré',
}

const LABEL_PERIODICITE = {
  mensuel: 'Mensuel',
  annuel: 'Annuel',
  personnalise: 'Personnalisé',
}

function classeStatut(s) {
  if (s === 'actif') return 'good'
  if (s === 'resilie' || s === 'expire') return 'crit'
  return 'warn'
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

export default function Abonnements({ droits }) {
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
      <CreationAbonnement
        produits={produits}
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
                    <span className={`badge ${classeStatut(s)}`}>{LABEL_STATUT[s] || a.statut || '—'}</span>
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
              {Object.entries(LABEL_STATUT).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
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

// Détails du produit choisi : prix indicatif, cadence, jour de prélèvement. Rendus depuis l'objet
// produit déjà chargé (la collection porte `formule` et `grilles`), sans appel supplémentaire.
function DetailsProduit({ produit }) {
  const f = produit.formule || {}
  const prix = prixIndicatif(produit)
  return (
    <div
      style={{
        border: '1px solid var(--line)',
        borderRadius: 10,
        padding: 'var(--esp-normal)',
        display: 'grid',
        gap: 'var(--esp-serre)',
      }}
    >
      <strong>{libelleProduit(produit)}</strong>
      <div className="row" style={{ gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
        <span>
          <span className="sub">Prix&nbsp;: </span>
          {prix != null ? euros(prix) : '—'}
        </span>
        <span>
          <span className="sub">Périodicité&nbsp;: </span>
          {labelPeriodicite(f.periodicite)}
        </span>
        {f.jourPrelevement != null && (
          <span>
            <span className="sub">Prélèvement le&nbsp;: </span>
            {f.jourPrelevement}
          </span>
        )}
        <span>
          <span className="sub">SEPA&nbsp;: </span>
          {f.sepaActif ? 'oui' : 'non'}
        </span>
      </div>
    </div>
  )
}

// Souscription au guichet. Le prix et la cadence NE SONT PAS envoyés — le serveur les résout depuis la
// formule du produit (« pas de prix libre »). Payeur et adhérent sont des CLIENTS (recherche/création
// comme en caisse) ; l'adhérent est facultatif (par défaut le payeur).
function CreationAbonnement({ produits, onAnnuler, onCree }) {
  const [produitId, setProduitId] = useState('')
  const [payeur, setPayeur] = useState(null)
  const [adherent, setAdherent] = useState(null)
  const [picker, setPicker] = useState(null) // null | 'payeur' | 'adherent'
  const [iban, setIban] = useState('')
  const [titulaire, setTitulaire] = useState('')
  const [dureeMois, setDureeMois] = useState(12)
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)

  // ⚠ CE QUE CET ÉCRAN SAIT SOUSCRIRE : un produit d'abonnement PRÉLEVÉ EN SEPA. Le filtre n'est pas
  // cosmétique — la souscription ouvre un mandat (IBAN + titulaire obligatoires). Montrer un produit
  // sans SEPA laisserait choisir ce qu'on ne peut pas finir de saisir ici.
  const produitsAbo = useMemo(
    () => (Array.isArray(produits) ? produits.filter((p) => p?.formule?.sepaActif) : []),
    [produits],
  )
  const produit = useMemo(
    () => produitsAbo.find((p) => idDe(p) === produitId) || null,
    [produitsAbo, produitId],
  )

  const soumettre = useCallback(async () => {
    setErreur(null)
    if (!produit || !payeur || !iban.trim() || !titulaire.trim()) {
      setErreur('Produit, payeur, IBAN et titulaire du mandat sont requis.')
      return
    }
    const formule = produit.formule ? idDe(produit.formule) : null
    if (!formule) {
      setErreur("Ce produit n'est pas un abonnement (aucune formule attachée).")
      return
    }
    setEnvoi(true)
    try {
      const corps = {
        payeur: idDe(payeur),
        formule,
        iban: iban.trim(),
        titulaireMandat: titulaire.trim(),
        dureeEngagementMois: Number(dureeMois) || 12,
      }
      // Adhérent facultatif : présent seulement s'il diffère du payeur. Absent = le serveur prend le
      // payeur comme adhérent (mais un adhérent DÉSIGNÉ mais introuvable est refusé, pas ignoré).
      if (adherent) corps.adherent = idDe(adherent)
      await api.souscrireAbonnement(corps)
      await onCree()
    } catch (e) {
      setErreur(e?.message || 'La souscription a été refusée.')
      setEnvoi(false)
    }
  }, [produit, payeur, adherent, iban, titulaire, dureeMois, onCree])

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <button
            type="button"
            className="btn ghost sm"
            onClick={onAnnuler}
            style={{ marginBottom: 'var(--esp-serre)' }}
          >
            ← Retour aux abonnements
          </button>
          <h2>Nouvel abonnement</h2>
          <p className="sub">Souscription au guichet : produit, payeur, adhérent, mandat SEPA.</p>
        </div>
      </div>

      <div className="card">
        <div className="card-b" style={{ display: 'grid', gap: 'var(--esp-normal)' }}>
          <label className="field" style={{ margin: 0 }}>
            <span className="sub">Produit d'abonnement</span>
            <select className="select" value={produitId} onChange={(e) => setProduitId(e.target.value)}>
              <option value="">Sélectionner…</option>
              {produitsAbo.map((p) => (
                <option key={idDe(p)} value={idDe(p)}>
                  {libelleProduit(p)}
                </option>
              ))}
            </select>
            {produits === undefined && <small className="crit">Produits non lisibles.</small>}
            {Array.isArray(produits) && produitsAbo.length === 0 && (
              <small className="sub">Aucun produit d'abonnement prélevé en SEPA dans le catalogue.</small>
            )}
            <small className="sub">Le prix et la cadence viennent de la formule du produit.</small>
          </label>

          {produit && <DetailsProduit produit={produit} />}

          <div className="field" style={{ margin: 0 }}>
            <span className="sub">Payeur</span>
            <div className="row" style={{ gap: 'var(--esp-serre)', alignItems: 'center', flexWrap: 'wrap' }}>
              <button type="button" className="btn" onClick={() => setPicker('payeur')}>
                {payeur ? 'Modifier le payeur' : 'Choisir un payeur'}
              </button>
              <span>{payeur ? nomClient(payeur) : <span className="sub">Aucun payeur choisi</span>}</span>
            </div>
          </div>

          <div className="field" style={{ margin: 0 }}>
            <span className="sub">Adhérent (facultatif)</span>
            <div className="row" style={{ gap: 'var(--esp-serre)', alignItems: 'center', flexWrap: 'wrap' }}>
              <button type="button" className="btn" onClick={() => setPicker('adherent')}>
                {adherent ? "Modifier l'adhérent" : 'Choisir un adhérent'}
              </button>
              <span>
                {adherent ? (
                  nomClient(adherent)
                ) : (
                  <span className="sub">Par défaut, le payeur est l'adhérent</span>
                )}
              </span>
              {adherent && (
                <button type="button" className="btn ghost sm" onClick={() => setAdherent(null)}>
                  Retirer
                </button>
              )}
            </div>
            <small className="sub">Recherche ou création d'un client, comme en caisse.</small>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-serre)' }}>
            <label className="field" style={{ margin: 0 }}>
              <span className="sub">IBAN (mandat SEPA)</span>
              <input className="input" value={iban} onChange={(e) => setIban(e.target.value)} placeholder="FR76 …" />
            </label>
            <label className="field" style={{ margin: 0 }}>
              <span className="sub">Titulaire du mandat</span>
              <input className="input" value={titulaire} onChange={(e) => setTitulaire(e.target.value)} />
            </label>
          </div>
          <small className="sub" style={{ display: 'block' }}>
            Le « mandat SEPA » est l'autorisation de prélèvement signée par le titulaire du compte : on
            prélèvera ensuite automatiquement le montant, à la cadence du produit.
          </small>

          <label className="field" style={{ margin: 0, maxWidth: 240 }}>
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

          <div className="row" style={{ justifyContent: 'flex-end', gap: 'var(--esp-serre)' }}>
            <button type="button" className="btn" onClick={onAnnuler} disabled={envoi}>
              Annuler
            </button>
            <button type="button" className="btn primary" onClick={soumettre} disabled={envoi}>
              {envoi ? 'Souscription…' : "Souscrire l'abonnement"}
            </button>
          </div>
        </div>
      </div>

      {picker && (
        <ClientPicker
          open
          titre={picker === 'payeur' ? 'Payeur' : 'Adhérent'}
          avecCreation
          onClose={() => setPicker(null)}
          onSelect={(c) => {
            if (picker === 'payeur') setPayeur(c)
            else setAdherent(c)
            setPicker(null)
          }}
        />
      )}
    </div>
  )
}
