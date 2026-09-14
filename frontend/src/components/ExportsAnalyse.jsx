import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'

// EMPORTER LES CHIFFRES — le seul écran du module qui produise quelque chose qu'on sort de l'outil.
//
// ── POUR QUI, ET CE QUE ÇA CHANGE ───────────────────────────────────────────────────────────────
//
// Celui qui exporte ne consulte pas : il rend des comptes. Un expert-comptable, une banque, un
// conseil d'administration, un financeur. Trois conséquences sur cet écran :
//
//   1. LA PÉRIODE SE CHOISIT EN MOIS CLOS, pas en « 14 derniers jours ». Personne ne présente un
//      arrêté sur une fenêtre glissante. Les raccourcis sont donc le mois en cours, le mois
//      dernier, le trimestre, l'exercice.
//   2. LE FICHIER PART SANS L'APPLICATION. Il faut donc que le fichier se défende seul : une
//      colonne `etat` qui distingue « mesuré » de « non agrégé », et l'unité de chaque indicateur.
//      Une cellule vide dans un tableur vaut zéro dans une somme — c'est hors de portée du produit,
//      et c'est exactement là que le chiffre devient faux.
//   3. ON DIT LA FIABILITÉ AVANT L'ENVOI, PAS APRÈS. Dès qu'un export est généré, l'écran lit son
//      contenu et annonce combien de jours n'ont pas de mesure. C'est le moment où ça compte : une
//      fois le fichier envoyé, plus personne ne peut le rattraper.
//
// ── UN SEUL FORMAT MARCHE, ET ON LE DIT ─────────────────────────────────────────────────────────
//
// `XlsxGenerateurExportStub` et `PdfGenerateurExportStub` refusent avec un message nommé, et
// l'export est alors enregistré en échec. Les proposer comme s'ils fonctionnaient produirait un
// export raté par clic. Ils sont donc affichés, désactivés, avec la raison — parce que la question
// « pourquoi pas d'Excel ? » se pose, et que la réponse doit être à l'écran.

const NIVEAUX = [
  ['etablissement', 'Ce site'],
  ['region', 'Sa région'],
  ['groupe', 'Le groupe'],
]

/** `Y-m-d` dans le fuseau de celui qui regarde — jamais `toISOString`, qui rend de l'UTC. */
function jourLocal(d) {
  return d.toLocaleDateString('sv-SE')
}

/** Les périodes telles qu'on les présente à un tiers : des mois clos, pas une fenêtre glissante. */
function periodes() {
  const a = new Date()
  const moisDebut = new Date(a.getFullYear(), a.getMonth(), 1)
  const moisPrecDebut = new Date(a.getFullYear(), a.getMonth() - 1, 1)
  const moisPrecFin = new Date(a.getFullYear(), a.getMonth(), 0)
  const trimDebut = new Date(a.getFullYear(), Math.floor(a.getMonth() / 3) * 3, 1)
  const anDebut = new Date(a.getFullYear(), 0, 1)
  return [
    ['mois', 'Ce mois-ci', jourLocal(moisDebut), jourLocal(a)],
    ['mois-1', 'Le mois dernier', jourLocal(moisPrecDebut), jourLocal(moisPrecFin)],
    ['trimestre', 'Ce trimestre', jourLocal(trimDebut), jourLocal(a)],
    ['annee', 'Cette année', jourLocal(anDebut), jourLocal(a)],
  ]
}

const FORMATS = [
  ['csv', 'CSV', true, ''],
  ['xlsx', 'Excel', false, 'Format XLSX non disponible dans cette version, utiliser CSV.'],
  ['pdf', 'PDF', false, 'Format PDF non disponible dans cette version, utiliser CSV.'],
]

