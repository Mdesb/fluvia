import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { dateHeureFr, jourLocal } from './Liste.jsx'

/**
 * LE PLANNING DE TRAVAIL — créer un créneau, l'annuler.
 *
 * L'écran Personnel savait déclarer un employé, ses absences et émettre ses badges. Il ne savait
 * pas le PLANIFIER. `POST /personnel/creneaux-travail` et son annulation étaient servies et
 * appelées par personne : un exploitant pouvait constater qu'un poste n'était pas couvert sans
 * jamais pouvoir dire qu'il devait l'être.
 *
 * ── LE CRÉNEAU EST UN BESOIN, PAS UNE AFFECTATION ───────────────────────────────────────────────
 *
 * Il dit « ce poste demande N personnes de telle heure à telle heure », pas « c'est Untel qui le
 * fait ». L'affectation d'un salarié est un second geste, servi par une autre route. L'écran le
 * dit, sinon on cherche longtemps où choisir la personne.
 */
export default function PlanningTravail({ etabActif, droits = [] }) {
  const [creneaux, setCreneaux] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [busy, setBusy] = useState(false)
  const [creation, setCreation] = useState(false)

  const peutGerer = aLeDroit(droits, 'personnel.gerer_planning')

  const recharger = useCallback(async () => {
    setErreur(null)
    try {
      setCreneaux(membres(await api.creneauxTravail()))
    } catch (e) {
      // ⚠ `null` = PAS LU. « Aucun créneau planifié » sur un planning est une affirmation qui
      // fait conclure que personne n'est attendu — et on ne remplace pas quelqu'un qu'on ne
      // sait pas manquant.
      setCreneaux(null)
      setErreur(e.message || 'Le planning n’a pas pu être lu.')
    }
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

  async function annuler(c) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.annulerCreneauTravail(c.id)
      setSucces('Créneau annulé.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’annulation a échoué.')
    } finally {
      setBusy(false)
    }
  }

  const actifs = (creneaux || []).filter((c) => c.statut !== 'annule')

  return (
    <section className="card">
      <div className="card-h">
        <h3>Créneaux de travail</h3>
        <span className="sub">
          {creneaux === null
            ? 'état inconnu — la lecture n’a pas abouti'
            : `${actifs.length} créneau${actifs.length > 1 ? 'x' : ''} planifié${actifs.length > 1 ? 's' : ''}`}
        </span>
        {peutGerer && (
          <div className="r">
            <button className="btn sm" type="button" onClick={() => setCreation(true)}>
              ＋ Planifier un créneau
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {creneaux === null ? (
          <div className="banner banner-error">
            Le planning n’a pas pu être lu. <b>N’en concluez pas qu’aucun poste n’est à
            couvrir</b> : cette liste n’a pas été obtenue.
          </div>
        ) : actifs.length === 0 ? (
          <div className="empty">
            Aucun créneau planifié. Un créneau dit qu’un poste demande du monde à telle heure —
            c’est lui qui rend un manque visible, et qui permet au roster de dire « non couvert ».
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Poste</th>
                  <th>Début</th>
                  <th>Fin</th>
                  <th className="num">Effectif</th>
                  <th>Statut</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {actifs.map((c) => (
                  <tr key={c.id}>
                    <td><span className="nm">{c.libellePoste || '—'}</span></td>
                    <td>{dateHeureFr(c.debut)}</td>
                    <td>{dateHeureFr(c.fin)}</td>
                    <td className="num">{c.effectifRequis ?? '—'}</td>
                    <td><span className="badge mut">{c.statut || '—'}</span></td>
                    {peutGerer && (
                      <td className="num">
                        <button
                          className="btn sm"
                          type="button"
                          disabled={busy}
                          title="Le créneau reste au planning, marqué annulé."
                          onClick={() => annuler(c)}
                        >
                          Annuler
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="hint">
          Un créneau exprime un <b>besoin</b> — « ce poste demande deux personnes de 9 h à 17 h » —
          et non une affectation. Rattacher un salarié à un créneau est un geste distinct.
        </div>
      </div>

      <CreneauModal
        open={creation}
        etabActif={etabActif}
        onClose={() => setCreation(false)}
        onFait={() => { setCreation(false); setSucces('Créneau planifié.'); recharger() }}
      />
    </section>
  )
}

function CreneauModal({ open, etabActif, onClose, onFait }) {
  const [poste, setPoste] = useState('')
  const [jour, setJour] = useState('')
  const [heureDebut, setHeureDebut] = useState('09:00')
  const [heureFin, setHeureFin] = useState('17:00')
  const [effectif, setEffectif] = useState('1')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return
    setPoste('')
    setJour(jourLocal())
    setHeureDebut('09:00')
    setHeureFin('17:00')
    setEffectif('1')
    setErreur(null)
  }, [open])

  // Les gardes locales disent EXACTEMENT ce que le serveur exige, pour que le refus arrive avant
  // l'aller-retour : `libellePoste` non vide, `fin > debut`, `effectifRequis >= 1`. La troisième
  // vient du validateur de classe `TopologieTravailCoherente`, pas d'une contrainte de champ.
  const n = Number(effectif)
  const finApresDebut = Boolean(jour) && heureFin > heureDebut
  const pret = poste.trim() !== '' && finApresDebut && Number.isFinite(n) && n >= 1

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.creerCreneauTravail({
        etablissement: etabActif,
        libellePoste: poste.trim(),
        debut: `${jour}T${heureDebut}:00`,
        fin: `${jour}T${heureFin}:00`,
        effectifRequis: n,
      })
      onFait()
    } catch (err) {
      setErreur(err.message || 'Le créneau n’a pas pu être planifié.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Planifier un créneau de travail">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="field">
          <label htmlFor="cr-poste">Poste *</label>
          <input
            id="cr-poste"
            className="input"
            value={poste}
            maxLength={120}
            placeholder="Surveillance bassin, caisse, accueil…"
            onChange={(e) => setPoste(e.target.value)}
          />
          <span className="hint">Ce qui doit être tenu, pas le nom de la personne.</span>
        </div>

        <div className="field">
          <label htmlFor="cr-jour">Jour *</label>
          <input id="cr-jour" className="input" type="date" value={jour} onChange={(e) => setJour(e.target.value)} />
        </div>

        <div className="row row-champs">
          <div className="field">
            <label htmlFor="cr-debut">Début *</label>
            <input id="cr-debut" className="input" type="time" value={heureDebut} onChange={(e) => setHeureDebut(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="cr-fin">Fin *</label>
            <input id="cr-fin" className="input" type="time" value={heureFin} onChange={(e) => setHeureFin(e.target.value)} />
            {jour && !finApresDebut && (
              <span className="hint">
                La fin doit être après le début. Un créneau de nuit se saisit en deux fois&nbsp;:
                le serveur refuse un intervalle qui traverse minuit.
              </span>
            )}
          </div>
        </div>

        <div className="field">
          <label htmlFor="cr-effectif">Effectif requis *</label>
          <input
            id="cr-effectif"
            className="input"
            type="number"
            min="1"
            step="1"
            value={effectif}
            onChange={(e) => setEffectif(e.target.value)}
          />
          <span className="hint">
            Au moins une personne. C’est ce nombre que le roster compare aux affectations pour
            dire si le poste est couvert.
          </span>
        </div>

        <div className="r">
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn primary" disabled={envoi || !pret}>
            {envoi ? 'Planification…' : 'Planifier'}
          </button>
        </div>
      </form>
    </Modal>
  )
}
