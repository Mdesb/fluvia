import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'

/**
 * PROJETS — le travail interne qui a une fin, un responsable et des tâches.
 *
 * **Ce que cet écran n'est pas.** Ce n'est pas l'assistance : un ticket arrive de l'extérieur et se
 * ferme quand on a répondu. Ce n'est pas la planification machine. C'est refaire les vestiaires,
 * ouvrir la patinoire éphémère, préparer la saison — un travail humain, décidé en interne.
 *
 * **Deux chiffres sont calculés, aucun n'est stocké.** L'avancement se compte depuis les tâches ; le
 * retard se déduit de l'échéance. Les stocker demanderait de les rafraîchir partout, donc de les
 * oublier quelque part — et un pourcentage faux est pire qu'absent, parce qu'il rassure.
 *
 * **Le retard d'une TÂCHE et celui d'un PROJET sont montrés séparément.** Un projet dont l'échéance
 * est loin peut contenir trois tâches en retard : c'est exactement ce qu'un responsable doit voir
 * **avant** que le projet, lui, ne dérape.
 *
 * > **Un projet ne dérape pas le jour de son échéance : il dérape trois semaines avant, une tâche à
 * > la fois.**
 */

const STATUTS = [
  ['planned', 'À venir'],
  ['active', 'En cours'],
  ['on_hold', 'En pause'],
  ['done', 'Terminé'],
  ['cancelled', 'Abandonné'],
]

const TON_STATUT = { planned: 'mut', active: 'good', on_hold: 'warn', done: 'mut', cancelled: 'mut' }

const COLONNES_TACHE = [
  ['todo', 'À faire'],
  ['doing', 'En cours'],
  ['done', 'Faites'],
]

function jour(v) {
  if (!v) return null
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? null : d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })
}

export default function Projets({ etabActif, droits = [] }) {
  const peutGerer = aLeDroit(droits, 'personnel.gerer') || aLeDroit(droits, 'organisation.gerer')

  const [tableau, setTableau] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [nouveau, setNouveau] = useState(false)
  const [ouvert, setOuvert] = useState(null)
  const [busy, setBusy] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setTableau(await api.tableauProjets())
    } catch (e) {
      setErreur(e.message || 'Les projets n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  async function agir(action) {
    setBusy(true)
    setErreur(null)
    try {
      await action()
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  if (chargement) return <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>

  const projets = tableau?.projets || []
  const ouverts = projets.filter((p) => p.statut !== 'done' && p.statut !== 'cancelled')
  const clos = projets.filter((p) => p.statut === 'done' || p.statut === 'cancelled')

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>Projets</h1>
          <div className="sub">{ouverts.length} en cours · {clos.length} clos</div>
        </div>
        {peutGerer && (
          <button className="btn primary" type="button" onClick={() => setNouveau(true)}>+ Nouveau projet</button>
        )}
      </div>

      {erreur && <div className="alert crit">{erreur}</div>}

      {projets.length === 0 ? (
        <div className="panel">
          <div className="sub" style={{ textAlign: 'center', padding: 28 }}>
            Aucun projet. Un projet, c&rsquo;est un travail interne qui a une fin — pas une demande
            d&rsquo;assistance, pas une tâche automatique.
          </div>
        </div>
      ) : (
        <div style={{ display: 'grid', gap: 12 }}>
          {[...ouverts, ...clos].map((p) => (
            <section className="panel" key={p.id}>
              <div className="panel-h" style={{ gap: 8, flexWrap: 'wrap' }}>
                <span>{p.nom}</span>
                <span className={`badge ${TON_STATUT[p.statut] || 'mut'}`}>{p.statutLibelle}</span>
                {p.enRetard && <span className="badge crit">en retard</span>}
                {p.tachesEnRetard > 0 && !p.enRetard && (
                  // Le signal AVANT le signal : trois taches en retard sur un projet encore a l'heure,
                  // c'est ce qu'on veut voir pendant qu'on peut encore agir.
                  <span className="badge warn">
                    {p.tachesEnRetard} tâche{p.tachesEnRetard > 1 ? 's' : ''} en retard
                  </span>
                )}
                <span className="sub" style={{ marginLeft: 'auto' }}>
                  {p.responsable || 'sans responsable'}
                  {jour(p.echeance) ? ` · échéance ${jour(p.echeance)}` : ''}
                </span>
                <button className="btn ghost sm" type="button" onClick={() => setOuvert(p)}>Ouvrir</button>
              </div>

              <div style={{ padding: '10px 14px 14px' }}>
                {p.nbTaches === 0 ? (
                  // `avancement` vaut `null` et non zero quand il n'y a aucune tache : zero dirait
                  // << rien n'est fait >> la ou la verite est << rien n'est prevu >>.
                  <div className="sub">Aucune tâche : rien n&rsquo;est encore prévu.</div>
                ) : (
                  <>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 4 }}>
                      <span className="sub">{p.nbFaites} / {p.nbTaches} faites</span>
                      <span className="sub" style={{ fontVariantNumeric: 'tabular-nums' }}>{p.avancement} %</span>
                    </div>
                    <div style={{ height: 6, borderRadius: 3, background: 'var(--panel-2)', overflow: 'hidden' }}>
                      <div
                        style={{
                          width: `${p.avancement}%`,
                          height: '100%',
                          background: p.enRetard ? 'var(--crit)' : 'var(--accent)',
                        }}
                      />
                    </div>
                  </>
                )}
              </div>
            </section>
          ))}
        </div>
      )}

      <NouveauProjet
        open={nouveau}
        busy={busy}
        onFermer={() => setNouveau(false)}
        onCreer={(corps) => agir(async () => { await api.creerProjet(corps); setNouveau(false) })}
      />

      <FicheProjet
        projet={ouvert}
        peutGerer={peutGerer}
        onFermer={() => setOuvert(null)}
        onChange={recharger}
      />
    </div>
  )
}

