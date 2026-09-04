import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres, ApiError } from '../../api/client.js'

// La fiche client de l'éditeur (ED-6) — liste à gauche, fiche à droite, sur le modèle de l'écran
// Clients du back-office.
//
// CE QUE CETTE FICHE RÉPOND. Quand un client appelle, l'exploitant veut savoir en même temps : qui
// il est, ce qu'il paie, si son prélèvement tient, si sa plateforme est livrée, et qui de
// l'assistance a pu regarder chez lui. Le serveur assemble tout en une lecture : cinq appels
// afficheraient des morceaux dans le désordre, et c'est le moment où l'on se trompe de client.
//
// CE QU'ELLE NE MONTRE PAS, ET C'EST VOULU. Aucune donnée d'exploitation du client — ni ses ventes,
// ni ses réservations, ni sa fréquentation. L'éditeur vend une plateforme, il ne lit pas ce qui s'y
// passe. Quand il doit regarder, c'est par un accès d'assistance nominatif et borné, et la dernière
// section de la fiche montre précisément qui l'a fait.

const ETATS = {
  draft: { label: 'Panier', classe: 'mut' },
  active: { label: 'Actif', classe: 'good' },
  suspended: { label: 'Suspendu', classe: 'warn' },
  cancelled: { label: 'Résilié', classe: 'mut' },
}

const LIVRAISON = {
  pending: { label: 'En attente', classe: 'warn' },
  completed: { label: 'Livrée', classe: 'good' },
  failed: { label: 'Échec', classe: 'crit' },
}

const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' })
const prix = (c) => euros.format((c || 0) / 100)
const dateFr = (iso) => (iso ? new Date(iso).toLocaleDateString('fr-FR') : '—')

