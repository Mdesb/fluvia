import { useCallback, useEffect, useState } from 'react'
import { api, membres, ApiError } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import DevisModal from '../components/DevisModal.jsx'
import { mot } from '../api/vocabulaire.js'

// Pièces commerciales (FAC-1) : devis -> bon de commande -> bon de livraison -> facture.
//
// POUR QUI. Les clubs qui vendent sans caisse : un stage, une location de créneau, une prestation
// facturée à une collectivité. Ils envoient un devis, le client l'accepte, et la facture doit
// reprendre ce qui a été convenu sans ressaisie — c'est la ressaisie qui fabrique les écarts.
//
// CE QUE CET ÉCRAN NE DÉCIDE PAS. Les gestes possibles viennent du serveur (`gestesPossibles`), pas
// d'une table recopiée ici. Le jour où la chaîne change, l'écran suit sans qu'on y touche ; s'il
// décidait lui-même, les deux divergeraient en silence. Seuls les DROITS sont évalués ici, avec
// `aLeDroit` — le serveur ne connaît pas l'utilisateur au moment où il sérialise la pièce.

const NATURE_BADGE = { quote: 'info', sales_order: 'warn', delivery_note: 'mut' }
const STATUT_BADGE = {
  draft: 'mut',
  issued: 'info',
  accepted: 'good',
  rejected: 'crit',
  expired: 'crit',
  converted: 'good',
  cancelled: 'mut',
}

const LIBELLE_GESTE = {
  issue: 'Émettre',
  accept: 'Accepter',
  reject: 'Refuser',
  derive: 'Transformer',
  invoice: 'Facturer',
}

// `invoice` engage la facturation directe : il exige `facturation.emettre_directe` là où les quatre
// autres se contentent de `facturation.gerer`. Ne pas déduire le bouton du droit qui affiche la ligne.
const DROIT_GESTE = {
  issue: 'facturation.gerer',
  accept: 'facturation.gerer',
  reject: 'facturation.gerer',
  derive: 'facturation.gerer',
  invoice: 'facturation.emettre_directe',
}

const euros = (montant) =>
  new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(montant || 0))

const idDe = (ressource) => {
  const brut = ressource?.id || ''
  return String(brut).includes('/') ? String(brut).split('/').pop() : String(brut)
}