function NouveauProjet({ open, busy, onFermer, onCreer }) {
  const [nom, setNom] = useState('')
  const [echeance, setEcheance] = useState('')
  const [description, setDescription] = useState('')

  useEffect(() => {
    if (!open) return
    setNom('')
    setEcheance('')
    setDescription('')
  }, [open])

  return (
    <Modal open={open} onClose={onFermer} titre="Nouveau projet" taille="md">
      <div style={{ display: 'grid', gap: 12 }}>
        <div>
          <label htmlFor="pj-nom">Nom *</label>
          <input
            id="pj-nom"
            className="input"
            value={nom}
            onChange={(e) => setNom(e.target.value)}
            placeholder="Ex. Réfection des vestiaires"
          />
        </div>
        <div>
          <label htmlFor="pj-ech">Échéance</label>
          <input id="pj-ech" className="input" type="date" value={echeance} onChange={(e) => setEcheance(e.target.value)} />
          <div className="hint" style={{ margin: '2px 0 0' }}>
            Sans échéance, aucun retard ne peut être signalé.
          </div>
        </div>
        <div>
          <label htmlFor="pj-desc">Description</label>
          <textarea id="pj-desc" className="input" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button
            className="btn primary"
            type="button"
            disabled={busy || nom.trim() === ''}
            onClick={() =>
              onCreer({
                name: nom.trim(),
                status: 'active',
                ...(echeance ? { dueDate: echeance } : {}),
                ...(description.trim() ? { description: description.trim() } : {}),
              })
            }
          >
            Créer
          </button>
        </div>
      </div>
    </Modal>
  )
}

