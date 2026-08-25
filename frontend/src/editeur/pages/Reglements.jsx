import { useEffect, useMemo, useState } from 'react'
import { api, membres, ApiError } from '../../api/client.js'

// Ce qui reste dû à l'éditeur (ED-8).
//
// UNE FACTURE ÉMISE N'EST PAS UNE FACTURE PAYÉE. L'écran de facturation dit ce qui a été émis ;
// celui-ci dit ce qui a été encaissé. Sans lui, automatiser l'émission produirait un flot de
// factures dont personne ne sait lesquelles sont honorées — de l'automatisation à l'aveugle.
//
// UNE FACTURE SOLDÉE N'APPARAÎT PAS. Elle n'est ni grisée ni repliée : elle sort. Une liste de
// créances où figurent les factures payées oblige à lire pour savoir quoi faire, et c'est exactement
// l'effort qu'on veut supprimer.
//
// LE RETARD VIENT DU SERVEUR. Calculé côté navigateur, il dépendrait de l'horloge du poste : deux
// exploitants verraient deux retards différents pour la même facture, et le plus optimiste ferait foi.

const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' })
const prix = (c) => euros.format((c || 0) / 100)
const dateFr = (iso) => (iso ? new Date(iso).toLocaleDateString('fr-FR') : '—')

/** Le retard se lit d'un coup d'œil ou il ne sert à rien. */
function badgeRetard(jours) {
  if (jours <= 0) return <span className="badge mut">Dans les délais</span>
  if (jours <= 15) return <span className="badge warn">{jours} j de retard</span>
  return <span className="badge crit">{jours} j de retard</span>
}

export default function Reglements({ onRefus }) {
  const [lignes, setLignes] = useState(null)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    let vivant = true
    api
      .editorReceivables()
      .then((c) => vivant && setLignes(membres(c)))
      .catch((e) => {
        if (!vivant) return
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

  const enRetard = useMemo(() => (lignes || []).filter((l) => l.daysLate > 0), [lignes])
  const duTotal = useMemo(() => (lignes || []).reduce((s, l) => s + l.remainingCents, 0), [lignes])
  const duEnRetard = useMemo(() => enRetard.reduce((s, l) => s + l.remainingCents, 0), [enRetard])

  if (erreur) return <div className="banner crit">Les créances n'ont pas pu être chargées.</div>
  if (!lignes) return <div className="center"><div className="spinner" /></div>

  if (lignes.length === 0) {
    return (
      <div className="empty">
        <p>Tout est encaissé. Aucune facture ne reste due.</p>
      </div>
    )
  }

  return (
    <>
      {enRetard.length > 0 && (
        <div className="banner crit">
          <strong>
            {enRetard.length === 1
              ? '1 facture est en retard de paiement'
              : `${enRetard.length} factures sont en retard de paiement`}
          </strong>{' '}
          — {prix(duEnRetard)} à recouvrer sur {prix(duTotal)} en attente.
        </div>
      )}

      <div className="card">
        <div className="card-h">
          <h2>Reste à encaisser</h2>
          <span className="mut">{prix(duTotal)}</span>
        </div>

        <div className="card-b" style={{ overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                <th>Client</th>
                <th>Facture</th>
                <th>Échéance</th>
                <th>Retard</th>
                <th style={{ textAlign: 'right' }}>Réglé</th>
                <th style={{ textAlign: 'right' }}>Reste dû</th>
              </tr>
            </thead>
            <tbody>
              {lignes.map((l) => (
                <tr key={l.invoiceId} className={l.daysLate > 15 ? 'ligne-alerte' : undefined}>
                  <td><strong>{l.customerName}</strong></td>
                  <td className="mut">{l.invoiceNumber || '—'}</td>
                  <td>{dateFr(l.dueDate)}</td>
                  <td>{badgeRetard(l.daysLate)}</td>
                  {/* Le réglé est affiché à côté du reste dû : un règlement partiel se voit, alors
                      qu'un simple « reste dû » laisse croire que rien n'a été payé. */}
                  <td style={{ textAlign: 'right' }} className="mut">
                    {l.paidCents > 0 ? prix(l.paidCents) : '—'}
                  </td>
                  <td style={{ textAlign: 'right' }}><strong>{prix(l.remainingCents)}</strong></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  )
}
