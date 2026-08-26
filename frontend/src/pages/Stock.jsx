import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import ReferentielEditable from '../components/ReferentielEditable.jsx'
import InventaireStock from '../components/InventaireStock.jsx'
import AchatsStock from '../components/AchatsStock.jsx'
import Tabs from '../components/Tabs.jsx'
import { dateHeureFr } from '../components/Liste.jsx'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { euros, libelleProduit } from '../api/produit.js'

// Stock — soixante-trois opérations exposées, aucune appelée jusqu'ici.
//
// L'ORDRE DES SECTIONS EST L'ORDRE DES URGENCES, PAS CELUI DU MODÈLE.
//
// Ce qui appelle une action aujourd'hui vient d'abord : les articles sous leur seuil. Le reste — la
// liste, le journal — répond à une question qu'on se pose, pas à une décision qu'on doit prendre.
//
// « COMBIEN M'EN RESTE-T-IL » N'EST PAS UNE PROPRIÉTÉ DU MODÈLE.
//
// `ArticleStock` ne porte aucune quantité : le stock réel vit dans les lots (`StockLot`), un article
// pouvant en avoir plusieurs — dates d'entrée et coûts d'achat différents, ce qui est exactement ce
// qu'il faut pour valoriser en FIFO. La conséquence est qu'une liste d'articles affichée telle quelle
// n'aurait pas le seul chiffre qu'on vient y chercher. On agrège donc les lots à l'écran.
//
// C'est un calcul qui a sa place sur le serveur, comme `getQuantiteDisponible()` sur `ParcPatins`.
// Demandé à `claude-F`, qui tient ce périmètre. En attendant, l'agrégation est faite ici — et elle
// REFUSE de rendre un total si la liste des lots est tronquée par la pagination.
//
// C'est le point important de cet écran. Un total calculé sur une page de lots au lieu de tous
// serait faux **en ayant l'air juste** : rien à l'écran ne distinguerait « il reste 12 » de « il
// reste 12 sur la première page ». On préfère donc afficher un point d'interrogation et dire
// pourquoi. Le jour où le champ serveur existe, l'agrégation et ce garde-fou disparaissent ensemble.

