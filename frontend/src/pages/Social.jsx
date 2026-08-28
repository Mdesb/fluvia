import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

/**
 * PUBLICATION SOCIALE — écrire une fois, publier sur plusieurs comptes, **et savoir lequel a échoué**.
 *
 * **L'état qui justifie cet écran s'appelle `partially_failed`.** Un message part vers deux comptes ;
 * l'un passe, l'autre non — jeton expiré, réseau indisponible, message trop long pour cette
 * plateforme. Un écran qui n'afficherait que le statut du *message* montrerait « échec » sur une
 * publication à moitié réussie, ou « publié » sur une publication à moitié ratée. Les deux mènent au
 * même geste : republier, et se retrouver en double là où ça avait marché.
 *
 * > **Un statut global sur une action partielle est faux dans les deux sens, et le lecteur ne peut pas
 * > savoir lequel.**
 *
 * Le détail par compte est donc affiché **à côté** du statut global, avec le message d'erreur du
 * réseau tel qu'il est revenu — c'est lui qui distingue « recommence » de « corrige d'abord ».
 *
 * **Les jetons ne sont ni affichés ni saisis ici.** `SocialAccount` les garde en écriture seule,
 * chiffrés avant persistance, et seul « en possède un » est lisible. Ajouter un champ de collage
 * serait techniquement possible et mauvais : une connexion de compte est un aller-retour OAuth, pas un
 * secret qu'on recopie depuis un autre écran.
 */

const RESEAUX = { mastodon: 'Mastodon', bluesky: 'Bluesky' }

const ETAT_COMPTE = {
  connected: { libelle: 'connecté', ton: 'good' },
  token_expired: { libelle: 'jeton expiré', ton: 'crit' },
  revoked: { libelle: 'révoqué', ton: 'crit' },
}

const ETAT_MESSAGE = {
  draft: { libelle: 'Brouillon', ton: 'mut' },
  scheduled: { libelle: 'Programmé', ton: 'warn' },
  publishing: { libelle: 'En cours d’envoi', ton: 'warn' },
  published: { libelle: 'Publié', ton: 'good' },
  partially_failed: { libelle: 'Partiellement échoué', ton: 'crit' },
  failed: { libelle: 'Échoué', ton: 'crit' },
}

const ETAT_PUBLICATION = {
  pending: { libelle: 'en attente', ton: 'mut' },
  publishing: { libelle: 'en cours', ton: 'warn' },
  published: { libelle: 'publié', ton: 'good' },
  failed: { libelle: 'échoué', ton: 'crit' },
  skipped: { libelle: 'ignoré', ton: 'mut' },
}

