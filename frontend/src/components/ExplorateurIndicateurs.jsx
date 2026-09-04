import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'

// L'EXPLORATEUR — le seul endroit d'où l'on peut interroger le moteur d'analyse.
//
// ── CE QUI MANQUAIT, ET CE QUI NE MANQUAIT PAS ──────────────────────────────────────────────────
//
// Le module Reporting porte sept ressources — axes analytiques, mesures, indicateurs, objectifs,
// tableaux de bord, rapports planifiés, exports — et l'écran de Pilotage n'en appelait AUCUNE : il
// lisait le tableau de bord d'établissement et la supervision, rien d'autre. `api.indicateurs()`
// était déclaré dans le client HTTP et appelé par zéro écran.
//
// La route `GET /reporting/explorateur` existait déjà, complète, et personne ne l'appelait. Ce
// composant ne construit donc rien de neuf côté serveur : il rend atteignable ce qui l'était déjà.
//
// ── UNE MESURE ABSENTE N'EST PAS UN ZÉRO, ET C'EST TOUT L'ENJEU DE CET ÉCRAN ────────────────────
//
// L'endpoint rend **404** quand aucune mesure ne couvre le jour demandé — son propre message le dit :
// « Aucune mesure pour cette combinaison — relancer reporting:agreger ». Afficher `0` dans ce cas
// serait le pire défaut possible sur un écran d'analyse : un chiffre d'affaires absent parce que
// l'agrégation n'a pas tourné se lirait comme une journée sans vente.
//
// Chaque jour porte donc l'un de trois états, jamais deux :
//
//     une valeur        la mesure existe
//     « pas agrégé »    404 — l'agrégation n'a pas couvert ce jour
//     « pas lu »        la requête a échoué, on ne sait rien
//
// ── LA COMPLÉTUDE SE MONTRE, ELLE NE SE RANGE PAS EN BAS DE PAGE ────────────────────────────────
//
// Une mesure de groupe agrégée alors qu'un site n'a pas remonté ses données est `partiel`, et elle
// nomme les sites manquants. Un total partiel affiché comme un total est un nombre faux qui a l'air
// juste — la famille de défauts que ce produit passe son temps à corriger. La pastille est donc à
// côté de la valeur, pas dans une note.
//
// ── POURQUOI UNE REQUÊTE PAR JOUR ───────────────────────────────────────────────────────────────
//
// Les mesures sont stockées à la granularité du JOUR, et `Periode::depuisDates` cherche une mesure
// dont la période correspond exactement : demander une plage rend 404, vérifié. La série est donc
// composée ici, un appel par jour. Sur quatorze jours c'est quatorze requêtes — acceptable pour un
// écran qu'on ouvre à la demande, et honnête : rien n'est inventé entre deux points.

const NIVEAUX = [
  ['etablissement', 'Ce site'],
  ['region', 'Sa région'],
  ['groupe', 'Le groupe'],
]

const JOURS = [7, 14, 30]

/** `Y-m-d` dans le fuseau de celui qui regarde — jamais `toISOString`, qui rend de l'UTC. */
function jourLocal(d) {
  return d.toLocaleDateString('sv-SE')
}

function joursAvant(n) {
  const out = []
  for (let i = n - 1; i >= 0; i--) {
    const d = new Date()
    d.setDate(d.getDate() - i)
    out.push(jourLocal(d))
  }
  return out
}

function formatValeur(valeur, unite) {
  const n = Number(valeur)
  if (Number.isNaN(n)) return String(valeur)
  if (unite === 'euro') return n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
  if (unite === 'pourcentage') return `${n.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`
  return n.toLocaleString('fr-FR', { maximumFractionDigits: 2 })
}

