import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

// COMPOSER UN TABLEAU DE BORD — la sixième ressource du module d'analyse à recevoir un écran.
//
// ── CE QUE CET ÉCRAN REND ATTEIGNABLE ───────────────────────────────────────────────────────────
//
// `TableauDeBord` est une composition d'indicateurs rattachée à un périmètre. Elle est référencée
// par `RapportPlanifie` (RG-M7-06) : sans tableau, aucun rapport ne peut être planifié. Rien ne
// permettait d'en créer un — 0 ligne en base, mesuré.
//
// ── LA CRÉATION EXIGE UN PÉRIMÈTRE, ET CE N'EST PAS UNE FORMALITÉ ───────────────────────────────
//
// Le serveur refuse une création sans `niveau` + `entiteId`, et l'écran le dit avant d'envoyer :
// un tableau sans rattachement tombe hors du cloisonnement, donc hors de la liste ET de l'accès
// unitaire. Et cette ressource ne se supprime pas — par choix de la spec, on la retire de la
// circulation avec `actif: false`. Une ligne orpheline serait donc définitive.
//
// ── « PAS LU » N'EST PAS « AUCUN » ──────────────────────────────────────────────────────────────
//
// `null` porte « on n'a pas pu lire », `[]` porte « lu, et il n'y en a pas ». L'écran ne dit
// jamais « aucun tableau de bord » sur la foi d'une requête qui a échoué.

const NIVEAUX = [
  ['etablissement', 'Ce site'],
  ['region', 'Sa région'],
  ['groupe', 'Le groupe'],
]

/** Le libellé du rattachement porté par une ligne, tel qu'il a été enregistré. */
function libellePerimetre(tdb) {
  if (tdb.niveau === 'region') return 'Région'
  if (tdb.niveau === 'groupe') return 'Groupe'
  return 'Site'
}

