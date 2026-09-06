import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { idDe } from '../api/iri.js'

/**
 * LE REGISTRE DES INCIDENTS DE BADGE — la moitié qu'on ne pouvait pas lire.
 *
 * ⚠ DÉCLARER MARCHAIT DÉJÀ, LIRE ET ANNULER NON. L'écran des badges sait signaler un badge perdu
 * (`POST /personnel/badges/{id}/declarer-incident`, appelé depuis la liste). Mais
 * `GET /personnel/declarations-incident` n'était lu par personne, et
 * `POST /personnel/declarations-incident/{id}/annuler` n'était appelé par personne : on pouvait
 * déclarer une perte, jamais revoir la liste ni revenir dessus quand le badge ressortait d'une
 * poche.
 *
 * ⚠ ET LA NUANCE QUI COMPTE, MESURÉE PLUTÔT QUE DEVINÉE : `annulee` est un DRAPEAU STOCKÉ sur la
 * déclaration (`$declaration->isAnnulee()`), pas un état dérivé du badge. Réactiver un badge depuis
 * l'onglet « Badges staff » ne referme donc PAS son incident — le badge revient en service, et sa
 * déclaration reste ouverte ici. Sans cette phrase, le registre accumule des incidents pour des
 * badges qui fonctionnent, et plus personne ne le lit.
 */
export default function IncidentsBadge({ etabActif, droits }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [declarations, setDeclarations] = useState(null)
  const [badges, setBadges] = useState(null)
  const [employes, setEmployes] = useState(null)
  const [utilisateurs, setUtilisateurs] = useState(null)
  const [busy, setBusy] = useState(null)
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  // Le serveur accepte l'annulation avec l'un OU l'autre : gérer les badges, ou bloquer un support.
  const peutAnnuler = aUnDesDroits(droits, ['personnel.gerer_badge', 'acces.bloquer_support'])

  function charger() {
    setDeclarations(null)
    api.declarationsIncidentBadge()
      .then((r) => setDeclarations(membres(r)))
      .catch(() => setDeclarations(undefined))
  }

  useEffect(charger, [etabActif])

  useEffect(() => {
    api.badgeStaffs().then((r) => setBadges(membres(r))).catch(() => setBadges(undefined))
    api.employes().then((r) => setEmployes(membres(r))).catch(() => setEmployes(undefined))
    api.utilisateurs().then((r) => setUtilisateurs(membres(r))).catch(() => setUtilisateurs(undefined))
  }, [etabActif])

  /**
   * Le porteur du badge : deux sauts, badge → employé.
   *
   * ⚠ TROIS RÉPONSES DISTINCTES, ET L'ÉCRAN LES DISTINGUE. `undefined` = on n'a pas pu lire la
   * liste ; `null` = on a lu et le badge n'y est pas ; une chaîne = on sait. Les confondre ferait
   * afficher « badge inconnu » pour une lecture ratée — et on chercherait un badge supprimé qui
   * existe très bien.
   */
  function porteur(badgeId) {
    if (!badgeId) return null
    if (!Array.isArray(badges)) return undefined
    const b = badges.find((x) => String(x.id) === String(badgeId))
    if (!b) return null
    const empId = idDe(b.employe)
    if (!empId) return null
    if (!Array.isArray(employes)) return undefined
    const e = employes.find((x) => String(x.id) === String(empId))
    if (!e) return null
    return [e.prenom, e.nom].filter(Boolean).join(' ') || e.matricule || null
  }

  function nomAgent(agentId) {
    if (!agentId) return null
    if (!Array.isArray(utilisateurs)) return undefined
    const u = utilisateurs.find((x) => String(x.id) === String(agentId))
    return u ? (u.nom || u.email || null) : null
  }

  const ouvertes = useMemo(
    () => (Array.isArray(declarations) ? declarations.filter((d) => !d.annulee) : []),
    [declarations],
  )

  async function annuler(d) {
    setBusy(d.id)
    setErreur(null)
    try {
      await api.annulerDeclarationIncidentBadge(d.id)
      setSucces('Déclaration annulée : le badge est réactivé.')
      charger()
    } catch (e) {
      setErreur(e.message || 'La déclaration n’a pas pu être annulée.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>Incidents de badge</h3>
        <span className="sub">
          {declarations === null
            ? 'lecture…'
            : declarations === undefined
              ? 'illisible'
              : `${ouvertes.length} ouvert(s) sur ${declarations.length}`}
        </span>
      </div>

      <div className="card-b">
        <div className="sub" style={{ marginBottom: 'var(--esp-normal)' }}>
          Un badge déclaré perdu ou volé est bloqué. Annuler la déclaration le réactive et corrige le
          registre — c’est le geste quand le badge ressort d’une poche.
        </div>

        {succes && <div className="banner banner-ok">{succes}</div>}
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {declarations === undefined && (
          <div className="banner banner-warn">
            Le registre n’a pas pu être lu. Ce n’est pas la même chose qu’« aucun incident ».
          </div>
        )}

        {declarations === null && <div className="empty">Lecture du registre…</div>}

        {Array.isArray(declarations) && declarations.length === 0 && (
          <div className="empty">
            Aucun incident déclaré. Un badge perdu se signale depuis l’onglet « Badges staff ».
          </div>
        )}

        {Array.isArray(declarations) && declarations.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Porteur</th>
                  <th>Motif</th>
                  <th>Déclaré par</th>
                  <th>État</th>
                  {peutAnnuler && <th />}
                </tr>
              </thead>
              <tbody>
                {declarations.map((d) => {
                  const p = porteur(d.badgeStaff)
                  const a = nomAgent(d.agent)
                  return (
                    <tr key={d.id}>
                      <td>{d.horodatage ? new Date(d.horodatage).toLocaleString('fr-FR') : <span className="sub">—</span>}</td>
                      <td>
                        {p === undefined
                          ? <span className="sub">liste non lue</span>
                          : p || <span className="sub">badge inconnu</span>}
                      </td>
                      <td>{d.motif || <span className="sub">—</span>}</td>
                      <td>
                        {a === undefined
                          ? <span className="sub">non lu</span>
                          : a || <span className="sub">—</span>}
                      </td>
                      <td>
                        {d.annulee
                          ? <span className="badge mut">annulée</span>
                          : <span className="badge crit">badge bloqué</span>}
                      </td>
                      {peutAnnuler && (
                        <td>
                          {!d.annulee && (
                            <button
                              type="button"
                              className="btn ghost sm"
                              disabled={busy === d.id}
                              title="Réactive le badge et referme l’incident"
                              onClick={() => annuler(d)}
                            >
                              {busy === d.id ? 'Annulation…' : 'Annuler la déclaration'}
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
        )}

        {/* ⚠ LA PHRASE QUI ÉVITE UN REGISTRE QUI MENT. Mesuré : `annulee` est un drapeau stocké sur
            la déclaration, pas un état dérivé du badge. Réactiver un badge ailleurs ne referme donc
            pas son incident ici. */}
        <div className="sub" style={{ marginTop: 'var(--esp-bloc)' }}>
          Réactiver un badge depuis « Badges staff » ne referme pas son incident&nbsp;: le badge
          revient en service, mais sa déclaration reste ouverte dans ce registre. Pour refermer les
          deux d’un coup, annulez la déclaration ici.
        </div>
      </div>
    </section>
  )
}
