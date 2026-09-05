import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { api, membres, ApiError } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'

/**
 * RAPPROCHEMENT BANCAIRE (T26).
 *
 * Chaque ligne de relevé se rapproche d'une écriture comptable (ligne 512 scellée, même compte que le
 * compte bancaire, montant exact au centime, dans une fenêtre de dates). Le serveur ne rapproche
 * JAMAIS tout seul : il suggère, on confirme. Confirmer, c'est lettrer — irréversible dans ce sens —
 * donc l'écran montre l'écriture visée avant le geste, et n'offre « ignorer » qu'avec un motif.
 */
const STATUTS = [
  ['unmatched', 'À rapprocher'],
  ['suggested', 'Suggérées'],
  ['reconciled', 'Rapprochées'],
  ['ignored', 'Ignorées'],
]
const LIBELLE_STATUT = { unmatched: 'à rapprocher', suggested: 'suggérée', reconciled: 'rapprochée', ignored: 'ignorée' }
const BADGE_STATUT = { unmatched: 'mut', suggested: 'info', reconciled: 'good', ignored: 'warn' }

export default function RapprochementBancaire({ etabActif, droits }) {
  const [comptes, setComptes] = useState([])
  const [compte, setCompte] = useState('')
  const [statut, setStatut] = useState('unmatched')
  const [lignes, setLignes] = useState([])
  const [lignesLues, setLignesLues] = useState(false)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [rapprocher, setRapprocher] = useState(null)
  const [ignorer, setIgnorer] = useState(null)

  const peutRapprocher = aLeDroit(droits, 'finance.treasury_reconcile')

  const chargerComptes = useCallback(async () => {
    try {
      const c = membres(await api.comptesBancaires())
      setComptes(c)
      setCompte((prec) => prec || c[0]?.id || '')
    } catch (e) {
      setErreur(e.message)
    }
  }, [etabActif])

  const chargerLignes = useCallback(async () => {
    if (!compte) { setLignes([]); setLignesLues(true); setChargement(false); return }
    setChargement(true)
    try {
      const l = membres(await api.lignesReleve({
        'statementImport.bankAccount': compte,
        status: statut,
        itemsPerPage: 100,
      }))
      setLignes(l)
      setLignesLues(true)
    } catch (e) {
      setErreur(e.message)
      setLignesLues(false)
    } finally {
      setChargement(false)
    }
  }, [compte, statut])

  useEffect(() => { chargerComptes() }, [chargerComptes])
  useEffect(() => { chargerLignes() }, [chargerLignes])

  const compteCourant = comptes.find((c) => c.id === compte)
  const sansCompte512 = compteCourant && !compteCourant.ledgerAccount

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}
      {sansCompte512 && (
        <div className="banner banner-warn">
          Ce compte n’a pas de compte comptable 512 rattaché : aucune suggestion de rapprochement n’est possible.
          Rattachez-en un dans l’onglet « Comptes ».
        </div>
      )}

      <section className="card">
        <div className="card-h">
          <h3>Rapprochement</h3>
          <span className="sub">{lignes.length} ligne{lignes.length > 1 ? 's' : ''}</span>
        </div>
        <div className="card-b">
          <div className="row row-champs" style={{ gap: 'var(--esp-large)', alignItems: 'flex-end', flexWrap: 'wrap' }}>
            <div className="field" style={{ flex: 1, marginBottom: 0 }}>
              <label htmlFor="rap-compte">Compte bancaire</label>
              <select id="rap-compte" className="input" value={compte} onChange={(e) => setCompte(e.target.value)}>
                {comptes.length === 0 && <option value="">— aucun compte —</option>}
                {comptes.map((c) => (
                  <option key={c.id} value={c.id}>{c.label}{c.ibanLast4 ? ` — •••• ${c.ibanLast4}` : ''}</option>
                ))}
              </select>
            </div>
            <div className="field" style={{ flex: 1, marginBottom: 0 }}>
              <label htmlFor="rap-statut">État</label>
              <select id="rap-statut" className="input" value={statut} onChange={(e) => setStatut(e.target.value)}>
                {STATUTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
          </div>
        </div>
      </section>

      <section className="card">
        <div className="card-b">
          {chargement ? (
            <div className="center"><div className="spinner" /></div>
          ) : !lignesLues ? (
            <div className="empty">
              Les lignes n’ont pas pu être lues. Ce compte a peut-être des mouvements à
              rapprocher — cet écran ne le sait pas.
            </div>
          ) : lignes.length === 0 ? (
            <div className="empty">Aucune ligne « {LIBELLE_STATUT[statut]} » sur ce compte.</div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Libellé</th>
                  <th className="num">Montant</th>
                  <th>État</th>
                  {peutRapprocher && <th />}
                </tr>
              </thead>
              <tbody>
                {lignes.map((l) => (
                  <tr key={l.id}>
                    <td className="mono">{l.operationDate ? String(l.operationDate).slice(0, 10) : '—'}</td>
                    <td><span className="nm">{l.label}</span>{l.reference ? <span className="sub"> · {l.reference}</span> : null}</td>
                    <td className="num">{euros(l.amount)}</td>
                    <td><span className={`badge ${BADGE_STATUT[l.status] || 'mut'}`}>{LIBELLE_STATUT[l.status] || l.status}</span></td>
                    {peutRapprocher && (
                      <td className="num">
                        {(l.status === 'unmatched' || l.status === 'suggested') && (
                          <div className="row" style={{ gap: 'var(--esp-serre)', justifyContent: 'flex-end' }}>
                            <button className="btn ghost sm" type="button" onClick={() => setRapprocher(l)}>Rapprocher</button>
                            <button className="btn ghost sm" type="button" onClick={() => setIgnorer(l)}>Ignorer</button>
                          </div>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>

      {rapprocher && (
        <ModaleRapprochement
          ligne={rapprocher}
          onAnnuler={() => setRapprocher(null)}
          onFait={async (msg) => {
            setRapprocher(null)
            setSucces(msg)
            setErreur(null)
            await chargerLignes()
          }}
        />
      )}
      {ignorer && (
        <ModaleIgnorer
          ligne={ignorer}
          onAnnuler={() => setIgnorer(null)}
          onFait={async (msg) => {
            setIgnorer(null)
            setSucces(msg)
            setErreur(null)
            await chargerLignes()
          }}
        />
      )}
    </>
  )
}

function ModaleRapprochement({ ligne, onAnnuler, onFait }) {
  const [candidats, setCandidats] = useState(null)
  const [choix, setChoix] = useState([])
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let vivant = true
    api.suggestionsLigneReleve(ligne.id)
      .then((r) => { if (vivant) setCandidats(Array.isArray(r) ? r : (r?.member ?? [])) })
      // ⚠ Pas de repli sur [] : « aucune écriture correspondante » est une conclusion, et une
      // lecture ratée n'en autorise aucune.
      .catch((e) => { if (vivant) setErreur(e.message) })
    return () => { vivant = false }
  }, [ligne.id])

  const basculer = (id) => setChoix((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]))

  async function confirmer() {
    setBusy(true)
    setErreur(null)
    try {
      await api.rapprocherLigneReleve(ligne.id, { ledgerLineIds: choix })
      await onFait('Ligne rapprochée.')
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) setErreur('Cette écriture est déjà lettrée, ou la ligne est déjà rapprochée.')
      else setErreur(e.message || 'Le rapprochement a échoué.')
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onAnnuler} titre="Rapprocher la ligne">
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <p className="hint">
        {ligne.label} — <b>{euros(ligne.amount)}</b> le {String(ligne.operationDate).slice(0, 10)}.
        Sélectionnez l’écriture comptable correspondante.
      </p>
      {candidats === null ? (
        <div className="center"><div className="spinner" /></div>
      ) : candidats.length === 0 ? (
        <div className="empty">
          {erreur
            ? 'Les suggestions n’ont pas pu être lues : on ne sait pas s’il existe une écriture correspondante.'
            : 'Aucune écriture correspondante (même compte 512, même montant, dates proches).'}
        </div>
      ) : (
        <table className="tbl">
          <thead><tr><th /><th>Date</th><th>Libellé</th><th className="num">Montant</th></tr></thead>
          <tbody>
            {candidats.map((c) => (
              <tr key={c.ledgerLineId}>
                <td>
                  <input
                    type="checkbox"
                    aria-label={`Sélectionner l’écriture du ${c.date}, ${euros((c.amountCents ?? 0) / 100)}`}
                    checked={choix.includes(c.ledgerLineId)}
                    onChange={() => basculer(c.ledgerLineId)}
                  />
                </td>
                <td className="mono">{c.date}</td>
                <td><span className="nm">{c.label}</span></td>
                <td className="num">{euros((c.amountCents ?? 0) / 100)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <div className="row" style={{ justifyContent: 'flex-end', gap: 'var(--esp-normal)' }}>
        <button className="btn ghost" type="button" onClick={onAnnuler} disabled={busy}>Annuler</button>
        <button className="btn primary" type="button" onClick={confirmer} disabled={busy || choix.length === 0}>
          {busy ? 'Rapprochement…' : `Rapprocher${choix.length > 1 ? ` (${choix.length})` : ''}`}
        </button>
      </div>
    </Modal>
  )
}

function ModaleIgnorer({ ligne, onAnnuler, onFait }) {
  const [motif, setMotif] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  async function confirmer(e) {
    e.preventDefault()
    setBusy(true)
    setErreur(null)
    try {
      await api.ignorerLigneReleve(ligne.id, { reason: motif })
      await onFait('Ligne ignorée.')
    } catch (err) {
      setErreur(err.message || 'Impossible d’ignorer la ligne.')
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onAnnuler} titre="Ignorer la ligne">
      <form onSubmit={confirmer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <p className="hint">{ligne.label} — {euros(ligne.amount)}. Une ligne ignorée sort du rapprochement ; le motif reste au journal.</p>
        <div className="field">
          <label htmlFor="ign-motif">Motif</label>
          <input id="ign-motif" className="input" value={motif} onChange={(e) => setMotif(e.target.value)} required placeholder="Ex. frais bancaire déjà comptabilisé autrement" />
        </div>
        <div className="row" style={{ justifyContent: 'flex-end', gap: 'var(--esp-normal)' }}>
          <button className="btn ghost" type="button" onClick={onAnnuler} disabled={busy}>Annuler</button>
          <button className="btn primary" type="submit" disabled={busy || !motif.trim()}>{busy ? '…' : 'Ignorer'}</button>
        </div>
      </form>
    </Modal>
  )
}
