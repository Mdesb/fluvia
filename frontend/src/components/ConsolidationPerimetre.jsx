import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'

// LA VUE D'UN DIRIGEANT — celle qui existait côté serveur et qu'aucun écran n'appelait.
//
// ── CE QUI MANQUAIT ─────────────────────────────────────────────────────────────────────────────
//
// `GET /reporting/dashboards/etablissement/{id}` était branché depuis l'origine : c'est la vue d'un
// responsable de site (encaissé du jour, jauges d'occupation). Ses deux voisines —
// `…/region/{id}` et `…/groupe/{id}` — n'étaient appelées par AUCUN écran, alors qu'elles rendent
// exactement ce qu'un dirigeant regarde : le classement de ses sites, l'écart vs objectif, l'écart
// vs n-1 avec son code couleur, et un badge de fraîcheur.
//
// `CalculComparaisonService` calcule ces écarts depuis l'origine. Personne ne les voyait.
//
// ── ⚠ LE PIÈGE DU NOM `comparabiliteRegime` ─────────────────────────────────────────────────────
//
// Il se lit comme une réassurance — « c'est comparable ». C'est l'inverse. Dans
// `AgregateurMesuresService` : `$comparabilite = count($regimes) > 1`. Le drapeau est VRAI quand la
// consolidation mélange plusieurs régimes d'exploitant, et le régime consolidé devient « mixte ».
// Construire cet écran sur la lecture naïve du nom l'aurait fait rassurer là où il doit alerter,
// sur le seul chiffre qu'un dirigeant transmet à son conseil.
//
// ── UN PÉRIMÈTRE SANS MESURE N'EST PAS UN PÉRIMÈTRE À ZÉRO ──────────────────────────────────────
//
// Aujourd'hui la préproduction rend `null` partout : aucune mesure n'est agrégée au niveau région
// ni groupe. C'est donc l'état vide que cet écran affichera le plus souvent au début — il est écrit
// pour celui-là d'abord. Un CA absent ne s'affiche jamais « 0,00 € ».

const PERIMETRES = [['region', 'Ma région'], ['groupe', 'Mon groupe']]

/** `Y-m-d` dans le fuseau de celui qui regarde — jamais `toISOString`, qui rend de l'UTC. */
function jourLocal(d) {
  return d.toLocaleDateString('sv-SE')
}

function periodes() {
  const a = new Date()
  return [
    ['jour', "Aujourd'hui", jourLocal(a), jourLocal(a)],
    ['mois', 'Ce mois-ci', jourLocal(new Date(a.getFullYear(), a.getMonth(), 1)), jourLocal(a)],
    ['mois-1', 'Le mois dernier',
      jourLocal(new Date(a.getFullYear(), a.getMonth() - 1, 1)),
      jourLocal(new Date(a.getFullYear(), a.getMonth(), 0))],
    ['annee', 'Cette année', jourLocal(new Date(a.getFullYear(), 0, 1)), jourLocal(a)],
  ]
}

const CLASSE_COULEUR = { bon: 'badge good', a_surveiller: 'badge warn', critique: 'badge crit' }
const MOT_COULEUR = { bon: 'au-dessus', a_surveiller: 'à surveiller', critique: 'critique' }

