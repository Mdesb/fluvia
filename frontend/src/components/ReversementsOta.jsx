import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from './Modal.jsx'

/**
 * LES REVERSEMENTS AUX PARTENAIRES — ce qu'on doit, et qui l'a touché.
 *
 * Trois routes servies, aucune appelée : lire les reversements, en générer un pour une période, le
 * marquer versé. Un musée qui vend par des plateformes partenaires leur doit une commission
 * période après période — et rien ne permettait ni de calculer cette dette, ni de dire qu'elle
 * était éteinte.
 *
 * ⚠ LE NOM DU PARTENAIRE N'ARRIVE PAS AVEC LE REVERSEMENT. `PartenaireOTA::$nom` n'est pas dans le
 * groupe `reversement_ota:read` : seul l'identifiant l'est. On résout donc depuis la liste des
 * partenaires — et on distingue « partenaires non lus » de « partenaire inconnu », qui ne se
 * corrigent pas de la même façon.
 */

const LIB_STATUT = {
  a_verser: ['à verser', 'warn'],
  verse: ['versé', 'good'],
}

function jourFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

export default function ReversementsOta({ etabActif, droits = [] }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [reversements, setReversements] = useState(null)
  const [partenaires, setPartenaires] = useState(null)
  const [generation, setGeneration] = useState(false)
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(null)

  const peutGerer = aLeDroit(droits, 'musee.gerer_ota')

  function charger() {
    setReversements(null)
    api.museeReversements()
      .then((r) => setReversements(membres(r)))
      .catch(() => setReversements(undefined))
  }

  useEffect(charger, [etabActif])

  useEffect(() => {
    api.museePartenairesOta()
      .then((r) => setPartenaires(membres(r)))
      .catch(() => setPartenaires(undefined))
  }, [etabActif])

  function nomPartenaire(ref) {
    if (!ref) return null
    const id = typeof ref === 'string' ? String(ref).split('/').pop() : String(ref.id || '')
    if (!Array.isArray(partenaires)) return undefined
    const p = partenaires.find((x) => String(x.id) === id)
    return p ? p.nom : null
  }

  async function marquerVerse(r) {
    setBusy(r.id)
    setErreur(null)
    try {
      await api.marquerReversementVerse(r.id)
      setSucces('Reversement marqué versé.')
      charger()
    } catch (e) {
      setErreur(e.message || 'Le reversement n’a pas pu être marqué versé.')
    } finally {
      setBusy(null)
    }
  }

  const aVerser = Array.isArray(reversements) ? reversements.filter((r) => r.statut === 'a_verser') : []

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Reversements aux partenaires</h3>
        <span className="sub">
          {reversements === null
            ? 'lecture…'
            : reversements === undefined
              ? 'illisible'
              : `${aVerser.length} à verser`}
        </span>
        {peutGerer && (
          <button
            className="btn primary sm"
            type="button"
            style={{ marginLeft: 'auto' }}
            onClick={() => { setGeneration(true); setErreur(null); setSucces(null) }}
          >
            Générer un reversement
          </button>
        )}
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {reversements === undefined && (
          <div className="banner banner-warn">
            Les reversements n’ont pas pu être lus. Cet écran ne sait donc pas ce que vous devez —
            ce n’est pas la même chose que « vous ne devez rien ».
          </div>
        )}

        {partenaires === undefined && Array.isArray(reversements) && reversements.length > 0 && (
          <div className="banner banner-warn">
            Les partenaires n’ont pas pu être lus&nbsp;: les lignes ci-dessous ne peuvent pas dire à
            qui elles se rapportent.
          </div>
        )}

        {reversements === null && <div className="empty">Lecture des reversements…</div>}

        {Array.isArray(reversements) && reversements.length === 0 && (
          <div className="empty">
            Aucun reversement. Générez-en un pour une période et un partenaire&nbsp;: le montant est
            calculé à partir des ventes de cette période.
          </div>
        )}

        {Array.isArray(reversements) && reversements.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Partenaire</th>
                  <th>Période</th>
                  <th className="num">Montant</th>
                  <th>État</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {reversements.map((r) => {
                  const [mot, ton] = LIB_STATUT[r.statut] || [r.statut, 'mut']
                  const nom = nomPartenaire(r.partenaire)
                  return (
                    <tr key={r.id}>
                      <td>
                        {nom === undefined
                          ? <span className="sub">partenaires non lus</span>
                          : nom || <span className="sub">partenaire inconnu</span>}
                      </td>
                      <td>{jourFr(r.periodeDebut)} → {jourFr(r.periodeFin)}</td>
                      <td className="num">{r.montant}</td>
                      <td><span className={`badge ${ton}`}>{mot}</span></td>
                      {peutGerer && (
                        <td>
                          {/* ⚠ UN SEUL GESTE, ET SEULEMENT SUR CE QUI RESTE DÛ. Marquer versé un
                              reversement déjà versé n'a pas de sens ; le proposer ferait douter de
                              ce que l'état affiche. */}
                          {r.statut === 'a_verser' && (
                            <button
                              className="btn sm"
                              type="button"
                              disabled={busy === r.id}
                              title="Le montant reste au registre ; seul son état change."
                              onClick={() => marquerVerse(r)}
                            >
                              {busy === r.id ? 'Enregistrement…' : 'Marquer versé'}
                            </button>
                          )}
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <GenerationReversement
        ouvert={generation}
        partenaires={partenaires}
        onFermer={() => setGeneration(false)}
        onFait={(m) => { setGeneration(false); setSucces(m); charger() }}
        onErreur={setErreur}
      />
    </section>
  )
}

/**
 * ⚠ LE MONTANT N'EST PAS SAISI, IL EST CALCULÉ. Le serveur le déduit des ventes de la période pour
 * ce partenaire — c'est pourquoi la fenêtre ne demande qu'un partenaire et deux dates. Offrir un
 * champ « montant » inviterait à corriger à la main un chiffre que la comptabilité doit pouvoir
 * refaire, et personne ne saurait plus lequel des deux fait foi.
 */
function GenerationReversement({ ouvert, partenaires, onFermer, onFait, onErreur }) {
  const [partenaire, setPartenaire] = useState('')
  const [debut, setDebut] = useState('')
  const [fin, setFin] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!ouvert) return
    setPartenaire(''); setDebut(''); setFin('')
  }, [ouvert])

  // La borne haute ne peut pas précéder la borne basse. Le serveur le refuserait ; le dire ici
  // évite un aller-retour pour une faute de frappe.
  const ordre = debut !== '' && fin !== '' && fin < debut
  const pret = partenaire !== '' && debut !== '' && fin !== '' && !ordre

  async function generer() {
    setBusy(true)
    onErreur(null)
    try {
      await api.genererReversement({
        partenaire: `/api/musee_partenaire_otas/${partenaire}`,
        periodeDebut: debut,
        periodeFin: fin,
      })
      await onFait('Reversement généré.')
    } catch (e) {
      onErreur(e.message || 'Le reversement n’a pas pu être généré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={ouvert} onClose={onFermer} titre="Générer un reversement" taille="sm">
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div className="sub">
          Le montant est calculé par le serveur à partir des ventes de la période pour ce
          partenaire. Vous ne le saisissez pas&nbsp;: c’est ce qui permet de le refaire à
          l’identique si on le conteste.
        </div>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Partenaire *</span>
          {partenaires === undefined ? (
            <span className="sub">Les partenaires n’ont pas pu être lus.</span>
          ) : (
            <select className="select" value={partenaire} onChange={(e) => setPartenaire(e.target.value)}>
              <option value="">— choisir —</option>
              {(partenaires || []).map((p) => <option key={p.id} value={p.id}>{p.nom}</option>)}
            </select>
          )}
          {Array.isArray(partenaires) && partenaires.length === 0 && (
            <span className="sub">
              Aucun partenaire n’est déclaré. Créez-en un dans la boutique en ligne avant de générer
              un reversement.
            </span>
          )}
        </label>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-normal)' }}>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Du *</span>
            <input className="input" type="date" value={debut} onChange={(e) => setDebut(e.target.value)} />
          </label>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Au *</span>
            <input className="input" type="date" value={fin} onChange={(e) => setFin(e.target.value)} />
          </label>
        </div>

        {ordre && (
          <div className="banner banner-warn">
            La fin de période précède son début.
          </div>
        )}

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="button" disabled={busy || !pret} onClick={generer}>
            {busy ? 'Génération…' : 'Générer'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