function FicheProjet({ projet, peutGerer, onFermer, onChange }) {
  const [taches, setTaches] = useState([])
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [titre, setTitre] = useState('')
  const [busy, setBusy] = useState(false)

  const recharger = useCallback(async () => {
    if (!projet?.id) return
    setChargement(true)
    try {
      // Chargees entieres puis filtrees : le `SearchFilter` sur `project` est de la famille D58, ou
      // le filtre rend soit tout soit rien, sans jamais lever.
      const toutes = membres(await api.tachesProjet())
      setTaches(toutes.filter((t) => {
        const ref = t.project
        const id = typeof ref === 'string' ? ref.split('/').pop() : ref?.id
        return String(id) === String(projet.id)
      }))
    } catch (e) {
      setErreur(e.message || 'Les tâches n’ont pas pu être chargées.')
    } finally {
      setChargement(false)
    }
  }, [projet?.id])

  useEffect(() => {
    setErreur(null)
    setTitre('')
    recharger()
  }, [recharger])

  async function agir(action) {
    setBusy(true)
    setErreur(null)
    try {
      await action()
      await recharger()
      onChange?.()
    } catch (e) {
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  const aujourdhui = new Date().toISOString().slice(0, 10)

  return (
    <Modal open={!!projet} onClose={onFermer} titre={projet?.nom || 'Projet'} taille="lg">
      {erreur && <div className="alert crit">{erreur}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : (
        <div style={{ display: 'grid', gap: 14 }}>
          {peutGerer && (
            <div style={{ display: 'flex', gap: 8 }}>
              <input
                className="input"
                value={titre}
                onChange={(e) => setTitre(e.target.value)}
                placeholder="Ajouter une tâche…"
                onKeyDown={(e) => {
                  if (e.key !== 'Enter' || titre.trim() === '') return
                  agir(async () => {
                    await api.creerTacheProjet({
                      project: `/api/projects/${projet.id}`,
                      title: titre.trim(),
                      status: 'todo',
                    })
                    setTitre('')
                  })
                }}
              />
              <button
                className="btn primary"
                type="button"
                disabled={busy || titre.trim() === ''}
                onClick={() =>
                  agir(async () => {
                    await api.creerTacheProjet({
                      project: `/api/projects/${projet.id}`,
                      title: titre.trim(),
                      status: 'todo',
                    })
                    setTitre('')
                  })
                }
              >
                Ajouter
              </button>
            </div>
          )}

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 10 }}>
            {COLONNES_TACHE.map(([cle, libelle]) => {
              const dedans = taches.filter((t) => t.status === cle)
              return (
                <section className="panel" key={cle}>
                  <div className="panel-h" style={{ flexDirection: 'column', alignItems: 'flex-start', gap: 2 }}>
                    <span>{libelle}</span>
                    <span className="sub">{dedans.length}</span>
                  </div>
                  <div style={{ display: 'grid', gap: 6, padding: 8 }}>
                    {dedans.length === 0 ? (
                      <div className="sub" style={{ textAlign: 'center', fontSize: 12.5, padding: 6 }}>—</div>
                    ) : (
                      dedans.map((t) => {
                        const enRetard = t.status !== 'done' && t.dueDate && t.dueDate < aujourdhui
                        return (
                          <article
                            key={t.id}
                            className="panel"
                            style={{ padding: 8, border: '1px solid var(--line)', display: 'grid', gap: 3 }}
                          >
                            <span style={{ fontSize: 13 }}>{t.title}</span>
                            <div className="sub" style={{ fontSize: 11.5, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                              {/* Une tache sans responsable se VOIT : c'est celle que personne n'a prise. */}
                              <span>{t.assignee?.nom || 'non assignée'}</span>
                              {enRetard && <span className="badge crit">en retard</span>}
                              {t.dueDate && !enRetard && <span>{jour(t.dueDate)}</span>}
                            </div>
                            {peutGerer && (
                              <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                                {COLONNES_TACHE.filter(([c2]) => c2 !== cle).map(([c2, l2]) => (
                                  <button
                                    key={c2}
                                    className="btn ghost sm"
                                    type="button"
                                    disabled={busy}
                                    style={{ padding: '0 6px', fontSize: 11 }}
                                    onClick={() => agir(() => api.majTacheProjet(t.id, { status: c2 }))}
                                  >
                                    → {l2}
                                  </button>
                                ))}
                                <button
                                  className="btn ghost sm"
                                  type="button"
                                  disabled={busy}
                                  style={{ padding: '0 6px', fontSize: 11 }}
                                  onClick={() => agir(() => api.supprimerTacheProjet(t.id))}
                                >
                                  ×
                                </button>
                              </div>
                            )}
                          </article>
                        )
                      })
                    )}
                  </div>
                </section>
              )
            })}
          </div>

          {peutGerer && (
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', borderTop: '1px solid var(--line)', paddingTop: 12 }}>
              <span className="sub">État du projet :</span>
              <select
                className="select sm"
                style={{ width: 180 }}
                value={projet?.statut || 'active'}
                disabled={busy}
                onChange={(e) => agir(() => api.majProjet(projet.id, { status: e.target.value }))}
              >
                {STATUTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