export default function Stock({ etabActif, droits }) {
  const [articles, setArticles] = useState([])
  const [lots, setLots] = useState([])
  const [lotsTronques, setLotsTronques] = useState(false)
  const [alertes, setAlertes] = useState([])
  const [mouvements, setMouvements] = useState([])
  const [parametrage, setParametrage] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [ajustement, setAjustement] = useState(null)
  const [rattachement, setRattachement] = useState(null)
  const [recherche, setRecherche] = useState('')
  const [onglet, setOnglet] = useState('etat')

  const peutAjuster = aUnDesDroits(droits, ['stock.ajuster', 'stock.gerer'])
  const peutGererArticle = aUnDesDroits(droits, ['stock.gerer_article', 'stock.gerer'])

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // `alertes-reappro` et le paramétrage sont tolérants à l'échec : une alerte manquante gêne, une
      // liste d'articles manquante empêche de travailler. On ne fait donc pas échouer l'écran entier
      // pour un modèle de lecture indisponible.
      const [a, l, m] = await Promise.all([api.stockArticles(), api.stockLots(), api.stockMouvements()])
      const lus = membres(l)
      const annonces = l?.totalItems ?? l?.['hydra:totalItems'] ?? lus.length
      setArticles(membres(a))
      setLots(lus)
      // Le serveur annonce combien de lots existent ; si on n'en a pas reçu autant, toute somme
      // calculée dessus est fausse — et rien à l'écran ne le montrerait.
      setLotsTronques(annonces > lus.length)
      setMouvements(membres(m))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
    api.stockAlertesReappro().then((r) => setAlertes(Array.isArray(r) ? r : membres(r))).catch(() => setAlertes([]))
    api.stockParametrage().then((r) => setParametrage(membres(r)[0] || null)).catch(() => setParametrage(null))
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  // Le stock d'un article = la somme de ce qui reste dans ses lots.
  const restantParArticle = useMemo(() => {
    const total = {}
    for (const lot of lots) {
      const id = lot.articleStock?.id || String(lot.articleStock || '').split('/').pop()
      if (!id) continue
      total[id] = (total[id] || 0) + (parseFloat(lot.quantiteRestante) || 0)
    }
    return total
  }, [lots])

  const filtres = useMemo(() => {
    const q = recherche.trim().toLowerCase()
    if (!q) return articles
    return articles.filter(
      (a) => (a.libelle || '').toLowerCase().includes(q) || (a.codeEAN || '').toLowerCase().includes(q),
    )
  }, [articles, recherche])

  async function apres(message) {
    setSucces(message)
    setErreur(null)
    await recharger()
  }

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Stock</h1>
          <p>Ce qu'il reste, ce qu'il faut recommander, et les corrections</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <Tabs
        onglets={[
          ['etat', 'Ce qu’il reste'],
          ['achats', 'Achats'],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
      ) : onglet === 'achats' ? (
        <AchatsStock
          articles={articles}
          droits={droits}
          etabActif={etabActif}
          onErreur={setErreur}
          onFait={apres}
        />
      ) : (
        <>
          <AlertesSection alertes={alertes} />

          <ArticlesSection
            articles={filtres}
            total={articles.length}
            restantParArticle={restantParArticle}
            lotsCharges={lots.length}
            tronque={lotsTronques}
            recherche={recherche}
            onRecherche={setRecherche}
            peutAjuster={peutAjuster && !lotsTronques}
            onAjuster={(a) => setAjustement({ article: a, restant: restantParArticle[a.id] || 0 })}
            onRattacher={peutGererArticle ? (a) => setRattachement(a) : null}
            nonSuivis={articles.filter((a) => !a.produit).length}
          />

          {peutGererArticle && <ArticlesEdition onChange={recharger} />}

          <RegleEcart parametrage={parametrage} droits={droits} />

          <InventaireStock
            articles={articles}
            droits={droits}
            etabActif={etabActif}
            onErreur={setErreur}
            onFait={apres}
          />

          <JournalSection mouvements={mouvements} />
        </>
      )}

      <AjustementModal
        etat={ajustement}
        onClose={() => setAjustement(null)}
        onFait={(m) => { setAjustement(null); apres(m) }}
        onErreur={setErreur}
      />

      <RattachementModal
        article={rattachement}
        onClose={() => setRattachement(null)}
        onFait={(m) => { setRattachement(null); apres(m) }}
        onErreur={setErreur}
      />
    </div>
  )
}