export default function Facturation({ etabActif, droits }) {
  const [pieces, setPieces] = useState([])
  const [tauxTva, setTauxTva] = useState([])
  const [nouveauDevis, setNouveauDevis] = useState(false)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      setPieces(membres(await api.piecesCommerciales()))
      setErreur(null)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Chargement impossible.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [etabActif, recharger])

  useEffect(() => {
    api
      .tauxTvas()
      .then((r) => setTauxTva(membres(r).filter((t) => t.actif !== false)))
      .catch(() => setTauxTva([]))
  }, [etabActif])

  async function faire(piece, geste) {
    const id = idDe(piece)
    setEnCours(`${id}:${geste}`)
    try {
      await api.gestePiece(id, geste)
      setErreur(null)
      await recharger()
    } catch (e) {
      // Le message du serveur tel quel : c'est lui qui sait pourquoi il refuse, et le reformuler
      // ferait diverger le diagnostic de la réalité.
      setErreur(e instanceof ApiError ? e.message : 'Action impossible.')
    } finally {
      setEnCours(null)
    }
  }

  const peutGerer = aLeDroit(droits, 'facturation.gerer')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Facturation</h1>
          <p>Devis, bons de commande, bons de livraison</p>
        </div>
        {/* LE FORMULAIRE EST DEVENU UNE MODALE PARTAGÉE, ET CE N'EST PAS UNE QUESTION DE STYLE.
            Il vit maintenant dans `components/DevisModal.jsx`, appelé aussi depuis la fiche d'un
            client — c'est la demande de Maxime du 28/08. Le laisser ici en carte permanente aurait
            imposé d'en écrire une seconde copie là-bas. */}
        {peutGerer && (
          <div className="actions">
            <button className="btn primary" type="button" onClick={() => setNouveauDevis(true)}>
              + Nouveau devis
            </button>
          </div>
        )}
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="resa-grid">
        <div className="card">
          <div className="card-h">
            <h3>Pièces commerciales</h3>
            <span className="sub">{pieces.length} en cours</span>
          </div>
          <div className="card-b">
            {chargement ? (
              <div className="empty">Chargement…</div>
            ) : pieces.length === 0 ? (
              <div className="empty">
                <p>Aucune pièce commerciale.</p>
                <p className="hint">
                  Un devis propose un prix à un client avant qu&apos;il s&apos;engage. Une fois accepté,
                  il se transforme en bon de commande puis en facture — sans que personne ne ressaisisse
                  les lignes, ce qui est exactement là où naissent les écarts.
                </p>
              </div>
            ) : (
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Pièce</th>
                    <th>Destinataire</th>
                    <th className="num">Total TTC</th>
                    <th>Statut</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {pieces.map((p) => (
                    <LignePiece
                      key={idDe(p)}
                      piece={p}
                      droits={droits}
                      enCours={enCours}
                      onGeste={faire}
                    />
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>

      </div>

      <DevisModal
        open={nouveauDevis}
        onClose={() => setNouveauDevis(false)}
        onCree={() => { setNouveauDevis(false); recharger() }}
      />
    </div>
  )
}

function LignePiece({ piece, droits, enCours, onGeste }) {
  const id = idDe(piece)
  const gestes = piece.gestesPossibles || []

  return (
    <tr>
      <td>
        <span className={`badge ${NATURE_BADGE[piece.nature] || 'mut'}`}>{mot(piece.nature)}</span>{' '}
        <span className="mono">{piece.numero || '—'}</span>
      </td>
      <td className="nm">{piece.destinataire?.raisonSociale || piece.destinataire?.nom || '—'}</td>
      <td className="num">{euros(piece.totalTTC)}</td>
      <td>
        <span className={`badge ${STATUT_BADGE[piece.statut] || 'mut'}`}>{mot(piece.statut)}</span>
      </td>
      <td>
        {gestes.length === 0 ? (
          <span className="hint">—</span>
        ) : (
          gestes.map((g) => (
            <BoutonGeste
              key={g}
              geste={g}
              droits={droits}
              occupe={enCours === `${id}:${g}`}
              onClick={() => onGeste(piece, g)}
            />
          ))
        )}
      </td>
    </tr>
  )
}

// Un geste que l'état n'autorise pas est ABSENT — il ne figure pas dans `gestesPossibles`. Un geste
// autorisé mais dont l'utilisateur n'a pas le droit reste VISIBLE et désactivé (D54) : le cacher
// ferait croire qu'il est impossible, et l'utilisateur chercherait un contournement.
//
// L'ordre des deux phrases n'est pas indifférent : d'abord POURQUOI le geste est renforcé — un fait
// sur la pièce —, ensuite si vous y avez droit — un fait sur vous. Seul le premier explique la
// situation ; commencer par le refus laisse croire à une erreur de compte.
function BoutonGeste({ geste, droits, occupe, onClick }) {
  const droitRequis = DROIT_GESTE[geste]
  const autorise = aLeDroit(droits, droitRequis)
  const renforce = 'invoice' === geste

  const titre = autorise
    ? undefined
    : renforce
      ? `Facturer émet une facture définitive, numérotée et inaltérable : ce geste exige le droit « ${droitRequis} », plus fort que celui qui permet de gérer la pièce. Vous ne l’avez pas.`
      : `Ce geste exige le droit « ${droitRequis} ». Vous ne l’avez pas.`

  return (
    <button
      type="button"
      className={`btn sm ${renforce ? 'primary' : 'ghost'}`}
      disabled={!autorise || occupe}
      title={titre}
      onClick={onClick}
    >
      {occupe ? '…' : LIBELLE_GESTE[geste] || geste}
    </button>
  )
}
