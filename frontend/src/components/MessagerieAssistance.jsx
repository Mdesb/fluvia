import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'

/**
 * LA DEMANDE D'ASSISTANCE EST UNE CONVERSATION, PAS UN FORMULAIRE SUIVI D'UN TABLEAU.
 *
 * Maxime, le 28/08 : *« la demande d'assistance doit être sous forme de messagerie »*, en montrant
 * les Discussions de Vespera. Ce qu'il demandait n'est pas un habillage : c'est un changement de ce
 * que l'écran raconte.
 *
 * **Ce que l'écran disait avant.** Un tableau de six colonnes — sujet, module, priorité, statut,
 * affecté à, dernière activité — et, au clic, une modale où le fil des échanges arrivait en
 * quatrième position, sous les statistiques et sous la description. Un tableau répond à *« combien
 * de demandes sont critiques ? »*. C'est la question de l'agent qui ouvre sa file le matin. Ce n'est
 * pas la question de celui qui a écrit hier et qui revient : lui demande *« est-ce qu'on m'a
 * répondu ? »*, et le tableau lui répondait par une date dans une colonne.
 *
 * > **Une file d'attente se lit en colonnes ; une réponse se lit en bulles.** Le même module sert
 * > les deux, alors l'écran doit répondre à la seconde question sans perdre la première.
 *
 * **Trois choses viennent de Vespera, et une n'en vient pas.** (Vespera est un produit
 * TIERS, hors périmètre depuis D9 : on s'en inspire, on n'en reprend rien. Notre produit
 * s'appelle Fluvia.)
 *   1. Les deux volets : la liste des fils à gauche, le fil ouvert à droite. On voit qui a écrit en
 *      dernier sans quitter la conversation qu'on lit.
 *   2. L'aperçu du dernier message dans la liste, préfixé de « Vous : » quand c'est nous. C'est ce
 *      qui distingue « j'attends une réponse » de « on attend la mienne », sans ouvrir le fil.
 *   3. Les puces d'action au-dessus de la zone de saisie, plutôt qu'une barre d'outils en haut.
 *      Les gestes sont là où se trouve la main au moment où l'on décide de les poser.
 *   4. Ce qui n'en vient pas : la **note interne**. Vespera n'a pas d'agents ; nous si. Une note
 *      invisible du demandeur ne peut pas ressembler à un message ordinaire — elle est décalée,
 *      hachurée et étiquetée, parce que se tromper de case coûte une phrase qu'on ne rattrape pas.
 *
 * **La description du ticket EST le premier message, et n'est plus affichée à part.** Elle était
 * dans un cadre au-dessus du fil ; en messagerie, la première bulle est celle du demandeur. C'est la
 * même donnée, lue dans l'ordre où elle a été écrite.
 */

const STATUTS = {
  nouveau: { libelle: 'Nouveau', cls: 'warn' },
  en_cours: { libelle: 'En cours', cls: 'good' },
  en_attente_client: { libelle: 'En attente du demandeur', cls: 'mut' },
  resolu: { libelle: 'Résolu', cls: 'good' },
  ferme: { libelle: 'Fermé', cls: 'mut' },
}

const PRIORITES = {
  basse: { libelle: 'Basse', cls: 'mut' },
  normale: { libelle: 'Normale', cls: 'mut' },
  haute: { libelle: 'Haute', cls: 'warn' },
  critique: { libelle: 'Critique', cls: 'crit' },
}

const STATUTS_POSABLES = ['en_cours', 'en_attente_client', 'resolu', 'ferme']

