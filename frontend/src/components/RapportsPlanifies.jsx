import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

// PLANIFIER UN RAPPORT — la septième et dernière ressource du module d'analyse à recevoir un écran.
//
// ── CE QUE CET ÉCRAN REND ATTEIGNABLE ───────────────────────────────────────────────────────────
//
// `RapportPlanifie` porte un processeur complet depuis l'origine, avec son contrôle de périmètre
// par destinataire (RG-M7-07). Aucun écran ne l'appelait : zéro ligne en base, et le seul endroit
// du frontal qui prononçait `reporting.planifier` était la liste des permissions du menu.
//
// ── UN RAPPORT QUI N'EST JAMAIS PARTI DOIT SE VOIR ──────────────────────────────────────────────
//
// `ExecuterRapportsCommand` sélectionne les rapports dont `prochainEnvoi` est nul ou dépassé, puis
// pose la date suivante. Un rapport actif dont `dernierEnvoi` reste nul longtemps après sa création
// n'est donc pas « en attente » : personne ne l'exécute.
//
// ⚠ L'ÉCRAN LE DÉDUIT DES DATES, IL NE L'AFFIRME PAS. Écrire ici « l'ordonnanceur n'exécute pas
// cette commande » serait vrai aujourd'hui et faux le jour où quelqu'un l'autorise, sans que rien
// ne relie les deux. La mention est donc tirée de `dernierEnvoi` et `etat`, qui restent justes
// quelle qu'en soit la cause.
//
// ── « PAS LU » N'EST PAS « AUCUN » ──────────────────────────────────────────────────────────────
//
// `null` porte « on n'a pas pu lire », `[]` porte « lu, et il n'y en a pas ».

const FORMATS = [['csv', 'CSV'], ['xlsx', 'Excel'], ['pdf', 'PDF']]
const PERIODICITES = [
  ['quotidienne', 'Chaque jour'],
  ['hebdomadaire', 'Chaque semaine'],
  ['mensuelle', 'Chaque mois'],
]