// --------------------------------------------------------------------------------------------
// Ce qui appelle une action aujourd'hui.
// --------------------------------------------------------------------------------------------
function AlertesSection({ alertes }) {
  if (alertes.length === 0) {
    return (
      <section className="card">
        <div className="card-h">
          <h3>À recommander</h3>
          <span className="sub">articles passés sous leur seuil</span>
        </div>
        <div className="card-b">
          <div className="empty">
            Aucun article sous son seuil. Cette liste se remplit toute seule quand un stock descend
            sous le minimum réglé sur la fiche article — c'est le seul endroit qui vous prévient avant
            la rupture.
          </div>
        </div>
      </section>
    )
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>À recommander</h3>
        <span className="sub">{alertes.length} article{alertes.length > 1 ? 's' : ''} sous son seuil</span>
      </div>
      <div className="card-b">
        <table className="tbl">
          <thead>
            <tr>
              <th>Article</th>
              <th className="num">Il reste</th>
              <th className="num">Seuil mini</th>
              <th className="num">À commander</th>
            </tr>
          </thead>
          <tbody>
            {alertes.map((a) => (
              <tr key={a.articleStock}>
                <td>
                  <span className="nm">{a.libelle || '—'}</span>
                  {a.codeEAN && <div className="sub mono">{a.codeEAN}</div>}
                </td>
                <td className="num">
                  <span className="badge crit">{a.disponibilite}</span>
                </td>
                <td className="num">{nombre(a.seuilMin)}</td>
                <td className="num"><b>{nombre(a.quantiteSuggeree)}</b></td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="hint">
          La quantité à commander est calculée pour remonter au seuil maximum de chaque article, pas
          pour couvrir une prévision de ventes.
        </div>
      </div>
    </section>
  )
}

// --------------------------------------------------------------------------------------------
// Les articles et ce qu'il en reste.
// --------------------------------------------------------------------------------------------
function ArticlesSection({
  articles, total, restantParArticle, lotsCharges, tronque, recherche, onRecherche, peutAjuster,
  onAjuster, onRattacher, nonSuivis,
}) {
  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Articles</h3>
        <span className="sub">{total} référence{total > 1 ? 's' : ''}</span>
        <div className="r" style={{ minWidth: 240 }}>
          <input
            className="input"
            value={recherche}
            placeholder="Chercher un article ou un code-barres…"
            onChange={(e) => onRecherche(e.target.value)}
          />
        </div>
      </div>
      <div className="card-b">
        {total === 0 ? (
          <div className="empty">
            Aucun article de stock. Un article, c'est ce que vous achetez et comptez — une canette, une
            paire de lacets, un sac de sel. Il devient vendable en le rattachant à un produit du
            catalogue.
          </div>
        ) : articles.length === 0 ? (
          <div className="empty">Aucun article ne correspond à « {recherche.trim()} ».</div>
        ) : (
          <>
            {nonSuivis > 0 && (
              <div className="banner banner-warn">
                <b>{nonSuivis} article{nonSuivis > 1 ? 's ne sont' : " n'est"} rattaché
                {nonSuivis > 1 ? 's' : ''} à aucun produit du catalogue.</b> Vendre ne les fera pas
                descendre : leur quantité ne bougera que par correction manuelle. Ce n'est pas
                forcément une erreur — on peut suivre un consommable qu'on ne vend pas — mais un
                article à zéro mouvement ne veut pas dire la même chose selon le cas.
              </div>
            )}
            {tronque && (
              <div className="banner banner-error">
                <b>Les quantités ne sont pas affichées, et c'est volontaire.</b> Cet établissement a
                plus de lots de stock que cet écran n'en charge : toute somme calculée ici porterait
                sur une partie seulement, et rien ne distinguerait « il reste 12 » de « il reste 12
                sur les lots que j'ai vus ». La correction de stock est également désactivée — on ne
                corrige pas un chiffre qu'on ne connaît pas. Le reste de l'écran est exact.
              </div>
            )}
            <table className="tbl">
              <thead>
                <tr>
                  <th>Article</th>
                  <th>Unité</th>
                  <th className="num">Il reste</th>
                  <th className="num">Seuil mini</th>
                  <th>Suivi des ventes</th>
                  <th className="num">Prix d'achat HT</th>
                  {peutAjuster && <th />}
                </tr>
              </thead>
              <tbody>
                {articles.map((a) => {
                  const restant = restantParArticle[a.id] || 0
                  const seuil = parseFloat(a.seuilMin) || 0
                  const sousSeuil = seuil > 0 && restant < seuil
                  return (
                    <tr key={a.id}>
                      <td>
                        <span className="nm">{a.libelle || '—'}</span>
                        {a.codeEAN && <div className="sub mono">{a.codeEAN}</div>}
                        {a.actif === false && <span className="badge mut" style={{ marginLeft: 6 }}>inactif</span>}
                      </td>
                      <td>{mot(a.unite)}</td>
                      <td className="num">
                        {tronque ? (
                          <span className="badge mut" title="Trop de lots pour calculer un total fiable ici.">?</span>
                        ) : (
                          <span className={`badge ${sousSeuil ? 'crit' : restant > 0 ? 'good' : 'mut'}`}>
                            {nombre(restant)}
                          </span>
                        )}
                      </td>
                      <td className="num">{nombre(a.seuilMin)}</td>
                      <td>
                        {a.produit ? (
                          <span className="badge good" title="Une vente de ce produit décrémente cet article.">
                            {libelleProduit(a.produit)}
                          </span>
                        ) : (
                          <span
                            className="badge warn"
                            title="Aucune vente ne décrémentera cet article tant qu'il n'est rattaché à aucun produit."
                          >
                            non suivi
                          </span>
                        )}
                      </td>
                      <td className="num">{euros(a.prixAchatHT)}</td>
                      {peutAjuster && (
                        <td className="num">
                          <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                            {onRattacher && (
                              <button className="btn ghost sm" type="button" onClick={() => onRattacher(a)}>
                                {a.produit ? 'Changer le produit' : 'Rattacher'}
                              </button>
                            )}
                            <button className="btn ghost sm" type="button" onClick={() => onAjuster(a)}>
                              Corriger
                            </button>
                          </div>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
            {!tronque && (
              <div className="hint">
                La colonne « il reste » est la somme des lots encore ouverts — {lotsCharges} lots lus,
                soit la totalité. Un article dont tous les lots sont épuisés affiche zéro, ce qui est
                exact.
              </div>
            )}
          </>
        )}
      </div>
    </section>
  )
}

// --------------------------------------------------------------------------------------------
// Créer et modifier un article.
// --------------------------------------------------------------------------------------------
function ArticlesEdition({ onChange }) {
  // Un article se crée et se modifie, mais ne se supprime PAS ici, et l'API ne le propose pas non
  // plus : un article porte des lots, des mouvements et un historique de valorisation. Ce qu'un
  // exploitant veut, c'est le retirer de la circulation — c'est la case « actif ».
  const descripteur = {
    titre: 'Créer ou modifier un article',
    aQuoiCaSert:
      "Un article de stock est ce que vous achetez et comptez. Il est distinct du produit vendu : un "
      + 'même article peut servir plusieurs produits, et un produit peut n’en consommer aucun.',
    siVide: "Aucun article. Créez le premier avec son code-barres et son seuil d'alerte.",
    consequenceSuppression: '',
    charger: api.stockArticles,
    creer: api.creerArticleStock,
    modifier: api.majArticleStock,
    supprimer: null,
    champs: [
      {
        nom: 'libelle',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Canette 33 cl — cola',
        aide: 'Le nom que verra celui qui compte le stock, pas celui qui achète au comptoir.',
      },
      {
        nom: 'codeEAN',
        libelle: 'Code-barres',
        type: 'text',
        exemple: '3017620422003',
        aide: 'Facultatif, mais c’est lui qui permettra de scanner au lieu de chercher.',
      },
      {
        nom: 'unite',
        libelle: 'Unité',
        type: 'choix',
        options: [
          { valeur: 'piece', libelle: 'Pièce' },
          { valeur: 'kg', libelle: 'Kilogramme' },
          { valeur: 'litre', libelle: 'Litre' },
          { valeur: 'paquet', libelle: 'Paquet' },
          { valeur: 'autre', libelle: 'Autre' },
        ],
        aide: 'Ce qu’on compte : des pièces, des kilos, des litres.',
      },
      {
        nom: 'prixAchatHT',
        libelle: 'Prix d’achat HT',
        type: 'text',
        exemple: '0.4200',
        aide: 'Sert à valoriser le stock. Ce n’est pas le prix de vente.',
      },
      {
        nom: 'seuilMin',
        libelle: 'Seuil d’alerte',
        type: 'text',
        exemple: '24.000',
        aide: 'En dessous, l’article apparaît en haut de cet écran, dans « à recommander ».',
      },
      {
        nom: 'seuilMax',
        libelle: 'Quantité cible',
        type: 'text',
        exemple: '120.000',
        aide: 'La quantité à laquelle on veut remonter. C’est elle qui calcule ce qu’il faut commander.',
      },
      {
        nom: 'actif',
        libelle: 'En service',
        type: 'bool',
        libelleCase: 'Cet article est utilisé',
        aide:
          'Décocher le retire des listes sans rien supprimer : ses lots, ses mouvements et son '
          + 'historique restent consultables.',
      },
    ],
    colonnes: [
      { cle: 'libelle', titre: 'Article', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
      { cle: 'codeEAN', titre: 'Code-barres', rendu: (r) => <span className="mono">{r.codeEAN || '—'}</span> },
      { cle: 'seuilMin', titre: 'Seuil', rendu: (r) => nombre(r.seuilMin) },
      {
        cle: 'actif',
        titre: 'État',
        rendu: (r) => (
          <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'en service' : 'retiré'}</span>
        ),
      },
    ],
  }

  return (
    <div style={{ marginTop: 16 }}>
      <ReferentielEditable descripteur={descripteur} peutEcrire onChange={onChange} />
    </div>
  )
}

// --------------------------------------------------------------------------------------------
// Corriger un stock.
// --------------------------------------------------------------------------------------------
// Trois motifs, et ils ne racontent pas la même histoire dans les comptes : une correction en plus ou
// en moins dit qu'on s'était trompé en comptant, une perte dit qu'on a réellement perdu la
// marchandise. Confondre les deux fait disparaître les pertes dans le bruit des corrections.
const MOTIFS_AJUSTEMENT = [
  {
    valeur: 'ajustement_positif',
    titre: 'Il y en a plus que prévu',
    effet: 'Le comptage était faux : on ajoute la différence sans supposer d’achat.',
  },
  {
    valeur: 'ajustement_negatif',
    titre: 'Il y en a moins que prévu',
    effet: 'Le comptage était faux : on retire la différence sans supposer de perte.',
  },
  {
    valeur: 'perte_casse',
    titre: 'Perte ou casse',
    effet: 'La marchandise a réellement disparu. Comptabilisée comme une perte, pas comme une erreur.',
  },
]

function AjustementModal({ etat, onClose, onFait, onErreur }) {
  const [type, setType] = useState('ajustement_negatif')
  const [quantite, setQuantite] = useState('')
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (etat) { setType('ajustement_negatif'); setQuantite(''); setMotif('') }
  }, [etat])

  const q = parseFloat(quantite) || 0
  const apresCorrection =
    etat == null ? 0 : type === 'ajustement_positif' ? etat.restant + q : etat.restant - q
  const negatif = apresCorrection < 0

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.stockAjuster({
        articleStock: etat.article.id,
        type,
        quantite: String(q),
        motif: motif.trim(),
      })
      onFait(`Stock corrigé : « ${etat.article.libelle} » passe à ${nombre(apresCorrection)}.`)
    } catch (err) {
      onErreur(err.message || "La correction n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!etat} onClose={onClose} titre={etat ? `Corriger — ${etat.article.libelle}` : ''}>
      {etat && (
        <form onSubmit={envoyer}>
          <p style={{ marginTop: 0 }}>
            Le logiciel compte <b>{nombre(etat.restant)}</b> {mot(etat.article.unite)}.
          </p>

          <div className="fiche-sec" style={{ marginTop: 0 }}>Que s'est-il passé ?</div>
          {MOTIFS_AJUSTEMENT.map((m) => (
            <label
              key={m.valeur}
              style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 0', fontWeight: 400 }}
            >
              <input
                type="radio"
                name="type-ajustement"
                checked={type === m.valeur}
                onChange={() => setType(m.valeur)}
                style={{ marginTop: 3 }}
              />
              <span>
                <b>{m.titre}</b>
                <div className="sub">{m.effet}</div>
              </span>
            </label>
          ))}

          <div className="field" style={{ marginTop: 10 }}>
            <label htmlFor="st-qte">
              {type === 'ajustement_positif' ? 'Combien en plus ?' : 'Combien en moins ?'}
            </label>
            <input
              id="st-qte"
              className="input"
              type="number"
              step="0.001"
              min="0"
              required
              value={quantite}
              onChange={(e) => setQuantite(e.target.value)}
            />
            <div className="hint">
              C'est la <b>différence</b>, pas le nouveau total.
              {q > 0 && ` Après correction : ${nombre(apresCorrection)}.`}
            </div>
          </div>

          {negatif && (
            <div className="banner banner-error">
              Cette correction ferait passer le stock à {nombre(apresCorrection)}, c'est-à-dire en
              dessous de zéro. Le serveur le refusera si le stock négatif n'est pas autorisé pour cet
              établissement — et s'il l'accepte, le chiffre restera faux.
            </div>
          )}

          <div className="field">
            <label htmlFor="st-motif">Pourquoi *</label>
            <input
              id="st-motif"
              className="input"
              required
              value={motif}
              placeholder="Comptage du 24/08, deux cartons oubliés en réserve"
              onChange={(e) => setMotif(e.target.value)}
            />
            <div className="hint">
              Obligatoire : une correction de stock sans raison écrite est indistinguable d'un vol
              quand on relit le journal six mois plus tard.
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || q <= 0 || !motif.trim()}>
              {enCours ? 'Correction…' : 'Enregistrer la correction'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// --------------------------------------------------------------------------------------------
// Rattacher un article à un produit vendu.
// --------------------------------------------------------------------------------------------
// Le détachement est proposé dans le même écran que le rattachement, parce que c'est le même sujet
// vu des deux côtés — et il dit ce qu'il casse : à partir de là, les ventes cessent de décrémenter.
function RattachementModal({ article, onClose, onFait, onErreur }) {
  const [produits, setProduits] = useState([])
  const [choix, setChoix] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!article) return
    setChoix('')
    api
      .produits()
      .then((c) => setProduits(membres(c)))
      .catch(() => setProduits([]))
  }, [article])

  async function rattacher(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.stockRattacherProduit(article.id, choix)
      onFait(`« ${article.libelle} » suit désormais les ventes.`)
    } catch (err) {
      onErreur(err.message || "Le rattachement n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  async function detacher() {
    if (
      !window.confirm(
        `Détacher « ${article.libelle} » de son produit ?\n\nÀ partir de maintenant, vendre ce `
          + `produit ne fera plus descendre le stock de cet article. Les mouvements déjà enregistrés `
          + `sont conservés.`,
      )
    )
      return
    setEnCours(true)
    try {
      await api.stockDetacherProduit(article.id)
      onFait(`« ${article.libelle} » ne suit plus les ventes.`)
    } catch (err) {
      onErreur(err.message || "Le détachement n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!article} onClose={onClose} titre={article ? `Suivi des ventes — ${article.libelle}` : ''}>
      {article && (
        <form onSubmit={rattacher}>
          <p style={{ marginTop: 0 }}>
            Rattacher un article à un produit, c'est ce qui fait qu'une vente au comptoir fait
            descendre le stock. Sans ce lien, le produit se vend normalement et la quantité ne bouge
            pas — c'est voulu, tous les produits ne sont pas gérés en stock.
          </p>

          <div className="field">
            <label htmlFor="st-produit">Produit vendu</label>
            <select id="st-produit" className="input" value={choix} onChange={(e) => setChoix(e.target.value)}>
              <option value="">Choisir…</option>
              {produits.map((p) => (
                <option key={p.id} value={p.id}>{libelleProduit(p)}</option>
              ))}
            </select>
            <div className="hint">
              {article.produit
                ? `Actuellement rattaché à « ${libelleProduit(article.produit)} ».`
                : "Cet article n'est rattaché à aucun produit."}
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            {article.produit && (
              <button className="btn ghost" type="button" disabled={enCours} onClick={detacher}>
                Ne plus suivre les ventes
              </button>
            )}
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !choix}>
              {enCours ? 'Enregistrement…' : 'Rattacher'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// --------------------------------------------------------------------------------------------
// La règle des écarts d'inventaire — dite telle qu'elle est, pas telle qu'on l'imagine.
// --------------------------------------------------------------------------------------------
// Le serveur exige `stock.valider_ecart` pour régulariser un écart d'inventaire « significatif ».
// Mais « significatif » se calcule à partir de `ParametrageStock`, et **quand cet enregistrement
// n'existe pas, la fonction renvoie `false` : plus rien n'est jamais significatif.** Autrement dit,
// sur un établissement fraîchement créé, le contrôle responsable ne s'applique à aucun écart, si
// grand soit-il, et personne ne le voit puisque tout fonctionne.
//
// Cet encadré existe pour que ça se voie. Il ne réclame rien à l'utilisateur — il lui dit dans quel
// état est son garde-fou.
function RegleEcart({ parametrage, droits }) {
  const pourcentage = parametrage?.seuilEcartSignificatifPourcentage
  const montant = parametrage?.seuilEcartSignificatifMontant
  const regle = pourcentage != null || montant != null
  const peutParametrer = aUnDesDroits(droits, ['stock.parametrer', 'stock.gerer'])

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Contrôle des écarts d'inventaire</h3>
        <span className="sub">qui peut valider un écart, et à partir de quand</span>
      </div>
      <div className="card-b">
        {regle ? (
          <>
            <p style={{ marginTop: 0 }}>
              Un écart d'inventaire est <b>significatif</b>, et demande alors la validation d'un
              responsable, à partir de{' '}
              {pourcentage != null && <b>{pourcentage} % de la quantité attendue</b>}
              {pourcentage != null && montant != null && ' ou de '}
              {montant != null && <b>{euros(montant)}</b>}.
            </p>
            <div className="hint" style={{ marginTop: 0 }}>
              En dessous, la régularisation est faite par celui qui compte. Au-dessus, elle exige le
              droit « valider un écart ».
            </div>
          </>
        ) : (
          <>
            <div className="banner banner-error">
              <b>Aucun seuil n'est réglé pour cet établissement, et la conséquence n'est pas neutre :
              aucun écart d'inventaire n'est considéré comme significatif.</b> La validation par un
              responsable ne se déclenchera donc jamais, quelle que soit la taille de l'écart. Tout
              fonctionne, et le contrôle est absent.
            </div>
            <div className="hint" style={{ marginTop: 0 }}>
              {peutParametrer
                ? 'Réglez un seuil en pourcentage, un seuil en montant, ou les deux : le premier atteint déclenche la validation.'
                : "Signalez-le à un responsable : le réglage demande le droit « paramétrer le stock »."}
            </div>
          </>
        )}
      </div>
    </section>
  )
}

// --------------------------------------------------------------------------------------------
// Le journal.
// --------------------------------------------------------------------------------------------
// Une correction sans trace visible est ce qui rend les corrections effrayantes. Le journal est donc
// sur le même écran que le bouton qui les crée, et non dans un module de rapports.
function JournalSection({ mouvements }) {
  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Derniers mouvements</h3>
        <span className="sub">tout ce qui a fait bouger le stock</span>
      </div>
      <div className="card-b">
        {mouvements.length === 0 ? (
          <div className="empty">
            Aucun mouvement. Chaque entrée, sortie, correction ou perte laisse une ligne ici, avec sa
            raison. Un journal vide peut aussi vouloir dire que vos articles ne sont rattachés à aucun
            produit : dans ce cas les ventes ne les touchent pas, et c'est la colonne « suivi des
            ventes » ci-dessus qui le dit.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Date</th>
                <th>Article</th>
                <th>Nature</th>
                <th className="num">Quantité</th>
                <th>Raison</th>
              </tr>
            </thead>
            <tbody>
              {mouvements.map((m) => (
                <tr key={m.id}>
                  <td>{dateHeureFr(m.date)}</td>
                  <td>{m.articleStock?.libelle || '—'}</td>
                  <td><span className="badge mut">{mot(m.type)}</span></td>
                  <td className="num">{nombre(m.quantite)}</td>
                  <td>{m.motif || <span className="sub">—</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}

// Les quantités de stock arrivent en décimal à trois chiffres (`24.000`). Les afficher telles quelles
// ferait lire « 24,000 » pour vingt-quatre canettes : on retire les zéros inutiles sans jamais
// tronquer une décimale qui porte de l'information.
function nombre(v) {
  const n = typeof v === 'number' ? v : parseFloat(v)
  if (!Number.isFinite(n)) return '—'
  return String(Math.round(n * 1000) / 1000).replace('.', ',')
}