function quand(v) {
  if (!v) return null
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? null : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function idDe(v) {
  if (!v) return null
  return typeof v === 'string' ? v.split('/').pop() : v.id || null
}

// UNE ARROBASE, PAS DEUX.
//
// Les identifiants de compte ne suivent pas la même convention selon le réseau : Mastodon rend
// « @piscine-a@mastodon.social », arobase comprise ; Bluesky rend « piscine-b.bsky.social », sans.
// Le préfixe était écrit en dur dans le JSX, si bien que le premier s'affichait avec une arobase
// doublée — vu à l'écran contre la préprod, sur les deux comptes de démonstration.
//
// On ne pose donc l'arobase que si elle manque, plutôt que de parier sur un réseau.
function arobase(handle) {
  const h = String(handle || '').trim()
  if (!h) return '—'
  return h.startsWith('@') ? h : `@${h}`
}

export default function Social({ etabActif, droits = [] }) {
  const peutPublier = aLeDroit(droits, 'social.publish')

  const [comptes, setComptes] = useState([])
  const [messages, setMessages] = useState([])
  const [publications, setPublications] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [busy, setBusy] = useState(false)

  const [texte, setTexte] = useState('')
  const [quandEnvoyer, setQuandEnvoyer] = useState('')
  const [cibles, setCibles] = useState([])

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [c, m, p] = await Promise.all([
        api.comptesSociaux(),
        api.messagesSociaux(),
        api.publicationsSociales().catch(() => null),
      ])
      setComptes(membres(c))
      setMessages(membres(m))
      setPublications(p ? membres(p) : [])
    } catch (e) {
      setErreur(e.message || 'Les publications n’ont pas pu être chargées.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  async function envoyer() {
    setBusy(true)
    setErreur(null)
    try {
      await api.creerMessageSocial({
        body: texte.trim(),
        targetAccounts: cibles,
        ...(quandEnvoyer ? { scheduledFor: new Date(quandEnvoyer).toISOString() } : {}),
      })
      setTexte('')
      setQuandEnvoyer('')
      setCibles([])
      setSucces(quandEnvoyer ? 'Message programmé.' : 'Message envoyé à la file de publication.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’envoi a échoué.')
    } finally {
      setBusy(false)
    }
  }

  if (chargement) return <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>

  const utilisables = comptes.filter((c) => c.status === 'connected')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Publication sociale</h1>
          <div className="sub">{comptes.length} compte{comptes.length > 1 ? 's' : ''} · {messages.length} message{messages.length > 1 ? 's' : ''}</div>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><span>Comptes</span></div>
        {comptes.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 22 }}>
            Aucun compte connecté. La connexion d&rsquo;un compte se fait par le réseau lui-même
            (autorisation OAuth) : elle ne se saisit pas ici.
          </div>
        ) : (
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', padding: 14 }}>
            {comptes.map((c) => {
              const etat = ETAT_COMPTE[c.status] || { libelle: c.status, ton: 'mut' }
              return (
                <div key={c.id} className="card" style={{ padding: 10, border: '1px solid var(--line)', minWidth: 200 }}>
                  <span className="nm">{arobase(c.handle)}</span>
                  <div className="sub">
                    {RESEAUX[c.network] || c.network}
                    {c.host ? ` · ${c.host}` : ''}
                  </div>
                  <span className={`badge ${etat.ton}`} style={{ marginTop: 4, display: 'inline-block' }}>
                    {etat.libelle}
                  </span>
                  {c.status === 'token_expired' && (
                    // Un jeton expire ne se voit qu'ici : sur la file de messages, il produit des
                    // echecs qu'on attribue au reseau. Le dire au bon endroit evite de republier
                    // trois fois avant de comprendre.
                    <div className="hint" style={{ margin: '4px 0 0' }}>
                      Les envois vers ce compte échoueront tant qu&rsquo;il n&rsquo;est pas reconnecté.
                    </div>
                  )}
                </div>
              )
            })}
          </div>
        )}
      </section>

      {peutPublier && utilisables.length > 0 && (
        <section className="card" style={{ marginBottom: 14 }}>
          <div className="card-h"><span>Écrire</span></div>
          <div style={{ display: 'grid', gap: 10, padding: 14 }}>
            <textarea
              className="input"
              rows={4}
              value={texte}
              onChange={(e) => setTexte(e.target.value)}
              placeholder="Votre message…"
            />
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
              {utilisables.map((c) => (
                <label key={c.id} style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 13 }}>
                  <input
                    type="checkbox"
                    checked={cibles.includes(c.id)}
                    onChange={() =>
                      setCibles((l) => (l.includes(c.id) ? l.filter((x) => x !== c.id) : [...l, c.id]))
                    }
                  />
                  {arobase(c.handle)}
                </label>
              ))}
              <label style={{ marginLeft: 'auto', display: 'flex', gap: 6, alignItems: 'center', fontSize: 13 }}>
                Programmer
                <input
                  className="input sm"
                  type="datetime-local"
                  style={{ width: 200 }}
                  value={quandEnvoyer}
                  onChange={(e) => setQuandEnvoyer(e.target.value)}
                />
              </label>
              <button
                className="btn primary"
                type="button"
                disabled={busy || texte.trim() === '' || cibles.length === 0}
                onClick={envoyer}
              >
                {quandEnvoyer ? 'Programmer' : 'Publier'}
              </button>
            </div>
            {cibles.length === 0 && (
              <div className="hint" style={{ margin: 0 }}>
                Choisissez au moins un compte : un message sans destinataire ne partirait nulle part.
              </div>
            )}
          </div>
        </section>
      )}

      <section className="card">
        <div className="card-h"><span>Messages</span></div>
        {messages.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 22 }}>Aucun message.</div>
        ) : (
          <div style={{ display: 'grid', gap: 10, padding: 14 }}>
            {messages.map((m) => {
              const etat = ETAT_MESSAGE[m.status] || { libelle: m.status, ton: 'mut' }
              const siennes = publications.filter((p) => idDe(p.post) === String(m.id))
              return (
                <article key={m.id} className="card" style={{ padding: 12, border: '1px solid var(--line)' }}>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                    <span className={`badge ${etat.ton}`}>{etat.libelle}</span>
                    {m.scheduledFor && <span className="sub">pour le {quand(m.scheduledFor)}</span>}
                    <span className="sub" style={{ marginLeft: 'auto' }}>{quand(m.createdAt)}</span>
                  </div>

                  <div style={{ whiteSpace: 'pre-wrap', margin: '8px 0' }}>{m.body}</div>

                  {/* LE DETAIL PAR COMPTE, A COTE DU STATUT GLOBAL.
                      C'est lui qui distingue << recommence >> de << corrige d'abord >>, et il evite de
                      republier partout pour rattraper un seul echec. */}
                  {siennes.length > 0 && (
                    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', fontSize: 12 }}>
                      {siennes.map((p) => {
                        const e = ETAT_PUBLICATION[p.status] || { libelle: p.status, ton: 'mut' }
                        const compte = comptes.find((c) => String(c.id) === idDe(p.account))
                        return (
                          <span key={p.id} className="sub">
                            <span className={`badge ${e.ton}`}>{e.libelle}</span>{' '}
                            @{compte?.handle || '—'}
                            {p.errorMessage ? ` — ${p.errorMessage}` : ''}
                            {p.attempts > 1 ? ` (${p.attempts} tentatives)` : ''}
                          </span>
                        )
                      })}
                    </div>
                  )}
                </article>
              )
            })}
          </div>
        )}
      </section>
    </div>
  )
}
