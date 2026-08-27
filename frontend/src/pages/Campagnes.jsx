import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'

/**
 * CAMPAGNES — première étape : les segments, et l'effectif avant l'envoi.
 *
 * **Ce que cet écran sert à décider.** Un segment décrit des clients ; un envoi les atteint. Entre
 * les deux il y a un chiffre — combien de personnes — et c'est le seul moment où l'exploitant peut
 * encore changer d'avis. Une fois parti, un message ne se rattrape pas.
 *
 * > **Sans l'effectif affiché avant l'envoi, on découvre l'ampleur de son geste après l'avoir fait.**
 *
 * **L'échantillon compte autant que le compte.** « 1 240 personnes » ne se vérifie pas ; « 1 240
 * personnes, dont Martin, Dupont et Nowak » se reconnaît — ou se conteste. Un exploitant qui ne
 * reconnaît personne dans son propre échantillon vient d'apprendre que son critère est faux, et il
 * l'apprend avant d'écrire à mille personnes.
 *
 * **L'écran dit qu'aucun envoi réel n'existe, et il ne le devine pas** : c'est le serveur qui le
 * déclare (`envoiReelDisponible`). Laisser croire qu'un message est parti serait pire que de ne rien
 * proposer du tout.
 *
 * ⚠ **Le formulaire n'offre que les critères que le serveur sait appliquer.** Un champ libre serait
 * une porte ouverte sur les clients des autres établissements — le cloisonnement porte sur les
 * clients résolus, et une expression arbitraire le rendrait intenable.
 */

// Les mêmes clés que `SegmentCriteria` côté serveur. Elles sont redites ici, et c'est une dette
// assumée : le serveur REFUSE une clé qu'il ne connaît pas, donc une divergence se voit à la
// première sauvegarde plutôt que de produire une audience silencieusement fausse.
const CRITERES = [
  {
    cle: 'sansVisiteDepuisJours',
    label: 'Sans visite depuis (jours)',
    type: 'number',
    aide: 'Inclut ceux qui ne sont jamais venus : c’est bien eux qu’on veut reconquérir.',
  },
  { cle: 'caCumuleMin', label: 'A dépensé au moins (€)', type: 'number' },
  { cle: 'caCumuleMax', label: 'A dépensé au plus (€)', type: 'number' },
  { cle: 'ageMin', label: 'Âge minimum', type: 'number' },
  { cle: 'ageMax', label: 'Âge maximum', type: 'number' },
  {
    cle: 'type',
    label: 'Type de client',
    type: 'choix',
    options: [['', 'Indifférent'], ['physique', 'Particulier'], ['morale', 'Société']],
  },
]