/** Une date ISO en jour lisible, ou le tiret cadratin quand il n'y a rien à montrer. */
function jour(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

export default function RapportsPlanifies({ etabActif, droits, versionTableaux }) {
  const [rapportsLus, setRapportsLus] = useState(null)
  const rapports = rapportsLus || []
  const [tableauxLus, setTableauxLus] = useState(null)
  const tableaux = tableauxLus || []

  const [nom, setNom] = useState('')
  const [tableau, setTableau] = useState('')
  const [format, setFormat] = useState('csv')
  const [periodicite, setPeriodicite] = useState('hebdomadaire')
  const [heure, setHeure] = useState('07:00')
  const [email, setEmail] = useState('')
  const [envoiEnCours, setEnvoiEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)
  // ⚠ POURQUOI la lecture a echoue, et pas seulement QU'ELLE a echoue. Le client d'API
  // redige pour un 403 une phrase qui dit quoi faire ; un `catch` qui l'ignore transforme
  // « il vous manque un droit » en « c'est casse », et envoie chercher au mauvais endroit.
  const [raisonNonLu, setRaisonNonLu] = useState(null)

  const peutPlanifier = aLeDroit(droits, 'reporting.planifier')

  const recharger = useCallback(() => {
    api.rapportsPlanifies()
      .then((r) => { setRapportsLus(membres(r)); setRaisonNonLu(null) })
      .catch((e) => { setRapportsLus(null); setRaisonNonLu(e) })
  }, [])

  useEffect(() => {
    let annule = false
    api.rapportsPlanifies()
      .then((r) => { if (!annule) { setRapportsLus(membres(r)); setRaisonNonLu(null) } })
      .catch((e) => { if (!annule) { setRapportsLus(null); setRaisonNonLu(e) } })
    api.tableauxDeBord()
      .then((r) => { if (!annule) setTableauxLus(membres(r).filter((t) => t.actif !== false)) })
      .catch(() => { if (!annule) setTableauxLus(null) })
    return () => { annule = true }
    // ⚠ `versionTableaux` change quand l'ecran voisin ecrit un tableau. Sans cette dependance, le
    // menu reste vide et la banniere continue de dire qu'il n'y en a aucun — une absence affirmee
    // que la page dementait deja quelques centimetres plus haut.
  }, [versionTableaux])

  async function creer(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoiEnCours(true)
    try {
      await api.creerRapportPlanifie({
        nom: nom.trim(),
        tableauDeBord: tableau,
        format,
        periodicite,
        heureEnvoi: heure,
        // ⚠ Le destinataire est confronté au périmètre du CRÉATEUR (RG-M7-07) : on l'attache donc
        // au site actif, le seul dont on sache ici qu'il est dans ce périmètre.
        destinataires: [{
          email: email.trim(),
          niveau: 'etablissement',
          etablissement: `/api/etablissements/${etabActif}`,
        }],
      })
      setNom('')
      setEmail('')
      recharger()
    } catch (err) {
      setErreur(err?.message || 'Le rapport n’a pas pu être planifié.')
    } finally {
      setEnvoiEnCours(false)
    }
  }

  async function basculerEtat(r) {
    setErreur(null)
    try {
      await api.modifierRapportPlanifie(r.id, {
        etat: r.etat === 'actif' ? 'suspendu' : 'actif',
      })
      recharger()
    } catch (err) {
      setErreur(err?.message || 'L’état n’a pas pu être changé.')
    }
  }

  const pretAEnvoyer = nom.trim() !== '' && tableau !== '' && email.trim() !== '' && !!etabActif
  const jamaisPartis = rapports.filter((r) => r.etat === 'actif' && !r.dernierEnvoi).length

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h2>Rapports planifiés</h2>
        <span className="hint">Un tableau de bord, une périodicité, des destinataires.</span>
      </div>
      <div className="card-b">
        {erreur && (
          <div className="banner banner-error" style={{ marginBottom: 'var(--esp-normal)' }}>{erreur}</div>
        )}

        {rapportsLus === null ? (
          <div className="banner banner-warn">
            La liste des rapports planifiés n’a pas pu être lue. Ce qui existe déjà n’est pas
            affiché ici — ce n’est pas une absence, c’est une lecture qui a échoué.
            {raisonNonLu?.message && (
              <div style={{ marginTop: 'var(--esp-petit)' }}>
                <b>{raisonNonLu.status === 403
                  ? 'Refusé par le serveur\u00a0:'
                  : 'Échec renvoyé par le serveur\u00a0:'}</b> {raisonNonLu.message}
              </div>
            )}
          </div>
        ) : rapports.length === 0 ? (
          <p className="hint">Aucun rapport n’a encore été planifié.</p>
        ) : (
          <>
            {jamaisPartis > 0 && (
              <div className="banner banner-warn" style={{ marginBottom: 'var(--esp-normal)' }}>
                {jamaisPartis === 1 ? 'Un rapport actif n’a' : `${jamaisPartis} rapports actifs n’ont`}{' '}
                jamais été envoyé{jamaisPartis > 1 ? 's' : ''}. Planifier n’envoie pas :
                l’expédition dépend d’une tâche périodique. Tant que la colonne « Dernier envoi »
                reste vide, rien n’est parti chez le destinataire.
              </div>
            )}
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Nom</th>
                    <th>Tableau</th>
                    <th>Format</th>
                    <th>Rythme</th>
                    <th>Heure</th>
                    <th>Destinataires</th>
                    <th>Dernier envoi</th>
                    <th>État</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {rapports.map((r) => (
                    <tr key={r.id}>
                      <td>{r.nom}</td>
                      <td>{r.tableauDeBord?.nom || '—'}</td>
                      <td>{(r.format || '').toUpperCase()}</td>
                      <td>{r.periodicite}</td>
                      <td>{r.heureEnvoi}</td>
                      <td>{(r.destinataires || []).length}</td>
                      <td>
                        {r.dernierEnvoi ? jour(r.dernierEnvoi) : (
                          <span className="badge warn">jamais</span>
                        )}
                      </td>
                      <td>
                        <span className={r.etat === 'actif' ? 'badge good' : 'badge mut'}>
                          {r.etat === 'actif' ? 'Actif' : 'Suspendu'}
                        </span>
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        {peutPlanifier && (
                          <button className="btn ghost sm" type="button" onClick={() => basculerEtat(r)}>
                            {r.etat === 'actif' ? 'Suspendre' : 'Réactiver'}
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}

        {peutPlanifier && (
          <form onSubmit={creer} style={{ marginTop: 'var(--esp-bloc)' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-normal)', alignItems: 'flex-end' }}>
              <div className="field" style={{ margin: 0, minWidth: 200 }}>
                <label className="field-lbl" htmlFor="rap-nom">Nom du rapport</label>
                <input id="rap-nom" className="input" type="text" value={nom}
                  onChange={(ev) => setNom(ev.target.value)} />
              </div>
              <div className="field" style={{ margin: 0, minWidth: 200 }}>
                <label className="field-lbl" htmlFor="rap-tdb">Tableau de bord</label>
                <select id="rap-tdb" className="input" value={tableau}
                  onChange={(ev) => setTableau(ev.target.value)}>
                  <option value="">Choisir…</option>
                  {tableaux.map((t) => <option key={t.id} value={t['@id']}>{t.nom}</option>)}
                </select>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label className="field-lbl" htmlFor="rap-fmt">Format</label>
                <select id="rap-fmt" className="input" value={format}
                  onChange={(ev) => setFormat(ev.target.value)}>
                  {FORMATS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label className="field-lbl" htmlFor="rap-per">Rythme</label>
                <select id="rap-per" className="input" value={periodicite}
                  onChange={(ev) => setPeriodicite(ev.target.value)}>
                  {PERIODICITES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label className="field-lbl" htmlFor="rap-heure">Heure</label>
                <input id="rap-heure" className="input" type="time" value={heure}
                  onChange={(ev) => setHeure(ev.target.value)} />
              </div>
              <div className="field" style={{ margin: 0, minWidth: 220 }}>
                <label className="field-lbl" htmlFor="rap-mail">Destinataire</label>
                <input id="rap-mail" className="input" type="email" value={email}
                  onChange={(ev) => setEmail(ev.target.value)} />
              </div>
              <button className="btn" type="submit" disabled={envoiEnCours || !pretAEnvoyer}>
                {envoiEnCours ? 'Planification…' : 'Planifier'}
              </button>
            </div>

            {tableauxLus === null ? (
              <div className="banner banner-warn" style={{ marginTop: 'var(--esp-normal)' }}>
                La liste des tableaux de bord n’a pas pu être lue : impossible de choisir sur quoi
                porte le rapport.
              </div>
            ) : tableaux.length === 0 && (
              <div className="banner banner-warn" style={{ marginTop: 'var(--esp-normal)' }}>
                Aucun tableau de bord actif : un rapport porte toujours sur un tableau (RG-M7-06).
                Composez-en un ci-dessus avant de planifier.
              </div>
            )}
          </form>
        )}
      </div>
    </section>
  )
}
