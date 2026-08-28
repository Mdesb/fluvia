import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client.js'

/**
 * LES RELANCES EN ATTENTE — ce qu'il reste à faire, déduit et jamais tenu à la main.
 *
 * **Il n'y a pas de seconde boîte de tâches dans ce produit, et c'est délibéré.** Un module commercial
 * posé à côté de l'assistance risquait d'ouvrir **deux listes de travail en cours** — et personne ne
 * regarde les deux. Ici, aucune tâche n'est créée : la liste est la conséquence des échanges déjà
 * enregistrés.
 *
 * > **Une tâche qu'il faut penser à cocher est une tâche qui reste ouverte pour toujours.**
 *
 * Une relance tient tant qu'aucun échange plus récent n'existe sur le même client ou la même affaire.
 * Rappeler suffit à la faire disparaître : c'est pour ça que le bouton mène à la fiche du client, là
 * où l'on note ce qui vient de se passer, plutôt qu'à un bouton « Fait ».
 *
 * **Le retard est calculé à l'affichage.** Un drapeau tenu en base serait faux entre deux passages
 * d'un traitement de nuit : la relance d'hier resterait « à l'heure » ce matin, précisément le jour
 * où elle compte.
 */

const LIB = { call: 'Appel', email: 'Courriel', meeting: 'Rendez-vous', note: 'Note' }

function jour(v) {
  if (!v) return '—'
  const d = new Date(`${v}T12:00:00`)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long' })
}

export default function Relances({ etabActif, onOuvrirClient }) {
  const [charge, setCharge] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [ouvert, setOuvert] = useState(true)

  const recharger = useCallback(async () => {
    setErreur(null)
    try {
      setCharge(await api.relances())
    } catch (e) {
      setErreur(e.message || 'Les relances n’ont pas pu être chargées.')
    }
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

  if (erreur) return <div className="banner banner-error">{erreur}</div>
  if (!charge) return null

  // RIEN A RELANCER N'EST PAS UNE LISTE VIDE : C'EST UNE BONNE NOUVELLE, ET ON L'ECRIT.
  // Un cadre vide se lit comme une panne de chargement ; la phrase dit que le compte est à jour.
  if (charge.total === 0) {
    return (
      <section className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><span>Relances</span></div>
        <div className="sub" style={{ padding: 12 }}>
          Aucune relance en attente : chaque échange en cours a déjà eu sa suite.
        </div>
      </section>
    )
  }

  return (
    <section className="card" style={{ marginBottom: 12 }}>
      <div className="card-h" style={{ gap: 10 }}>
        <span>Relances</span>
        <span className="sub" style={{ fontVariantNumeric: 'tabular-nums' }}>
          {charge.total} en attente
          {charge.enRetard > 0 ? ` · ${charge.enRetard} en retard` : ''}
        </span>
        <button
          className="btn ghost sm"
          type="button"
          style={{ marginLeft: 'auto' }}
          onClick={() => setOuvert((v) => !v)}
        >
          {ouvert ? 'Masquer' : 'Afficher'}
        </button>
      </div>

      {ouvert && (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Échéance</th>
                <th>À faire</th>
                <th>Client</th>
                <th>Affaire</th>
                <th>Dernier échange</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {charge.relances.map((r) => (
                <tr key={r.id}>
                  <td style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                    <span className={`badge ${r.enRetard ? 'crit' : 'mut'}`}>{jour(r.echeance)}</span>
                  </td>
                  <td className="nm">{r.aFaire}</td>
                  <td>{r.client || '—'}</td>
                  <td>{r.affaire || '—'}</td>
                  <td className="sub">
                    {LIB[r.dernierEchange?.type] || r.dernierEchange?.typeLibelle || '—'}
                    {r.dernierEchange?.resume ? ` — ${r.dernierEchange.resume}` : ''}
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    {r.clientId && onOuvrirClient && (
                      // Le bouton mene la ou l'on NOTE ce qui s'est passe. Pas de << Fait >> : c'est
                      // le nouvel echange qui remplace la relance, et lui seul.
                      <button
                        className="btn ghost sm"
                        type="button"
                        style={{ padding: '1px 8px', fontSize: 11.5 }}
                        onClick={() => onOuvrirClient(r.clientId)}
                      >
                        Ouvrir la fiche
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