export default function Campagnes({ etabActif, droits = [] }) {
  const peutGerer = aLeDroit(droits, 'campagne.gerer')

  const [segments, setSegments] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [edite, setEdite] = useState(null)
  const [apercu, setApercu] = useState(null)
  const [busy, setBusy] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setSegments(membres(await api.segments()))
    } catch (e) {
      setErreur(e.message || 'Les segments n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

  // ON RELIT AVANT D'EDITER.
  //
  // La ligne du tableau date du dernier chargement de la liste. Ouvrir l'edition a partir d'elle,
  // c'est risquer de reecrire des criteres qu'un collegue vient de changer -- et de les ecraser sans
  // que personne ne s'en apercoive, puisque le formulaire aurait l'air correct.
  //
  // Si la relecture echoue, on edite quand meme a partir de la ligne : refuser d'ouvrir le
  // formulaire pour une lecture ratee punirait l'utilisateur d'un incident qui ne le concerne pas.
  async function relire(segment) {
    setBusy(true)
    try {
      setEdite(await api.segment(segment.id))
    } catch {
      setEdite(segment)
    } finally {
      setBusy(false)
    }
  }

  async function ouvrirApercu(segment) {
    setBusy(true)
    setErreur(null)
    try {
      setApercu({ segment, ...(await api.apercuSegment(segment.id)) })
    } catch (e) {
      setErreur(e.message || 'L’aperçu n’a pas pu être calculé.')
    } finally {
      setBusy(false)
    }
  }

  async function supprimer(segment) {
    if (!window.confirm(`Supprimer le segment « ${segment.label} » ?`)) return
    setBusy(true)
    setErreur(null)
    try {
      await api.supprimerSegment(segment.id)
      setSucces('Segment supprimé.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Le segment n’a pas pu être supprimé.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>Campagnes</h1>
          <div className="sub">
            {segments.length} segment{segments.length > 1 ? 's' : ''} · l’effectif se calcule à chaque lecture
          </div>
        </div>
        {peutGerer && (
          <button className="btn primary" type="button" onClick={() => setEdite({})}>
            + Nouveau segment
          </button>
        )}
      </div>

      {/* AUCUN ENVOI RÉEL N'EXISTE, ET ON LE DIT EN HAUT.
          Le dire en petit en bas d'un écran de campagnes reviendrait à ne pas le dire. Tant qu'aucun
          prestataire n'est branché, un exploitant doit savoir que ce qu'il prépare ne partira pas. */}
      <div className="alert warn">
        <strong>Aucun envoi réel n’est branché.</strong> Les segments se construisent et se comptent ;
        les messages sont journalisés, jamais expédiés. Le jour où un prestataire d’envoi est
        raccordé, rien d’autre ne change.
      </div>

      {erreur && <div className="alert crit">{erreur}</div>}
      {succes && <div className="alert good">{succes}</div>}

      <div className="panel">
        <div className="panel-h"><span>Segments</span></div>

        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : segments.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 26 }}>
            Aucun segment. Le premier qu’écrivent la plupart des exploitants&nbsp;: «&nbsp;sans visite
            depuis 90 jours&nbsp;».
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Segment</th>
                  <th>Critères</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {segments.map((s) => (
                  <tr key={s.id}>
                    <td><span className="nm">{s.label}</span></td>
                    <td className="sub">{resumerCriteres(s.criteria)}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                        <button
                          className="btn sm"
                          type="button"
                          disabled={busy}
                          style={{ padding: '1px 8px', fontSize: 11.5 }}
                          onClick={() => ouvrirApercu(s)}
                        >
                          Combien de personnes ?
                        </button>
                        {peutGerer && (
                          <>
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={busy}
                              style={{ padding: '1px 8px', fontSize: 11.5 }}
                              onClick={() => relire(s)}
                            >
                              Modifier
                            </button>
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={busy}
                              style={{ padding: '1px 8px', fontSize: 11.5 }}
                              onClick={() => supprimer(s)}
                            >
                              Supprimer
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <ApercuSegment apercu={apercu} onFermer={() => setApercu(null)} />

      <EditionSegment
        segment={edite}
        onFermer={() => setEdite(null)}
        onEnregistre={async (message) => { setEdite(null); setSucces(message); await recharger() }}
        onErreur={setErreur}
      />
    </div>
  )
}

function resumerCriteres(criteria) {
  const c = criteria || {}
  const morceaux = CRITERES
    .filter(({ cle }) => c[cle] !== undefined && c[cle] !== '' && c[cle] !== null)
    .map(({ cle, label }) => `${label} : ${c[cle]}`)

  return morceaux.length > 0 ? morceaux.join(' · ') : '—'
}

/**
 * L'APERÇU — le seul moment où l'on peut encore changer d'avis.
 *
 * **Un effectif de zéro n'est pas une erreur, et l'écran le dit autrement.** Un critère trop
 * restrictif est le cas le plus fréquent, et le message doit envoyer l'exploitant vers ses critères
 * plutôt que le laisser conclure à une panne.
 */
function ApercuSegment({ apercu, onFermer }) {
  const vide = apercu && apercu.effectif === 0

  return (
    <Modal
      open={!!apercu}
      onClose={onFermer}
      titre={apercu ? `Aperçu — ${apercu.segment.label}` : ''}
      taille="md"
    >
      {apercu && (
        <div style={{ display: 'grid', gap: 14 }}>
          <div className="fiche-stats">
            <div>
              <div className="st-lib">Personnes touchées</div>
              <div className="st-val num">{apercu.effectif}</div>
            </div>
          </div>

          {vide ? (
            <div className="sub">
              Aucune personne ne correspond. C’est presque toujours un critère trop étroit — et
              c’est mieux de l’apprendre ici que devant une campagne partie à personne.
            </div>
          ) : (
            <div>
              <div className="st-lib" style={{ marginBottom: 6 }}>Quelques-unes d’entre elles</div>
              <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead>
                    <tr><th>Client</th><th className="num">Dernière visite</th></tr>
                  </thead>
                  <tbody>
                    {apercu.echantillon.map((c) => (
                      <tr key={c.id}>
                        <td>{c.nom}</td>
                        <td className="num">{c.derniereVisite || <span className="sub">jamais</span>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="sub" style={{ marginTop: 6 }}>
                Un échantillon où vous ne reconnaissez personne veut dire que le critère est faux.
              </div>
            </div>
          )}

          {!apercu.envoiReelDisponible && (
            <div className="alert warn" style={{ margin: 0 }}>
              Aucun envoi réel n’est branché : ce segment se compte, il ne s’expédie pas encore.
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}

/**
 * LA SAISIE — que des critères que le serveur sait appliquer.
 *
 * **Un critère laissé vide n'est pas envoyé.** Envoyer `caCumuleMin: ""` ferait porter au serveur la
 * charge de deviner qu'un vide veut dire « pas de condition » ; ici, un champ vide ne produit
 * simplement pas de critère.
 */
function EditionSegment({ segment, onFermer, onEnregistre, onErreur }) {
  const ouvert = segment !== null && segment !== undefined
  const existant = ouvert && !!segment.id

  const [label, setLabel] = useState('')
  const [valeurs, setValeurs] = useState({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!ouvert) return
    setLabel(segment.label || '')
    setValeurs({ ...(segment.criteria || {}) })
  }, [ouvert, segment])

  const aucunCritere = Object.entries(valeurs)
    .filter(([, v]) => v !== '' && v !== null && v !== undefined).length === 0

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const criteria = {}
      for (const [cle, v] of Object.entries(valeurs)) {
        if (v === '' || v === null || v === undefined) continue
        criteria[cle] = CRITERES.find((c) => c.cle === cle)?.type === 'number' ? Number(v) : v
      }

      if (existant) {
        await api.majSegment(segment.id, { label: label.trim(), criteria })
        await onEnregistre('Segment modifié.')
      } else {
        await api.creerSegment({ label: label.trim(), criteria })
        await onEnregistre('Segment créé. Vérifiez son effectif avant d’en faire une campagne.')
      }
    } catch (e) {
      onErreur(e.message || 'Le segment n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={ouvert}
      onClose={onFermer}
      titre={existant ? 'Modifier le segment' : 'Nouveau segment'}
      taille="md"
    >
      <div style={{ display: 'grid', gap: 12 }}>
        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Nom du segment</span>
          <input
            className="input"
            value={label}
            maxLength={120}
            placeholder="Ex. Nageurs perdus de vue"
            onChange={(e) => setLabel(e.target.value)}
          />
        </label>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
          {CRITERES.map((critere) => (
            <label key={critere.cle} style={{ display: 'grid', gap: 4 }}>
              <span className="sub">{critere.label}</span>
              {critere.type === 'choix' ? (
                <select
                  className="select"
                  value={valeurs[critere.cle] ?? ''}
                  onChange={(e) => setValeurs((v) => ({ ...v, [critere.cle]: e.target.value }))}
                >
                  {critere.options.map(([val, lib]) => <option key={val} value={val}>{lib}</option>)}
                </select>
              ) : (
                <input
                  className="input"
                  type="number"
                  min="0"
                  value={valeurs[critere.cle] ?? ''}
                  onChange={(e) => setValeurs((v) => ({ ...v, [critere.cle]: e.target.value }))}
                />
              )}
              {critere.aide && <span className="sub" style={{ fontSize: 12 }}>{critere.aide}</span>}
            </label>
          ))}
        </div>

        {aucunCritere && (
          <div className="alert warn" style={{ margin: 0 }}>
            Un segment sans critère désigne <strong>tout le monde</strong>. Précisez au moins une
            condition — le serveur refusera de l’enregistrer autrement.
          </div>
        )}

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer} disabled={busy}>Annuler</button>
          <button
            className="btn primary"
            type="button"
            onClick={enregistrer}
            disabled={busy || label.trim() === '' || aucunCritere}
          >
            Enregistrer
          </button>
        </div>
      </div>
    </Modal>
  )
}
