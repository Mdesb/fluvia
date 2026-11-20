import { useCallback, useEffect, useState } from 'react'
import { ApiError, api, membres } from '../../api/client.js'
import {
  NOTE_MAX,
  dateResolution,
  filtrerAlertes,
  libelleStatut,
  longueurNote,
  noteValide,
  trierAlertes,
} from '../../api/nf525Alerts.js'

// LES ALERTES « CHAÎNE FISCALE BLOQUÉE » — l'écran complet de l'éditeur.
//
// Il voit ce que l'exploitant ne voit pas : le détail technique, le nombre de détections, l'origine,
// tous les établissements liés. Son seul geste d'écriture est la note interne.
//
// ── ⚠ TOUT CE QUI VIENT DU SERVEUR EST RENDU EN TEXTE ──────────────────────────────────────────
//
// `chainLabel` et `establishmentNames` viennent de noms saisis par un client d'un autre tenant ;
// `technicalDetail` et `note` sont du texte libre. Ce sont des nœuds React (échappés), jamais du
// HTML injecté : ce serait une XSS stockée d'un client vers la session de l'éditeur. Le test
// `AlertesNf525.test.js` le prouve (source + rendu d'une alerte piégée).
export default function AlertesNf525({ onRefus }) {
  const [alertes, setAlertes] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [filtre, setFiltre] = useState('open')

  const charger = useCallback(() => {
    setErreur(null)
    api
      .editorNf525Alertes()
      .then((r) => setAlertes(membres(r)))
      .catch((e) => {
        // Un 404 veut dire « cette session n'est pas celle de l'éditeur » : la coquille le dit mieux.
        if (e instanceof ApiError && e.status === 404) onRefus?.()
        setErreur(e.message || 'Les alertes n’ont pas pu être chargées.')
      })
  }, [onRefus])

  useEffect(charger, [charger])

  if (alertes === null && !erreur) return <div className="empty">Chargement…</div>

  return (
    <div className="card">
      <div className="card-h">Alertes de scellement NF525</div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <ListeAlertes alertes={alertes ?? []} filtre={filtre} onFiltre={setFiltre} onEnregistre={charger} />
      </div>
    </div>
  )
}

// Présentation pure (testable sans réseau) : la liste, le filtre et les cartes.
export function ListeAlertes({ alertes, filtre, onFiltre, onEnregistre }) {
  const visibles = trierAlertes(filtrerAlertes(alertes, filtre))
  return (
    <>
      <div className="seg" role="group" aria-label="Filtrer les alertes">
        {[['open', 'Ouvertes'], ['resolved', 'Résolues']].map(([v, l]) => (
          <button key={v} type="button" className={filtre === v ? 'on' : ''} aria-pressed={filtre === v} onClick={() => onFiltre?.(v)}>
            {l}
          </button>
        ))}
      </div>

      {visibles.length === 0 && (
        <div className="empty">
          <p>{filtre === 'resolved' ? 'Aucune alerte résolue.' : 'Aucune alerte ouverte.'}</p>
          <p className="hint">C’est l’état normal : une alerte n’existe que si une chaîne fiscale a refusé de se prolonger.</p>
        </div>
      )}

      {visibles.map((a) => (
        <CarteAlerte key={a.id} alerte={a} onEnregistre={onEnregistre} />
      ))}
    </>
  )
}

export function CarteAlerte({ alerte: a, onEnregistre }) {
  const resolue = dateResolution(a)
  const noms = Array.isArray(a.establishmentNames) ? a.establishmentNames : []
  return (
    <div className="sup-acces">
      <div className="sup-acces-t">
        <strong>{a.reference}</strong>
        <span className={`badge ${a.status === 'open' ? 'crit' : 'good'}`}>{libelleStatut(a.status)}</span>
      </div>
      <div>
        {a.chainLabel} — {a.causeLabel}
      </div>
      <div className="hint">
        {a.refusalCount} refus, {a.detectionCount} détection(s), origine {a.origin} — du {formaterDate(a.firstEventAt)} au{' '}
        {formaterDate(a.lastEventAt)}
        {resolue && `, résolue le ${formaterDate(resolue)}`}
      </div>
      <div className="hint">Établissements liés :</div>
      <div>
        {noms.length === 0 ? <span className="hint">aucun</span> : noms.map((n, i) => <span key={i} className="chip">{n}</span>)}
      </div>
      <div className="hint">Détail technique (jamais montré à l’exploitant) :</div>
      <pre className="hint" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{a.technicalDetail}</pre>
      <NoteAlerte alerte={a} onEnregistre={onEnregistre} />
    </div>
  )
}

function NoteAlerte({ alerte: a, onEnregistre }) {
  const [note, setNote] = useState(a.note ?? '')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [fait, setFait] = useState(false)
  const longueur = longueurNote(note)
  const valide = noteValide(note)

  async function enregistrer() {
    setEnCours(true)
    setErreur(null)
    setFait(false)
    try {
      await api.editorNf525Note(a.id, note)
      setFait(true)
      onEnregistre?.()
    } catch (e) {
      setErreur(e.message || 'La note n’a pas pu être enregistrée.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div className="field">
      <label htmlFor={`note-${a.id}`}>Note interne</label>
      <textarea
        id={`note-${a.id}`}
        className="input"
        rows={3}
        value={note}
        onChange={(e) => { setNote(e.target.value); setFait(false) }}
        aria-describedby={`note-cpt-${a.id}`}
      />
      <div id={`note-cpt-${a.id}`} className="hint" style={valide ? undefined : { color: 'var(--crit)', fontWeight: 600 }}>
        {longueur} / {NOTE_MAX} caractères
        {a.noteAt && a.noteBy && ` — dernière note : ${a.noteBy}, ${formaterDate(a.noteAt)}`}
      </div>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {fait && <div className="hint" role="status">Note enregistrée.</div>}
      <div>
        <button type="button" className="btn ghost sm" disabled={enCours || !valide} onClick={enregistrer}>
          {enCours ? 'Enregistrement…' : 'Enregistrer la note'}
        </button>
      </div>
    </div>
  )
}

function formaterDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
