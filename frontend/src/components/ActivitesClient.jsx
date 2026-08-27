import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'

/**
 * L'HISTORIQUE DES ÉCHANGES D'UN CLIENT — et le prochain geste.
 *
 * **Pourquoi ce bloc n'est pas un bloc de tâches.** Le module d'assistance traite des demandes
 * **subies**, qui se ferment. Ici, les échanges sont **décidés** et la relation continue : il n'y a
 * rien à fermer. On n'a donc pas mis de case à cocher sur une relance — parce qu'une liste qu'il faut
 * penser à vider ne se vide jamais.
 *
 * > **On ne coche pas une relance : on la remplace en agissant.**
 *
 * C'est pour ça que le bouton dit « J'ai rappelé » et ouvre la saisie d'un nouvel échange, au lieu de
 * proposer « Terminé ». Le geste que l'écran demande est le vrai geste : noter ce qui vient de se
 * passer. La relance disparaît alors d'elle-même, parce qu'elle est **déduite** — elle tient tant
 * qu'aucun échange plus récent n'existe.
 *
 * ⚠ **La liste est chargée entière puis filtrée ici**, comme pour les contacts. `CommercialActivity`
 * porte un `SearchFilter` sur `customer` — famille D58, où le filtre rend soit tout (paramètre
 * ignoré) soit rien (identifiant lié sans type), sans jamais lever. On ne l'emprunte pas : un
 * historique vide ressemble à un client qu'on n'a jamais appelé.
 */

const TYPES = [
  ['call', 'Appel'],
  ['email', 'Courriel'],
  ['meeting', 'Rendez-vous'],
  ['note', 'Note'],
]

const LIB = Object.fromEntries(TYPES)

function quand(v) {
  if (!v) return '—'
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
}

function jourSeul(v) {
  if (!v) return '—'
  const d = new Date(`${v}T12:00:00`)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long' })
}

