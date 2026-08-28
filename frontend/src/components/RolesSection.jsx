import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import Modal from './Modal.jsx'

// Créer et modifier un rôle — sur l'écran qui s'appelle « Utilisateurs et droits » et où l'on ne
// pouvait ni créer ni modifier un rôle.
//
// C'EST DE LA SÉCURITÉ, ET L'ÉCRAN EST CONÇU POUR ÇA :
//
// 1. **Les droits sont groupés par module, jamais en liste plate.** Deux cents cases à la suite ne se
//    lisent pas : on coche par fatigue, et personne ne peut dire ensuite ce qu'un rôle autorise. Par
//    module, on lit « ce rôle touche à la caisse et au catalogue » d'un coup d'œil.
//
// 2. **Le joker est traité à part, en haut, avec un avertissement.** Une permission dont le module est
//    `*` donne ce droit sur TOUS les modules — y compris ceux qui n'existent pas encore. Noyée au
//    milieu des autres, elle se coche comme n'importe quelle case et ouvre tout. C'est exactement ce
//    qui rendait l'administrateur invisible à mon propre filtre de menu : je cherchais `caisse.lire`
//    et il portait `*.lire`.
//
// 3. **Un rôle modèle ne se modifie pas ici.** Le socle les installe ; les altérer changerait le point
//    de départ de tous les établissements. On propose de les DUPLIQUER, ce que le serveur sait faire.
//
// 4. **La suppression dit ce qu'elle casse.** Un rôle supprimé retire ses droits à tous ceux qui le
//    portent, d'un coup et sans préavis.

