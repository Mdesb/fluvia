import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { confirmer } from './Confirmation.jsx'

/**
 * ACCÈS PARTENAIRES — ce que l'établissement ACTIF ouvre aux applications tierces (spec API partenaire v1).
 *
 * L'éditeur crée les applications et leurs clés ; une clé n'ouvre pourtant rien tant qu'un
 * établissement n'a pas coché de portée ici. Chaque accord vaut pour l'établissement sélectionné en
 * haut de l'écran, et pour lui seul : un groupe de six piscines accorde six fois.
 *
 * ⚠ MODIFIER LES PORTÉES, C'EST RETIRER L'ACCORD PUIS EN DONNER UN NEUF. Le serveur date chaque accord
 * et ne réécrit jamais l'ancien : le journal d'audit dit ainsi qui a ouvert quoi, et quand.
 */
export default function PartnerAccessSettings({ droits, etabActif }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [acces, setAcces] = useState(null)
  const [choix, setChoix] = useState({})
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  function charger() {
    setAcces(null)
    api.accesPartenaires()
      .then((r) => {
        const lignes = membres(r)
        setAcces(lignes)
        // Seules les portées proposées par le serveur se cochent : une portée d'un ancien accord qui
        // n'est plus accordable serait refusée (422) à l'enregistrement.
        setChoix(Object.fromEntries(lignes.map((l) => [
          l.id,
          (l.grant?.scopes ?? []).filter((s) => l.availableScopes.some((a) => a.value === s)),
        ])))
      })
      .catch(() => setAcces(undefined))
  }

  useEffect(charger, [etabActif])

  if (!aLeDroit(droits, 'api.gerer')) {
    return <div className="empty">Votre profil ne porte pas le droit de gérer les accès partenaires (« api.gerer »).</div>
  }

  function cocher(id, portee, coche) {
    setChoix((p) => ({ ...p, [id]: coche ? [...(p[id] || []), portee] : (p[id] || []).filter((s) => s !== portee) }))
  }

  async function accorder(a) {
    setErreur(null)
    setSucces(null)
    try {
      await api.accorderAccesPartenaire(a.id, choix[a.id] || [])
      setSucces(`Accès accordé à « ${a.applicationName} ».`)
      charger()
    } catch (e) {
      setErreur(e.message || 'L’accès n’a pas pu être accordé.')
    }
  }

  async function retirer(a) {
    if (!(await confirmer({
      titre: `Retirer l’accès de « ${a.applicationName} » ?`,
      consequence: 'Ses clés ne verront plus aucune donnée de cet établissement, dès la requête suivante.',
      libelleOk: 'Retirer l’accès',
      danger: true,
    }))) return
    setErreur(null)
    setSucces(null)
    try {
      await api.retirerAccesPartenaire(a.id)
      setSucces(`Accès de « ${a.applicationName} » retiré.`)
      charger()
    } catch (e) {
      setErreur(e.message || 'L’accès n’a pas pu être retiré.')
    }
  }

  return (
    <section className="card">
      <div className="card-h">Accès partenaires</div>
      <div className="card-b">
        <div className="sub">
          Les applications ci-dessous peuvent lire les données de cet établissement par l’API Fluvia, dans la
          limite des portées que vous cochez. Rien n’est ouvert tant que vous n’avez pas accordé l’accès.
        </div>

        {succes && <div className="banner banner-ok">{succes}</div>}
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {acces === undefined && (
          <div className="banner banner-warn">Les accès n’ont pas pu être lus : cet écran ne sait pas ce qui est ouvert.</div>
        )}
        {acces === null && <div className="empty">Lecture des accès…</div>}
        {Array.isArray(acces) && acces.length === 0 && (
          <div className="empty">Aucune application partenaire n’est proposée pour l’instant.</div>
        )}

        {(Array.isArray(acces) ? acces : []).map((a) => (
          <section key={a.id} className="card">
            <div className="card-h">
              <span>
                {a.applicationName}{' '}
                {a.grant
                  ? <span className="badge good">accès accordé</span>
                  : <span className="badge mut">aucun accès</span>}
                {!a.applicationActive && <span className="badge warn">désactivée par l’éditeur</span>}
                <div className="sub">{a.contactEmail}</div>
              </span>
            </div>
            <div className="card-b">
              {a.availableScopes.map((s) => (
                <label key={s.value} className="field-lbl">
                  <input type="checkbox" disabled={!a.applicationActive}
                    checked={(choix[a.id] || []).includes(s.value)}
                    onChange={(e) => cocher(a.id, s.value, e.target.checked)} />{' '}
                  {s.label} <span className="sub mono">{s.value}</span>
                </label>
              ))}
              {a.grant && (
                <div className="sub">
                  Accordé le {new Date(a.grant.grantedAt).toLocaleDateString('fr-FR')}
                  {a.grant.grantedBy ? ` par ${a.grant.grantedBy}` : ''}.
                </div>
              )}
              {a.applicationActive && (
                <button type="button" className="btn primary sm" disabled={(choix[a.id] || []).length === 0} onClick={() => accorder(a)}>
                  {a.grant ? 'Enregistrer les portées' : 'Accorder l’accès'}
                </button>
              )}
              {a.grant && (
                <button type="button" className="btn ghost sm" onClick={() => retirer(a)}>Retirer l’accès</button>
              )}
            </div>
          </section>
        ))}
      </div>
    </section>
  )
}