/**
 * Ce que le fichier contient vraiment, pour pouvoir le dire avant qu'il parte.
 *
 * ⚠ SUR-ESTIMER UNE ABSENCE EST UN MENSONGE COMME LA SOUS-ESTIMER. La première version comptait
 * les jours portant AU MOINS UN trou et les annonçait « sans aucune mesure ». Sur la période
 * mesurée, ces jours portaient six indicateurs sur neuf : la phrase aurait fait renoncer à un
 * fichier utilisable. On compte donc trois choses distinctes — les cellules manquantes, les jours
 * qu'elles touchent, et les indicateurs concernés — et on ne dit « aucune mesure » que d'un jour
 * qui n'en porte réellement aucune.
 */
function lireLeFichier(csv) {
  const lignes = csv.split(/\r\n|\r|\n/).filter((l) => l.trim() !== '')
  const vide = { lignes: 0, trous: 0, joursTouches: 0, joursVides: 0, jours: 0, indicateurs: [] }
  if (lignes.length < 2) return vide

  const cellules = (l) => l.split(';').map((x) => x.replace(/^"|"$/g, ''))
  const entetes = cellules(lignes[0])
  const iEtat = entetes.indexOf('etat')
  const iJour = entetes.indexOf('jour')
  const iInd = entetes.indexOf('indicateur')
  if (iEtat < 0 || iJour < 0) return vide

  const parJour = new Map()
  const indicateurs = new Set()
  const tousIndicateurs = new Set()
  let trous = 0
  for (const l of lignes.slice(1)) {
    const c = cellules(l)
    const jour = c[iJour]
    if (!parJour.has(jour)) parJour.set(jour, { total: 0, manquants: 0 })
    const j = parJour.get(jour)
    j.total += 1
    if (iInd >= 0) tousIndicateurs.add(c[iInd])
    if (c[iEtat] === 'non agrege') {
      j.manquants += 1
      trous += 1
      if (iInd >= 0) indicateurs.add(c[iInd])
    }
  }

  const jours = [...parJour.values()]
  return {
    lignes: lignes.length - 1,
    trous,
    joursTouches: jours.filter((j) => j.manquants > 0).length,
    joursVides: jours.filter((j) => j.manquants === j.total).length,
    jours: jours.length,
    indicateurs: [...indicateurs],
    // ⚠ « ne touchant que N indicateurs » suggère un sous-ensemble. Quand ce sont TOUS, la
    // tournure minimise le trou au lieu de le décrire — on a besoin du total pour le savoir.
    tousIndicateurs: tousIndicateurs.size,
  }
}

