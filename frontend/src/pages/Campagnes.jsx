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
  // Lire le résultat, c'est compter des envois ; lire l'attribution, c'est lire ce que dépense une
  // part du fichier client. Deux droits, parce que ce ne sont pas les mêmes yeux.
  const peutLireJournal = aLeDroit(droits, 'campagne.lire_journal')

  const [onglet, setOnglet] = useState('campagnes')
  const [campagnes, setCampagnes] = useState([])
  const [redigee, setRedigee] = useState(null)
  const [resultat, setResultat] = useState(null)
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
      const [s, ca] = await Promise.all([api.segments(), api.campagnes()])
      setSegments(membres(s))
      setCampagnes(membres(ca))
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

  // L'ENVOI DEMANDE UNE CONFIRMATION QUI RAPPELLE L'EFFECTIF.
  //
  // Pas « êtes-vous sûr ? » — personne ne lit « êtes-vous sûr ». La confirmation dit COMBIEN de
  // personnes vont recevoir le message, parce que c'est le seul chiffre qui fait hésiter à bon
  // escient. Et elle rappelle que c'est définitif : une campagne ne se rejoue pas.
  // Le résultat et l'attribution se lisent ensemble : ce sont les deux moitiés de la même
  // question — « à qui a-t-on écrit », puis « cela a-t-il servi à quelque chose ».
  async function chargerResultat(campagne) {
    const base = await api.resultatCampagne(campagne.id)
    let attribution = null
    if (peutLireJournal) {
      // L'attribution est un supplément : sans le droit, ou en cas de panne, l'écran garde son
      // résultat plutôt que de ne rien afficher.
      try { attribution = await api.attributionCampagne(campagne.id) } catch { /* sans */ }
    }

    return { campagne, ...base, attribution }
  }

  async function envoyer(campagne) {
    const segment = segments.find((s) => idDe(campagne.segment) === s.id)
    let effectif = null
    try {
      effectif = segment ? (await api.apercuSegment(segment.id)).effectif : null
    } catch { /* l'aperçu est un confort : son échec ne doit pas empêcher de décider */ }

    const combien = effectif === null ? 'un nombre inconnu de' : effectif
    if (!window.confirm(
      `Envoyer « ${campagne.label} » à ${combien} personne(s) ?\n\n`
      + 'Une campagne ne se rejoue pas : ceux qui la recevront ne pourront pas la « dé-recevoir ».',
    )) return

    setBusy(true)
    setErreur(null)
    try {
      const r = await api.envoyerCampagne(campagne.id)
      setResultat({ ...(await chargerResultat(campagne)), immediat: r.resultat })
      await recharger()
    } catch (e) {
      setErreur(e.message || 'La campagne n’a pas pu partir.')
    } finally {
      setBusy(false)
    }
  }

  async function voirResultat(campagne) {
    setBusy(true)
    setErreur(null)
    try {
      setResultat(await chargerResultat(campagne))
    } catch (e) {
      setErreur(e.message || 'Le résultat n’a pas pu être lu.')
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
        {peutGerer && onglet !== 'fidelite' && (
          <button
            className="btn primary"
            type="button"
            onClick={() => (onglet === 'campagnes' ? setRedigee({}) : setEdite({}))}
          >
            {onglet === 'campagnes' ? '+ Nouvelle campagne' : '+ Nouveau segment'}
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

      <div className="seg" style={{ marginBottom: 16 }}>
        {[['campagnes', 'Campagnes'], ['segments', 'Segments'], ['fidelite', 'Fidélité']].map(([k, l]) => (
          <button key={k} className={onglet === k ? 'on' : ''} onClick={() => setOnglet(k)}>{l}</button>
        ))}
      </div>

      {onglet === 'fidelite' && <ReglagesFidelite droits={droits} onErreur={setErreur} />}

      {onglet === 'campagnes' && (
        <ListeCampagnes
          campagnes={campagnes}
          segments={segments}
          peutGerer={peutGerer}
          busy={busy}
          onRediger={setRedigee}
          onEnvoyer={envoyer}
          onResultat={voirResultat}
        />
      )}

      <div className="panel" style={{ display: onglet === 'segments' ? undefined : 'none' }}>
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

      <ResultatCampagne resultat={resultat} onFermer={() => setResultat(null)} />

      <RedactionCampagne
        campagne={redigee}
        segments={segments}
        onFermer={() => setRedigee(null)}
        onEnregistre={async (message) => { setRedigee(null); setSucces(message); await recharger() }}
        onErreur={setErreur}
      />

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

const STATUTS = {
  brouillon: { libelle: 'Brouillon', ton: 'warn' },
  planifiee: { libelle: 'Planifiée', ton: 'mut' },
  envoyee: { libelle: 'Envoyée', ton: 'good' },
  arretee: { libelle: 'Arrêtée', ton: 'crit' },
}

const CANAUX = [['email', 'Courriel'], ['sms', 'SMS']]

function idDe(ref) {
  if (!ref) return null
  return typeof ref === 'string' ? ref.split('/').pop() : ref.id
}

/**
 * LA LISTE DES CAMPAGNES — et surtout, ce qu'on peut encore en faire.
 *
 * **Une campagne envoyée n'offre plus qu'un bouton : voir le résultat.** Pas de « renvoyer », pas
 * de « modifier ». Rejouer recontacterait des gens qui ont déjà reçu le message — c'est la façon la
 * plus simple de brûler un canal, et un bouton suffirait.
 */
function ListeCampagnes({ campagnes, segments, peutGerer, busy, onRediger, onEnvoyer, onResultat }) {
  const nomSegment = (ref) => segments.find((s) => s.id === idDe(ref))?.label || '—'

  return (
    <div className="panel">
      <div className="panel-h"><span>Campagnes</span></div>

      {campagnes.length === 0 ? (
        <div className="sub" style={{ textAlign: 'center', padding: 26 }}>
          Aucune campagne. Une campagne, c’est un segment plus un message&nbsp;: commencez par le
          segment.
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Campagne</th>
                <th>Audience</th>
                <th>Canal</th>
                <th>Statut</th>
                <th className="num">Témoin</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {campagnes.map((c) => {
                const statut = STATUTS[c.status] || { libelle: c.status, ton: 'mut' }
                const partie = c.status === 'envoyee' || c.status === 'arretee'
                return (
                  <tr key={c.id}>
                    <td><span className="nm">{c.label}</span></td>
                    <td className="sub">{nomSegment(c.segment)}</td>
                    <td>{CANAUX.find(([v]) => v === c.channel)?.[1] || c.channel}</td>
                    <td><span className={`badge ${statut.ton}`}>{statut.libelle}</span></td>
                    <td className="num">{c.controlGroupPercent} %</td>
                    <td>
                      <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                        {partie ? (
                          <button
                            className="btn sm"
                            type="button"
                            disabled={busy}
                            style={{ padding: '1px 8px', fontSize: 11.5 }}
                            onClick={() => onResultat(c)}
                          >
                            Résultat
                          </button>
                        ) : peutGerer && (
                          <>
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={busy}
                              style={{ padding: '1px 8px', fontSize: 11.5 }}
                              onClick={() => onRediger(c)}
                            >
                              Modifier
                            </button>
                            <button
                              className="btn sm"
                              type="button"
                              disabled={busy}
                              style={{ padding: '1px 8px', fontSize: 11.5 }}
                              onClick={() => onEnvoyer(c)}
                            >
                              Envoyer
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

/**
 * LE RÉSULTAT — et le détail par motif, jamais un total.
 *
 * « 930 envoyés » ne dit rien. « 310 exclus faute de consentement » dit qu'il faut travailler le
 * recueil du consentement avant d'écrire un message de plus — et c'est souvent l'information la
 * plus utile de tout l'écran.
 *
 * **Le groupe témoin est montré à part, en clair.** Le ranger parmi les exclus ferait croire qu'on a
 * raté une part de son audience, alors que c'est la seule chose qui permettra de dire si la campagne
 * a servi à quelque chose.
 */
function ResultatCampagne({ resultat, onFermer }) {
  return (
    <Modal
      open={!!resultat}
      onClose={onFermer}
      titre={resultat ? `Résultat — ${resultat.campagne.label}` : ''}
      taille="lg"
    >
      {resultat && (
        <div style={{ display: 'grid', gap: 14 }}>
          <div className="fiche-stats">
            <div>
              <div className="st-lib">Ciblés</div>
              <div className="st-val num">{resultat.cibles}</div>
            </div>
            <div>
              <div className="st-lib">Contactés</div>
              <div className="st-val num">{resultat.contactes}</div>
            </div>
            <div>
              <div className="st-lib">Groupe témoin</div>
              <div className="st-val num">{resultat.temoins}</div>
            </div>
          </div>

          {resultat.temoins > 0 && (
            <div className="sub">
              Le groupe témoin n’a <strong>volontairement</strong> rien reçu. C’est lui qui permettra
              de dire combien de gens sont revenus <em>grâce à</em> la campagne, et non simplement
              combien sont revenus.
            </div>
          )}

          <Attribution attribution={resultat.attribution} />

          {resultat.exclus.length > 0 && (
            <div>
              <div className="st-lib" style={{ marginBottom: 6 }}>Écartés, et pourquoi</div>
              <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead><tr><th>Motif</th><th className="num">Nombre</th></tr></thead>
                  <tbody>
                    {resultat.exclus.map((e) => (
                      <tr key={e.motif}>
                        <td>{e.motif}</td>
                        <td className="num">{e.nombre}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="sub" style={{ marginTop: 6 }}>
                Beaucoup d’exclusions « sans consentement » veut dire qu’il faut travailler le
                recueil du consentement — pas le message.
              </div>
            </div>
          )}

          {!resultat.envoiReelDisponible && (
            <div className="alert warn" style={{ margin: 0 }}>
              Aucun envoi réel n’est branché : ces messages ont été <strong>journalisés</strong>,
              pas expédiés.
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}

/**
 * LA RÉDACTION.
 *
 * **Les variables sont proposées, pas devinées.** Une variable que le serveur ne sait pas remplir
 * exclurait chaque destinataire au moment de l'envoi — l'exploitant l'apprendrait sur un rapport
 * disant « 1 240 exclus ». La liste des variables disponibles est donc affichée sous le champ.
 *
 * **Le groupe témoin dit ce qu'il coûte.** Dix pour cent de l'audience non contactée, c'est dix pour
 * cent de chiffre d'affaires potentiel sacrifié pour savoir si la campagne sert à quelque chose. Le
 * décider en silence serait malhonnête ; le proposer sans l'expliquer le serait aussi.
 */
function RedactionCampagne({ campagne, segments, onFermer, onEnregistre, onErreur }) {
  const ouvert = campagne !== null && campagne !== undefined
  const existante = ouvert && !!campagne.id

  const [label, setLabel] = useState('')
  const [segment, setSegment] = useState('')
  const [channel, setChannel] = useState('email')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [temoin, setTemoin] = useState(10)
  const [fenetre, setFenetre] = useState(30)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!ouvert) return
    setLabel(campagne.label || '')
    setSegment(idDe(campagne.segment) || '')
    setChannel(campagne.channel || 'email')
    setSubject(campagne.subject || '')
    setBody(campagne.body || '')
    setTemoin(campagne.controlGroupPercent ?? 10)
    setFenetre(campagne.attributionWindowDays ?? 30)
  }, [ouvert, campagne])

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = {
        label: label.trim(),
        segment: `/api/segments/${segment}`,
        channel,
        subject: subject.trim(),
        body,
        controlGroupPercent: Number(temoin),
        attributionWindowDays: Number(fenetre),
      }
      if (existante) {
        await api.majCampagne(campagne.id, corps)
        await onEnregistre('Campagne modifiée.')
      } else {
        await api.creerCampagne(corps)
        await onEnregistre('Campagne créée. Elle ne partira que si vous l’envoyez.')
      }
    } catch (e) {
      onErreur(e.message || 'La campagne n’a pas pu être enregistrée.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={ouvert}
      onClose={onFermer}
      titre={existante ? 'Modifier la campagne' : 'Nouvelle campagne'}
      taille="lg"
    >
      <div style={{ display: 'grid', gap: 12 }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <label style={{ display: 'grid', gap: 4 }}>
            <span className="sub">Nom de la campagne</span>
            <input className="input" value={label} maxLength={120} onChange={(e) => setLabel(e.target.value)} />
          </label>

          <label style={{ display: 'grid', gap: 4 }}>
            <span className="sub">Audience</span>
            <select className="select" value={segment} onChange={(e) => setSegment(e.target.value)}>
              <option value="">Choisir un segment…</option>
              {segments.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
            </select>
          </label>
        </div>

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Canal</span>
          <select className="select" value={channel} onChange={(e) => setChannel(e.target.value)}>
            {CANAUX.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          <span className="sub" style={{ fontSize: 12 }}>
            Un message écrit pour un courriel fait un mauvais SMS. Deux canaux, c’est deux campagnes.
          </span>
        </label>

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Objet</span>
          <input className="input" value={subject} maxLength={200} onChange={(e) => setSubject(e.target.value)} />
        </label>

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Message</span>
          <textarea rows={7} value={body} onChange={(e) => setBody(e.target.value)} />
          <span className="sub" style={{ fontSize: 12 }}>
            Variables disponibles&nbsp;: <code>{'{{prenom}}'}</code> <code>{'{{nom}}'}</code>{' '}
            <code>{'{{civilite}}'}</code>. Une variable que le serveur ne sait pas remplir écarte la
            personne concernée plutôt que d’écrire «&nbsp;Bonjour ,&nbsp;».
          </span>
        </label>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <label style={{ display: 'grid', gap: 4 }}>
            <span className="sub">Groupe témoin (%)</span>
            <input
              className="input"
              type="number"
              min="0"
              max="50"
              value={temoin}
              onChange={(e) => setTemoin(e.target.value)}
            />
            <span className="sub" style={{ fontSize: 12 }}>
              Cette part ne recevra rien. C’est ce qui permet de dire si la campagne a servi à
              quelque chose — et c’est aussi autant de clients non sollicités. Zéro pour la désactiver.
            </span>
          </label>

          <label style={{ display: 'grid', gap: 4 }}>
            <span className="sub">Fenêtre d’attribution (jours)</span>
            <input
              className="input"
              type="number"
              min="1"
              max="365"
              value={fenetre}
              onChange={(e) => setFenetre(e.target.value)}
            />
            <span className="sub" style={{ fontSize: 12 }}>
              Durée pendant laquelle une visite sera rattachée à cette campagne. Trente jours pour
              une piscine, davantage pour un musée.
            </span>
          </label>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer} disabled={busy}>Annuler</button>
          <button
            className="btn primary"
            type="button"
            onClick={enregistrer}
            disabled={busy || label.trim() === '' || segment === '' || body.trim() === ''}
          >
            Enregistrer
          </button>
        </div>
      </div>
    </Modal>
  )
}

/**
 * L'ATTRIBUTION — le seul endroit de l'écran où l'on ose dire « la campagne a servi ».
 *
 * Trois refus tiennent ce composant :
 *
 *   - sans témoin, on n'affiche AUCUN effet — le taux de retour brut mesure la saison, pas le
 *     message, et le nommer « effet » serait le mensonge le plus facile du module ;
 *   - un écart plus petit que sa marge est rendu comme « on ne sait pas encore », pas comme un
 *     gain — sur quarante témoins, deux visites déplacent le taux de cinq points ;
 *   - un écart négatif s'affiche tel quel : un tableau de bord qui ne sait montrer que des gains
 *     ne mesure rien, il rassure.
 */
function Attribution({ attribution }) {
  if (!attribution) return null

  if (!attribution.mesurable) {
    return <div className="sub">{attribution.raison}</div>
  }

  const { contactes, temoins, fenetre } = attribution
  const nb = (v) => Number(v).toLocaleString('fr-FR')
  const euros = (v) => `${Number(v).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`
  const pct = (v) => `${Number(v).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`
  const signe = (v) => (v > 0 ? '+' : '') + Number(v).toLocaleString('fr-FR', { maximumFractionDigits: 1 })

  const lignes = [
    ['Effectif', nb(contactes.effectif), nb(temoins.effectif)],
    ['Sont revenus', nb(contactes.revenus), nb(temoins.revenus)],
    ['Taux de retour', pct(contactes.tauxRetour), pct(temoins.tauxRetour)],
    ['Chiffre d’affaires', euros(contactes.ca), euros(temoins.ca)],
    ['Panier moyen', euros(contactes.panierMoyen), euros(temoins.panierMoyen)],
  ]

  return (
    <div>
      <div className="st-lib" style={{ marginBottom: 6 }}>
        Ce que la campagne a produit — fenêtre de {fenetre.jours} jours
      </div>

      <div style={{ overflowX: 'auto' }}>
        <table className="tbl">
          <thead>
            <tr><th /><th className="num">Contactés</th><th className="num">Groupe témoin</th></tr>
          </thead>
          <tbody>
            {lignes.map(([libelle, a, b]) => (
              <tr key={libelle}>
                <td>{libelle}</td>
                <td className="num">{a}</td>
                <td className="num">{b}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {!attribution.comparable ? (
        <div className="alert warn" style={{ marginTop: 10, marginBottom: 0 }}>
          <strong>Aucun effet ne peut être attribué à cette campagne.</strong> {attribution.raison}
          {' '}Les retours affichés sont ceux du groupe contacté : on ne sait pas combien seraient
          revenus sans le message.
        </div>
      ) : !attribution.concluant ? (
        <div className="alert warn" style={{ marginTop: 10, marginBottom: 0 }}>
          <strong>On ne peut pas encore conclure.</strong> L’écart mesuré est de{' '}
          {signe(attribution.ecartPoints)} points, pour une marge d’incertitude de{' '}
          ± {nb(attribution.margeErreur)}. Plus petit que sa marge, il ne se distingue pas de zéro —
          un groupe témoin plus grand, ou une audience plus large, trancherait.
        </div>
      ) : attribution.ecartPoints > 0 ? (
        <div className="alert good" style={{ marginTop: 10, marginBottom: 0 }}>
          <strong>
            La campagne a ramené {nb(attribution.visitesGagnees)} personne(s) de plus
          </strong>{' '}
          que si elle n’avait pas eu lieu, soit environ {euros(attribution.caGagne)} de chiffre
          d’affaires. Écart : {signe(attribution.ecartPoints)} points ± {nb(attribution.margeErreur)}.
        </div>
      ) : (
        <div className="alert crit" style={{ marginTop: 10, marginBottom: 0 }}>
          <strong>Le groupe contacté est revenu MOINS que le témoin</strong> —{' '}
          {signe(attribution.ecartPoints)} points ± {nb(attribution.margeErreur)}. L’écart dépasse sa
          marge : ce n’est pas du bruit. Le message, le moment ou la cible ont desservi.
        </div>
      )}

      {!fenetre.close && (
        <div className="sub" style={{ marginTop: 6 }}>
          Mesure <strong>provisoire</strong> : la fenêtre se referme dans {fenetre.joursRestants}{' '}
          jour(s). Ces chiffres bougeront encore.
        </div>
      )}

      <div className="sub" style={{ marginTop: 6 }}>
        Les ventes sont comptées où qu’elles aient eu lieu dans le groupe : la campagne a ramené une
        personne, pas une caisse.
      </div>
    </div>
  )
}

/**
 * LE PARAMÉTRAGE DE LA FIDÉLITÉ — trois réglages, trois raisons.
 *
 * **Le barème s'AJOUTE, il ne se modifie pas.** Une règle vaut à partir d'une date ; corriger celle
 * d'hier changerait des soldes déjà annoncés aux clients. Se tromper se répare en posant un
 * nouveau barème, exactement comme on ne rature pas une écriture comptable.
 *
 * **Un palier se lit sur douze mois glissants**, indépendamment des dépenses : dépenser ses points
 * ne doit pas faire perdre son statut, sinon le client apprend à ne jamais s'en servir.
 *
 * **Le seuil d'achat du parrainage est le cœur du programme.** À zéro, on récompense une
 * inscription — c'est-à-dire quiconque sait créer une adresse e-mail.
 */
/** Une date lisible, ou un tiret. Le format ISO brut ne se lit pas dans un tableau. */
function dateFr(v) {
  if (!v) return '—'
  const d = new Date(v)

  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

function ReglagesFidelite({ droits, onErreur }) {
  const [baremes, setBaremes] = useState([])
  const [paliers, setPaliers] = useState([])
  const [programme, setProgramme] = useState(null)
  const [busy, setBusy] = useState(false)

  const peutRegler = aLeDroit(droits, 'fidelite.parametrer')

  const charger = useCallback(async () => {
    try {
      const [b, p, pr] = await Promise.all([
        api.baremesFidelite(),
        api.paliersFidelite(),
        api.programmesParrainage(),
      ])
      setBaremes(membres(b))
      setPaliers(membres(p))
      setProgramme(membres(pr)[0] || null)
    } catch (e) {
      onErreur?.(e.message || 'Le paramétrage n’a pas pu être lu.')
    }
  }, [onErreur])

  useEffect(() => { charger() }, [charger])

  async function agir(promesse) {
    setBusy(true)
    try {
      await promesse
      await charger()
    } catch (e) {
      onErreur?.(e.message || 'Le réglage n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <PanneauBaremes baremes={baremes} peutRegler={peutRegler} busy={busy} onAjouter={agir} />
      <PanneauPaliers paliers={paliers} peutRegler={peutRegler} busy={busy} onAgir={agir} />
      <PanneauParrainage programme={programme} peutRegler={peutRegler} busy={busy} onAgir={agir} />
    </div>
  )
}

function PanneauBaremes({ baremes, peutRegler, busy, onAjouter }) {
  const [points, setPoints] = useState('1')
  const [depuis, setDepuis] = useState(() => new Date().toISOString().slice(0, 10))

  const tries = [...baremes].sort((a, b) => (a.validFrom < b.validFrom ? 1 : -1))

  return (
    <div className="panel">
      <div className="panel-h"><span>Barème — points par euro</span></div>

      <div className="sub" style={{ marginBottom: 8 }}>
        Un barème vaut <strong>à partir</strong> de sa date et jusqu’au suivant. On en ajoute un, on
        n’en corrige jamais : changer celui d’hier modifierait des soldes déjà annoncés aux clients.
      </div>

      {tries.length > 0 && (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead><tr><th>En vigueur depuis</th><th className="num">Points par euro</th></tr></thead>
            <tbody>
              {tries.map((b, i) => (
                <tr key={b.id}>
                  <td>{dateFr(b.validFrom)} {i === 0 && <span className="badge good">actuel</span>}</td>
                  <td className="num">{b.pointsPerEuro}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {peutRegler && (
        <div className="r" style={{ gap: 8, marginTop: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Points par euro</label>
            <input type="number" min="0" value={points} onChange={(e) => setPoints(e.target.value)} />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>À partir du</label>
            <input type="date" value={depuis} onChange={(e) => setDepuis(e.target.value)} />
          </div>
          <button
            className="btn primary sm"
            type="button"
            disabled={busy}
            onClick={() => onAjouter(api.creerBaremeFidelite({
              pointsPerEuro: Number(points),
              validFrom: `${depuis}T00:00:00+00:00`,
            }))}
          >
            Ajouter ce barème
          </button>
        </div>
      )}
    </div>
  )
}

function PanneauPaliers({ paliers, peutRegler, busy, onAgir }) {
  const [libelle, setLibelle] = useState('')
  const [seuil, setSeuil] = useState('')

  const tries = [...paliers].sort((a, b) => a.threshold - b.threshold)

  return (
    <div className="panel">
      <div className="panel-h"><span>Paliers</span></div>

      <div className="sub" style={{ marginBottom: 8 }}>
        Le palier se lit sur les points gagnés sur <strong>douze mois glissants</strong>, pas sur le
        solde : dépenser ses points ne doit pas faire perdre son statut.
      </div>

      {tries.length > 0 && (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead><tr><th>Palier</th><th className="num">À partir de</th><th /></tr></thead>
            <tbody>
              {tries.map((p) => (
                <tr key={p.id}>
                  <td>{p.label}</td>
                  <td className="num">{p.threshold}</td>
                  <td>
                    {peutRegler && (
                      <button
                        className="btn ghost sm"
                        type="button"
                        disabled={busy}
                        onClick={() => onAgir(api.supprimerPalierFidelite(p.id))}
                      >
                        Retirer
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {peutRegler && (
        <div className="r" style={{ gap: 8, marginTop: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Nom du palier</label>
            <input value={libelle} onChange={(e) => setLibelle(e.target.value)} placeholder="Argent" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Points requis</label>
            <input type="number" min="1" value={seuil} onChange={(e) => setSeuil(e.target.value)} />
          </div>
          <button
            className="btn primary sm"
            type="button"
            disabled={busy || !libelle.trim() || !seuil}
            onClick={() => onAgir(api.creerPalierFidelite({
              label: libelle.trim(),
              threshold: Number(seuil),
            }).then(() => { setLibelle(''); setSeuil('') }))}
          >
            Ajouter
          </button>
        </div>
      )}
    </div>
  )
}

function PanneauParrainage({ programme, peutRegler, busy, onAgir }) {
  const [points, setPoints] = useState(String(programme?.rewardPoints ?? 100))
  const [minimum, setMinimum] = useState(String(programme?.minimumPurchase ?? '10.00'))

  useEffect(() => {
    setPoints(String(programme?.rewardPoints ?? 100))
    setMinimum(String(programme?.minimumPurchase ?? '10.00'))
  }, [programme])

  const corps = { rewardPoints: Number(points), minimumPurchase: Number(minimum).toFixed(2) }

  return (
    <div className="panel">
      <div className="panel-h">
        <span>Parrainage</span>
        {programme && (
          <span className={`badge ${programme.enabled ? 'good' : 'mut'}`} style={{ marginLeft: 'auto' }}>
            {programme.enabled ? 'actif' : 'suspendu'}
          </span>
        )}
      </div>

      <div className="sub" style={{ marginBottom: 8 }}>
        Le <strong>montant minimum</strong> est le cœur du programme : il fait dépendre la récompense
        d’un achat encaissé, jamais d’une inscription. À zéro, on récompense quiconque sait créer une
        adresse e-mail.
      </div>

      {peutRegler ? (
        <div className="r" style={{ gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Points au parrain</label>
            <input type="number" min="1" value={points} onChange={(e) => setPoints(e.target.value)} />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Achat minimum du filleul (€)</label>
            <input type="number" min="0" step="0.01" value={minimum} onChange={(e) => setMinimum(e.target.value)} />
          </div>
          <button
            className="btn primary sm"
            type="button"
            disabled={busy}
            onClick={() => onAgir(programme
              ? api.majProgrammeParrainage(programme.id, corps)
              : api.creerProgrammeParrainage(corps))}
          >
            {programme ? 'Enregistrer' : 'Créer le programme'}
          </button>
          {programme && (
            <button
              className="btn ghost sm"
              type="button"
              disabled={busy}
              onClick={() => onAgir(api.majProgrammeParrainage(programme.id, { enabled: !programme.enabled }))}
            >
              {programme.enabled ? 'Suspendre' : 'Réactiver'}
            </button>
          )}
        </div>
      ) : (
        <div className="sub">
          {programme
            ? `${programme.rewardPoints} points au parrain dès ${programme.minimumPurchase} € encaissés.`
            : 'Aucun programme défini.'}
        </div>
      )}
    </div>
  )
}
