import { useCallback, useEffect, useState } from 'react'
import { ApiError, api, membres } from '../../api/client.js'
import { useEtatUrl } from '../../api/url.js'
import Tabs from '../../components/Tabs.jsx'
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
const DEFAUTS_URL = { filtre: 'open' }
const FILTRES = [['open', 'Ouvertes'], ['resolved', 'Résolues'], ['all', 'Toutes']]

export default function AlertesNf525({ onRefus }) {
  const [params, majParams] = useEtatUrl('alertes-nf525', DEFAUTS_URL)
  // `null` = PAS LU · `[]` = LU ET VIDE.
  const [alertes, setAlertes] = useState(null)
  const [total, setTotal] = useState(0)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  // `silencieux` : recharge sans démonter la liste (après une note : le message de succès reste).
  const charger = useCallback((silencieux = false) => {
    setErreur(null)
    if (silencieux !== true) setChargement(true)
    api
      .editorNf525Alertes()
      .then((r) => {
        const liste = membres(r)
        setAlertes(liste)
        setTotal(r?.totalItems ?? r?.['hydra:totalItems'] ?? liste.length)
      })
      .catch((e) => {
        // Un 404 veut dire « cette session n'est pas celle de l'éditeur » : la coquille le dit mieux.
        if (e instanceof ApiError && e.status === 404) onRefus?.()
        setAlertes(null)
        setErreur(e.message || 'Les alertes n’ont pas pu être chargées.')
      })
      .finally(() => setChargement(false))
  }, [onRefus])

  useEffect(() => { charger() }, [charger])

  return (
    <div className="card">
      <div className="card-h">
        <span>Alertes de scellement NF525</span>
        <div className="r">
          <button className="btn ghost sm" type="button" onClick={() => charger()}>Actualiser</button>
        </div>
      </div>
      <div className="card-b">
        {chargement ? (
          <div className="center"><div className="spinner" /></div>
        ) : erreur ? (
          // En cas d'échec, jamais la liste ni un « tout va bien » : on ne sait pas.
          <EtatErreur message={erreur} onReessayer={() => charger()} />
        ) : (
          <ListeAlertes
            alertes={alertes ?? []}
            total={total}
            filtre={params.filtre}
            onFiltre={(v) => majParams({ filtre: v })}
            onEnregistre={() => charger(true)}
          />
        )}
      </div>
    </div>
  )
}

// La lecture a échoué : seulement l'erreur et « Réessayer ».
export function EtatErreur({ message, onReessayer }) {
  return (
    <div className="banner banner-error" role="alert">
      {message} <b>N’en concluez pas qu’aucune chaîne n’est bloquée</b>&nbsp;: la liste n’a pas été obtenue.
      <div>
        <button type="button" className="btn ghost sm" onClick={onReessayer}>Réessayer</button>
      </div>
    </div>
  )
}

// Présentation pure (testable sans réseau) : la liste, le filtre et les cartes.
export function ListeAlertes({ alertes, total = 0, filtre, onFiltre, onEnregistre }) {
  const visibles = trierAlertes(filtrerAlertes(alertes, filtre))
  const partielle = total > alertes.length
  return (
    <>
      <Tabs onglets={FILTRES} actif={filtre} onChange={(v) => onFiltre?.(v)} />

      {partielle && (
        <div className="banner banner-warn">
          Liste partielle : {alertes.length} alertes affichées sur {total}. Les plus anciennes ne sont pas lues.
        </div>
      )}

      {visibles.length === 0 && (
        <div className="empty">
          <p>
            {partielle
              ? 'Aucune alerte de ce type dans la partie lue de la liste : n’en concluez pas qu’il n’y en a pas.'
              : filtre === 'resolved' ? 'Aucune alerte résolue.' : filtre === 'all' ? 'Aucune alerte.' : 'Aucune alerte ouverte.'}
          </p>
          {!partielle && filtre === 'open' && (
            <p className="hint">C’est l’état normal : une alerte n’existe que si une chaîne fiscale a refusé de se prolonger.</p>
          )}
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
      <pre className="texte-brut">{a.technicalDetail}</pre>
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
        aria-invalid={!valide}
        aria-describedby={`note-cpt-${a.id}`}
      />
      <div id={`note-cpt-${a.id}`} className={valide ? 'hint' : 'hint is-over'} aria-live="polite">
        {longueur} / {NOTE_MAX} caractères{valide ? '' : ` : dépasse la limite de ${NOTE_MAX} caractères`}
        {a.noteAt && a.noteBy && ` — dernière note : ${a.noteBy}, ${formaterDate(a.noteAt)}`}
      </div>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <div className="hint" role="status">{fait ? 'Note enregistrée.' : ''}</div>
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
