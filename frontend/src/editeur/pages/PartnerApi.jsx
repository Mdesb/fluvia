import { useCallback, useEffect, useState } from 'react'
import { ApiError, api, membres } from '../../api/client.js'
import Modal from '../../components/Modal.jsx'
import { confirmer } from '../../components/Confirmation.jsx'

// L'API PARTENAIRE, CÔTÉ ÉDITEUR — les applications tierces et leurs clés (spec API partenaire v1, §3.1).
//
// Jusqu'au 04/10, tout se faisait en base : aucune clé ne s'émettait, aucune ne se révoquait, et
// personne ne savait laquelle servait encore. Cet écran est le seul endroit d'où l'on émet et révoque.
//
// ⚠ LE SECRET N'EST MONTRÉ QU'UNE FOIS, ET L'ÉCRAN NE PEUT PAS FAIRE AUTREMENT. Le serveur ne garde
// que son empreinte : il le rend dans la réponse d'émission, puis plus jamais. Il vit ici dans un état
// local, effacé dès qu'on le referme — recharger la page le perd, et c'est voulu.
//
// Une clé ne donne accès à rien par elle-même : ce sont les établissements qui accordent des portées,
// depuis leurs paramètres (« Accès partenaires »).
export default function PartnerApi({ onRefus }) {
  const [applications, setApplications] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(null)
  const [creation, setCreation] = useState(false)
  // { application, prefix, secret } — la seule copie du secret côté navigateur.
  const [cle, setCle] = useState(null)

  const charger = useCallback(() => {
    api.editorPartnerApplications()
      .then((r) => setApplications(membres(r)))
      .catch((e) => {
        if (e instanceof ApiError && e.status === 404) onRefus?.()
        setErreur(e.message || 'Les applications n’ont pas pu être chargées.')
      })
  }, [onRefus])

  useEffect(charger, [charger])

  async function geste(id, appel, echec) {
    setEnCours(id)
    setErreur(null)
    try {
      return await appel()
    } catch (e) {
      setErreur(e.message || echec)
      return null
    } finally {
      setEnCours(null)
      charger()
    }
  }

  async function emettre(a) {
    const fiche = await geste(a.id, () => api.emettreClePartenaire(a.id), 'La clé n’a pas pu être émise.')
    const emise = fiche?.credentials?.find((c) => c.id === fiche.issuedCredentialId)
    if (fiche?.issuedSecret) setCle({ application: a.name, prefix: emise?.prefix, secret: fiche.issuedSecret })
  }

  async function revoquer(a, c) {
    if (!(await confirmer({
      titre: `Révoquer la clé ${c.prefix}… de « ${a.name} » ?`,
      consequence: 'Toute requête qui la présente sera refusée immédiatement. Une clé révoquée ne se réactive pas : on en émet une neuve.',
      libelleOk: 'Révoquer',
      danger: true,
    }))) return
    await geste(c.id, () => api.revoquerClePartenaire(c.id), 'La clé n’a pas pu être révoquée.')
  }

  async function desactiver(a) {
    if (!(await confirmer({
      titre: `Désactiver « ${a.name} » ?`,
      consequence: 'Toutes ses clés seront refusées, quels que soient les accords donnés par les établissements. L’historique reste lisible.',
      libelleOk: 'Désactiver',
      danger: true,
    }))) return
    await geste(a.id, () => api.desactiverApplicationPartenaire(a.id), 'L’application n’a pas pu être désactivée.')
  }

  if (applications === null && !erreur) return <div className="empty">Chargement…</div>

  return (
    <div className="card">
      <div className="card-h">
        Applications partenaires
        <button type="button" className="btn primary sm" onClick={() => setCreation(true)}>Nouvelle application</button>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {cle && (
          <div className="banner banner-warn" role="status">
            <p>
              Clé émise pour <b>{cle.application}</b>. Copiez-la maintenant : elle ne sera <b>plus jamais affichée</b>,
              ni ici ni ailleurs.
            </p>
            <p className="mono">{cle.secret}</p>
            <button type="button" className="btn sm" onClick={() => navigator.clipboard?.writeText(cle.secret)}>Copier</button>
            <button type="button" className="btn ghost sm" onClick={() => setCle(null)}>J’ai copié la clé</button>
          </div>
        )}

        {applications?.length === 0 && (
          <div className="empty">
            <p>Aucune application partenaire.</p>
            <p className="hint">Créez-en une pour chaque société qui s’intègre : ses clés et les accords des établissements s’y rattachent.</p>
          </div>
        )}

        {(applications ?? []).map((a) => (
          <section key={a.id} className="card">
            <div className="card-h">
              <span>
                {a.name} <span className={`badge ${a.active ? 'good' : 'mut'}`}>{a.active ? 'active' : 'désactivée'}</span>
                <div className="sub">{a.contactEmail}</div>
              </span>
              {a.active && (
                <span>
                  <button type="button" className="btn sm" disabled={enCours === a.id} onClick={() => emettre(a)}>Émettre une clé</button>
                  <button type="button" className="btn ghost sm" disabled={enCours === a.id} onClick={() => desactiver(a)}>Désactiver</button>
                </span>
              )}
            </div>
            <div className="card-b">
              {a.credentials.length === 0 ? (
                <div className="hint">Aucune clé émise.</div>
              ) : (
                <table className="tbl">
                  <thead>
                    <tr><th>Clé</th><th>État</th><th>Émise</th><th>Expire</th><th>Dernier usage</th><th /></tr>
                  </thead>
                  <tbody>
                    {a.credentials.map((c) => (
                      <tr key={c.id}>
                        <td className="mono">{c.prefix}…</td>
                        <td>
                          <span className={`badge ${ETAT[c.status]?.[1] || 'mut'}`}>{ETAT[c.status]?.[0] || c.status}</span>
                          {c.revokedAt && <div className="sub">le {date(c.revokedAt)} par {c.revokedBy || 'inconnu'}</div>}
                        </td>
                        <td>{date(c.issuedAt)}</td>
                        <td>{c.expiresAt ? date(c.expiresAt) : 'jamais'}</td>
                        <td>{c.lastUsedAt ? date(c.lastUsedAt) : <span className="sub">jamais utilisée</span>}</td>
                        <td>
                          {c.status !== 'revoked' && (
                            <button type="button" className="btn ghost sm" disabled={enCours === c.id} onClick={() => revoquer(a, c)}>Révoquer</button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </section>
        ))}
      </div>

      <CreationApplication ouvert={creation} onFermer={() => setCreation(false)} onCreee={() => { setCreation(false); charger() }} />
    </div>
  )
}

const ETAT = { active: ['active', 'good'], expired: ['expirée', 'warn'], revoked: ['révoquée', 'crit'] }

function CreationApplication({ ouvert, onFermer, onCreee }) {
  const [v, setV] = useState({ name: '', contactEmail: '' })
  const [erreur, setErreur] = useState(null)

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    try {
      await api.creerApplicationPartenaire(v)
      setV({ name: '', contactEmail: '' })
      onCreee()
    } catch (err) {
      setErreur(err.message || 'L’application n’a pas pu être créée.')
    }
  }

  return (
    <Modal open={ouvert} onClose={onFermer} titre="Nouvelle application partenaire" taille="sm">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="field">
          <label htmlFor="partner-app-name">Nom de la société ou du produit</label>
          <input id="partner-app-name" className="input" required maxLength={120} value={v.name}
            onChange={(e) => setV((p) => ({ ...p, name: e.target.value }))} />
        </div>
        <div className="field">
          <label htmlFor="partner-app-email">E-mail de contact technique</label>
          <input id="partner-app-email" className="input" type="email" required maxLength={180} value={v.contactEmail}
            onChange={(e) => setV((p) => ({ ...p, contactEmail: e.target.value }))} />
          <span className="hint">C’est à cette adresse qu’on écrit quand une clé fuit.</span>
        </div>
        <button type="submit" className="btn primary">Créer</button>
      </form>
    </Modal>
  )
}

function date(iso) {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