export default function ClientsEditeur({ onRefus }) {
  const [q, setQ] = useState('')
  const [items, setItems] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [selId, setSelId] = useState(null)
  const [fiche, setFiche] = useState(null)
  const [ficheErr, setFicheErr] = useState(null)

  const refuse = useCallback(
    (e) => {
      if (e instanceof ApiError && e.status === 404) {
        onRefus?.()
        return true
      }
      return false
    },
    [onRefus],
  )

  useEffect(() => {
    let vivant = true
    api
      .editorCustomers()
      .then((c) => vivant && setItems(membres(c)))
      .catch((e) => vivant && !refuse(e) && setErreur(e))
    return () => {
      vivant = false
    }
  }, [refuse])

  useEffect(() => {
    if (!selId) {
      setFiche(null)
      return
    }
    let vivant = true
    setFiche(null)
    setFicheErr(null)
    api
      .editorCustomer(selId)
      .then((f) => vivant && setFiche(f))
      .catch((e) => vivant && setFicheErr(e))
    return () => {
      vivant = false
    }
  }, [selId])

  const filtres = useMemo(() => {
    const terme = q.trim().toLowerCase()
    if (!items) return []
    if (!terme) return items
    return items.filter(
      (c) =>
        (c.name || '').toLowerCase().includes(terme) || (c.email || '').toLowerCase().includes(terme),
    )
  }, [items, q])

  if (erreur) return <div className="banner banner-error">Les clients n'ont pas pu être chargés.</div>
  if (!items) return <div className="center"><div className="spinner" /></div>

  if (items.length === 0) {
    return (
      <div className="empty">
        <p>
          Aucun client pour le moment. Les fiches apparaissent ici dès qu'un prospect compose un
          panier depuis le site vitrine.
        </p>
      </div>
    )
  }

  return (
    <div className="grid-2">
      <section className="card">
        <div className="card-h">
          <h2>Clients</h2>
          <span className="mut">{filtres.length}</span>
        </div>
        <div className="card-b">
          <div className="field">
            <input
              type="search"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Nom ou adresse e-mail"
              aria-label="Rechercher un client"
            />
          </div>

          {filtres.length === 0 ? (
            <p className="hint">Aucun client ne correspond à « {q} ».</p>
          ) : (
            <ul className="liste-clients" role="list">
              {filtres.map((c) => (
                <li key={c.id}>
                  <button
                    type="button"
                    className={`ligne-client ${selId === c.id ? 'active' : ''}`}
                    aria-current={selId === c.id ? 'true' : undefined}
                    onClick={() => setSelId(c.id)}
                  >
                    <strong>{c.name}</strong>
                    {c.email && <span className="mut">{c.email}</span>}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>

      <section className="card">
        <div className="card-h">
          <h2>Fiche</h2>
        </div>
        <div className="card-b">
          {!selId && <p className="hint">Choisissez un client pour voir sa fiche.</p>}
          {selId && ficheErr && <div className="banner banner-error">Cette fiche n'a pas pu être chargée.</div>}
          {selId && !fiche && !ficheErr && <div className="center"><div className="spinner" /></div>}
          {fiche && <Fiche fiche={fiche} />}
        </div>
      </section>
    </div>
  )
}

function Fiche({ fiche }) {
  return (
    <>
      <div className="fiche-sec">Identité</div>
      <p>
        <strong>{fiche.name}</strong>
        {fiche.email && <><br />{fiche.email}</>}
        {fiche.phone && <><br />{fiche.phone}</>}
      </p>

      <div className="fiche-sec">Abonnements</div>
      {fiche.subscriptions.length === 0 ? (
        <p className="hint">Ce client n'a jamais composé de panier.</p>
      ) : (
        fiche.subscriptions.map((a) => {
          const etat = ETATS[a.status] || { label: a.status, classe: 'mut' }
          const livraison = a.provisioningStatus ? LIVRAISON[a.provisioningStatus] : null

          return (
            <div key={a.id} className="bloc-abo">
              <div>
                <strong>{a.planLabel || '—'}</strong>{' '}
                <span className={`badge ${etat.classe}`}>{etat.label}</span>{' '}
                {livraison ? (
                  <span className={`badge ${livraison.classe}`}>Plateforme : {livraison.label}</span>
                ) : (
                  <span className="badge mut">Plateforme non demandée</span>
                )}
              </div>
              <div className="mut">
                {prix(a.monthlyPriceCents)} / mois · depuis {dateFr(a.startedAt)}
                {a.establishmentName && <> · {a.establishmentName}</>}
              </div>
              {/* La cause de l'échec est écrite pour l'exploitant, en clair : la cacher obligerait à
                  ouvrir les journaux pour comprendre pourquoi un client payant n'a rien. */}
              {a.provisioningFailure && <div className="hint">{a.provisioningFailure}</div>}
            </div>
          )
        })
      )}

      <div className="fiche-sec">Prélèvement</div>
      {fiche.mandate ? (
        <p>
          Mandat {fiche.mandate.rum}
          <br />
          Compte se terminant par <strong>{fiche.mandate.last4}</strong> · {fiche.mandate.status}
          <br />
          <span className="mut">Signé le {dateFr(fiche.mandate.signedAt)}</span>
        </p>
      ) : (
        <p className="hint">
          Aucun mandat signé. Tant qu'il n'y en a pas, rien ne peut être prélevé — et un abonnement
          qu'on activerait quand même ouvrirait un service que rien ne paie.
        </p>
      )}

      <div className="fiche-sec">Accès d'assistance</div>
      {fiche.supportAccesses.length === 0 ? (
        <p className="hint">Personne de l'éditeur n'a eu accès à la plateforme de ce client.</p>
      ) : (
        <ul className="liste-acces" role="list">
          {fiche.supportAccesses.map((a, i) => (
            <li key={i}>
              <strong>{a.grantee}</strong>{' '}
              {a.usable ? (
                <span className="badge warn">Accès ouvert</span>
              ) : (
                <span className="badge mut">{a.revokedAt ? 'Révoqué' : 'Expiré'}</span>
              )}
              <div className="mut">
                Du {dateFr(a.grantedAt)} au {dateFr(a.expiresAt)}
                {a.revokedAt && <> · révoqué le {dateFr(a.revokedAt)}</>}
              </div>
              <div className="hint">{a.reason}</div>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}
