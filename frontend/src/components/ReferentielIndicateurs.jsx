import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

// LE RÉFÉRENTIEL DES INDICATEURS — ce qu'un responsable règle lui-même, et ce qu'il ne doit pas.
//
// ── CE QUI MANQUAIT ─────────────────────────────────────────────────────────────────────────────
//
// `PATCH /api/indicateurs/{id}` existait depuis l'origine et aucun écran ne l'appelait : un
// libellé, un seuil de complétude ou la mise au repos d'un indicateur se corrigeaient en base.
//
// ── L'API ACCEPTE HUIT CHAMPS ; CET ÉCRAN EN OFFRE TROIS, ET C'EST DÉLIBÉRÉ ──────────────────────
//
// Le groupe `indicateur:write` couvre aussi `code`, `modeCalcul`, `nature` et `sourceModule`. Les
// exposer ici serait offrir à quelqu'un qui pilote des boutons qui réécrivent silencieusement le
// passé :
//
//   `code`        — MESURÉ : `Mesure::calculerCleAgregation()` construit la clé à partir du code,
//                   et la base porte 2 068 mesures pour 2 068 clés distinctes. Changer un code
//                   détache tout l'historique de cet indicateur, sans erreur et sans trace.
//   `modeCalcul`  — passer de `somme` à `moyenne` change le sens de ce qui a déjà été agrégé : les
//                   anciennes mesures restent des sommes, les nouvelles deviennent des moyennes, et
//                   la même colonne mélange les deux.
//   `nature`, `sourceModule` — décrivent d'où vient la donnée et comment le moteur l'assemble.
//
// Ce ne sont pas des réglages de gestion, ce sont des définitions de calcul. Elles se changent avec
// une reprise de l'historique, pas depuis un écran de pilotage. Le dire à l'écran vaut mieux que
// de le taire : la question « pourquoi je ne peux pas renommer le code ? » a une réponse.

const UNITES = { euro: '€', nombre: 'nombre', pourcentage: '%', ratio: 'ratio' }

