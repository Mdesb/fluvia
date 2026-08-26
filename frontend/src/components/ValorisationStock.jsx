import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import { api, membres } from '../api/client.js'
import { mot } from '../api/vocabulaire.js'
import { euros } from '../api/produit.js'

// La valorisation du stock : ce que vaut ce qu'il reste.
//
// CE CHIFFRE VA DANS UN BILAN. IL NE DOIT DONC PAS ÊTRE AFFICHÉ SEUL.
//
// La valorisation est calculée en consommant les lots par couches (FIFO). Quand les couches sont en
// rupture, `ConsommationLots` impute **partiellement** : le mouvement existe, sa quantité est
// complète, et son coût ne couvre qu'une partie. Le serveur en fait un `warning` dans un journal
// (`stock.consommation.rupture_couches`) — c'est-à-dire nulle part, pour l'exploitant.
//
// Un avertissement qui ne vit que dans un journal n'avertit personne. On le reconstitue donc ici,
// par soustraction : somme des quantités imputées contre quantité du mouvement.
//
// TROIS PRÉCAUTIONS, ET ELLES VIENNENT DE `claude-G` QUI A LU LE CODE.
//
// 1. **Seules les sorties sont contrôlées.** Les imputations sont écrites sur le chemin des sorties
//    (`creerMouvementSortie`) et sur `sortie_vente`. Appliquer la soustraction à une entrée d'achat
//    conclurait « totalement non couvert » sur un mouvement parfaitement normal — le contraire de ce
//    qu'on cherche.
// 2. **La collection peut être tronquée** par la pagination. Comme pour les lots : si elle l'est, on
//    ne fait pas le contrôle et on le dit, plutôt que de rendre un verdict sur une partie.
// 3. **Les imputations ne sont pas lisibles depuis le mouvement.** `MouvementStock` expose bien
//    `imputations` dans `mouvement:read`, mais aucune propriété d'`ImputationLotStock` ne porte ce
//    groupe : la collection sort en simples IRI. On charge donc la collection séparément, ce qui
//    marche parce qu'elle est listable.
//
// CE QUE LE SERVEUR NE SAIT PAS FAIRE, ET QU'ON NE FAIT PAS SEMBLANT DE FAIRE.
//
// `/stock/articles/{id}/valorisation?date=` reconstruit la valeur historique d'UN article.
// `/stock/valorisation` ne prend pas de date : il n'y a pas de valeur historique du stock entier.
// La reconstituer demanderait un appel par article — deux cents requêtes pour un seul chiffre, et un
// total dont on ne saurait pas dire à quel instant il correspond. On offre donc l'historique là où
// il existe, article par article, et on dit pourquoi il n'est pas au-dessus du total.

const SORTIES = [
  'sortie_vente',
  'ajustement_negatif',
  'perte_casse',
  'sortie_transfert',
  'retour_fournisseur',
]