function heure(v) {
  if (!v) return ''
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

/**
 * L'HORODATAGE D'UNE LISTE DE FILS N'EST PAS CELUI D'UN TABLEAU.
 *
 * Un tableau porte une colonne « dernière activité » qu'on lit en la comparant aux voisines : la
 * date complète y est utile. Dans une liste de conversations on lit UNE ligne, et « 14:22 » suffit
 * si c'est aujourd'hui. « 28/08/2026 14:22 » sur un message de ce matin fait croire à de l'ancien.
 */
function quandCourt(v) {
  if (!v) return ''
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return ''
  const maintenant = new Date()
  const memeJour = d.toDateString() === maintenant.toDateString()
  if (memeJour) return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  const hier = new Date(maintenant)
  hier.setDate(hier.getDate() - 1)
  if (d.toDateString() === hier.toDateString()) return 'hier'
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
}

function nomUtilisateur(u) {
  if (!u) return null
  if (typeof u === 'string') return null // IRI seule : on ne devine pas un nom à partir d'une URL.
  const complet = [u.prenom, u.nom].filter(Boolean).join(' ').trim()
  return complet || u.email || null
}

/** L'identifiant d'un utilisateur, qu'il arrive en objet ou en IRI (`/api/utilisateurs/<uuid>`). */
function idUtilisateur(u) {
  if (!u) return null
  if (typeof u === 'string') return u.split('/').filter(Boolean).pop() || null
  return u.id ? String(u.id) : null
}

/**
 * ⚠ LES INITIALES SE PRENNENT SUR LE NOM AFFICHÉ, PAS SUR L'OBJET.
 *
 * La première version lisait l'utilisateur interlocuteur — qui est `null` tant qu'aucun agent n'a
 * pris la demande en charge, c'est-à-dire pour toute demande nouvelle. Chaque conversation
 * s'ouvrait donc sur un avatar « ? » à côté du mot « Assistance ». Deux éléments côte à côte
 * disant deux choses différentes de la même personne : l'un savait, l'autre pas.
 */
function initiales(nom) {
  if (!nom) return '?'
  const parts = String(nom).replace(/@.*/, '').split(/[\s.]+/).filter(Boolean)
  return ((parts[0]?.[0] || '?') + (parts[1]?.[0] || '')).toUpperCase()
}

/**
 * L'INTERLOCUTEUR, C'EST-À-DIRE « L'AUTRE ».
 *
 * Vespera affiche le nom du fan, parce qu'il n'y a qu'un point de vue. Ici il y en a deux : le
 * demandeur voit l'assistance, l'agent voit le demandeur. Afficher toujours le demandeur mettrait
 * son propre nom en tête de sa propre conversation — ce qui se lit comme une erreur d'affichage.
 */
function interlocuteur(ticket, monId) {
  const demandeur = ticket?.demandeur
  if (idUtilisateur(demandeur) === monId) {
    return { objet: ticket?.affecteA, nom: nomUtilisateur(ticket?.affecteA) || 'Assistance' }
  }
  return { objet: demandeur, nom: nomUtilisateur(demandeur) || 'Demandeur' }
}

export default function MessagerieAssistance({
  tickets,
  chargement,
  erreur,
  filtreStatut,
  onFiltrerStatut,
  filtrePriorite,
  onFiltrerPriorite,
  agent,
  peut,
  me,
  onRecharger,
  onOuvrirNouveau,
}) {
  const monId = me?.id ? String(me.id) : null
  const [choisi, setChoisi] = useState(null)

  // Le premier fil s'ouvre tout seul, et seulement s'il n'y en a pas déjà un d'ouvert. Une
  // messagerie qui s'ouvre sur un volet droit vide demande un clic pour ne rien apprendre.
  // ⚠ On revérifie que le fil choisi existe TOUJOURS dans la liste : un changement de filtre peut
  // l'en retirer, et le volet droit resterait alors sur une conversation que la liste ne montre
  // plus — un état que l'utilisateur ne peut ni comprendre ni annuler.
  useEffect(() => {
    if (chargement) return
    if (choisi && tickets.some((t) => t.id === choisi)) return
    setChoisi(tickets[0]?.id || null)
  }, [tickets, chargement, choisi])

  const ticketChoisi = useMemo(() => tickets.find((t) => t.id === choisi) || null, [tickets, choisi])

  return (
    <div className="msgr">
      <aside className="msgr-liste">
        <div className="msgr-liste-h">
          <select
            className="select sm"
            value={filtrePriorite}
            onChange={(e) => onFiltrerPriorite(e.target.value)}
          >
            <option value="">Toutes priorités</option>
            {Object.entries(PRIORITES).map(([cle, p]) => (
              <option key={cle} value={cle}>{p.libelle}</option>
            ))}
          </select>
          <select
            className="select sm"
            value={filtreStatut}
            onChange={(e) => onFiltrerStatut(e.target.value)}
          >
            <option value="">Tous les statuts</option>
            {Object.entries(STATUTS).map(([cle, s]) => (
              <option key={cle} value={cle}>{s.libelle}</option>
            ))}
          </select>
        </div>

        <div className="msgr-fils">
          {chargement ? (
            <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
          ) : tickets.length === 0 ? (
            // D54 : le fait sur la donnée d'abord. « Aucune demande » et « le filtre n'en laisse
            // aucune » ne demandent pas la même action de la part du lecteur.
            <div className="empty">
              {filtreStatut || filtrePriorite
                ? 'Aucune demande ne correspond à ces filtres. Les autres restent visibles en les retirant.'
                : 'Aucune conversation. Ouvrez une demande : un agent y répondra ici même.'}
            </div>
          ) : (
            tickets.map((t) => {
              const autre = interlocuteur(t, monId)
              const st = STATUTS[t.statut] || { libelle: t.statut, cls: 'mut' }
              return (
                <button
                  key={t.id}
                  type="button"
                  className={t.id === choisi ? 'msgr-fil on' : 'msgr-fil'}
                  onClick={() => setChoisi(t.id)}
                >
                  <span className="msgr-av">{initiales(autre.nom)}</span>
                  <span className="msgr-fil-txt">
                    <span className="msgr-fil-h">
                      <span className="msgr-fil-nom">{t.sujet || 'Sans sujet'}</span>
                      <span className="msgr-fil-t">{quandCourt(t.dateDerniereMaj || t.dateCreation)}</span>
                    </span>
                    <span className="msgr-fil-apercu">{autre.nom}</span>
                    <span className="msgr-fil-etats">
                      <span className={`badge ${st.cls}`}>{st.libelle}</span>
                      {t.priorite && t.priorite !== 'normale' && (
                        <span className={`badge ${PRIORITES[t.priorite]?.cls || 'mut'}`}>
                          {PRIORITES[t.priorite]?.libelle || t.priorite}
                        </span>
                      )}
                    </span>
                  </span>
                </button>
              )
            })
          )}
        </div>

        {peut('support.ouvrir_ticket') && (
          <div className="msgr-liste-p">
            <button className="btn primary sm" type="button" onClick={onOuvrirNouveau}>
              + Ouvrir une demande
            </button>
          </div>
        )}
      </aside>

      <section className="msgr-vue">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {!ticketChoisi ? (
          <div className="empty">
            Choisissez une conversation à gauche pour lire les échanges et répondre.
          </div>
        ) : (
          <FilDiscussion
            key={ticketChoisi.id}
            resume={ticketChoisi}
            agent={agent}
            peut={peut}
            monId={monId}
            onChange={onRecharger}
          />
        )}
      </section>
    </div>
  )
}

/**
 * Le fil ouvert : son en-tête, ses bulles, les gestes qu'on peut poser, et la zone de saisie.
 *
 * **Les actions absentes ne sont pas grisées, elles ne sont pas là.** Un agent N1 ne voit pas
 * « Réaffecter », qui appartient au N2 ; un demandeur ne voit ni l'un ni l'autre. C'est la règle du
 * dépôt — *une action sans objet est absente, jamais grisée* —, et l'exception admise (afficher avec
 * un motif) ne vaut que quand l'utilisateur a une raison de chercher l'action. Personne ne cherche
 * un bouton d'escalade qu'il n'a jamais eu.
 */
function FilDiscussion({ resume, agent, peut, monId, onChange }) {
  const id = resume.id
  const [ticket, setTicket] = useState(null)
  const [messages, setMessages] = useState([])
  const [reponse, setReponse] = useState('')
  const [note, setNote] = useState(false)
  const [motif, setMotif] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [agents, setAgents] = useState([])
  const [articles, setArticles] = useState([])
  const [nouvelAgent, setNouvelAgent] = useState('')
  const [article, setArticle] = useState('')
  const bas = useRef(null)

  const charger = useCallback(async () => {
    setErreur(null)
    try {
      const [t, m] = await Promise.all([api.supportTicket(id), api.messagesTicket(id).catch(() => null)])
      setTicket(t)
      setMessages(m ? membres(m) : [])
    } catch (e) {
      setErreur(e.message || 'La conversation n’a pas pu être relue.')
    }
  }, [id])

  useEffect(() => {
    charger()
  }, [charger])

  // LE FIL SE POSITIONNE SUR LE DERNIER MESSAGE, JAMAIS SUR LE PREMIER.
  //
  // Une conversation se lit par la fin : ce qu'on vient d'ouvrir, c'est « qu'est-ce qu'on m'a
  // répondu ». Sans ce défilement, un fil de vingt messages s'ouvre sur la demande initiale, et la
  // réponse qu'on attendait est hors de l'écran.
  useEffect(() => {
    bas.current?.scrollIntoView({ block: 'end' })
  }, [messages.length, ticket?.id])

  // LES DEUX LISTES QUI RENDENT LES ACTIONS POSSIBLES.
  //
  // Elles échouent en silence : réaffecter et rattacher un article sont des gestes de confort. Si
  // l'une des listes ne charge pas, le reste du fil — répondre, fermer, escalader — doit continuer
  // à fonctionner. Un écran qui refuse de s'ouvrir parce qu'un menu déroulant secondaire n'a pas
  // répondu punit l'utilisateur pour une panne qui ne le concerne pas.
  useEffect(() => {
    let annule = false
    api.utilisateurs()
      .then((r) => { if (!annule) setAgents(membres(r)) })
      .catch(() => {})
    api.articlesAide()
      .then((r) => { if (!annule) setArticles(membres(r)) })
      .catch(() => {})
    return () => { annule = true }
  }, [id])

  async function agir(action) {
    setBusy(true)
    setErreur(null)
    try {
      await action()
      await charger()
      onChange?.()
    } catch (e) {
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  if (!ticket) {
    return <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
  }

  const statut = ticket.statut
  const autre = interlocuteur(ticket, monId)
  const ferme = statut === 'ferme'

  // La description du ticket est le premier message du fil : même auteur, même horodatage, et
  // c'est bien ce que le demandeur a écrit en premier.
  const bulles = [
    {
      cle: 'ouverture',
      auteur: ticket.demandeur,
      contenu: ticket.description,
      date: ticket.dateCreation,
      noteInterne: false,
    },
    ...messages.map((m) => ({
      cle: m.id,
      auteur: m.auteur,
      contenu: m.contenu,
      date: m.dateCreation,
      noteInterne: !!m.noteInterne,
    })),
  ]

  return (
    <>
      <header className="msgr-vue-h">
        <span className="msgr-av">{initiales(autre.nom)}</span>
        <div className="msgr-vue-t">
          <h3>{ticket.sujet || 'Sans sujet'}</h3>
          <div className="sub">
            {autre.nom}
            {ticket.moduleConcerne ? ` · ${ticket.moduleConcerne}` : ''}
            {ticket.niveauAffectation ? ` · ${ticket.niveauAffectation}` : ''}
          </div>
        </div>
        <span className={`badge ${STATUTS[statut]?.cls || 'mut'}`}>{STATUTS[statut]?.libelle || statut}</span>
        <span className={`badge ${PRIORITES[ticket.priorite]?.cls || 'mut'}`}>
          {PRIORITES[ticket.priorite]?.libelle || ticket.priorite}
        </span>
      </header>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="msgr-bulles">
        {bulles.map((b) => {
          const mien = idUtilisateur(b.auteur) === monId
          const classes = ['msgr-b']
          if (mien) classes.push('moi')
          if (b.noteInterne) classes.push('note')
          return (
            <div key={b.cle} className={classes.join(' ')}>
              {/* Le nom de l'auteur n'est écrit que sur les bulles des AUTRES. Sur les siennes, il
                  est déjà connu, et le répéter à chaque bulle encombre la lecture. */}
              {!mien && <div className="msgr-b-a">{nomUtilisateur(b.auteur) || 'Auteur inconnu'}</div>}
              {b.noteInterne && <div className="msgr-b-n">Note interne — invisible du demandeur</div>}
              <div className="msgr-b-c">{b.contenu}</div>
              <div className="msgr-b-t">{heure(b.date)}</div>
            </div>
          )
        })}
        {ticket.motifFermeture && (
          <div className="msgr-cloture">
            Conversation fermée — {ticket.motifFermeture}
          </div>
        )}
        <div ref={bas} />
      </div>

      {/* LES PUCES D'ACTION, ENTRE LE FIL ET LA SAISIE.
          C'est l'emplacement de Vespera (« Proposer un créneau visio »), et il est juste : on décide
          d'un geste APRÈS avoir lu, au moment où la main est déjà sur la zone de réponse. */}
      <div className="msgr-puces">
        {agent && !ticket.affecteA && !ferme && (
          <button className="btn sm" type="button" disabled={busy} onClick={() => agir(() => api.prendreEnChargeTicket(ticket.id))}>
            Prendre en charge
          </button>
        )}

        {agent && !ferme && (
          <select
            className="select sm"
            value=""
            disabled={busy}
            onChange={(e) => {
              const cible = e.target.value
              if (!cible) return
              // Une fermeture sans motif ne se refuse pas en silence : on demande, et si la demande
              // reste vide on n'envoie rien plutôt que de fermer sans raison.
              if (cible === 'ferme' && motif.trim() === '') {
                setErreur('Indiquez le motif de fermeture avant de fermer la conversation.')
                return
              }
              agir(() => api.changerStatutTicket(ticket.id, cible, cible === 'ferme' ? motif.trim() : null))
            }}
          >
            <option value="">Changer le statut…</option>
            {STATUTS_POSABLES.filter((s) => s !== statut).map((s) => (
              <option key={s} value={s}>{STATUTS[s].libelle}</option>
            ))}
          </select>
        )}

        {agent && !ferme && (
          <input
            className="input sm"
            placeholder="Motif de fermeture"
            value={motif}
            onChange={(e) => setMotif(e.target.value)}
          />
        )}

        {peut('support.traiter_ticket_n1') && ticket.niveauAffectation !== 'N2' && !ferme && (
          <button className="btn sm" type="button" disabled={busy} onClick={() => agir(() => api.escaladerTicket(ticket.id))}>
            Escalader en N2
          </button>
        )}

        {/* RÉAFFECTER — le geste qui manquait quand la personne en charge n'est pas là.
            Escalader change de NIVEAU ; réaffecter change de PERSONNE. Sans lui, une demande
            affectée à quelqu'un en congé n'avait qu'une sortie : l'escalade, qui ment sur la
            raison. Un mauvais motif dans un historique vaut une statistique fausse. */}
        {peut('support.traiter_ticket_n2') && !ferme && agents.length > 0 && (
          <>
            <select
              className="select sm"
              value={nouvelAgent}
              onChange={(e) => setNouvelAgent(e.target.value)}
            >
              <option value="">Réaffecter à…</option>
              {agents.map((u) => (
                <option key={u.id} value={`/api/utilisateurs/${u.id}`}>{u.nom || u.email}</option>
              ))}
            </select>
            <button
              className="btn sm"
              type="button"
              disabled={busy || nouvelAgent === ''}
              onClick={() => agir(async () => {
                await api.reaffecterTicket(ticket.id, nouvelAgent)
                setNouvelAgent('')
              })}
            >
              Réaffecter
            </button>
          </>
        )}

        {/* RATTACHER L'ARTICLE QUI RÉPOND — c'est ce qui ferme la boucle entre les deux moitiés du
            module. Une réponse écrite trois fois dans trois conversations est un article qui
            manque ; un article qu'aucune conversation ne cite est un article que personne n'a
            trouvé utile. */}
        {agent && !ferme && articles.length > 0 && (
          <>
            <select
              className="select sm"
              value={article}
              onChange={(e) => setArticle(e.target.value)}
            >
              <option value="">Article de réponse…</option>
              {articles.map((a) => (
                <option key={a.id} value={a.id}>{a.titre}</option>
              ))}
            </select>
            <button
              className="btn sm"
              type="button"
              disabled={busy || article === ''}
              onClick={() => agir(async () => {
                await api.lierArticleTicket(ticket.id, article)
                setArticle('')
              })}
            >
              Rattacher
            </button>
          </>
        )}

        {ferme && (
          <button className="btn sm" type="button" disabled={busy} onClick={() => agir(() => api.rouvrirTicket(ticket.id))}>
            Rouvrir la conversation
          </button>
        )}
      </div>

      {ferme ? (
        // On ne montre pas une zone de saisie désactivée : elle laisserait croire qu'on peut écrire
        // et qu'on s'y prend mal. On dit ce qui rendrait l'écriture possible.
        <div className="msgr-saisie-close">
          Cette conversation est fermée. La rouvrir permet d’y écrire à nouveau.
        </div>
      ) : (
        <div className="msgr-saisie">
          <textarea
            className="input"
            rows={2}
            placeholder={note ? 'Note interne, invisible du demandeur…' : 'Votre réponse…'}
            value={reponse}
            onChange={(e) => setReponse(e.target.value)}
            onKeyDown={(e) => {
              // Entrée seule insère un retour à la ligne : une demande d'assistance contient des
              // messages d'erreur collés, et les envoyer ligne par ligne serait illisible pour
              // l'agent. Ctrl/⌘+Entrée envoie, comme partout ailleurs.
              if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && reponse.trim() !== '' && !busy) {
                e.preventDefault()
                agir(async () => {
                  await api.repondreTicket(ticket.id, reponse.trim(), note)
                  setReponse('')
                  setNote(false)
                })
              }
            }}
          />
          <div className="msgr-saisie-p">
            {agent && (
              <label className="msgr-note-b">
                <input type="checkbox" checked={note} onChange={(e) => setNote(e.target.checked)} />
                Note interne
              </label>
            )}
            <button
              className="btn primary sm"
              type="button"
              disabled={busy || reponse.trim() === ''}
              onClick={() =>
                agir(async () => {
                  await api.repondreTicket(ticket.id, reponse.trim(), note)
                  setReponse('')
                  setNote(false)
                })
              }
            >
              Envoyer
            </button>
          </div>
        </div>
      )}
    </>
  )
}