export default function RolesSection({ droits, peutGerer, onChange }) {
  const [roles, setRoles] = useState([])
  const [permissions, setPermissions] = useState([])
  const [erreurEdition, setErreurEdition] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [edition, setEdition] = useState(null)
  const [enCours, setEnCours] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [r, p] = await Promise.all([api.roles(), api.permissions()])
      setRoles(membres(r))
      setPermissions(membres(p))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger])

  // Groupement par module, le joker isolé — il ne se lit pas comme les autres et ne doit pas se
  // cocher comme les autres.
  const { joker, parModule } = useMemo(() => {
    const j = []
    const m = {}
    for (const p of permissions) {
      if (p.module === '*') j.push(p)
      else (m[p.module] ||= []).push(p)
    }
    Object.values(m).forEach((l) => l.sort((a, b) => (a.action || '').localeCompare(b.action || '')))
    return { joker: j, parModule: Object.fromEntries(Object.entries(m).sort(([a], [b]) => a.localeCompare(b))) }
  }, [permissions])

  function ouvrir(role) {
    setSucces(null)
    setErreur(null)
    setErreurEdition(null)
    setEdition({
      role,
      nom: role?.nom || '',
      choisies: new Set((role?.permissions || []).map((p) => String(p.id || p['@id']?.split('/').pop()))),
    })
  }

  function basculer(id) {
    setEdition((s) => {
      const c = new Set(s.choisies)
      if (c.has(id)) c.delete(id)
      else c.add(id)
      return { ...s, choisies: c }
    })
  }

  function basculerModule(liste, tout) {
    setEdition((s) => {
      const c = new Set(s.choisies)
      liste.forEach((p) => (tout ? c.add(String(p.id)) : c.delete(String(p.id))))
      return { ...s, choisies: c }
    })
  }

  async function enregistrer(e) {
    e.preventDefault()
    setErreur(null)
    setErreurEdition(null)
    setEnCours(true)
    try {
      const corps = {
        nom: edition.nom.trim(),
        permissions: [...edition.choisies].map((id) => `/api/permissions/${id}`),
      }
      if (edition.role) await api.majRole(edition.role.id, corps)
      else await api.creerRole(corps)
      setSucces(edition.role ? 'Rôle modifié.' : 'Rôle créé.')
      setEdition(null)
      await recharger()
      onChange?.()
    } catch (err) {
      setErreurEdition(err.message || "L'enregistrement n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  async function dupliquer(role) {
    setErreur(null)
    setSucces(null)
    try {
      await api.dupliquerRole(role.id)
      setSucces(`« ${role.nom} » dupliqué.`)
      await recharger()
      onChange?.()
    } catch (e) {
      setErreur(e.message || "La duplication n'a pas abouti.")
    }
  }

  async function supprimer(role) {
    if (
      !window.confirm(
        `Supprimer le rôle « ${role.nom} » ?\n\nTous les comptes qui le portent perdront ces droits `
          + `immédiatement, sans préavis et sans qu'on puisse dire lesquels étaient concernés après coup.`,
      )
    )
      return
    setErreur(null)
    setSucces(null)
    try {
      await api.supprimerRole(role.id)
      setSucces('Rôle supprimé.')
      await recharger()
      onChange?.()
    } catch (e) {
      setErreur(e.message || "La suppression n'a pas abouti.")
    }
  }

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Rôles</h3>
        <span className="sub">ce que chaque profil a le droit de faire</span>
        {peutGerer && (
          <div className="r">
            <button className="btn primary sm" type="button" onClick={() => ouvrir(null)}>＋ Nouveau rôle</button>
          </div>
        )}
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {chargement ? (
          <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Rôle</th>
                <th className="num">Droits</th>
                <th>Modules concernés</th>
                {peutGerer && <th />}
              </tr>
            </thead>
            <tbody>
              {roles.map((r) => {
                const perms = r.permissions || []
                const modules = [...new Set(perms.map((p) => p.module))].sort()
                return (
                  <tr key={r.id}>
                    <td>
                      <span className="nm">{r.nom}</span>
                      {r.estModele && (
                        <span
                          className="badge mut"
                          style={{ marginLeft: 8 }}
                          title="Installé par le socle. Le modifier changerait le point de départ de tous les établissements."
                        >
                          modèle
                        </span>
                      )}
                    </td>
                    <td className="num">{perms.length}</td>
                    <td>
                      {modules.length === 0 ? (
                        <span className="sub">aucun — ce rôle ne donne rien</span>
                      ) : modules.includes('*') ? (
                        <span className="badge crit" title="Ce rôle porte un droit sur tous les modules.">
                          tous les modules
                        </span>
                      ) : (
                        modules.join(', ')
                      )}
                    </td>
                    {peutGerer && (
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          {/* Un rôle modèle se duplique, il ne se modifie pas : l'altérer changerait
                              le point de départ de tous les établissements. */}
                          {!r.estModele && (
                            <button className="btn ghost sm" type="button" onClick={() => ouvrir(r)}>Modifier</button>
                          )}
                          <button className="btn ghost sm" type="button" onClick={() => dupliquer(r)}>Dupliquer</button>
                          {!r.estModele && (
                            <button className="btn ghost sm" type="button" onClick={() => supprimer(r)}>Supprimer</button>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                )
              })}
              {roles.length === 0 && (
                <tr><td colSpan={peutGerer ? 4 : 3} className="empty">Aucun rôle.</td></tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      <Modal
        open={!!edition}
        onClose={() => { setEdition(null); setErreurEdition(null) }}
        titre={edition?.role ? `Modifier — ${edition.role.nom}` : 'Nouveau rôle'}
        taille="lg"
      >
        {edition && (
          <form onSubmit={enregistrer}>
            {/* LE REFUS DU SERVEUR S'AFFICHAIT DERRIÈRE LA MODALE RESTÉE OUVERTE.
                Maxime : « j'ai changé les droits d'un rôle, et je ne peux plus le faire maintenant. »
                L'erreur était bien récupérée — et écrite dans le bandeau de la CARTE, c'est-à-dire
                sous la fenêtre ouverte. On cliquait « Enregistrer », rien ne bougeait, et
                l'explication était cachée.
                Or le message du serveur est précisément celui qui débloque : « ce rôle est la seule
                source du droit d'administration de l'établissement X — désignez un remplaçant avant
                de retirer ce droit. » Il ne dit pas non, il dit dans quel ordre faire.
                Une modale doit porter l'erreur qui l'empêche de se fermer. Sinon on ferme la modale
                pour lire pourquoi on n'a pas pu la valider. */}
            {erreurEdition && <div className="banner banner-error">{erreurEdition}</div>}

            <div className="field">
              <label htmlFor="rl-nom">Nom du rôle *</label>
              <input
                id="rl-nom"
                className="input"
                required
                value={edition.nom}
                placeholder="Caissier du samedi"
                onChange={(e) => setEdition((s) => ({ ...s, nom: e.target.value }))}
              />
              <div className="hint">Le nom que verra celui qui attribue ce rôle à un compte.</div>
            </div>

            {joker.length > 0 && (
              <>
                <div className="fiche-sec" style={{ marginTop: 14 }}>Droits sur tout le logiciel</div>
                <div className="banner banner-error" style={{ marginBottom: 8 }}>
                  Ces droits s'appliquent à <b>tous les modules</b>, y compris ceux qui seront ajoutés
                  plus tard. Ne les cochez que pour un rôle d'administration.
                </div>
                {joker.map((p) => (
                  <label key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '4px 0' }}>
                    <input
                      type="checkbox"
                      checked={edition.choisies.has(String(p.id))}
                      onChange={() => basculer(String(p.id))}
                    />
                    Tout {p.action}
                  </label>
                ))}
              </>
            )}

            <div className="fiche-sec" style={{ marginTop: 14 }}>Droits par module</div>
            {Object.entries(parModule).map(([module, liste]) => {
              const coches = liste.filter((p) => edition.choisies.has(String(p.id))).length
              return (
                <div key={module} className="card" style={{ marginBottom: 8 }}>
                  <div className="card-b" style={{ padding: 10 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
                      <b>{module}</b>
                      <span className="sub">{coches} / {liste.length}</span>
                      <div style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
                        <button className="btn ghost sm" type="button" onClick={() => basculerModule(liste, true)}>
                          Tout
                        </button>
                        <button className="btn ghost sm" type="button" onClick={() => basculerModule(liste, false)}>
                          Rien
                        </button>
                      </div>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px 16px' }}>
                      {liste.map((p) => (
                        <label
                          key={p.id}
                          style={{ display: 'flex', alignItems: 'center', gap: 6, minWidth: 190, fontWeight: 400 }}
                        >
                          <input
                            type="checkbox"
                            checked={edition.choisies.has(String(p.id))}
                            onChange={() => basculer(String(p.id))}
                          />
                          {p.action}
                        </label>
                      ))}
                    </div>
                  </div>
                </div>
              )
            })}

            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
              <button className="btn" type="button" onClick={() => setEdition(null)}>Annuler</button>
              <button className="btn primary" type="submit" disabled={enCours}>
                {enCours ? 'Enregistrement…' : 'Enregistrer'}
              </button>
            </div>
          </form>
        )}
      </Modal>
    </section>
  )
}
