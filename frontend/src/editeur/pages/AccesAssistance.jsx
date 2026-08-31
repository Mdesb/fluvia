import { useCallback, useEffect, useState } from 'react'
import { ApiError, api } from '../../api/client.js'

// LES ACCÈS D'ASSISTANCE OUVERTS — et le seul endroit d'où l'on peut les refermer.
//
// ── POURQUOI CET ÉCRAN EXISTE ──────────────────────────────────────────────────────────────────
//
// `GET /editor/support-accesses` répond depuis des jours et personne ne l'appelait. Ouvrir un accès
// était possible ; savoir combien étaient ouverts, chez qui, et depuis quand, ne l'était pas.
//
// **Un accès qu'on ouvre sans que personne ne le voie finit par ne plus se refermer.** C'est la
// mécanique exacte par laquelle un accès temporaire devient permanent : pas une décision, une
// absence de regard. Le plafond de huit heures limite les dégâts ; il ne remplace pas le fait de
// voir.
//
// ── ⚠ CE QU'ON NE PEUT PAS FAIRE DEPUIS L'ONGLET DE SUPPORT ───────────────────────────────────
//
// Révoquer exige que l'établissement ACTIF soit celui de l'éditeur. Dans l'onglet ouvert chez un
// client, l'établissement actif est celui du client : l'appel rendrait 404. Ce n'est pas une lacune
// du serveur mais sa règle — les gestes d'éditeur se font depuis l'éditeur. D'où le bouton « Sortir
// du mode support » qui ferme l'onglet sans révoquer, et cette page-ci qui révoque.
export default function AccesAssistance({ onRefus }) {
  const [acces, setAcces] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(null)

  const charger = useCallback(() => {
    setErreur(null)
    api
      .editorSupportAccesses()
      .then((r) => setAcces(r['hydra:member'] ?? r.member ?? []))
      .catch((e) => {
        // Un 404 sur ces routes veut dire « cette session n'est pas celle de l'éditeur ». La
        // coquille sait le dire mieux que cet écran ; on lui laisse la main.
        if (e instanceof ApiError && e.status === 404) onRefus?.()
        setErreur(e.message || 'La liste des accès n’a pas pu être chargée.')
      })
  }, [onRefus])

  useEffect(charger, [charger])

  async function revoquer(id) {
    setEnCours(id)
    setErreur(null)
    try {
      await api.revoquerAccesAssistance(id)
      charger()
    } catch (e) {
      setErreur(e.message || 'L’accès n’a pas pu être refermé.')
    } finally {
      setEnCours(null)
    }
  }

  if (acces === null && !erreur) return <div className="empty">Chargement…</div>

  return (
    <>
      <div className="card">
        <div className="card-h">Accès d’assistance ouverts</div>
        <div className="card-b">
          {erreur && <div className="banner banner-error">{erreur}</div>}

          {acces?.length === 0 && (
            <div className="empty">
              <p>Aucun accès d’assistance n’est ouvert en ce moment.</p>
              <p className="hint">
                C’est l’état normal. Un accès s’ouvre pour un dépannage précis, se referme tout seul
                à son terme, et laisse une trace dans le journal d’audit du client.
              </p>
            </div>
          )}

          {(acces ?? []).map((a) => (
            <div key={a.id} className="sup-acces">
              <div className="sup-acces-t">
                <strong>{a.establishmentName || 'Établissement inconnu'}</strong>
                {/* Le temps restant vient du SERVEUR (`remainingMinutes`), pas d'un calcul sur
                    l'horloge du navigateur : c'est le serveur qui décide quand l'accès cesse, et
                    afficher un autre chiffre que le sien serait afficher le mauvais. */}
                <span className={`badge ${a.remainingMinutes > 30 ? 'good' : 'mut'}`}>
                  {a.remainingMinutes > 0
                    ? `encore ${a.remainingMinutes} min`
                    : 'terminé'}
                </span>
              </div>
              <div className="hint">
                {a.granteeName || a.granteeEmail} — {a.reason}
              </div>
              <div className="hint">
                Ouvert par {a.grantedBy || 'inconnu'}, jusqu’à {formaterHeure(a.expiresAt)}
              </div>
              <button
                type="button"
                className="btn ghost sm"
                disabled={enCours === a.id || a.remainingMinutes <= 0}
                onClick={() => revoquer(a.id)}
                title="Referme l’accès immédiatement. L’entrée reste dans le journal : c’est l’historique de qui a pu voir quoi."
              >
                {enCours === a.id ? 'Fermeture…' : 'Refermer maintenant'}
              </button>
            </div>
          ))}
        </div>
      </div>
    </>
  )
}

// L'heure locale, sans la date quand c'est aujourd'hui : un accès dure au plus huit heures, donc la
// date n'apporte rien neuf fois sur dix et allonge une ligne qu'on lit en diagonale.
function formaterHeure(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  const memeJour = d.toDateString() === new Date().toDateString()
  return memeJour
    ? d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}