export default function ValorisationStock({ etabActif, onErreur }) {
  const [lignes, setLignes] = useState([])
  const [mouvements, setMouvements] = useState([])
  const [imputations, setImputations] = useState([])
  const [imputationsTronquees, setImputationsTronquees] = useState(false)
  const [chargement, setChargement] = useState(true)
  const [detail, setDetail] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const v = await api.stockValorisation()
      setLignes(Array.isArray(v) ? v : membres(v))
    } catch (e) {
      onErreur(e.message)
    } finally {
      setChargement(false)
    }
    // Le contrôle de couverture ne doit pas empêcher d'afficher la valorisation : s'il échoue, on
    // perd le contrôle, pas le chiffre.
    try {
      const [m, i] = await Promise.all([api.stockMouvements(), api.stockImputations()])
      setMouvements(membres(m))
      const lus = membres(i)
      const annonces = i?.totalItems ?? i?.['hydra:totalItems'] ?? lus.length
      setImputations(lus)
      setImputationsTronquees(annonces > lus.length)
    } catch {
      setImputations([])
      setImputationsTronquees(true)
    }
  }, [etabActif, onErreur])

  useEffect(() => {
    recharger()
  }, [recharger])

  const total = lignes.reduce((s, l) => s + (parseFloat(l.valorisation) || 0), 0)

  // Les sorties dont le coût ne couvre pas la quantité.
  const malCouverts = useMemo(() => {
    if (imputationsTronquees) return []
    const parMouvement = {}
    for (const im of imputations) {
      const id = im.mouvementStock?.id || String(im.mouvementStock || '').split('/').pop()
      if (!id) continue
      parMouvement[id] = (parMouvement[id] || 0) + (parseFloat(im.quantiteImputee) || 0)
    }
    return mouvements
      .filter((m) => SORTIES.includes(m.type))
      .map((m) => ({
        mouvement: m,
        quantite: parseFloat(m.quantite) || 0,
        impute: parMouvement[m.id] || 0,
      }))
      // Tolérance au millième : les quantités sont décimales à trois chiffres, et une comparaison
      // stricte signalerait des écarts d'arrondi qui n'en sont pas.
      .filter((x) => x.quantite - x.impute > 0.0005)
  }, [mouvements, imputations, imputationsTronquees])

  if (chargement) {
    return (
      <section className="card">
        <div className="card-b center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      </section>
    )
  }

  return (
    <>
      <section className="card">
        <div className="card-h">
          <h3>Valeur du stock</h3>
          <span className="sub">au coût d'achat, aujourd'hui</span>
        </div>
        <div className="card-b">
          {lignes.length === 0 ? (
            <div className="empty">
              Rien à valoriser. La valeur du stock est celle des lots encore ouverts, au coût auquel
              ils sont entrés — pas au prix de vente.
            </div>
          ) : (
            <>
              {imputationsTronquees ? (
                <div className="banner banner-warn">
                  <b>Le contrôle de couverture des coûts n'a pas pu être fait.</b> Il y a plus de
                  lignes d'imputation que cet écran n'en charge, et un verdict rendu sur une partie ne
                  vaudrait rien. Les valeurs ci-dessous sont celles calculées par le serveur ; c'est
                  seulement leur vérification qui manque.
                </div>
              ) : malCouverts.length > 0 ? (
                <div className="banner banner-error">
                  <b>{malCouverts.length} sortie{malCouverts.length > 1 ? 's ont' : ' a'} un coût
                  incomplet.</b> La marchandise est bien sortie, mais il n'y avait pas assez de lots
                  ouverts pour en couvrir tout le coût — la valeur ci-dessous est donc calculée sur un
                  historique d'achats incomplet. C'est le genre d'écart qu'on ne veut pas découvrir en
                  signant un bilan. Le détail est plus bas.
                </div>
              ) : null}

              <table className="tbl">
                <thead>
                  <tr>
                    <th>Article</th>
                    <th className="num">Valeur</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {lignes.map((l) => (
                    <tr key={l.articleStock}>
                      <td><span className="nm">{l.libelle || '—'}</span></td>
                      <td className="num">{euros(l.valorisation)}</td>
                      <td className="num">
                        <button
                          className="btn ghost sm"
                          type="button"
                          onClick={() => setDetail({ id: l.articleStock, libelle: l.libelle })}
                        >
                          Valeur à une date
                        </button>
                      </td>
                    </tr>
                  ))}
                  <tr>
                    <td><b>Total</b></td>
                    <td className="num"><b>{euros(total.toFixed(2))}</b></td>
                    <td />
                  </tr>
                </tbody>
              </table>

              <div className="hint">
                La valeur historique existe article par article, pas sur le total : le serveur ne
                reconstruit une date que pour un article à la fois. La calculer pour l'établissement
                entier demanderait un appel par article, et donnerait un total dont on ne pourrait pas
                dire à quel instant il correspond.
              </div>
            </>
          )}
        </div>
      </section>

      {malCouverts.length > 0 && (
        <section className="card" style={{ marginTop: 16 }}>
          <div className="card-h">
            <h3>Sorties au coût incomplet</h3>
            <span className="sub">marchandise sortie sans lot d'achat en face</span>
          </div>
          <div className="card-b">
            <table className="tbl">
              <thead>
                <tr>
                  <th>Article</th>
                  <th>Nature</th>
                  <th className="num">Quantité sortie</th>
                  <th className="num">Quantité couverte</th>
                  <th className="num">Non couverte</th>
                </tr>
              </thead>
              <tbody>
                {malCouverts.map((x) => (
                  <tr key={x.mouvement.id}>
                    <td>{x.mouvement.articleStock?.libelle || '—'}</td>
                    <td><span className="badge mut">{mot(x.mouvement.type)}</span></td>
                    <td className="num">{x.quantite}</td>
                    <td className="num">{x.impute}</td>
                    <td className="num">
                      <span className="badge crit">{(x.quantite - x.impute).toFixed(3)}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="hint">
              Cela arrive quand du stock sort sans être jamais entré — une réception oubliée, ou un
              article dont le stock a été corrigé à la main sans lot d'achat. La sortie est correcte,
              c'est son coût qui manque.
            </div>
          </div>
        </section>
      )}

      <ValeurADateModal etat={detail} onClose={() => setDetail(null)} onErreur={onErreur} />
    </>
  )
}

// La valeur d'un article à une date passée. Le serveur reconstruit à partir des mouvements ; on
// affiche la date qu'il RENVOIE et non celle qu'on a demandée, parce que ce sont deux choses
// différentes dès qu'un fuseau ou une heure s'en mêle.
function ValeurADateModal({ etat, onClose, onErreur }) {
  const [date, setDate] = useState('')
  const [resultat, setResultat] = useState(null)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (etat) { setDate(''); setResultat(null) }
  }, [etat])

  async function interroger(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      setResultat(await api.stockValorisationArticle(etat.id, date || undefined))
    } catch (err) {
      onErreur(err.message || "La valeur n'a pas pu être calculée.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!etat} onClose={onClose} titre={etat ? `Valeur — ${etat.libelle}` : ''}>
      {etat && (
        <form onSubmit={interroger}>
          <div className="field">
            <label htmlFor="vl-date">À quelle date ?</label>
            <input id="vl-date" className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            <div className="hint">
              Laissez vide pour la valeur d'aujourd'hui. À une date passée, le serveur rejoue les
              mouvements : c'est la valeur qu'avait cet article ce jour-là, pas sa valeur actuelle.
            </div>
          </div>

          {resultat && (
            <div className="banner banner-ok">
              <b>{euros(resultat.valorisation)}</b> au {resultat.date ? resultat.date.slice(0, 10) : '—'}.
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Fermer</button>
            <button className="btn primary" type="submit" disabled={enCours}>
              {enCours ? 'Calcul…' : 'Calculer'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}
