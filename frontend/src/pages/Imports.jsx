import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'

/**
 * REPRISE INITIALE (App\Import) — quatre routes serveur, aucun écran jusqu'ici.
 *
 * Un import est un OBJET, pas une action : il porte le fichier, son verdict, et ce qu'il a créé.
 * L'écran suit les DEUX TEMPS STRICTS du serveur (D98) et ne les mélange pas :
 *
 *  1. On dépose un CSV → `POST /imports` VALIDE tout sans rien écrire en base métier. Le serveur
 *     rend le lot avec son verdict : `validated` (N lignes prêtes) ou `rejected` (+ la liste
 *     ENTIÈRE des erreurs, ligne → message). Un rejet n'est pas une panne : c'est le résultat
 *     attendu d'un fichier à corriger, et il est conservé pour qu'on puisse le rejuger.
 *  2. Sur un lot `validated`, **Appliquer** écrit en une transaction (`applied`).
 *  3. Sur un lot `applied`, **Annuler** défait exactement ce que CE lot a créé (`reverted`) — et le
 *     serveur REFUSE en 409 si une ligne créée a servi depuis. On affiche alors ce refus tel quel :
 *     ce n'est pas un bug, c'est la règle « on corrige par un second import, on ne défait pas ce
 *     qui a servi ».
 *
 * `errors` voyage sur l'objet lui-même (groupe de lecture), donc la liste des lots suffit à
 * rouvrir le détail d'un rejet — rien à recharger.
 */

// Seul `customers` a un RowImporter côté serveur (I1). Les autres types (products, tariffs,
// subscribers, card_credits, staff) sont des incréments futurs : les proposer ici enverrait droit
// vers un 422. On n'offre que ce qui existe.
const TYPES = [
  ['customers', 'Clients'],
]

function libelleType(v) {
  return TYPES.find(([c]) => c === v)?.[1] || v || '—'
}

const STATUTS = {
  validated: ['Validé', 'info'],
  rejected: ['Rejeté', 'crit'],
  applied: ['Appliqué', 'good'],
  reverted: ['Annulé', 'mut'],
  pending: ['En attente', 'mut'],
}

function badgeStatut(v) {
  const [label, ton] = STATUTS[v] || [v || '—', 'mut']
  return <span className={`badge ${ton}`}>{label}</span>
}