export default function ExportsAnalyse({ etabActif, etablissements }) {
  const [exportsLus, setExportsLus] = useState(null)
  const listeExports = exportsLus || []
  const [indicateursLus, setIndicateursLus] = useState(null)
  const indicateurs = indicateursLus || []
  const [groupesLus, setGroupesLus] = useState(null)

  const PERIODES = useMemo(() => periodes(), [])
  const [presel, setPresel] = useState('mois')
  const [debut, setDebut] = useState(PERIODES[0][2])
  const [fin, setFin] = useState(PERIODES[0][3])
  const [niveau, setNiveau] = useState('etablissement')
  const [format, setFormat] = useState('csv')
  const [choisis, setChoisis] = useState([])
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)
  // ⚠ POURQUOI la lecture a echoue, et pas seulement QU'ELLE a echoue. Le client d'API
  // redige pour un 403 une phrase qui dit quoi faire ; un `catch` qui la jette transforme
  // « il vous manque un droit » en « c'est casse », et envoie chercher au mauvais endroit.
  const [raisonNonLu, setRaisonNonLu] = useState(null)
  const [dernier, setDernier] = useState(null)

  const etab = etablissements.find((e) => e.id === etabActif) || null
  const regionId = etab?.region?.id || null
  const groupeId = (groupesLus || [])[0]?.id || null
  const entiteId = niveau === 'etablissement' ? etabActif : niveau === 'region' ? regionId : groupeId

  const recharger = useCallback(() => {
    api.exportsAnalyse()
      .then((r) => setExportsLus(membres(r)))
      .catch((e) => { setExportsLus(null); setRaisonNonLu(e) })
  }, [])

  useEffect(() => {
    let annule = false
    api.exportsAnalyse()
      .then((r) => { if (!annule) setExportsLus(membres(r)) })
      .catch((e) => { if (!annule) { setExportsLus(null); setRaisonNonLu(e) } })
    api.indicateurs()
      .then((r) => { if (!annule) setIndicateursLus(membres(r).filter((i) => i.actif !== false)) })
      .catch((e) => { if (!annule) { setIndicateursLus(null); setRaisonNonLu(e) } })
    api.groupes()
      .then((r) => { if (!annule) setGroupesLus(membres(r)) })
      .catch((e) => { if (!annule) { setGroupesLus(null); setRaisonNonLu(e) } })
    return () => { annule = true }
  }, [])

  function choisirPeriode(cle) {
    setPresel(cle)
    const p = PERIODES.find((x) => x[0] === cle)
    if (p) { setDebut(p[2]); setFin(p[3]) }
  }

  const nbJours = useMemo(() => {
    const d = new Date(debut)
    const f = new Date(fin)
    if (Number.isNaN(d.getTime()) || Number.isNaN(f.getTime()) || f < d) return 0
    return Math.round((f - d) / 86400000) + 1
  }, [debut, fin])

  const nbIndicateurs = choisis.length > 0 ? choisis.length : indicateurs.length
  const nbLignes = nbJours * nbIndicateurs

  async function generer(e) {
    e.preventDefault()
    setErreur(null)
    setDernier(null)
    setEnCours(true)
    try {
      const cree = await api.creerExportAnalyse({
        format,
        niveau,
        entiteId,
        periodeDebut: debut,
        periodeFin: fin,
        ...(choisis.length > 0 ? { indicateurs: choisis } : {}),
      })
      recharger()
      // ⚠ ON LIT LE FICHIER TOUT DE SUITE. C'est le seul moment où l'on peut encore dire à
      // quelqu'un que son état est incomplet : après l'envoi, personne ne le rattrape.
      try {
        const complet = await api.telechargerExportAnalyse(cree.id)
        const csv = atob(complet.contenuBase64 || '')
        setDernier({ id: cree.id, csv, resume: lireLeFichier(csv), statut: cree.statut })
      } catch {
        setDernier({ id: cree.id, csv: null, resume: null, statut: cree.statut })
      }
    } catch (err) {
      setErreur(err?.message || 'L’export n’a pas pu être généré.')
    } finally {
      setEnCours(false)
    }
  }

  async function telecharger(id) {
    setErreur(null)
    try {
      const complet = await api.telechargerExportAnalyse(id)
      const csv = atob(complet.contenuBase64 || '')
      const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `analyse-${id.slice(0, 8)}.csv`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      setErreur(err?.message || 'Le fichier n’a pas pu être récupéré.')
    }
  }

  const pretAGenerer = !!entiteId && nbJours > 0 && format === 'csv' && indicateurs.length > 0

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h2>Emporter les chiffres</h2>
        <span className="hint">Un fichier, pour une période et un périmètre.</span>
      </div>
      <div className="card-b">
        {erreur && (
          <div className="banner banner-error" style={{ marginBottom: 'var(--esp-normal)' }}>{erreur}</div>
        )}
        {raisonNonLu?.message && (
          <div className="banner banner-warn" style={{ marginBottom: 'var(--esp-normal)' }}>
            <b>{raisonNonLu.status === 403
                  ? 'Une lecture a été refusée\u00a0:'
                  : 'Une lecture a échoué\u00a0:'}</b> {raisonNonLu.message}
          </div>
        )}

        <form onSubmit={generer}>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-normal)', alignItems: 'flex-end' }}>
            <div className="field" style={{ margin: 0 }}>
              <label className="field-lbl" htmlFor="exp-per">Période</label>
              <select id="exp-per" className="input" value={presel}
                onChange={(ev) => choisirPeriode(ev.target.value)}>
                {PERIODES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                <option value="perso">Personnalisée</option>
              </select>
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label className="field-lbl" htmlFor="exp-du">Du</label>
              <input id="exp-du" className="input" type="date" value={debut}
                onChange={(ev) => { setDebut(ev.target.value); setPresel('perso') }} />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label className="field-lbl" htmlFor="exp-au">Au</label>
              <input id="exp-au" className="input" type="date" value={fin}
                onChange={(ev) => { setFin(ev.target.value); setPresel('perso') }} />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label className="field-lbl" htmlFor="exp-niv">Périmètre</label>
              <select id="exp-niv" className="input" value={niveau}
                onChange={(ev) => setNiveau(ev.target.value)}>
                {NIVEAUX.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label className="field-lbl" htmlFor="exp-fmt">Format</label>
              <select id="exp-fmt" className="input" value={format}
                onChange={(ev) => setFormat(ev.target.value)}>
                {FORMATS.map(([v, l, dispo]) => (
                  <option key={v} value={v} disabled={!dispo}>
                    {dispo ? l : `${l} — indisponible`}
                  </option>
                ))}
              </select>
            </div>
            <button className="btn primary" type="submit" disabled={enCours || !pretAGenerer}>
              {enCours ? 'Génération…' : 'Générer le fichier'}
            </button>
          </div>

          {/* Ce que le fichier contiendra, dit avant de le demander : personne n'aime découvrir
              qu'il a lancé trente mille lignes, ni qu'il n'en a qu'une. */}
          <p className="hint" style={{ marginTop: 'var(--esp-normal)' }}>
            {nbJours > 0 && indicateurs.length > 0 ? (
              <>Le fichier portera <b>{nbJours} jour(s)</b> × <b>{nbIndicateurs} indicateur(s)</b>
                {' '}= {nbLignes.toLocaleString('fr-FR')} ligne(s), une par jour et par indicateur.</>
            ) : nbJours <= 0 ? (
              <>La période est vide&nbsp;: la date de fin précède la date de début.</>
            ) : (
              <>Aucun indicateur actif&nbsp;: il n’y aurait rien à exporter.</>
            )}
            {!entiteId && <> Aucune entité connue pour ce périmètre&nbsp;: l’export ne peut pas être rattaché.</>}
          </p>

          {FORMATS.filter(([v]) => v === format).map(([v, , dispo, raison]) => !dispo && (
            <div key={v} className="banner banner-warn" style={{ marginTop: 'var(--esp-normal)' }}>{raison}</div>
          ))}

          <fieldset style={{ marginTop: 'var(--esp-normal)', border: 0, padding: 0 }}>
            <legend className="field-lbl">
              Indicateurs {choisis.length === 0 ? '· tous les actifs' : `· ${choisis.length} choisi(s)`}
            </legend>
            {indicateursLus === null ? (
              <div className="banner banner-warn">
                Le référentiel des indicateurs n’a pas pu être lu : on ne sait pas ce qu’un export
                contiendrait.
              </div>
            ) : indicateurs.length === 0 ? (
              <p className="hint">Aucun indicateur actif au référentiel.</p>
            ) : (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-normal)' }}>
                {indicateurs.map((i) => (
                  <label key={i.id} className="hint"
                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--esp-serre)' }}>
                    <input type="checkbox" checked={choisis.includes(i.code)}
                      onChange={() => setChoisis((a) => a.includes(i.code)
                        ? a.filter((x) => x !== i.code) : [...a, i.code])} />
                    {i.libelle || i.code}
                  </label>
                ))}
              </div>
            )}
          </fieldset>
        </form>

        {/* ⚠ LA FIABILITÉ, DITE AU SEUL MOMENT OÙ ELLE PEUT ENCORE SERVIR. */}
        {dernier && (
          <div className={dernier.resume?.trous > 0 ? 'banner banner-warn' : 'banner banner-ok'}
            style={{ marginTop: 'var(--esp-bloc)' }}>
            {dernier.resume === null ? (
              <>L’export est enregistré, mais son contenu n’a pas pu être relu ici. Le fichier est
                peut-être disponible dans la liste ci-dessous.</>
            ) : dernier.resume.trous > 0 ? (
              <>
                <b>{dernier.resume.lignes.toLocaleString('fr-FR')} ligne(s)</b>, dont{' '}
                <b>{dernier.resume.trous.toLocaleString('fr-FR')} sans mesure</b>, réparties sur{' '}
                {dernier.resume.joursTouches} jour(s) sur {dernier.resume.jours}
                {dernier.resume.indicateurs.length >= dernier.resume.tousIndicateurs ? (
                  <> et touchant <b>tous les indicateurs</b></>
                ) : dernier.resume.indicateurs.length > 0 && (
                  <> et ne touchant que {dernier.resume.indicateurs.length} indicateur(s) sur{' '}
                    {dernier.resume.tousIndicateurs}&nbsp;: {dernier.resume.indicateurs.join(', ')}</>
                )}.
                {dernier.resume.joursVides > 0 && (
                  <> <b>{dernier.resume.joursVides} jour(s) n’ont aucune mesure du tout.</b></>
                )}
                {' '}Ces cellules sortent vides, avec l’état «&nbsp;non agrégé&nbsp;» —{' '}
                <b>ne les additionnez pas comme des zéros</b>, et dites-le si vous transmettez ce
                fichier.
              </>
            ) : (
              <><b>{dernier.resume.lignes.toLocaleString('fr-FR')} ligne(s)</b> sur{' '}
                {dernier.resume.jours} jour(s), toutes mesurées.</>
            )}
            {' '}
            <button className="btn sm" type="button" onClick={() => telecharger(dernier.id)}
              style={{ marginLeft: 'var(--esp-serre)' }}>
              Télécharger
            </button>
          </div>
        )}

        <h3 style={{ marginTop: 'var(--esp-bloc)' }}>Exports demandés</h3>
        {exportsLus === null ? (
          <div className="banner banner-warn">
            La liste des exports n’a pas pu être lue. Ce qui a déjà été demandé n’apparaît pas ici —
            ce n’est pas une absence.
          </div>
        ) : listeExports.length === 0 ? (
          <p className="hint">Aucun export n’a encore été demandé.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Généré le</th>
                  <th>Période couverte</th>
                  <th>Format</th>
                  <th>État</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {listeExports.map((x) => {
                  const axes = x.axesAppliques || {}
                  return (
                    <tr key={x.id}>
                      <td>{(x.genereLe || '').slice(0, 10)}</td>
                      <td>
                        {axes.periodeDebut
                          ? `${axes.periodeDebut} → ${axes.periodeFin}`
                          : <span className="hint">non renseignée</span>}
                      </td>
                      <td>{(x.format || '').toUpperCase()}</td>
                      <td>
                        {/* ⚠ TROIS ÉTATS, PAS DEUX. `envoye` est un SUCCÈS — le rapport
                            planifié a produit le fichier PUIS l'a expédié. Le ranger avec
                            `echec` affichait en rouge un rapport que le destinataire a reçu. */}
                        <span className={x.statut === 'echec' ? 'badge crit' : 'badge good'}>
                          {{ genere: 'disponible', envoye: 'envoyé' }[x.statut] || 'échec'}
                        </span>
                        {x.statut === 'envoye' && x.destinataireEmail && (
                          <div className="hint">à {x.destinataireEmail}</div>
                        )}
                        {x.statut === 'echec' && x.messageErreur && (
                          <div className="hint">{x.messageErreur}</div>
                        )}
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        {x.statut !== 'echec' && (
                          <button className="btn ghost sm" type="button" onClick={() => telecharger(x.id)}>
                            Télécharger
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  )
}
