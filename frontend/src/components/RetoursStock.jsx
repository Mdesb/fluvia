import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import { dateHeureFr } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { euros } from '../api/produit.js'
import { idDe } from '../api/iri.js'

// LES RETOURS CLIENTS — un geste que le serveur ATTEND d'un humain, et qui n'avait pas de bouton.
//
// ── CE QUE LE MODULE DIT DE LUI-MÊME ────────────────────────────────────────────────────────────
//
// `ReintegrationRetourHandler` porte cette phrase :
//
//   > réintégration du stock après un avoir M2, **jamais automatique** — invoquée volontairement
//   > après confirmation opérateur du retour physique en bon état.
//
// C'est un choix, et un bon : un avoir dit qu'on a remboursé, pas que la marchandise est revenue ni
// qu'elle est revendable. Seul quelqu'un qui l'a en main peut le dire.
//
// Mais le geste n'avait aucun écran. Un avoir était donc émis, l'argent rendu, et la marchandise
// n'était jamais remise en stock : le compte informatique descend et ne remonte plus, et l'écart
// avec le comptage physique grandit à chaque retour, sans que rien ne le signale.
//
// ── POURQUOI CET ÉCRAN MONTRE « DÉJÀ RÉINTÉGRÉ » AVANT TOUT LE RESTE ────────────────────────────
//
// Rien côté serveur n'empêche de réintégrer deux fois le même avoir : le processeur vérifie le
// périmètre et les champs, pas l'unicité. Deux clics font donc entrer la marchandise deux fois, et
// cette erreur-là est INVISIBLE — un stock trop haut ne bloque personne, il se découvre à
// l'inventaire suivant.
//
// Le mouvement genere porte `referenceType: 'Avoir'` et `referenceId` : on peut donc savoir ce qui a
// déjà été traité, et c'est ce que la colonne « État » affiche.
//
// ⚠ ET CETTE MESURE A UNE LIMITE QU'ON NE PEUT PAS TAIRE. `MouvementStock` ne déclare de filtre que
// sur `articleStock`, `type` et `date` — pas sur `referenceId`. On interroge donc les ajustements
// positifs et on cherche dedans. Si le serveur en détient plus que la page n'en rend, l'absence
// d'un avoir dans cette liste ne prouve rien : l'état devient « indéterminé », jamais « à traiter ».
// Annoncer « pas encore réintégré » sur une mesure tronquée ferait doubler un stock.

const NATURES = {
  annulation: 'Annulation',
  remboursement: 'Remboursement',
  geste_commercial: 'Geste commercial',
}

