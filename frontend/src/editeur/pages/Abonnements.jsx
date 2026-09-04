import { useEffect, useMemo, useState } from 'react'
import { api, membres, ApiError } from '../../api/client.js'

// Les abonnements vendus par l'éditeur (ED-6).
//
// CE QUE CET ÉCRAN DOIT MONTRER EN PREMIER, ET POURQUOI CE N'EST PAS LE CHIFFRE D'AFFAIRES.
// La ligne qui coûte cher est celle d'un abonnement ACTIF dont le PROVISIONNEMENT A ÉCHOUÉ : un
// client a payé et n'a pas sa plateforme. Rien ne le signale ailleurs — le prélèvement passe, la
// facture part, et le client découvre le problème en essayant de se connecter. Ces lignes sont donc
// remontées en tête et signalées, avant tout le reste.
//
// AUCUNE RÈGLE D'AUTORISATION N'EST REJOUÉE ICI (D39). Le serveur répond 404 si la session n'est pas
// celle de l'éditeur ; cet écran se contente de le dire. Filtrer à moitié côté client produirait un
// écran vide sans cause visible — le défaut du 24/08 au soir.

const ETATS = {
  draft: { label: 'Panier', classe: 'mut' },
  active: { label: 'Actif', classe: 'good' },
  suspended: { label: 'Suspendu', classe: 'warn' },
  cancelled: { label: 'Résilié', classe: 'mut' },
}

const PROVISIONNEMENT = {
  pending: { label: 'En attente', classe: 'warn' },
  completed: { label: 'Livrée', classe: 'good' },
  failed: { label: 'Échec', classe: 'crit' },
}

const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' })

function prix(cents) {
  return euros.format((cents || 0) / 100)
}

function date(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('fr-FR')
}

/** Un client a payé et n'a rien : c'est la seule anomalie que cet écran doit crier. */
function estEnSouffrance(ligne) {
  return ligne.status === 'active' && ligne.provisioningStatus !== 'completed'
}

export default function Abonnements({ onRefus }) {
  const [lignes, setLignes] = useState(null)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    let vivant = true

    api
      .editorSubscriptions()
      .then((collection) => {
        if (vivant) setLignes(membres(collection))
      })
      .catch((e) => {
        if (!vivant) return
        // 404 = cette session n'est pas celle de l'éditeur. Le serveur ne dit pas « interdit », il
        // dit « rien ici » — c'est voulu, et c'est au parent d'expliquer.
        if (e instanceof ApiError && e.status === 404) {
          onRefus?.()
          return
        }
        setErreur(e)
      })

    return () => {
      vivant = false
    }
  }, [onRefus])

  const triees = useMemo(() => {
    if (!lignes) return []
    // Les abonnements en souffrance d'abord ; le reste garde l'ordre du serveur (plus récent en tête).
    return [...lignes].sort((a, b) => Number(estEnSouffrance(b)) - Number(estEnSouffrance(a)))
  }, [lignes])

  const enSouffrance = triees.filter(estEnSouffrance).length

  if (erreur) {
    return <div className="banner banner-error">Les abonnements n'ont pas pu être chargés.</div>
  }

  if (!lignes) {
    return <div className="center"><div className="spinner" /></div>
  }

  if (lignes.length === 0) {
    return <div className="empty"><p>Aucun abonnement pour le moment.</p></div>
  }

  return (
    <>
      {enSouffrance > 0 && (
        <div className="banner banner-error">
          <strong>
            {enSouffrance === 1
              ? '1 client a payé sans obtenir sa plateforme.'
              : `${enSouffrance} clients ont payé sans obtenir leur plateforme.`}
          </strong>{' '}
          Voir la colonne « Plateforme » ci-dessous.
        </div>
      )}

      <div className="card">
        <div className="card-h">
          <h2>Abonnements</h2>
          <span className="mut">{lignes.length}</span>
        </div>

        <div className="card-b" style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Client</th>
                <th>Formule</th>
                <th>État</th>
                <th>Plateforme</th>
                <th>Depuis</th>
                <th style={{ textAlign: 'right' }}>Par mois</th>
              </tr>
            </thead>
            <tbody>
              {triees.map((l) => {
                const etat = ETATS[l.status] || { label: l.status, classe: 'mut' }
                const livraison = l.provisioningStatus ? PROVISIONNEMENT[l.provisioningStatus] : null

                return (
                  <tr key={l.id} className={estEnSouffrance(l) ? 'ligne-alerte' : undefined}>
                    <td>
                      <strong>{l.customerName}</strong>
                      {l.customerEmail && <div className="mut">{l.customerEmail}</div>}
                    </td>
                    <td>{l.planLabel || '—'}</td>
                    <td><span className={`badge ${etat.classe}`}>{etat.label}</span></td>
                    <td>
                      {livraison ? (
                        <>
                          <span className={`badge ${livraison.classe}`}>{livraison.label}</span>
                          {l.establishmentName && <div className="mut">{l.establishmentName}</div>}
                          {/*
                            La cause de l'échec est écrite pour l'exploitant, en clair. La cacher
                            derrière un pictogramme obligerait à ouvrir les journaux pour comprendre
                            pourquoi un client payant n'a rien.
                          */}
                          {l.provisioningFailure && (
                            <div className="hint">{l.provisioningFailure}</div>
                          )}
                        </>
                      ) : (
                        <span className="badge mut">Non demandée</span>
                      )}
                    </td>
                    <td>{date(l.startedAt)}</td>
                    <td style={{ textAlign: 'right' }}>{prix(l.monthlyPriceCents)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    </>
  )
}
