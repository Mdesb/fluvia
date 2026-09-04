import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri'

/**
 * LES HORAIRES D'UNE RESSOURCE, ET SES ABSENCES.
 *
 * **Ce que ça débloque, et qui n'était pas évident.** Maxime demandait *« les verticales salon de
 * massage, salon de coiffure »*. La tentation était d'écrire un module. Elle aurait été fausse : le
 * module `Reservation` modélise déjà tout ce dont vit un salon —
 *
 * - `Activite` porte une **durée en minutes** : c'est une prestation ;
 * - `Ressource` a une **capacité propre** : à 1, c'est un praticien ;
 * - `DisponibiliteRessource` porte les **horaires hebdomadaires** ;
 * - `IndisponibiliteRessource` les **absences** ;
 * - et il existe déjà des règles d'annulation et une **facturation des non-présentations**, qui est
 *   précisément ce qui fait vivre un salon.
 *
 * Ce qui manquait n'était donc pas le métier, c'était **l'écran** : le front ne mentionnait
 * `disponibilite` nulle part. Un coiffeur ne pouvait pas déclarer qu'il travaille le mardi.
 *
 * > **Avant d'écrire un module pour un métier, il faut regarder si le métier n'est pas déjà écrit
 * > sous un autre nom.**
 *
 * **Le vocabulaire reste celui du dépôt — ressource, activité.** Le traduire ici (« praticien »,
 * « prestation ») créerait deux noms pour la même chose selon l'écran, et c'est ainsi qu'on finit par
 * ne plus savoir lequel désigne quoi. La traduction, si elle vient, se fera dans `vocabulaire.js`,
 * pour tous les écrans à la fois.
 *
 * ⚠ **Les collections sont chargées entières, pas filtrées, et c'est délibéré.** Cet écran montre
 * TOUTES les ressources côte à côte : il lui faut la collection entière de toute façon, et filtrer
 * par ressource ferait une requête par ressource pour reconstituer ce qu'une seule rend déjà. Le
 * regroupement se fait donc ici, où il est visible.
 *
 * La justification d'origine invoquait la famille D58 — un `SearchFilter` sur identifiant Uuid qui
 * rendait soit tout, soit rien, sans jamais lever. Ce défaut a été réparé le 29/08 par un
 * décorateur de plateforme, et trois écrans ont perdu leur tri local à cette occasion. Celui-ci
 * l'a gardé : la raison a changé, pas la décision. C'est la différence entre un contournement,
 * qu'on retire quand le défaut disparaît, et un choix de chargement, qui tient tout seul.
 */

const JOURS = [
  [1, 'Lundi'],
  [2, 'Mardi'],
  [3, 'Mercredi'],
  [4, 'Jeudi'],
  [5, 'Vendredi'],
  [6, 'Samedi'],
  [7, 'Dimanche'],
]

function heure(v) {
  if (!v) return '—'
  // Le serveur rend un `time_immutable` : selon la sérialisation, une heure seule ou une date
  // complète. On lit les deux plutôt que de parier sur l'une.
  const s = String(v)
  const m = /(\d{2}):(\d{2})/.exec(s)
  return m ? `${m[1]}:${m[2]}` : '—'
}

