import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'

/**
 * ASSISTANCE — l'écran qui manquait à un module entièrement construit.
 *
 * **Pourquoi il n'existait pas.** `App\Support` expose onze opérations de tickets et sept d'articles
 * d'aide : ouverture, prise en charge, changement de statut, escalade N1→N2, réaffectation, notes
 * internes, base de connaissances versionnée et publiable. Rien n'y menait. L'entrée de menu était
 * écrite depuis le début, avec `absent: true` — la porte était dessinée et condamnée.
 *
 * Maxime, le 27/08 : *« il manque la partie support dans les écrans »*. Il ne demandait pas une
 * fonctionnalité ; il constatait qu'une fonctionnalité livrée était inatteignable. `CARTE-MODULES.md`
 * compte **815 opérations sans porte pour 234 atteignables** : ce qui manque à ce produit n'est
 * presque jamais la règle métier, c'est le chemin qui y mène.
 *
 * **Deux publics, un seul écran, et c'est délibéré.** Le demandeur ouvre un ticket et suit sa
 * réponse ; l'agent traite une file. Les séparer en deux écrans obligerait à choisir *avant* de
 * savoir : un responsable de site est les deux dans la même journée. Ce sont donc les **droits** qui
 * décident de ce qui s'affiche, jamais un onglet à choisir soi-même.
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

// Les statuts qu'un agent peut poser lui-même. `ferme` en fait partie et demande un motif : une
// fermeture sans raison écrite est une question à laquelle personne ne pourra répondre six mois plus
// tard, quand le même incident reviendra.
const STATUTS_POSABLES = ['en_cours', 'en_attente_client', 'resolu', 'ferme']

function quand(v) {
  if (!v) return '—'
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function nomUtilisateur(u) {
  if (!u) return null
  if (typeof u === 'string') return null // IRI seule : on ne devine pas un nom à partir d'une URL.
  const complet = [u.prenom, u.nom].filter(Boolean).join(' ').trim()
  return complet || u.email || null
}

export default function Support({ droits = [] }) {
  // ⚠ `droits.includes(code)` NE VOIT PAS LE JOKER, et le garde-fou n°13 me l'a refuse a raison.
  //
  // Une permission peut arriver sous la forme `support.*` ou `*.lire` : une egalite stricte la
  // manque, sans lever, et l'ecran s'affiche simplement AMPUTE de ses actions. L'administrateur
  // conclut qu'il n'a pas le droit, alors qu'il a TOUS les droits. Aucun test ne rougit pour ca.
  const peut = useCallback(
    (code) => aLeDroit(droits, code) || aLeDroit(droits, 'support.administrer'),
    [droits],
  )
  const agent = peut('support.traiter_ticket_n1') || peut('support.traiter_ticket_n2')

  const [onglet, setOnglet] = useState('tickets')
  const [tickets, setTickets] = useState([])
  const [filtreStatut, setFiltreStatut] = useState('')
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [ouvert, setOuvert] = useState(null)
  const [nouveau, setNouveau] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setTickets(membres(await api.supportTickets(filtreStatut ? { statut: filtreStatut } : {})))
    } catch (e) {
      setErreur(e.message || 'Les tickets n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [filtreStatut])

  useEffect(() => {
    if (onglet === 'tickets') recharger()
  }, [onglet, recharger])

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>Assistance</h1>
          <div className="sub">Demandes d&rsquo;aide et base de connaissances</div>
        </div>
        {peut('support.ouvrir_ticket') && (
          <button className="btn primary" type="button" onClick={() => setNouveau(true)}>
            + Ouvrir une demande
          </button>
        )}
      </div>

      <Tabs
        onglets={[['tickets', 'Demandes'], ['articles', 'Base de connaissances']]}
        actif={onglet}
        onChange={setOnglet}
      />

      {erreur && <div className="alert crit">{erreur}</div>}

      {onglet === 'tickets' ? (
        <ListeTickets
          tickets={tickets}
          chargement={chargement}
          filtreStatut={filtreStatut}
          onFiltrer={setFiltreStatut}
          onOuvrir={setOuvert}
        />
      ) : (
        <BaseConnaissances />
      )}

      <FicheTicket
        id={ouvert}
        agent={agent}
        peut={peut}
        onFermer={() => setOuvert(null)}
        onChange={recharger}
      />

      <OuvrirDemande
        open={nouveau}
        onFermer={() => setNouveau(false)}
        onOuvert={(id) => {
          setNouveau(false)
          recharger()
          setOuvert(id)
        }}
      />
    </div>
  )
}

function ListeTickets({ tickets, chargement, filtreStatut, onFiltrer, onOuvrir }) {
  return (
    <div className="panel">
      <div className="panel-h">
        <span>Demandes</span>
        <select
          className="select sm"
          style={{ marginLeft: 'auto', width: 220 }}
          value={filtreStatut}
          onChange={(e) => onFiltrer(e.target.value)}
        >
          <option value="">Tous les statuts</option>
          {Object.entries(STATUTS).map(([cle, s]) => (
            <option key={cle} value={cle}>{s.libelle}</option>
          ))}
        </select>
      </div>

      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : tickets.length === 0 ? (
        // D54 : le fait sur la donnée d'abord. « Aucune demande » et « le filtre n'en laisse
        // aucune » ne demandent pas la même action de la part du lecteur.
        <div className="sub" style={{ textAlign: 'center', padding: 24 }}>
          {filtreStatut
            ? 'Aucune demande dans ce statut. Les autres restent visibles en retirant le filtre.'
            : 'Aucune demande ouverte.'}
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Sujet</th>
                <th>Module</th>
                <th>Priorité</th>
                <th>Statut</th>
                <th>Affecté à</th>
                <th className="num">Dernière activité</th>
              </tr>
            </thead>
            <tbody>
              {tickets.map((t) => (
                <tr key={t.id} style={{ cursor: 'pointer' }} onClick={() => onOuvrir(t.id)}>
                  <td><span className="nm">{t.sujet || '—'}</span></td>
                  <td>{t.moduleConcerne || '—'}</td>
                  <td>
                    <span className={`badge ${PRIORITES[t.priorite]?.cls || 'mut'}`}>
                      {PRIORITES[t.priorite]?.libelle || t.priorite}
                    </span>
                  </td>
                  <td>
                    <span className={`badge ${STATUTS[t.statut]?.cls || 'mut'}`}>
                      {STATUTS[t.statut]?.libelle || t.statut}
                    </span>
                    {t.niveauAffectation && (
                      <span className="badge mut" style={{ marginLeft: 6 }}>{t.niveauAffectation}</span>
                    )}
                  </td>
                  <td>{nomUtilisateur(t.affecteA) || <span className="sub">non affecté</span>}</td>
                  <td className="num">{quand(t.dateDerniereMaj)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

/**
 * La fiche d'une demande : son fil, et les gestes qu'on peut poser dessus.
 *
 * **Les actions absentes ne sont pas grisées, elles ne sont pas là.** Un agent N1 ne voit pas
 * « Réaffecter », qui appartient au N2 ; un demandeur ne voit ni l'un ni l'autre. C'est la règle du
 * dépôt — *une action sans objet est absente, jamais grisée* —, et l'exception admise (afficher avec
 * un motif) ne vaut que quand l'utilisateur a une raison de chercher l'action. Personne ne cherche
 * un bouton d'escalade qu'il n'a jamais eu.
 */