export default function ReferentielIndicateurs({ droits }) {
  // `undefined` = pas encore demande ; `null` = demande et echoue ; tableau = lu.
  // Sans ce troisieme etat, le rendu affirmait un echec avant la premiere reponse.
  const [indicateursLus, setIndicateursLus] = useState()
  const indicateurs = indicateursLus || []
  const [edition, setEdition] = useState(null)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)
  // ⚠ POURQUOI la lecture a echoue, et pas seulement QU'ELLE a echoue. Le client d'API
  // redige pour un 403 une phrase qui dit quoi faire ; un `catch` qui la jette transforme
  // « il vous manque un droit » en « c'est casse », et envoie chercher au mauvais endroit.
  const [raisonNonLu, setRaisonNonLu] = useState(null)
  const [succes, setSucces] = useState(null)

  const peutConfigurer = aLeDroit(droits, 'reporting.configurer')

  const recharger = useCallback(() => {
    api.indicateurs()
      .then((r) => setIndicateursLus(membres(r)))
      .catch((e) => { setIndicateursLus(null); setRaisonNonLu(e) })
  }, [])

  useEffect(() => {
    let annule = false
    api.indicateurs()
      .then((r) => { if (!annule) setIndicateursLus(membres(r)) })
      .catch((e) => { if (!annule) { setIndicateursLus(null); setRaisonNonLu(e) } })
    return () => { annule = true }
  }, [])

  async function enregistrer(e) {
    e.preventDefault()
    if (!edition) return
    setErreur(null)
    setSucces(null)
    setEnCours(true)
    try {
      const seuil = String(edition.seuil).trim()
      await api.modifierIndicateur(edition.id, {
        libelle: edition.libelle.trim(),
        seuilCompletudeMinutes: seuil === '' ? null : Number(seuil),
      })
      setSucces(`« ${edition.libelle.trim()} » enregistré.`)
      setEdition(null)
      recharger()
    } catch (err) {
      setErreur(err?.message || 'La modification n’a pas pu être enregistrée.')
    } finally {
      setEnCours(false)
    }
  }

  async function basculerActif(i) {
    setErreur(null)
    setSucces(null)
    try {
      await api.modifierIndicateur(i.id, { actif: !i.actif })
      recharger()
    } catch (err) {
      setErreur(err?.message || 'L’état n’a pas pu être changé.')
    }
  }

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h2>Référentiel des indicateurs</h2>
        <span className="hint">
          {indicateursLus === undefined
            ? 'lecture…'
            : indicateursLus === null
            ? 'non lu'
            : `${indicateurs.length} indicateur(s), dont ${indicateurs.filter((i) => i.actif !== false).length} actif(s)`}
        </span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-normal)' }}>{erreur}</div>}
        {raisonNonLu?.message && (
          <div className="banner banner-warn" style={{ marginBottom: 'var(--esp-normal)' }}>
            <b>{raisonNonLu.status === 403
                  ? 'Une lecture a été refusée\u00a0:'
                  : 'Une lecture a échoué\u00a0:'}</b> {raisonNonLu.message}
          </div>
        )}
        {succes && <div className="banner banner-ok" style={{ marginBottom: 'var(--esp-normal)' }}>{succes}</div>}

        {indicateursLus === undefined ? (
          <p className="hint">Lecture…</p>
        ) : indicateursLus === null ? (
          <div className="banner banner-warn">
            Le référentiel n’a pas pu être lu. Ce qui existe n’est pas affiché ici — n’en concluez
            pas qu’aucun indicateur n’est défini.
          </div>
        ) : indicateurs.length === 0 ? (
          <p className="hint">Aucun indicateur au référentiel.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Libellé</th>
                  <th>Code</th>
                  <th>Unité</th>
                  <th>Source</th>
                  <th>Seuil de complétude</th>
                  <th>État</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {indicateurs.map((i) => (
                  <tr key={i.id}>
                    <td>{i.libelle || <span className="hint">sans libellé</span>}</td>
                    <td><code>{i.code}</code></td>
                    <td>{UNITES[i.unite] || i.unite}</td>
                    <td>{i.sourceModule}</td>
                    <td>
                      {i.seuilCompletudeMinutes === null || i.seuilCompletudeMinutes === undefined
                        ? <span className="hint">aucun</span>
                        : `${i.seuilCompletudeMinutes} min`}
                    </td>
                    <td>
                      <span className={i.actif !== false ? 'badge good' : 'badge mut'}>
                        {i.actif !== false ? 'actif' : 'au repos'}
                      </span>
                    </td>
                    <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                      {peutConfigurer && (
                        <>
                          <button className="btn ghost sm" type="button"
                            onClick={() => setEdition({
                              id: i.id,
                              libelle: i.libelle || '',
                              seuil: i.seuilCompletudeMinutes ?? '',
                            })}>
                            Modifier
                          </button>{' '}
                          <button className="btn ghost sm" type="button" onClick={() => basculerActif(i)}>
                            {i.actif !== false ? 'Mettre au repos' : 'Réactiver'}
                          </button>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {edition && (
          <form onSubmit={enregistrer} style={{ marginTop: 'var(--esp-bloc)' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-normal)', alignItems: 'flex-end' }}>
              <div className="field" style={{ margin: 0, minWidth: 240 }}>
                <label className="field-lbl" htmlFor="ind-lib">Libellé</label>
                <input id="ind-lib" className="input" type="text" value={edition.libelle}
                  onChange={(ev) => setEdition({ ...edition, libelle: ev.target.value })} />
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label className="field-lbl" htmlFor="ind-seuil">Seuil de complétude (minutes)</label>
                <input id="ind-seuil" className="input" type="number" min="0" value={edition.seuil}
                  onChange={(ev) => setEdition({ ...edition, seuil: ev.target.value })} />
              </div>
              <button className="btn primary" type="submit"
                disabled={enCours || edition.libelle.trim() === ''}>
                {enCours ? 'Enregistrement…' : 'Enregistrer'}
              </button>
              <button className="btn ghost" type="button" onClick={() => setEdition(null)}>Annuler</button>
            </div>
            <p className="hint" style={{ marginTop: 'var(--esp-serre)' }}>
              Le seuil dit au bout de combien de minutes une mesure non remontée est comptée comme
              incomplète. Le laisser vide retire ce contrôle pour cet indicateur.
            </p>
          </form>
        )}

        <p className="hint" style={{ marginTop: 'var(--esp-bloc)' }}>
          Le <b>code</b>, le <b>mode de calcul</b>, la <b>nature</b> et le <b>module source</b> ne se
          modifient pas ici, et ce n’est pas un oubli. Le code sert de clé aux mesures déjà
          agrégées&nbsp;: le changer détacherait l’historique de cet indicateur sans le dire. Changer
          le mode de calcul ferait cohabiter dans la même colonne des sommes anciennes et des
          moyennes nouvelles. Ce sont des définitions de calcul&nbsp;: elles se reprennent avec
          l’historique, pas depuis un écran de pilotage.
        </p>
      </div>
    </section>
  )
}