export default function ExplorateurIndicateurs({ etabActif, etablissements = [] }) {
  // ⚠ `null` = PAS LU, `[]` = LU ET VIDE. Convention de la maison.
  const [indicateursLus, setIndicateursLus] = useState(null)
  const indicateurs = indicateursLus || []
  const [groupesLus, setGroupesLus] = useState(null)

  const [code, setCode] = useState('')
  const [niveau, setNiveau] = useState('etablissement')
  const [nbJours, setNbJours] = useState(14)
  const [points, setPoints] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)

  const etab = etablissements.find((e) => e.id === etabActif) || null
  const regionId = etab?.region?.id || null
  const groupeId = (groupesLus || [])[0]?.id || null

  useEffect(() => {
    let annule = false
    api.indicateurs()
      .then((r) => { if (!annule) setIndicateursLus(membres(r).filter((i) => i.actif !== false)) })
      .catch(() => { if (!annule) setIndicateursLus(null) })
    api.groupes()
      .then((r) => { if (!annule) setGroupesLus(membres(r)) })
      .catch(() => { if (!annule) setGroupesLus(null) })
    return () => { annule = true }
  }, [])

  // Le premier indicateur actif sert de choix par défaut, pour que l'écran ouvre sur quelque chose.
  useEffect(() => {
    if (!code && indicateurs.length > 0) setCode(indicateurs[0].code)
  }, [code, indicateurs])

  const entiteId = niveau === 'etablissement' ? etabActif : niveau === 'region' ? regionId : groupeId
  const indicateur = indicateurs.find((i) => i.code === code) || null

  const interroger = useCallback(async () => {
    if (!code || !entiteId) return
    setChargement(true)
    setErreur(null)
    const jours = joursAvant(nbJours)
    // ⚠ Un appel par jour : voir l'en-tête. On les lance ensemble, mais chaque jour garde SON
    // verdict — un échec sur un jour ne doit pas effacer les autres, ni se faire passer pour zéro.
    const resultats = await Promise.all(jours.map(async (jour) => {
      try {
        const r = await api.explorerIndicateur({
          indicateur: code, niveau, entiteId, periodeDebut: jour, periodeFin: jour,
        })
        return { jour, etat: 'mesure', valeur: r?.valeur, completude: r?.statutCompletude,
          sitesManquants: r?.sitesManquants || [] }
      } catch (e) {
        // 404 = l'agrégation n'a pas couvert ce jour. Tout le reste = on n'a pas pu lire.
        return { jour, etat: e?.status === 404 ? 'absent' : 'nonLu', message: e?.message }
      }
    }))
    setPoints(resultats)
    const echecs = resultats.filter((p) => p.etat === 'nonLu')
    if (echecs.length === resultats.length) setErreur(echecs[0]?.message || 'Lecture impossible.')
    setChargement(false)
  }, [code, niveau, entiteId, nbJours])

  useEffect(() => { interroger() }, [interroger])

  const mesures = useMemo(() => (points || []).filter((p) => p.etat === 'mesure'), [points])
  const maxi = useMemo(
    () => mesures.reduce((m, p) => Math.max(m, Math.abs(Number(p.valeur) || 0)), 0),
    [mesures],
  )
  const partiels = mesures.filter((p) => p.completude === 'partiel').length
  const absents = (points || []).filter((p) => p.etat === 'absent').length

  const niveauIndisponible = niveau !== 'etablissement' && !entiteId

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Explorateur</h3>
        <span className="sub">un indicateur, un périmètre, une période</span>
      </div>
      <div className="card-b">
        {indicateursLus === null ? (
          <div className="empty">
            Le référentiel des indicateurs n’a pas pu être lu&nbsp;: cette liste est vide parce que
            la lecture a échoué, pas parce qu’aucun indicateur n’est défini.
          </div>
        ) : (
          <>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-normal)', alignItems: 'flex-end' }}>
              <div className="field" style={{ margin: 0, minWidth: 220 }}>
                <label className="field-lbl" htmlFor="expl-ind">Indicateur</label>
                <select id="expl-ind" className="select" value={code} onChange={(e) => setCode(e.target.value)}>
                  {indicateurs.map((i) => (
                    <option key={i.code} value={i.code}>{i.libelle}</option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label className="field-lbl" htmlFor="expl-niv">Périmètre</label>
                <select id="expl-niv" className="select" value={niveau} onChange={(e) => setNiveau(e.target.value)}>
                  {NIVEAUX.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label className="field-lbl" htmlFor="expl-per">Période</label>
                <select id="expl-per" className="select" value={nbJours} onChange={(e) => setNbJours(Number(e.target.value))}>
                  {JOURS.map((n) => <option key={n} value={n}>{n} derniers jours</option>)}
                </select>
              </div>
              <button className="btn" type="button" onClick={interroger} disabled={chargement}>
                {chargement ? 'Lecture…' : '↻ Relire'}
              </button>
            </div>

            {niveauIndisponible && (
              <div className="banner banner-warn" style={{ marginTop: 'var(--esp-normal)' }}>
                Ce niveau n’est pas disponible&nbsp;: aucun{niveau === 'region' ? 'e région' : ' groupe'} n’est
                rattaché à ce site, ou la lecture du référentiel a échoué. Ce n’est pas qu’il n’y a rien à
                y voir.
              </div>
            )}

            {erreur && <div className="banner banner-error" style={{ marginTop: 'var(--esp-normal)' }}>{erreur}</div>}

            {/* ⚠ TROIS ÉTATS PAR JOUR, JAMAIS DEUX. Une barre absente n'est pas une barre à zéro :
                elle porte sa propre marque, et la légende sous le graphe la nomme. */}
            {points !== null && !niveauIndisponible && (
              <>
                <div style={{
                  display: 'flex', alignItems: 'flex-end', gap: 'var(--esp-serre)', height: 120,
                  marginTop: 'var(--esp-bloc)',
                }}>
                  {points.map((p) => {
                    const v = Number(p.valeur) || 0
                    const h = p.etat === 'mesure' && maxi > 0 ? Math.max(2, Math.round((Math.abs(v) / maxi) * 108)) : 0
                    const titre = p.etat === 'mesure'
                      ? `${p.jour} — ${formatValeur(p.valeur, indicateur?.unite)}${p.completude === 'partiel' ? ' (partiel)' : ''}`
                      : p.etat === 'absent'
                        ? `${p.jour} — aucune mesure : l’agrégation n’a pas couvert ce jour`
                        : `${p.jour} — lecture impossible`
                    return (
                      <div key={p.jour} title={titre} style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', height: '100%' }}>
                        {p.etat === 'mesure' ? (
                          <div style={{
                            height: h,
                            background: p.completude === 'partiel' ? 'var(--warn)' : 'var(--accent)',
                            borderRadius: '3px 3px 0 0',
                          }} />
                        ) : (
                          <div style={{
                            height: 108,
                            background: 'repeating-linear-gradient(45deg, var(--panel-2), var(--panel-2) 4px, transparent 4px, transparent 8px)',
                            border: `1px dashed ${p.etat === 'nonLu' ? 'var(--crit)' : 'var(--line)'}`,
                            borderRadius: '3px 3px 0 0',
                          }} />
                        )}
                      </div>
                    )
                  })}
                </div>
                <div className="hint" style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-large)', marginTop: 'var(--esp-serre)' }}>
                  <span>{points[0]?.jour} → {points[points.length - 1]?.jour}</span>
                  <span>{mesures.length} jour(s) mesuré(s)</span>
                  {partiels > 0 && <span><b>{partiels} partiel(s)</b> — un site au moins n’a pas remonté</span>}
                  {absents > 0 && <span><b>{absents} sans mesure</b> — l’agrégation n’a pas couvert ces jours</span>}
                </div>

                <div style={{ overflowX: 'auto', marginTop: 'var(--esp-bloc)' }}>
                  <table className="tbl">
                    <thead>
                      <tr><th>Jour</th><th className="num">Valeur</th><th>Complétude</th></tr>
                    </thead>
                    <tbody>
                      {[...points].reverse().map((p) => (
                        <tr key={p.jour}>
                          <td className="mono">{p.jour}</td>
                          <td className="num">
                            {p.etat === 'mesure'
                              ? formatValeur(p.valeur, indicateur?.unite)
                              : <span className="hint">—</span>}
                          </td>
                          <td>
                            {p.etat === 'mesure' ? (
                              p.completude === 'partiel' ? (
                                <span className="badge warn" title={p.sitesManquants.length
                                  ? `${p.sitesManquants.length} site(s) manquant(s)`
                                  : undefined}>
                                  partiel{p.sitesManquants.length ? ` · ${p.sitesManquants.length} site(s) manquant(s)` : ''}
                                </span>
                              ) : <span className="badge good">complet</span>
                            ) : p.etat === 'absent' ? (
                              <span className="hint">aucune mesure — l’agrégation n’a pas couvert ce jour</span>
                            ) : (
                              <span className="badge crit">lecture impossible</span>
                            )}
                          </td>
                        </tr>
                      ))}
                      {points.length === 0 && (
                        <tr><td colSpan={3} className="empty">Aucun jour dans la période.</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </>
        )}
      </div>
    </section>
  )
}