function FicheTicket({ id, agent, peut, onFermer, onChange }) {
  const [ticket, setTicket] = useState(null)
  const [messages, setMessages] = useState([])
  const [reponse, setReponse] = useState('')
  const [note, setNote] = useState(false)
  const [motif, setMotif] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(async () => {
    if (!id) return
    setErreur(null)
    try {
      const [t, m] = await Promise.all([api.supportTicket(id), api.messagesTicket(id).catch(() => null)])
      setTicket(t)
      setMessages(m ? membres(m) : [])
    } catch (e) {
      setErreur(e.message || 'La demande n’a pas pu être relue.')
    }
  }, [id])

  useEffect(() => {
    setTicket(null)
    setMessages([])
    setReponse('')
    setNote(false)
    setMotif('')
    charger()
  }, [charger])

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

  const statut = ticket?.statut

  return (
    <Modal open={!!id} onClose={onFermer} titre={ticket?.sujet || 'Demande'} taille="lg">
      {!ticket ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : (
        <div style={{ display: 'grid', gap: 16 }}>
          {erreur && <div className="alert crit">{erreur}</div>}

          <div className="fiche-stats">
            <div>
              <div className="st-lib">Statut</div>
              <div className="st-val">{STATUTS[statut]?.libelle || statut}</div>
            </div>
            <div>
              <div className="st-lib">Priorité</div>
              <div className="st-val">{PRIORITES[ticket.priorite]?.libelle || ticket.priorite}</div>
            </div>
            <div>
              <div className="st-lib">Module</div>
              <div className="st-val">{ticket.moduleConcerne || '—'}</div>
            </div>
            <div>
              <div className="st-lib">Affecté à</div>
              <div className="st-val">{nomUtilisateur(ticket.affecteA) || '—'}</div>
            </div>
          </div>

          <div className="panel" style={{ padding: 14 }}>
            <div className="sub" style={{ marginBottom: 6 }}>
              Ouvert le {quand(ticket.dateCreation)}
              {nomUtilisateur(ticket.demandeur) ? ` par ${nomUtilisateur(ticket.demandeur)}` : ''}
            </div>
            <div style={{ whiteSpace: 'pre-wrap' }}>{ticket.description}</div>
          </div>

          <div>
            <div className="st-lib" style={{ marginBottom: 8 }}>Échanges</div>
            {messages.length === 0 ? (
              <div className="sub">Aucun échange pour l&rsquo;instant.</div>
            ) : (
              <div style={{ display: 'grid', gap: 10 }}>
                {messages.map((m) => (
                  <div
                    key={m.id}
                    className="panel"
                    style={{
                      padding: 12,
                      // Une note interne ne se distingue pas par une étiquette qu'on peut manquer :
                      // elle change de fond. C'est un texte que le demandeur ne doit jamais voir, et
                      // l'agent doit le savoir sans lire.
                      borderLeft: m.noteInterne ? '3px solid var(--warn)' : '3px solid transparent',
                    }}
                  >
                    <div className="sub" style={{ marginBottom: 4 }}>
                      {nomUtilisateur(m.auteur) || 'Auteur inconnu'} · {quand(m.dateCreation)}
                      {m.noteInterne && <span className="badge warn" style={{ marginLeft: 8 }}>note interne</span>}
                    </div>
                    <div style={{ whiteSpace: 'pre-wrap' }}>{m.contenu}</div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {statut !== 'ferme' && (
            <div style={{ display: 'grid', gap: 8 }}>
              <textarea
                className="input"
                rows={3}
                placeholder="Votre réponse…"
                value={reponse}
                onChange={(e) => setReponse(e.target.value)}
              />
              <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                {agent && (
                  <label style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 13 }}>
                    <input type="checkbox" checked={note} onChange={(e) => setNote(e.target.checked)} />
                    Note interne (invisible du demandeur)
                  </label>
                )}
                <button
                  className="btn primary sm"
                  type="button"
                  style={{ marginLeft: 'auto' }}
                  disabled={busy || reponse.trim() === ''}
                  onClick={() =>
                    agir(async () => {
                      await api.repondreTicket(ticket.id, reponse.trim(), note)
                      setReponse('')
                      setNote(false)
                    })
                  }
                >
                  Répondre
                </button>
              </div>
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', borderTop: '1px solid var(--line)', paddingTop: 14 }}>
            {agent && !ticket.affecteA && (
              <button className="btn sm" type="button" disabled={busy} onClick={() => agir(() => api.prendreEnChargeTicket(ticket.id))}>
                Prendre en charge
              </button>
            )}

            {agent && statut !== 'ferme' && (
              <select
                className="select sm"
                style={{ width: 200 }}
                value=""
                disabled={busy}
                onChange={(e) => {
                  const cible = e.target.value
                  if (!cible) return
                  // Une fermeture sans motif ne se refuse pas en silence : on demande, et si la
                  // demande reste vide on n'envoie rien plutôt que de fermer sans raison.
                  if (cible === 'ferme' && motif.trim() === '') {
                    setErreur('Indiquez le motif de fermeture avant de fermer la demande.')
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

            {agent && statut !== 'ferme' && (
              <input
                className="input sm"
                style={{ width: 240 }}
                placeholder="Motif de fermeture"
                value={motif}
                onChange={(e) => setMotif(e.target.value)}
              />
            )}

            {peut('support.traiter_ticket_n1') && ticket.niveauAffectation !== 'N2' && statut !== 'ferme' && (
              <button className="btn sm" type="button" disabled={busy} onClick={() => agir(() => api.escaladerTicket(ticket.id))}>
                Escalader en N2
              </button>
            )}

            {statut === 'ferme' && (
              <button className="btn sm" type="button" disabled={busy} onClick={() => agir(() => api.rouvrirTicket(ticket.id))}>
                Rouvrir
              </button>
            )}
          </div>

          {ticket.motifFermeture && (
            <div className="sub">Fermée le {quand(ticket.dateFermeture)} — {ticket.motifFermeture}</div>
          )}
        </div>
      )}
    </Modal>
  )
}

function OuvrirDemande({ open, onFermer, onOuvert }) {
  const [sujet, setSujet] = useState('')
  const [description, setDescription] = useState('')
  const [priorite, setPriorite] = useState('normale')
  const [moduleConcerne, setModule] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!open) return
    setSujet('')
    setDescription('')
    setPriorite('normale')
    setModule('')
    setErreur(null)
  }, [open])

  async function envoyer(e) {
    e.preventDefault()
    setBusy(true)
    setErreur(null)
    try {
      const cree = await api.ouvrirTicket({
        sujet: sujet.trim(),
        description: description.trim(),
        priorite,
        ...(moduleConcerne.trim() ? { moduleConcerne: moduleConcerne.trim() } : {}),
      })
      onOuvert(cree.id)
    } catch (err) {
      setErreur(err.message || 'La demande n’a pas pu être ouverte.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={open} onClose={onFermer} titre="Ouvrir une demande" taille="md">
      <form onSubmit={envoyer} style={{ display: 'grid', gap: 12 }}>
        {erreur && <div className="alert crit">{erreur}</div>}
        <div>
          <label htmlFor="tk-sujet">Sujet *</label>
          <input id="tk-sujet" className="input" required value={sujet} onChange={(e) => setSujet(e.target.value)} />
        </div>
        <div>
          <label htmlFor="tk-desc">Description *</label>
          <textarea
            id="tk-desc"
            className="input"
            rows={5}
            required
            placeholder="Ce qui s’est passé, à quel endroit, et ce que vous attendiez."
            value={description}
            onChange={(e) => setDescription(e.target.value)}
          />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div>
            <label htmlFor="tk-prio">Priorité</label>
            <select id="tk-prio" className="select" value={priorite} onChange={(e) => setPriorite(e.target.value)}>
              {Object.entries(PRIORITES).map(([cle, p]) => (
                <option key={cle} value={cle}>{p.libelle}</option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="tk-mod">Écran concerné</label>
            <input
              id="tk-mod"
              className="input"
              placeholder="Ex. Caisse"
              value={moduleConcerne}
              onChange={(e) => setModule(e.target.value)}
            />
          </div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={busy || sujet.trim() === '' || description.trim() === ''}>
            Ouvrir la demande
          </button>
        </div>
      </form>
    </Modal>
  )
}

/**
 * La base de connaissances, lue par sa route **publique**.
 *
 * `/support/articles/publics` et `/support/articles/recherche` sont en `PUBLIC_ACCESS`, et rendent
 * déjà la version publiée — pas les brouillons. L'écran n'a donc aucun filtrage à refaire : il
 * afficherait sinon une seconde définition de « publié », qui divergerait au premier changement de
 * règle côté serveur.
 */
function BaseConnaissances() {
  const [articles, setArticles] = useState([])
  const [q, setQ] = useState('')
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [lu, setLu] = useState(null)

  useEffect(() => {
    let annule = false
    setChargement(true)
    setErreur(null)
    ;(async () => {
      try {
        const rep = q.trim().length >= 2 ? await api.rechercheArticles(q.trim()) : await api.articlesAide()
        if (!annule) setArticles(membres(rep))
      } catch (e) {
        if (!annule) setErreur(e.message || 'Les articles n’ont pas pu être chargés.')
      } finally {
        if (!annule) setChargement(false)
      }
    })()
    return () => {
      annule = true
    }
  }, [q])

  return (
    <div className="panel">
      <div className="panel-h">
        <span>Articles d&rsquo;aide</span>
        <input
          className="input sm"
          style={{ marginLeft: 'auto', width: 280 }}
          placeholder="Rechercher…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
      </div>

      {erreur && <div className="alert crit">{erreur}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : articles.length === 0 ? (
        <div className="sub" style={{ textAlign: 'center', padding: 24 }}>
          {q.trim().length >= 2
            ? `Aucun article ne correspond à « ${q.trim()} ».`
            : 'Aucun article publié pour l’instant.'}
        </div>
      ) : (
        <div style={{ display: 'grid', gap: 10, padding: 4 }}>
          {articles.map((a) => (
            <button
              key={a.id}
              type="button"
              className="panel"
              style={{ padding: 12, textAlign: 'left', cursor: 'pointer', background: 'none', border: '1px solid var(--line)' }}
              onClick={() => setLu(a)}
            >
              <span className="nm">{a.titre}</span>
              {a.resume && <div className="sub" style={{ marginTop: 4 }}>{a.resume}</div>}
            </button>
          ))}
        </div>
      )}

      <Modal open={!!lu} onClose={() => setLu(null)} titre={lu?.titre || ''} taille="lg">
        {lu && <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.6 }}>{lu.contenu}</div>}
      </Modal>
    </div>
  )
}
