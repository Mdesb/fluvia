import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'

// LES BORDEREAUX PAYFIP — rendus visibles, et rien de plus.
//
// ── CE QUI ÉTAIT INVISIBLE ──────────────────────────────────────────────────────────────────────
//
// PayFiP est le service d'encaissement de la DGFiP : un adhérent paie en ligne, le Trésor confirme.
// Chaque transaction ouvre un bordereau, qui reste `en_attente` jusqu'au retour.
//
// Le référentiel entier n'avait **aucune** trace dans l'interface : ni liste, ni détail, ni statut.
// Un paiement dont le retour ne revient jamais restait donc en attente indéfiniment, sans que
// personne puisse seulement le constater. C'est ce que cet écran répare, et c'est tout ce qu'il
// répare.
//
// ── ⚠ POURQUOI IL N'Y A PAS DE BOUTON « REJOUER », ALORS QUE LA ROUTE EXISTE ───────────────────
//
// `POST /compta/payfip/{id}/rejouer` est appelable, et `TraiterRetourPayFipHandler::rejouer()`
// fait exactement ceci :
//
//     $bordereau->setNbTentativesRejeu($bordereau->getNbTentativesRejeu() + 1);
//
// Il **n'interroge pas la DGFiP**, ne change aucun statut, ne rapproche aucune vente. Un bouton
// « Rejouer » promettrait donc une relance qui n'a pas lieu — et le compteur qui s'incrémente
// donnerait l'illusion que quelque chose a été tenté. C'est pire que pas de bouton : on croirait
// avoir agi.
//
// Le vrai rejeu suppose d'interroger la DGFiP, ce qui suppose la preuve d'authenticité de ses
// rappels (signature). Elle nous est inconnue et c'est un **bloqueur externe consigné (E-7)** — pas
// quelque chose qu'on écrit en attendant.
//
// ── ⚠ ET « RAPPROCHÉE » NE VEUT PAS DIRE « PAYÉE » ─────────────────────────────────────────────
//
// `TraiterRetourPayFipHandler` porte en tête que ce module ne modifie **jamais** la vente : le
// statut « payé » reste porté par le module de vente. Ce que cette colonne dit, c'est que la
// comptabilité a rapproché son propre référentiel — pas que l'encaissement est acquis côté vente.
// Confondre les deux ferait clore des dossiers qui ne le sont pas.
export default function BordereauxPayFip({ etabActif }) {
  const [bordereaux, setBordereaux] = useState(null)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(() => {
    api.bordereauxPayFip()
      .then((r) => setBordereaux(membres(r)))
      .catch((e) => {
        // Une lecture qui échoue n'est pas une absence de bordereau : dire « aucun » ferait
        // conclure qu'aucun paiement n'attend son retour, ce qui est précisément le contraire de
        // ce que cet écran existe pour montrer.
        setErreur(e.message || 'Les bordereaux PayFiP n’ont pas pu être lus.')
        setBordereaux([])
      })
  }, [])

  useEffect(() => { charger() }, [etabActif, charger])

  const enAttente = (bordereaux ?? []).filter((b) => b.statutRetour === 'en_attente').length

  return (
    <section className="card">
      <div className="card-h">
        <h3>Encaissements PayFiP</h3>
        <span className="sub">retours de la DGFiP</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {bordereaux === null && !erreur && <div className="empty">Chargement…</div>}

        {bordereaux !== null && bordereaux.length === 0 && !erreur && (
          <div className="empty">
            <p>Aucun encaissement PayFiP.</p>
            <p className="hint">
              Les transactions apparaissent ici dès qu’un paiement est initié auprès de la DGFiP.
            </p>
          </div>
        )}

        {enAttente > 0 && (
          <div className="banner">
            {enAttente === 1
              ? '1 transaction attend toujours son retour de la DGFiP.'
              : `${enAttente} transactions attendent toujours leur retour de la DGFiP.`}{' '}
            La relance automatique n’existe pas encore : elle suppose de vérifier l’authenticité des
            rappels du Trésor, ce qui n’est pas de notre côté. Rapprochez ces lignes à la main
            depuis votre espace DGFiP.
          </div>
        )}

        {(bordereaux ?? []).map((b) => (
          <div key={b.id} className="sup-acces">
            <div className="sup-acces-t">
              <span className="mono">{b.referenceTransaction || '—'}</span>
              <span className={`badge ${badgeStatut(b.statutRetour)}`}>
                {libelleStatut(b.statutRetour)}
              </span>
              {/* ⚠ « rapprochée » est un fait COMPTABLE, pas un fait de vente : ce module ne
                  touche jamais la vente, dont le statut « payé » reste porté ailleurs. */}
              {b.venteRapprochee && <span className="badge good">rapprochée en compta</span>}
            </div>
            <div className="hint">
              Vente <span className="mono">{String(b.venteOrigine || '').slice(0, 8)}</span>
              {' · '}{dateHeureFr(b.dateHeure)}
              {b.nbTentativesRejeu > 0 && ` · ${b.nbTentativesRejeu} rejeu(x) enregistré(s)`}
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

function libelleStatut(s) {
  return { ok: 'encaissé', echec: 'échec', annule: 'annulé', en_attente: 'en attente' }[s] || s || '—'
}

function badgeStatut(s) {
  return { ok: 'good', echec: 'crit', annule: 'mut', en_attente: 'warn' }[s] || 'mut'
}

function dateHeureFr(valeur) {
  const s = String(valeur || '')
  const [d, h] = s.split('T')
  const [a, m, j] = (d || '').split('-')
  return j ? `${j}/${m}/${a} ${(h || '').slice(0, 5)}` : '—'
}