function jourEtHeure(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

export default function Disponibilites({ droits = [] }) {
  const peutGerer = aLeDroit(droits, 'reservation.gerer_ressource')

  const [ressources, setRessources] = useState([])
  const [dispos, setDispos] = useState([])
  const [indispos, setIndispos] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)
  const [ouverte, setOuverte] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [r, d, i] = await Promise.all([
        api.reservationRessources(),
        api.reservationDisponibilites(),
        api.reservationIndisponibilites(),
      ])
      setRessources(membres(r))
      setDispos(membres(d))
      setIndispos(membres(i))
    } catch (e) {
      setErreur(e.message || 'Les disponibilités n’ont pas pu être chargées.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger])

  const parRessource = useMemo(() => {
    const m = {}
    for (const d of dispos) {
      const rid = idDe(d.ressource)
      if (!rid) continue
      ;(m[rid] ||= []).push(d)
    }
    return m
  }, [dispos])

  const absencesParRessource = useMemo(() => {
    const m = {}
    for (const i of indispos) {
      const rid = idDe(i.ressource)
      if (!rid) continue
      ;(m[rid] ||= []).push(i)
    }
    return m
  }, [indispos])

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

  if (chargement) return <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>

  return (
    <div style={{ display: 'grid', gap: 14 }}>
      {erreur && <div className="banner banner-error">{erreur}</div>}

      {ressources.length === 0 ? (
        <div className="card">
          <div className="empty">
            Aucune ressource. Un praticien, un terrain ou une salle se déclare comme ressource avant de
            pouvoir recevoir des horaires.
          </div>
        </div>
      ) : (
        ressources.map((r) => {
          const journees = parRessource[r.id] || []
          const absences = (absencesParRessource[r.id] || [])
            .filter((a) => !a.fin || new Date(a.fin) >= new Date())
            .sort((a, b) => new Date(a.debut) - new Date(b.debut))

          return (
            <section className="card" key={r.id}>
              <div className="card-h">
                <span>{r.libelle || r.codeType || 'Ressource'}</span>
                {/* LA JAUGE SE REGLE ICI, ET ELLE N'ETAIT REGLABLE NULLE PART.
                    `capacitePropre` distingue deja un terrain de padel (4) d'un court de tennis en
                    simple (2) et d'un bassin (cinquante). Le modele savait ; l'ecran ne montrait que
                    le resultat. */}
                <JaugeEditable
                  ressource={r}
                  peutGerer={peutGerer}
                  busy={busy}
                  onEnregistrer={(capacite) =>
                    agir(() => api.majRessourceReservation(r.id, { capacitePropre: capacite }))
                  }
                />
                {journees.length === 0 && (
                  <span className="badge warn" style={{ marginLeft: 8 }}>aucun horaire</span>
                )}
                {(absences.length > 0 || peutGerer) && (
                  <span className="badge mut" style={{ marginLeft: 8 }}>
                    {absences.length} absence{absences.length > 1 ? 's' : ''}
                  </span>
                )}
              </div>

              <div style={{ display: 'grid', gap: 14, padding: 14 }}>
                <div>
                  <div className="st-lib">Semaine type</div>
                  {journees.length === 0 ? (
                    // D54 : le fait sur la donnée, et sa conséquence. « Aucun horaire » sans dire ce
                    // que ça implique laisse croire à un réglage facultatif.
                    <div className="sub" style={{ marginTop: 4 }}>
                      Aucun horaire déclaré : rien ne peut être réservé sur cette ressource.
                    </div>
                  ) : (
                    <div style={{ overflowX: 'auto', marginTop: 6 }}>
                      <table className="tbl">
                        <thead>
                          <tr>
                            <th>Jour</th>
                            <th className="num">Ouverture</th>
                            <th className="num">Fermeture</th>
                            {peutGerer && <th />}
                          </tr>
                        </thead>
                        <tbody>
                          {[...journees]
                            .sort((a, b) => (a.jourSemaine - b.jourSemaine) || String(a.heureDebut).localeCompare(String(b.heureDebut)))
                            .map((d) => (
                              <tr key={d.id}>
                                <td>{JOURS.find(([n]) => n === d.jourSemaine)?.[1] || d.jourSemaine}</td>
                                <td className="num">{heure(d.heureDebut)}</td>
                                <td className="num">{heure(d.heureFin)}</td>
                                {peutGerer && (
                                  <td>
                                    <button
                                      className="btn ghost sm"
                                      type="button"
                                      disabled={busy}
                                      onClick={() => agir(() => api.supprimerDisponibilite(d.id))}
                                    >
                                      Retirer
                                    </button>
                                  </td>
                                )}
                              </tr>
                            ))}
                        </tbody>
                      </table>
                    </div>
                  )}

                  {peutGerer && (
                    <AjoutJournee
                      ouverte={ouverte === r.id}
                      onOuvrir={() => setOuverte(ouverte === r.id ? null : r.id)}
                      busy={busy}
                      onAjouter={(corps) =>
                        agir(async () => {
                          await api.creerDisponibilite({ ...corps, ressource: `/api/reservation_ressources/${r.id}` })
                          setOuverte(null)
                        })
                      }
                    />
                  )}
                </div>

                {absences.length > 0 && (
                  <div>
                    <div className="st-lib">Absences à venir</div>
                    {absences.length === 0 && (
                      // ON ÉCRIT LE VIDE PLUTÔT QUE DE MASQUER LE BLOC.
                      //
                      // Tant que le bloc disparaissait faute d'absence, il n'y avait aucun endroit
                      // où en déclarer une : le seul moyen d'obtenir le formulaire aurait été d'avoir
                      // déjà ce qu'il sert à créer.
                      <div className="sub" style={{ marginTop: 4 }}>
                        Aucune absence déclarée : la ressource est disponible sur toutes ses journées
                        d’ouverture.
                      </div>
                    )}
                    <div style={{ overflowX: 'auto', marginTop: 6, display: absences.length === 0 ? 'none' : undefined }}>
                      <table className="tbl">
                        <thead>
                          <tr>
                            <th className="num">Du</th>
                            <th className="num">Au</th>
                            <th>Motif</th>
                            {peutGerer && <th />}
                          </tr>
                        </thead>
                        <tbody>
                          {absences.map((a) => (
                            <tr key={a.id}>
                              <td className="num">{jourEtHeure(a.debut)}</td>
                              <td className="num">{jourEtHeure(a.fin)}</td>
                              <td>{a.motif || <span className="sub">—</span>}</td>
                              {peutGerer && (
                                <td>
                                  <button
                                    className="btn ghost sm"
                                    type="button"
                                    disabled={busy}
                                    onClick={() => agir(() => api.supprimerIndisponibilite(a.id))}
                                  >
                                    Retirer
                                  </button>
                                </td>
                              )}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    {peutGerer && (
                      <DeclarerAbsence
                        busy={busy}
                        onDeclarer={(corps) =>
                          agir(() => api.creerIndisponibilite({
                            ...corps,
                            ressource: `/api/reservation_ressources/${r.id}`,
                          }))
                        }
                      />
                    )}
                  </div>
                )}
              </div>
            </section>
          )
        })
      )}
    </div>
  )
}

/**
 * La jauge d'une ressource, lisible et modifiable au meme endroit.
 *
 * **On affiche le SENS avant le nombre.** << 4 places >> ne dit pas grand-chose ; << une place a la
 * fois >> dit qu'on parle d'une personne ou d'un terrain en simple. Un exploitant qui configure son
 * padel doit reconnaitre sa situation dans la phrase, pas la deduire du chiffre.
 *
 * **Zero est refuse ici, pas au serveur.** Une jauge a zero rend la ressource invisible partout sans
 * qu'aucun ecran ne dise pourquoi -- ce n'est pas une desactivation, c'est une disparition. Pour
 * retirer une ressource, on la desactive ; c'est un geste distinct, et il se voit.
 */
function JaugeEditable({ ressource, peutGerer, busy, onEnregistrer }) {
  const [edite, setEdite] = useState(false)
  const [valeur, setValeur] = useState(String(ressource.capacitePropre ?? 1))

  useEffect(() => {
    setValeur(String(ressource.capacitePropre ?? 1))
  }, [ressource.capacitePropre])

  const n = Number(valeur)
  const invalide = !Number.isInteger(n) || n < 1

  if (!edite) {
    return (
      <span className="sub" style={{ marginLeft: 8, display: 'inline-flex', gap: 6, alignItems: 'center' }}>
        {ressource.capacitePropre === 1 ? 'une place a la fois' : `${ressource.capacitePropre} places`}
        {peutGerer && (
          <button
            className="btn ghost sm"
            type="button"
            style={{ padding: '0 6px', fontSize: 11.5 }}
            onClick={() => setEdite(true)}
          >
            Modifier la jauge
          </button>
        )}
      </span>
    )
  }

  return (
    <span style={{ marginLeft: 8, display: 'inline-flex', gap: 6, alignItems: 'center' }}>
      <input
        className="input sm"
        type="number"
        min="1"
        step="1"
        style={{ width: 80 }}
        value={valeur}
        onChange={(e) => setValeur(e.target.value)}
      />
      <button
        className="btn primary sm"
        type="button"
        disabled={busy || invalide}
        onClick={() => { onEnregistrer(n); setEdite(false) }}
      >
        Enregistrer
      </button>
      <button className="btn ghost sm" type="button" onClick={() => setEdite(false)}>Annuler</button>
      {invalide && <span className="sub">Au moins une place.</span>}
    </span>
  )
}

function AjoutJournee({ ouverte, onOuvrir, busy, onAjouter }) {
  const [jour, setJour] = useState(1)
  const [debut, setDebut] = useState('09:00')
  const [fin, setFin] = useState('18:00')

  if (!ouverte) {
    return (
      <button className="btn ghost sm" type="button" style={{ marginTop: 8 }} onClick={onOuvrir}>
        + Ajouter une journée
      </button>
    )
  }

  // Une plage qui finit avant de commencer n'est pas une erreur de saisie qu'on corrige au serveur :
  // elle se refuse ici, où l'utilisateur a les deux champs sous les yeux.
  const incoherent = debut >= fin

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 10, flexWrap: 'wrap' }}>
      <select className="select sm" style={{ width: 150 }} value={jour} onChange={(e) => setJour(Number(e.target.value))}>
        {JOURS.map(([n, l]) => <option key={n} value={n}>{l}</option>)}
      </select>
      <input className="input sm" type="time" style={{ width: 120 }} value={debut} onChange={(e) => setDebut(e.target.value)} />
      <span className="sub">→</span>
      <input className="input sm" type="time" style={{ width: 120 }} value={fin} onChange={(e) => setFin(e.target.value)} />
      <button
        className="btn primary sm"
        type="button"
        disabled={busy || incoherent}
        onClick={() => onAjouter({ jourSemaine: jour, heureDebut: debut, heureFin: fin })}
      >
        Ajouter
      </button>
      <button className="btn ghost sm" type="button" onClick={onOuvrir}>Annuler</button>
      {incoherent && <span className="sub">La fermeture doit suivre l&rsquo;ouverture.</span>}
    </div>
  )
}

/**
 * DÉCLARER UNE ABSENCE — congés, panne, fermeture exceptionnelle.
 *
 * **Ce qui manquait, et ce que ça rendait inerte.** L'écran savait RETIRER une absence et pas en
 * CRÉER une. Pire : le bloc entier disparaissait quand il n'y en avait aucune, si bien que le seul
 * moyen d'atteindre un formulaire de création aurait été de posséder déjà ce qu'il sert à créer.
 *
 * Le moteur de créneaux, lui, tenait compte des absences depuis le début — il coupe la journée en
 * deux autour d'elles. Une règle métier écrite, testée, et qu'aucun exploitant ne pouvait déclencher.
 *
 * > **Une règle que personne ne peut alimenter n'est pas une règle, c'est une intention.**
 *
 * **Les deux dates sont des horodatages, pas des jours.** Une panne de quatre heures un mardi
 * après-midi n'est pas une journée fermée : arrondir à la journée annulerait des réservations du
 * matin qui tenaient parfaitement.
 *
 * **Le motif est facultatif et l'écran ne l'exige pas.** Il est lu par des collègues, pas par une
 * machine, et une absence sans motif reste plus utile qu'une absence qu'on renonce à saisir.
 */
function DeclarerAbsence({ busy, onDeclarer }) {
  const [ouvert, setOuvert] = useState(false)
  const [debut, setDebut] = useState('')
  const [fin, setFin] = useState('')
  const [motif, setMotif] = useState('')

  const incoherent = debut !== '' && fin !== '' && fin <= debut

  if (!ouvert) {
    return (
      <button className="btn ghost sm" type="button" style={{ marginTop: 8 }} onClick={() => setOuvert(true)}>
        + Déclarer une absence
      </button>
    )
  }

  return (
    <div className="card" style={{ padding: 10, marginTop: 8, display: 'grid', gap: 8 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Du</span>
          <input className="input sm" type="datetime-local" value={debut} onChange={(e) => setDebut(e.target.value)} />
        </label>
        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Au</span>
          <input className="input sm" type="datetime-local" value={fin} onChange={(e) => setFin(e.target.value)} />
        </label>
        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Motif</span>
          <input
            className="input sm"
            type="text"
            maxLength={255}
            value={motif}
            placeholder="Facultatif — congés, panne…"
            onChange={(e) => setMotif(e.target.value)}
          />
        </label>
      </div>

      {incoherent && (
        // On le dit ici plutot que de laisser le serveur repondre : la correction se fait a l'endroit
        // ou la faute a ete commise.
        <div className="sub">La fin doit être postérieure au début.</div>
      )}

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
        <button className="btn ghost sm" type="button" disabled={busy} onClick={() => setOuvert(false)}>
          Annuler
        </button>
        <button
          className="btn primary sm"
          type="button"
          disabled={busy || debut === '' || fin === '' || incoherent}
          onClick={() => {
            onDeclarer({
              debut: new Date(debut).toISOString(),
              fin: new Date(fin).toISOString(),
              ...(motif.trim() ? { motif: motif.trim() } : {}),
            })
            setOuvert(false)
            setDebut('')
            setFin('')
            setMotif('')
          }}
        >
          Déclarer
        </button>
      </div>
    </div>
  )
}