export default function RetoursStock({ etabActif, articles, peutAjuster, onErreur, onFait }) {
  const [avoirs, setAvoirs] = useState(null)
  const [reintegres, setReintegres] = useState(new Set())
  const [mesurePartielle, setMesurePartielle] = useState(false)
  const [chargement, setChargement] = useState(true)
  const [aTraiter, setATraiter] = useState(null)

  const charger = useCallback(async () => {
    setChargement(true)
    try {
      const [av, mv] = await Promise.all([
        api.avoirs(),
        api.stockAjustementsPositifs(),
      ])
      setAvoirs(membres(av))

      const mouvements = membres(mv)
      const total = mv?.totalItems ?? mv?.['hydra:totalItems']
      setMesurePartielle(typeof total === 'number' && total > mouvements.length)

      const vus = new Set()
      for (const m of mouvements) {
        if (m.referenceType === 'Avoir' && m.referenceId) vus.add(String(m.referenceId))
      }
      setReintegres(vus)
    } catch (e) {
      // ⚠ `null` ET NON `[]` : une lecture qui a échoué ne dit pas « aucun retour ». La table
      // affichera l'échec, pas une absence.
      setAvoirs(null)
      onErreur(e.message || 'Les avoirs n’ont pas pu être lus.')
    } finally {
      setChargement(false)
    }
  }, [etabActif, onErreur])

  useEffect(() => {
    charger()
  }, [charger])

  // Aucun filtre d'ordre n'est déclaré sur `Avoir` : un `order[...]` serait ignoré en silence, et
  // la liste arriverait dans l'ordre du serveur en ayant l'air triée. On trie donc ici, et on le
  // sait.
  const tries = useMemo(
    () => [...(avoirs || [])].sort((a, b) => String(b.dateHeure || '').localeCompare(String(a.dateHeure || ''))),
    [avoirs],
  )

  function etat(avoir) {
    if (reintegres.has(String(idDe(avoir)))) return ['Réintégré', 'good']
    if (mesurePartielle) return ['Indéterminé', 'mut']
    return ['À traiter', 'warn']
  }

  async function reintegrer(avoir, articleStock, quantite) {
    onErreur(null)
    try {
      await api.reintegrerRetour({
        avoirId: idDe(avoir),
        // L'identifiant NU, pas une IRI : le resolveur du serveur ne lit que le dernier segment
        // (`basename`) et exige un UUID. Composer une IRI ici m'a deja fait ecrire un chemin qui
        // n'existe pas (`/api/stock_articles/` au lieu de `/api/article_stocks/`) — sans consequence
        // parce que seul le segment compte, mais illisible pour qui relit.
        articleStock,
        // La quantité part en CHAÎNE : le serveur la lit telle quelle (`(string) $corps['quantite']`)
        // et la passe au calcul de couche de coût, où un flottant JSON perdrait des décimales.
        quantite: String(quantite),
      })
      setATraiter(null)
      await charger()
      onFait('Retour réintégré en stock.')
    } catch (e) {
      onErreur(e.message || 'La réintégration a échoué.')
    }
  }

  return (
    <div className="card">
      <div className="card-h">
        <h3>Retours clients</h3>
      </div>
      <div className="card-b">
        <p className="hint">
          Un avoir dit qu&rsquo;on a remboursé — pas que la marchandise est revenue, ni qu&rsquo;elle
          est revendable. La remise en stock est donc <strong>volontaire</strong> : elle se fait ici,
          après avoir eu l&rsquo;article en main.
        </p>

        {mesurePartielle && (
          <div className="banner banner-warn">
            Le serveur détient plus de mouvements que cette page n&rsquo;en a lu, et il n&rsquo;existe
            pas de filtre pour interroger un avoir précis. Les retours non trouvés sont donc marqués
            <strong> « indéterminé »</strong> plutôt que « à traiter » : vérifiez le mouvement de
            stock avant de réintégrer, au risque de le faire deux fois.
          </div>
        )}

        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : avoirs === null ? (
          <p className="empty">Les avoirs n&rsquo;ont pas pu être lus.</p>
        ) : tries.length === 0 ? (
          <p className="empty">Aucun avoir. Un retour se déclare d&rsquo;abord en caisse, par un avoir.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Avoir</th>
                  <th>Le</th>
                  <th className="num">Montant</th>
                  <th>Motif</th>
                  <th>Nature</th>
                  <th>Stock</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {tries.map((a) => {
                  const [libelle, ton] = etat(a)
                  const fait = reintegres.has(String(idDe(a)))
                  return (
                    <tr key={idDe(a)}>
                      <td className="mono">{a.numero || '—'}</td>
                      <td>{dateHeureFr(a.dateHeure)}</td>
                      <td className="num">{euros(Number(a.montant))}</td>
                      <td>{a.motif || '—'}</td>
                      <td>{NATURES[a.nature] || a.nature || '—'}</td>
                      <td><span className={`badge ${ton}`}>{libelle}</span></td>
                      <td className="num">
                        {peutAjuster && !fait && (
                          <button type="button" className="btn sm" onClick={() => setATraiter(a)}>
                            Réintégrer
                          </button>
                        )}
                        {fait && <span className="sub">rien à faire</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {aTraiter && (
        <ReintegrerModal
          avoir={aTraiter}
          articles={articles || []}
          indetermine={mesurePartielle}
          onFermer={() => setATraiter(null)}
          onValider={reintegrer}
        />
      )}
    </div>
  )
}

function ReintegrerModal({ avoir, articles, indetermine, onFermer, onValider }) {
  const [article, setArticle] = useState('')
  const [quantite, setQuantite] = useState('1')
  const [envoi, setEnvoi] = useState(false)

  // La quantité est décimale côté serveur (couche de coût) : on accepte le point, pas la virgule,
  // et on refuse le vide plutôt que d'envoyer une chaîne que le serveur rejetterait en 422.
  const quantiteValide = /^\d+(\.\d{1,3})?$/.test(quantite.trim()) && Number(quantite) > 0

  async function valider() {
    setEnvoi(true)
    await onValider(avoir, article, quantite.trim())
    setEnvoi(false)
  }

  return (
    <Modal open onClose={onFermer} titre={`Réintégrer le retour ${avoir.numero || ''}`} taille="md">
      <div className="deflist">
        <div><span>Avoir</span><span className="mono">{avoir.numero || '—'}</span></div>
        <div><span>Montant remboursé</span><span>{euros(Number(avoir.montant))}</span></div>
        <div><span>Motif</span><span>{avoir.motif || '—'}</span></div>
      </div>

      {indetermine && (
        <div className="banner banner-warn">
          Cette page n&rsquo;a pas pu vérifier que ce retour n&rsquo;a pas déjà été réintégré.
          Réintégrer deux fois gonfle le stock sans rien bloquer : l&rsquo;écart ne se verra
          qu&rsquo;à l&rsquo;inventaire.
        </div>
      )}

      <div className="field">
        <label htmlFor="rs-art">Article revenu en stock</label>
        <select id="rs-art" className="select" value={article} onChange={(e) => setArticle(e.target.value)}>
          <option value="">Choisir…</option>
          {articles.map((a) => (
            <option key={idDe(a)} value={idDe(a)}>{a.libelle || a.codeEAN || idDe(a)}</option>
          ))}
        </select>
        <div className="hint">
          {/* L'avoir porte la vente d'origine, pas l'article de STOCK : le lien produit → article
              passe par un rattachement qui n'est pas toujours posé. On demande donc, plutôt que de
              deviner un article et de créditer le mauvais. */}
          L&rsquo;avoir ne dit pas quel article de stock est revenu — il porte la vente, et le lien
          vers le stock n&rsquo;existe que si le produit a été rattaché à un article.
        </div>
      </div>

      <div className="field">
        <label htmlFor="rs-qte">Quantité remise en stock</label>
        <input
          id="rs-qte"
          className="input num"
          value={quantite}
          onChange={(e) => setQuantite(e.target.value)}
          inputMode="decimal"
        />
        <div className="hint">
          Elle entre au prix d&rsquo;achat courant, comme toute entrée manuelle — pas au prix
          auquel elle avait été vendue.
        </div>
      </div>

      <div className="bar">
        <button type="button" className="btn" onClick={onFermer}>Annuler</button>
        <button
          type="button"
          className="btn primary"
          onClick={valider}
          disabled={envoi || !article || !quantiteValide}
        >
          {envoi ? 'Réintégration…' : 'Réintégrer en stock'}
        </button>
      </div>
    </Modal>
  )
}
