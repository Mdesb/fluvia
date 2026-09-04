import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri'

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

// « CONNECTÉ » PROMETTAIT UNE VÉRIFICATION QUI N'A JAMAIS EU LIEU.
//
// `SocialAccountStatus::Connected` est la valeur PAR DÉFAUT de la colonne : elle veut dire « un
// jeton a été enregistré », pas « le lien fonctionne ». Rien ne l'éprouve — aucune route de test
// n'existe, et le statut ne bascule en `token_expired` que lorsqu'une PUBLICATION échoue, dans
// `PublishSocialPublicationHandler`. Vérifié dans l'entité et dans le handler, pas déduit.
//
// Un compte dont le jeton est faux depuis le premier jour s'affichait donc « connecté » en vert
// jusqu'au premier message — et l'échec arrivait alors sur la file des messages, où on l'attribue
// au réseau. Même famille que le contrôleur d'accès affiché « En ligne » sans avoir jamais parlé, et
// que les opérations badgées « contrôlée » qu'aucun plafond ne limitait : l'écran affirmait un état
// qu'il n'avait pas constaté.
//
// On dit donc ce qu'on sait — un jeton est enregistré — et le ton passe au neutre : le vert est
// réservé à ce qu'on a vu marcher.
const ETAT_COMPTE = {
  connected: { libelle: 'jeton enregistré', ton: 'info' },
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
  // ⚠ `lu` DISTINGUE << charge et vide >> DE << pas charge >>, ET C'EST TOUT LE SUJET.
  //
  // `comptes` et `messages` partent a `[]`. Sur une lecture refusee ils y RESTENT, et l'ecran
  // annoncait alors << 0 compte · 0 message >> juste au-dessus du bandeau qui disait qu'il n'avait
  // pas pu demander. Zero et << je n'ai pas pu demander >> sont des affirmations opposees : la
  // premiere se verifie, rassure, et fait renoncer a chercher plus loin.
  //
  // Un booleen separe de `erreur` est necessaire : `erreur` porte aussi les echecs d'ENVOI, et
  // dans ce cas les donnees sont bien chargees et les comptes bien reels.
  const [lu, setLu] = useState(false)
  // Le detail par compte est lu a part et son echec est tolere -- mais il est RETENU. Voir plus bas.
  const [detailIndisponible, setDetailIndisponible] = useState(false)

  const [texte, setTexte] = useState('')
  const [quandEnvoyer, setQuandEnvoyer] = useState('')
  const [cibles, setCibles] = useState([])

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // ⚠ L'ECHEC DU DETAIL EST TOLERE MAIS PLUS AVALE.
      //
      // `catch(() => null)` laissait le detail par compte disparaitre en silence. Or c'est lui qui
      // distingue << recommence >> de << corrige d'abord >> : un message `partially_failed` sans
      // detail se lit << aucun detail disponible >>, alors que la verite est << je n'ai pas pu le
      // lire >>. On garde la tolerance -- ce detail ne doit pas emporter l'ecran entier -- et on
      // retient l'echec pour le dire a l'endroit exact ou le detail manque.
      let detailKo = false
      const [c, m, p] = await Promise.all([
        api.comptesSociaux(),
        api.messagesSociaux(),
        api.publicationsSociales().catch(() => { detailKo = true; return null }),
      ])
      setComptes(membres(c))
      setMessages(membres(m))
      setPublications(p ? membres(p) : [])
      setDetailIndisponible(detailKo)
      setLu(true)
    } catch (e) {
      setErreur(e.message || 'Les publications n’ont pas pu être chargées.')
      // On ne garde pas de donnees a moitie lues : un decompte partiel est aussi trompeur qu'un
      // zero invente, et il a en plus l'air normal.
      setLu(false)
      setComptes([])
      setMessages([])
      setPublications([])
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
          <div className="sub">
            {lu
              ? `${comptes.length} compte${comptes.length > 1 ? 's' : ''} · ${messages.length} message${messages.length > 1 ? 's' : ''}`
              : 'Comptes et messages non lus — la lecture n’a pas abouti.'}
          </div>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><span>Comptes</span></div>
        {!lu ? (
          // ⚠ PAS L'ETAT VIDE. << Aucun compte connecte >> est une phrase sure d'elle, qui explique
          // meme comment en connecter un : affichee sur une lecture refusee, elle envoie chercher
          // une autorisation OAuth pour un compte qui existe peut-etre deja.
          <div className="empty">
            La liste des comptes n&rsquo;a pas pu être lue. Il y en a peut-être&nbsp;: on ne le sait pas.
          </div>
        ) : comptes.length === 0 ? (
          <div className="empty">
            Aucun compte connecté. La connexion d&rsquo;un compte se fait par le réseau lui-même
            (autorisation OAuth) : elle ne se saisit pas ici.
          </div>
        ) : (
          <div style={{ display: 'flex', gap: 'var(--esp-normal)', flexWrap: 'wrap', padding: 'var(--esp-large)' }}>
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
                  {c.status === 'connected' && (
                    <div className="hint" style={{ margin: '4px 0 0' }}>
                      Le lien n’est éprouvé qu’au premier message envoyé : personne ne l’a testé
                      depuis l’enregistrement du jeton.
                    </div>
                  )}
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
          <div style={{ display: 'grid', gap: 'var(--esp-normal)', padding: 'var(--esp-large)' }}>
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
        {!lu ? (
          <div className="empty">
            La file des messages n&rsquo;a pas pu être lue.
          </div>
        ) : messages.length === 0 ? (
          <div className="empty">Aucun message.</div>
        ) : (
          <div style={{ display: 'grid', gap: 'var(--esp-normal)', padding: 'var(--esp-large)' }}>
            {messages.map((m) => {
              const etat = ETAT_MESSAGE[m.status] || { libelle: m.status, ton: 'mut' }
              const siennes = publications.filter((p) => idDe(p.post) === String(m.id))
              return (
                <article key={m.id} className="card" style={{ padding: 'var(--esp-large)', border: '1px solid var(--line)' }}>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                    <span className={`badge ${etat.ton}`}>{etat.libelle}</span>
                    {m.scheduledFor && <span className="sub">pour le {quand(m.scheduledFor)}</span>}
                    <span className="sub" style={{ marginLeft: 'auto' }}>{quand(m.createdAt)}</span>
                  </div>

                  <div style={{ whiteSpace: 'pre-wrap', margin: '8px 0' }}>{m.body}</div>

                  {/* LE DETAIL PAR COMPTE, A COTE DU STATUT GLOBAL.
                      C'est lui qui distingue << recommence >> de << corrige d'abord >>, et il evite de
                      republier partout pour rattraper un seul echec. */}
                  {/* Le detail manque parce qu'on n'a pas pu le lire : on le dit ICI, a la place
                      exacte ou il devrait etre. Dit en haut de l'ecran, il se perdrait ; tu
                      conclurais que ce message n'a simplement pas de detail. */}
                  {detailIndisponible && (
                    <div className="sub" style={{ fontSize: 12 }}>
                      Détail par compte indisponible — cette lecture a échoué. Le statut ci-dessus
                      reste juste, mais il ne dit pas <i>quel</i> compte a échoué.
                    </div>
                  )}

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