export default function ActivitesClient({ client, peutModifier }) {
  const [activites, setActivites] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)
  const [saisie, setSaisie] = useState(false)

  const recharger = useCallback(async () => {
    if (!client?.id) return
    setChargement(true)
    try {
      const tous = membres(await api.activitesCommerciales())
      const miennes = tous.filter((a) => {
        const ref = a.customer
        const id = typeof ref === 'string' ? ref.split('/').pop() : ref?.id
        return String(id) === String(client.id)
      })
      // Du plus récent au plus ancien : un historique se lit par le haut.
      miennes.sort((a, b) => String(b.occurredAt || '').localeCompare(String(a.occurredAt || '')))
      setActivites(miennes)
    } catch (e) {
      setErreur(e.message || 'Les échanges n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [client?.id])

  useEffect(() => { recharger() }, [recharger])

  if (!client?.id) return null

  // LA RELANCE EN ATTENTE EST CELLE DU DERNIER ECHANGE, ET D'AUCUN AUTRE.
  // Même règle que le serveur, pour la même raison : si l'écran affichait toutes les dates trouvées,
  // il ressortirait des relances déjà honorées, et la liste deviendrait du bruit qu'on cesse de lire.
  const dernier = activites[0]
  const relance = dernier?.nextActionAt ? dernier : null
  const enRetard = relance && relance.nextActionAt < new Date().toISOString().slice(0, 10)

  return (
    <div>
      <div className="fiche-sec">Échanges commerciaux</div>

      {erreur && <div className="alert crit">{erreur}</div>}

      {relance && (
        <div className={`alert ${enRetard ? 'warn' : 'mut'}`} style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <span style={{ flex: 1, minWidth: 200 }}>
            <strong>{relance.nextAction}</strong>
            <span className="sub"> — {enRetard ? 'à faire depuis le' : 'prévu le'} {jourSeul(relance.nextActionAt)}</span>
          </span>
          {peutModifier && (
            // PAS DE << TERMINE >>. Le geste utile est de noter ce qui s'est passe : c'est ce nouvel
            // echange qui remplace la relance, et qui porte -- ou non -- la suivante.
            <button className="btn sm" type="button" onClick={() => setSaisie(true)}>
              J’ai rappelé →
            </button>
          )}
        </div>
      )}

      {chargement ? (
        <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
      ) : activites.length === 0 ? (
        <div className="sub" style={{ padding: '6px 0' }}>Aucun échange enregistré.</div>
      ) : (
        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 8 }}>
          {activites.map((a) => (
            <li key={a.id} style={{ display: 'grid', gap: 2, borderLeft: '2px solid var(--line)', paddingLeft: 10 }}>
              <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                <span className="badge mut">{LIB[a.type] || a.type}</span>
                <span className="sub" style={{ fontVariantNumeric: 'tabular-nums' }}>{quand(a.occurredAt)}</span>
                {a.author?.nom && <span className="sub">· {a.author.nom}</span>}
              </div>
              <div style={{ whiteSpace: 'pre-wrap' }}>{a.summary}</div>
            </li>
          ))}
        </ul>
      )}

      {peutModifier && !saisie && (
        <button className="btn ghost sm" type="button" style={{ marginTop: 8 }} onClick={() => setSaisie(true)}>
          + Noter un échange
        </button>
      )}

      {saisie && (
        <SaisieEchange
          clientId={client.id}
          busy={busy}
          setBusy={setBusy}
          onFini={async () => { setSaisie(false); await recharger() }}
          onAnnuler={() => setSaisie(false)}
          onErreur={setErreur}
        />
      )}
    </div>
  )
}

/**
 * La saisie d'un échange, et facultativement du prochain geste.
 *
 * **La date de relance et son intitulé vont ensemble.** Le serveur refuse l'une sans l'autre, et
 * l'écran le dit avant l'envoi : six semaines plus tard, « rappeler le 12 » ne dit ni pourquoi ni de
 * quoi parler, et l'appel ne se fait pas.
 */
function SaisieEchange({ clientId, busy, setBusy, onFini, onAnnuler, onErreur }) {
  const [type, setType] = useState('call')
  const [resume, setResume] = useState('')
  const [date, setDate] = useState('')
  const [suite, setSuite] = useState('')

  const incomplet = (date !== '' && suite.trim() === '') || resume.trim() === ''

  async function envoyer() {
    setBusy(true)
    onErreur(null)
    try {
      await api.creerActivite({
        customer: `/api/clients/${clientId}`,
        type,
        summary: resume.trim(),
        ...(date ? { nextActionAt: date, nextAction: suite.trim() } : {}),
      })
      await onFini()
    } catch (e) {
      onErreur(e.message || 'L’échange n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="panel" style={{ padding: 12, marginTop: 10, display: 'grid', gap: 10 }}>
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        {TYPES.map(([v, l]) => (
          <button
            key={v}
            type="button"
            className={`btn sm ${type === v ? 'primary' : 'ghost'}`}
            onClick={() => setType(v)}
          >
            {l}
          </button>
        ))}
      </div>

      <label style={{ display: 'grid', gap: 4 }}>
        <span className="sub">Ce qui s’est dit</span>
        <textarea rows={3} value={resume} onChange={(e) => setResume(e.target.value)} />
      </label>

      <div style={{ display: 'grid', gridTemplateColumns: '150px 1fr', gap: 10 }}>
        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Rappeler le</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </label>
        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Pour faire quoi</span>
          <input
            type="text"
            maxLength={250}
            value={suite}
            placeholder={date ? 'Ex. envoyer le devis annuel' : 'Facultatif'}
            onChange={(e) => setSuite(e.target.value)}
          />
        </label>
      </div>

      {date !== '' && suite.trim() === '' && (
        <div className="sub">Une date seule ne dit pas de quoi parler : indiquez ce qu’il faudra faire.</div>
      )}

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
        <button className="btn ghost sm" type="button" onClick={onAnnuler} disabled={busy}>Annuler</button>
        <button className="btn primary sm" type="button" onClick={envoyer} disabled={busy || incomplet}>
          Enregistrer
        </button>
      </div>
    </div>
  )
}