export default function TableauxDeBord({ etabActif, etablissements, droits }) {
  const [tableauxLus, setTableauxLus] = useState(null)
  const tableaux = tableauxLus || []
  const [indicateursLus, setIndicateursLus] = useState(null)
  const indicateurs = indicateursLus || []
  const [groupesLus, setGroupesLus] = useState(null)

  const [nom, setNom] = useState('')
  const [niveau, setNiveau] = useState('etablissement')
  const [choisis, setChoisis] = useState([])
  const [envoiEnCours, setEnvoiEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  const peutConfigurer = aLeDroit(droits, 'reporting.configurer')

  const etab = etablissements.find((e) => e.id === etabActif) || null
  const regionId = etab?.region?.id || null
  const groupeId = (groupesLus || [])[0]?.id || null
  const entiteId = niveau === 'etablissement' ? etabActif : niveau === 'region' ? regionId : groupeId

  const recharger = useCallback(() => {
    api.tableauxDeBord()
      .then((r) => setTableauxLus(membres(r)))
      .catch(() => setTableauxLus(null))
  }, [])

  useEffect(() => {
    let annule = false
    api.tableauxDeBord()
      .then((r) => { if (!annule) setTableauxLus(membres(r)) })
      .catch(() => { if (!annule) setTableauxLus(null) })
    api.indicateurs()
      .then((r) => { if (!annule) setIndicateursLus(membres(r).filter((i) => i.actif !== false)) })
      .catch(() => { if (!annule) setIndicateursLus(null) })
    api.groupes()
      .then((r) => { if (!annule) setGroupesLus(membres(r)) })
      .catch(() => { if (!annule) setGroupesLus(null) })
    return () => { annule = true }
  }, [])

  function basculer(iri) {
    setChoisis((avant) => (avant.includes(iri) ? avant.filter((x) => x !== iri) : [...avant, iri]))
  }

  async function creer(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoiEnCours(true)
    try {
      await api.creerTableauDeBord({
        nom: nom.trim(),
        indicateurs: choisis,
        niveau,
        entiteId,
      })
      setNom('')
      setChoisis([])
      recharger()
    } catch (err) {
      setErreur(err?.message || 'Le tableau de bord n’a pas pu être créé.')
    } finally {
      setEnvoiEnCours(false)
    }
  }

  async function basculerActif(tdb) {
    setErreur(null)
    try {
      await api.modifierTableauDeBord(tdb.id, { actif: !tdb.actif })
      recharger()
    } catch (err) {
      setErreur(err?.message || 'L’état n’a pas pu être changé.')
    }
  }

  // Le bouton reste inerte tant que les trois conditions du serveur ne sont pas réunies : le nom,
  // au moins un indicateur (RG-M7-06), et une entité pour le niveau choisi. Rien n'est deviné à la
  // place de celui qui remplit — le périmètre manquant est dit, pas contourné.
  const pretAEnvoyer = nom.trim() !== '' && choisis.length > 0 && !!entiteId

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h2>Tableaux de bord</h2>
        <span className="hint">Une composition d’indicateurs, rattachée à un périmètre.</span>
      </div>
      <div className="card-b">
        {erreur && (
          <div className="banner banner-error" style={{ marginBottom: 'var(--esp-normal)' }}>{erreur}</div>
        )}

        {tableauxLus === null ? (
          <div className="banner banner-warn">
            La liste des tableaux de bord n’a pas pu être lue. Ce qui existe déjà n’est pas affiché
            ici — ce n’est pas une absence, c’est une lecture qui a échoué.
          </div>
        ) : tableaux.length === 0 ? (
          <p className="hint">Aucun tableau de bord n’a encore été composé.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Nom</th>
                  <th>Périmètre</th>
                  <th>Indicateurs</th>
                  <th>État</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {tableaux.map((t) => (
                  <tr key={t.id}>
                    <td>{t.nom}</td>
                    <td>{libellePerimetre(t)}</td>
                    <td>{(t.indicateurs || []).length}</td>
                    <td>
                      <span className={t.actif ? 'badge good' : 'badge mut'}>
                        {t.actif ? 'Actif' : 'Retiré'}
                      </span>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {peutConfigurer && (
                        <button className="btn ghost sm" type="button" onClick={() => basculerActif(t)}>
                          {t.actif ? 'Retirer de la circulation' : 'Remettre en circulation'}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {peutConfigurer && (
          <form onSubmit={creer} style={{ marginTop: 'var(--esp-bloc)' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-normal)', alignItems: 'flex-end' }}>
              <div className="field" style={{ margin: 0, minWidth: 220 }}>
                <label className="field-lbl" htmlFor="tdb-nom">Nom du tableau</label>
                <input
                  id="tdb-nom"
                  className="input"
                  type="text"
                  value={nom}
                  onChange={(ev) => setNom(ev.target.value)}
                />
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label className="field-lbl" htmlFor="tdb-niv">Périmètre</label>
                <select id="tdb-niv" className="input" value={niveau} onChange={(ev) => setNiveau(ev.target.value)}>
                  {NIVEAUX.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </div>
              <button className="btn" type="submit" disabled={envoiEnCours || !pretAEnvoyer}>
                {envoiEnCours ? 'Création…' : 'Créer le tableau'}
              </button>
            </div>

            {!entiteId && (
              <div className="banner banner-warn" style={{ marginTop: 'var(--esp-normal)' }}>
                Aucune entité connue pour ce périmètre : le tableau ne peut pas être rattaché, et un
                tableau sans rattachement serait introuvable ensuite.
              </div>
            )}

            <fieldset style={{ marginTop: 'var(--esp-normal)', border: 0, padding: 0 }}>
              <legend className="field-lbl">
                Indicateurs composés {choisis.length > 0 && <>· {choisis.length} choisi(s)</>}
              </legend>
              {indicateursLus === null ? (
                <div className="banner banner-warn">
                  Le référentiel des indicateurs n’a pas pu être lu : impossible de composer sans lui.
                </div>
              ) : indicateurs.length === 0 ? (
                <p className="hint">Aucun indicateur actif au référentiel.</p>
              ) : (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-normal)' }}>
                  {indicateurs.map((i) => (
                    <label key={i.id} className="hint" style={{ display: 'flex', alignItems: 'center', gap: 'var(--esp-serre)' }}>
                      <input
                        type="checkbox"
                        checked={choisis.includes(i['@id'])}
                        onChange={() => basculer(i['@id'])}
                      />
                      {i.libelle || i.code}
                    </label>
                  ))}
                </div>
              )}
            </fieldset>
          </form>
        )}
      </div>
    </section>
  )
}