function quand(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

// Le fichier part en base64 SANS le préfixe `data:…;base64,` que `readAsDataURL` ajoute : le serveur
// attend le base64 nu (il fait `base64_decode` dessus), et le préfixe le ferait échouer en « base64
// invalide ». On le retire donc ici, une fois, plutôt que de le laisser fuiter dans le corps.
function fichierEnBase64(fichier) {
  return new Promise((resolve, reject) => {
    const lecteur = new FileReader()
    lecteur.onload = () => {
      const brut = String(lecteur.result || '')
      const virgule = brut.indexOf(',')
      resolve(virgule >= 0 ? brut.slice(virgule + 1) : brut)
    }
    lecteur.onerror = () => reject(new Error('Le fichier n’a pas pu être lu.'))
    lecteur.readAsDataURL(fichier)
  })
}

export default function Imports({ etabActif, droits = [] }) {
  const peutCreer = aLeDroit(droits, 'import.create')
  const peutAppliquer = aLeDroit(droits, 'import.apply')
  const peutAnnuler = aLeDroit(droits, 'import.revert')

  // ⚠ `null` = PAS LU (distinct de « lu, et vide »).
  const [lots, setLots] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [busy, setBusy] = useState(false)
  const [type, setType] = useState('customers')
  const [detail, setDetail] = useState(null)

  const champFichier = useRef(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const rep = await api.imports()
      setLots(membres(rep))
    } catch (e) {
      setErreur(e.message || 'Les imports n’ont pas pu être chargés.')
      setLots(null)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  const visibles = useMemo(() => lots || [], [lots])

  async function valider(fichier) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      const content = await fichierEnBase64(fichier)
      const lot = await api.creerImport({
        type,
        content,
        fileName: fichier.name,
        mimeType: fichier.type || 'text/csv',
      })
      const nbErreurs = lot.errors ? Object.keys(lot.errors).length : 0
      if (lot.status === 'validated') {
        setSucces(`« ${fichier.name} » validé : ${lot.rowCount} ligne${lot.rowCount > 1 ? 's' : ''} prête${lot.rowCount > 1 ? 's' : ''} à appliquer.`)
      } else {
        // Un rejet n'est PAS une erreur d'appel : on le montre comme un verdict, avec le détail à
        // portée de clic, pas comme une panne.
        setErreur(`« ${fichier.name} » rejeté : ${nbErreurs} ligne${nbErreurs > 1 ? 's' : ''} en erreur. Ouvrez le détail pour corriger le fichier.`)
        setDetail(lot)
      }
      await recharger()
    } catch (e) {
      setErreur(e.message || 'La validation a échoué.')
    } finally {
      setBusy(false)
    }
  }

  async function voirDetail(lot) {
    // Le détail complet (contenu + liste ENTIÈRE des erreurs) vit sur l'item `GET /imports/{id}`,
    // pas sur la collection : on recharge le lot par son id pour ouvrir la fiche à jour. Repli sur
    // l'objet déjà en liste si la relecture échoue — le détail reste ouvrable hors ligne.
    try {
      const frais = await api.detailImport(lot.id)
      setDetail(frais)
    } catch {
      setDetail(lot)
    }
  }

  async function appliquer(lot) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.appliquerImport(lot.id)
      setSucces(`Lot appliqué : ${lot.rowCount} ligne${lot.rowCount > 1 ? 's' : ''} créée${lot.rowCount > 1 ? 's' : ''}.`)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’application a échoué.')
    } finally {
      setBusy(false)
    }
  }

  async function annuler(lot) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.annulerImport(lot.id)
      setSucces('Lot annulé : les lignes créées par ce lot ont été retirées.')
      await recharger()
    } catch (e) {
      // Le 409 « des lignes ont servi depuis » arrive ici avec son message serveur : on l'affiche
      // tel quel, c'est une règle métier lisible, pas un incident.
      setErreur(e.message || 'L’annulation a échoué.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Reprise initiale</h1>
          <div className="sub">
            {lots === null
              ? 'imports non lus — la lecture n’a pas abouti'
              : `${visibles.length} lot${visibles.length > 1 ? 's' : ''} d’import`}
          </div>
        </div>
        {peutCreer && (
          <div className="actions">
            <select
              className="select sm"
              style={{ width: 180 }}
              value={type}
              onChange={(e) => setType(e.target.value)}
              aria-label="Type de données à reprendre"
              disabled={busy}
            >
              {TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            <input
              ref={champFichier}
              type="file"
              accept=".csv,text/csv"
              style={{ display: 'none' }}
              onChange={(e) => {
                const f = e.target.files?.[0]
                // On remet le champ à zéro : sans ça, redéposer LE MÊME fichier ne déclencherait
                // aucun événement, et l'utilisateur conclurait que le bouton ne marche plus.
                e.target.value = ''
                if (f) valider(f)
              }}
            />
            <button
              className="btn primary"
              type="button"
              disabled={busy}
              onClick={() => champFichier.current?.click()}
            >
              + Valider un fichier CSV
            </button>
          </div>
        )}
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <div className="card">
        <div className="card-h">
          <span>Lots d’import</span>
        </div>

        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : visibles.length === 0 ? (
          <div className="empty">
            {lots === null
              ? 'Les imports n’ont pas pu être lus : ce tableau est vide parce que la lecture a échoué, pas parce qu’aucun import n’a été fait.'
              : 'Aucun import. Déposez un CSV de clients pour lancer une reprise : il est d’abord validé, puis appliqué en un second geste.'}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Fichier</th>
                  <th>Type</th>
                  <th>Statut</th>
                  <th className="num">Lignes</th>
                  <th className="num">Déposé le</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {visibles.map((lot) => {
                  const nbErreurs = lot.errors ? Object.keys(lot.errors).length : 0
                  return (
                    <tr key={lot.id}>
                      <td><span className="nm">{lot.fileName || '—'}</span></td>
                      <td>{libelleType(lot.type)}</td>
                      <td>{badgeStatut(lot.status)}</td>
                      <td className="num">{lot.rowCount}</td>
                      <td className="num">{quand(lot.createdAt)}</td>
                      <td>
                        <div style={{ display: 'flex', gap: 'var(--esp-serre)', justifyContent: 'flex-end' }}>
                          {nbErreurs > 0 && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              onClick={() => voirDetail(lot)}
                            >
                              {nbErreurs} erreur{nbErreurs > 1 ? 's' : ''}
                            </button>
                          )}
                          {lot.status === 'validated' && peutAppliquer && (
                            <button
                              className="btn primary sm"
                              type="button"
                              disabled={busy}
                              onClick={() => appliquer(lot)}
                            >
                              Appliquer
                            </button>
                          )}
                          {lot.status === 'applied' && peutAnnuler && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={busy}
                              onClick={() => annuler(lot)}
                            >
                              Annuler
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <DetailErreurs lot={detail} onFermer={() => setDetail(null)} />
    </div>
  )
}

/**
 * LE DÉTAIL D'UN REJET — la liste ENTIÈRE des lignes en erreur, jamais tronquée (D98).
 *
 * Un compte d'erreurs (« 12 lignes en erreur ») dit l'ampleur, pas quoi corriger. Le numéro de
 * ligne renvoie à la ligne du CSV, et le message dit ce qui cloche : c'est ce qui permet de rouvrir
 * le fichier au bon endroit plutôt que de le relire en entier.
 */
function DetailErreurs({ lot, onFermer }) {
  const lignes = lot?.errors ? Object.entries(lot.errors) : []
  return (
    <Modal open={!!lot} onClose={onFermer} titre={lot ? `Erreurs — ${lot.fileName || 'import'}` : ''} taille="md">
      {lignes.length === 0 ? (
        <div className="sub">Ce lot ne porte aucune erreur.</div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th className="num">Ligne</th>
                <th>Erreur</th>
              </tr>
            </thead>
            <tbody>
              {lignes.map(([ligne, message]) => (
                <tr key={ligne}>
                  <td className="num">{ligne}</td>
                  <td>{message}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  )
}