function euros(v) {
  if (v === null || v === undefined || v === '') return null
  const n = Number(v)
  return Number.isNaN(n) ? null : n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

/** Un écart, ou l'explication de son absence — jamais une case vide qui se lise « zéro ». */
function Ecart({ titre, ecart, absence }) {
  return (
    <div style={{ minWidth: 190 }}>
      <div className="hint">{titre}</div>
      {!ecart ? (
        <div className="hint"><i>{absence}</i></div>
      ) : (
        <div>
          <span className={CLASSE_COULEUR[ecart.couleur] || 'badge mut'}>
            {ecart.ecartPourcentage !== null
              ? `${Number(ecart.ecartPourcentage) >= 0 ? '+' : ''}${ecart.ecartPourcentage} %`
              : 'référence à zéro'}
          </span>{' '}
          <span className="hint">
            {MOT_COULEUR[ecart.couleur] || 'non qualifié'} · référence {euros(ecart.valeurCible) || ecart.valeurCible}
          </span>
        </div>
      )}
    </div>
  )
}

export default function ConsolidationPerimetre({ etabActif, etablissements }) {
  const PERIODES = useMemo(() => periodes(), [])
  const [portee, setPortee] = useState('region')
  const [presel, setPresel] = useState('mois')
  const [groupesLus, setGroupesLus] = useState(null)
  const [donnees, setDonnees] = useState(null)
  const [lu, setLu] = useState(false)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)
  // ⚠ POURQUOI la lecture a echoue, et pas seulement QU'ELLE a echoue. Le client d'API
  // redige pour un 403 une phrase qui dit quoi faire ; un `catch` qui la jette transforme
  // « il vous manque un droit » en « c'est casse », et envoie chercher au mauvais endroit.
  const [raisonNonLu, setRaisonNonLu] = useState(null)

  const etab = etablissements.find((e) => e.id === etabActif) || null
  const regionId = etab?.region?.id || null
  const regionNom = etab?.region?.nom || null
  const groupeId = (groupesLus || [])[0]?.id || null
  const cibleId = portee === 'region' ? regionId : groupeId

  useEffect(() => {
    let annule = false
    api.groupes()
      .then((r) => { if (!annule) setGroupesLus(membres(r)) })
      .catch((e) => { if (!annule) { setGroupesLus(null); setRaisonNonLu(e) } })
    return () => { annule = true }
  }, [])

  const interroger = useCallback(async () => {
    if (!cibleId) { setDonnees(null); setLu(false); return }
    const p = PERIODES.find((x) => x[0] === presel) || PERIODES[0]
    setChargement(true)
    setErreur(null)
    try {
      const appel = portee === 'region' ? api.dashboardRegion : api.dashboardGroupe
      const d = await appel(cibleId, { periodeDebut: p[2], periodeFin: p[3] })
      setDonnees(d)
      setLu(true)
    } catch (e) {
      setErreur(e?.message || 'La consolidation n’a pas pu être lue.')
      setDonnees(null)
      setLu(false)
    } finally {
      setChargement(false)
    }
  }, [cibleId, portee, presel, PERIODES])

  useEffect(() => { interroger() }, [interroger])

  // ⚠ Le classement vient du serveur, déjà trié par CA décroissant. On ne le retrie pas : deux
  // tris qui divergeraient donneraient deux vérités pour le même écran.
  const lignes = portee === 'region' ? (donnees?.sites || []) : (donnees?.regions || [])
  const mesurees = lignes.filter((l) => l.ca !== null && l.ca !== undefined)
  const partielles = lignes.filter((l) => l.statutCompletude === 'partiel')

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h2>Consolidation</h2>
        <span className="hint">
          {portee === 'region'
            ? `Vos sites${regionNom ? ` — ${regionNom}` : ''}, classés et comparés.`
            : 'Vos régions, classées et comparées.'}
        </span>
      </div>
      <div className="card-b">
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-normal)', alignItems: 'flex-end' }}>
          <div className="field" style={{ margin: 0 }}>
            <label className="field-lbl" htmlFor="cons-por">Périmètre</label>
            <select id="cons-por" className="input" value={portee}
              onChange={(ev) => setPortee(ev.target.value)}>
              {PERIMETRES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label className="field-lbl" htmlFor="cons-per">Période</label>
            <select id="cons-per" className="input" value={presel}
              onChange={(ev) => setPresel(ev.target.value)}>
              {PERIODES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <button className="btn" type="button" onClick={interroger} disabled={chargement || !cibleId}>
            {chargement ? 'Lecture…' : '↻ Relire'}
          </button>
        </div>

        {erreur && (
          <div className="banner banner-error" style={{ marginTop: 'var(--esp-normal)' }}>{erreur}</div>
        )}
        {raisonNonLu?.message && (
          <div className="banner banner-warn" style={{ marginBottom: 'var(--esp-normal)' }}>
            <b>{raisonNonLu.status === 403
                  ? 'Une lecture a été refusée\u00a0:'
                  : 'Une lecture a échoué\u00a0:'}</b> {raisonNonLu.message}
          </div>
        )}

        {!cibleId ? (
          <div className="banner banner-warn" style={{ marginTop: 'var(--esp-normal)' }}>
            {portee === 'region'
              ? 'Le site actif n’est rattaché à aucune région : il n’y a rien à consolider.'
              : 'Aucun groupe connu : il n’y a rien à consolider.'}
          </div>
        ) : !lu ? (
          <div className="banner banner-warn" style={{ marginTop: 'var(--esp-normal)' }}>
            La consolidation n’a pas pu être lue. Ce qui suit n’est pas un état à zéro — c’est un
            écran sans données.
          </div>
        ) : (
          <>
            {/* ⚠ LA FRAÎCHEUR EN PREMIER. Un chiffre consolidé sans date de génération ne se
                transmet pas : celui qui le reçoit ne peut pas savoir de quand il date. */}
            <div className="banner" style={{
              marginTop: 'var(--esp-normal)',
              background: donnees?.fraicheur ? 'var(--accent-soft)' : 'var(--warn-bg)',
            }}>
              {donnees?.fraicheur ? (
                <>Mesures agrégées il y a <b>{donnees.fraicheur.ilYAMinutes} minute(s)</b>.
                  Cette vue lit des mesures pré-calculées, elle n’est pas en temps réel.</>
              ) : (
                <><b>Aucune mesure consolidée pour cette période.</b> Les valeurs ci-dessous sont
                  absentes, pas nulles — l’agrégation n’a rien produit à ce niveau. Les chiffres de
                  chaque site restent visibles dans l’Explorateur.</>
              )}
            </div>

            {/* ⚠ Le nom `comparabiliteRegime` se lit à l'envers : VRAI = plusieurs régimes mélangés. */}
            {donnees?.comparabiliteRegime && (
              <div className="banner banner-warn" style={{ marginTop: 'var(--esp-normal)' }}>
                <b>Cette consolidation mélange plusieurs régimes d’exploitant.</b> Le total n’est pas
                directement comparable d’un site à l’autre, et ne devrait pas être présenté comme un
                agrégat homogène.
                {(donnees.vuesRegimeIsolees || []).length > 0 && (
                  <> Les vues isolées par site sont fournies&nbsp;: {donnees.vuesRegimeIsolees.map((v) => v.nom).join(', ')}.</>
                )}
              </div>
            )}

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-large)', marginTop: 'var(--esp-bloc)' }}>
              <Ecart
                titre="Chiffre d’affaires vs objectif"
                ecart={portee === 'region' ? donnees?.ecartCaVsObjectif : null}
                absence={portee === 'groupe'
                  ? 'non calculé à l’échelle du groupe'
                  : donnees?.fraicheur ? 'aucun objectif posé sur cette période' : 'pas de mesure consolidée'} />
              <Ecart
                titre="Chiffre d’affaires vs année précédente"
                ecart={donnees?.ecartCaVsN1}
                absence={donnees?.fraicheur ? 'pas de mesure sur la période équivalente' : 'pas de mesure consolidée'} />
            </div>

            <h3 style={{ marginTop: 'var(--esp-bloc)' }}>
              {portee === 'region' ? 'Vos sites' : 'Vos régions'}
              <span className="hint" style={{ marginLeft: 'var(--esp-normal)' }}>
                {lignes.length === 0
                  ? 'aucun rattaché'
                  : `${mesurees.length} mesuré(s) sur ${lignes.length}${partielles.length > 0 ? ` · ${partielles.length} partiel(s)` : ''}`}
              </span>
            </h3>

            {lignes.length === 0 ? (
              <p className="hint">
                {portee === 'region' ? 'Aucun site rattaché à cette région.' : 'Aucune région dans ce groupe.'}
              </p>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>{portee === 'region' ? 'Site' : 'Région'}</th>
                      <th className="num">Chiffre d’affaires</th>
                      {portee === 'region' && <th className="num">Entrées</th>}
                      <th>Complétude</th>
                    </tr>
                  </thead>
                  <tbody>
                    {lignes.map((l, i) => (
                      <tr key={l.etablissementId || l.regionId}>
                        <td className="hint">{i + 1}</td>
                        <td>{l.nom}</td>
                        <td className="num">
                          {euros(l.ca) || <span className="hint">non mesuré</span>}
                        </td>
                        {portee === 'region' && (
                          <td className="num">
                            {l.entrees === null || l.entrees === undefined
                              ? <span className="hint">non mesuré</span>
                              : Number(l.entrees).toLocaleString('fr-FR')}
                          </td>
                        )}
                        <td>
                          {l.statutCompletude === null || l.statutCompletude === undefined ? (
                            <span className="hint">—</span>
                          ) : (
                            /* ⚠ TROIS ÉTATS, TROIS TONS. `non_instrumente` n'est pas un défaut
                               — le site n'a pas de source pour cet indicateur (arbitrage n°7) : un
                               badge d'alerte le ferait chercher une panne. Et on n'affiche plus la
                               valeur brute de l'enum. */
                            <span className={{
                              complet: 'badge good',
                              partiel: 'badge warn',
                              non_instrumente: 'badge',
                            }[l.statutCompletude] || 'badge warn'}>
                              {{
                                complet: 'complet',
                                partiel: 'partiel',
                                non_instrumente: 'non instrumenté',
                              }[l.statutCompletude] || l.statutCompletude}
                            </span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </div>
    </section>
  )
}
