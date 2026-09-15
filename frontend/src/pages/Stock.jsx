import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import ReferentielEditable from '../components/ReferentielEditable.jsx'
import InventaireStock from '../components/InventaireStock.jsx'
import AchatsStock from '../components/AchatsStock.jsx'
import ValorisationStock from '../components/ValorisationStock.jsx'
import RetoursStock from '../components/RetoursStock.jsx'
import Tabs from '../components/Tabs.jsx'
import { dateHeureFr, resoudre } from '../components/Liste.jsx'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { euros, libelleProduit } from '../api/produit.js'
import { idDe } from '../api/iri.js'
import { confirmer } from '../components/Confirmation.jsx'
import { useEtatUrl } from '../api/url.js'

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

// `corriger` : l'identifiant de l'article dont on corrige le stock.
// `inventaire` : `lancer` quand on lance un inventaire.
const DEFAUTS_URL = { corriger: '', inventaire: '' }

export default function Stock({ etabActif, droits }) {
  const [params, majParams] = useEtatUrl('stock', DEFAUTS_URL)
  // ⚠ `null` = PAS LU. Sur une lecture refusee, l'ecran annoncait << 0 reference >> puis
  // << Aucun article de stock. Un article, c'est ce que vous achetez et comptez [...] >> --
  // c'est-a-dire le message d'accueil d'un etablissement neuf, servi a un exploitant dont le stock
  // existe et n'a simplement pas pu etre lu.
  const [articles, setArticles] = useState(null)
  const [lots, setLots] = useState([])
  const [lotsTronques, setLotsTronques] = useState(false)
  // ⚠ `null` = PAS LU · `[]` = LU ET VIDE. Ici la phrase de l'etat vide VANTE le filet :
  // << Aucun article sous son seuil. Cette liste se remplit toute seule [...] c'est le seul endroit
  // qui vous previent avant la rupture. >> Elle s'affichait quand la lecture avait ECHOUE, c'est-a-dire
  // exactement quand le filet n'etait pas pose. On ne rassure pas au nom d'un controle qui n'a pas eu lieu.
  const [alertes, setAlertes] = useState(null)
  // ⚠ `null` = PAS LU. Le `catch` voisin retenait deja l'echec pour les articles
  // (`setArticles(null)`) mais pas pour le journal : le meme refus rendait un tableau honnete et
  // un tableau menteur, cote a cote.
  const [mouvementsLus, setMouvementsLus] = useState(null)
  const mouvements = mouvementsLus || []
  // ⚠ `null` DISAIT DEUX CHOSES : << aucun seuil configure >> ET << pas lu >>. Le second etat a
  // son propre drapeau, parce que le bloc en tire une AFFIRMATION en rouge : << aucun ecart
  // d'inventaire n'est considere comme significatif, la validation par un responsable ne se
  // declenchera donc jamais >>. Dite sur une lecture echouee, elle envoie regler un seuil qui est
  // peut-etre deja en place -- ou pire, rassure sur un controle qu'on croit absent.
  const [parametrage, setParametrage] = useState(null)
  const [parametrageLu, setParametrageLu] = useState(false)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [rattachement, setRattachement] = useState(null)
  const [recherche, setRecherche] = useState('')
  const [onglet, setOnglet] = useState('etat')

  const peutAjuster = aUnDesDroits(droits, ['stock.ajuster', 'stock.gerer'])
  // Le meme couple que le serveur exige sur les trois routes de transfert.
  const peutTransferer = aUnDesDroits(droits, ['stock.transferer', 'stock.gerer'])
  const peutGererArticle = aUnDesDroits(droits, ['stock.gerer_article', 'stock.gerer'])
  const peutValoriser = aUnDesDroits(droits, ['stock.lire_valorisation', 'stock.gerer'])

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
      setMouvementsLus(membres(m))
    } catch (e) {
      setErreur(e.message)
      setArticles(null)
      setMouvementsLus(null)
    } finally {
      setChargement(false)
    }
    // `catch(() => setAlertes([]))` transformait l'echec en << rien a recommander >>. La tolerance
    // reste -- une alerte manquante ne doit pas emporter l'ecran -- mais l'echec est RETENU.
    api.stockAlertesReappro().then((r) => setAlertes(Array.isArray(r) ? r : membres(r))).catch(() => setAlertes(null))
    api.stockParametrage()
      .then((r) => { setParametrage(membres(r)[0] || null); setParametrageLu(true) })
      .catch(() => { setParametrage(null); setParametrageLu(false) })
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  // ⚠ L'ARTICLE CORRIGÉ SE LIT PAR SON IDENTIFIANT : la liste est bornée à 200, et un site en
  // compte davantage. Seul un 404 dit « il n'existe pas » ; tout le reste est une lecture échouée.
  const [articleCorrige, setArticleCorrige] = useState(null)
  const [chargementArticle, setChargementArticle] = useState(false)
  const [lectureArticleEchouee, setLectureArticleEchouee] = useState(false)
  useEffect(() => {
    const id = params.corriger
    if (!id) { setArticleCorrige(null); setLectureArticleEchouee(false); return undefined }
    let vivant = true
    setChargementArticle(true)
    setLectureArticleEchouee(false)
    setArticleCorrige(null)
    api.stockArticle(id)
      .then((a) => { if (vivant) setArticleCorrige(a) })
      .catch((e) => { if (vivant) setLectureArticleEchouee(e?.status !== 404) })
      .finally(() => { if (vivant) setChargementArticle(false) })
    return () => { vivant = false }
  }, [params.corriger, etabActif])

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
    // `articles` vaut `null` quand la lecture a echoue : la recherche porte alors sur rien, et
    // c'est le decompte `total` -- laisse a `null` -- qui dit pourquoi.
    if (!q) return articles || []
    return (articles || []).filter(
      // ⚠ L'EMPLACEMENT EST CHERCHABLE, sinon il ne sert qu'à être lu une ligne à la fois :
      // « qu'est-ce qu'il y a en réserve » est la question qu'on pose devant un inventaire.
      (a) => (a.libelle || '').toLowerCase().includes(q)
        || (a.codeEAN || '').toLowerCase().includes(q)
        || (a.storageLocation || '').toLowerCase().includes(q),
    )
  }, [articles, recherche])

  // ⚠ `etat` GARDE SON IDENTITÉ : l'effet du formulaire remet quantité et motif à zéro à chaque
  // nouvel objet. Écrit en ligne, il effacerait la frappe à chaque rendu.
  const restantCorrige = articleCorrige ? (restantParArticle[articleCorrige.id] || 0) : 0
  const etatCorrection = useMemo(
    () => (articleCorrige ? { article: articleCorrige, restant: restantCorrige } : null),
    [articleCorrige, restantCorrige],
  )

  async function apres(message) {
    setSucces(message)
    setErreur(null)
    await recharger()
  }

  // ── LANCER UN INVENTAIRE, EN ÉCRAN ─────────────────────────────────────────────────────────
  //
  // La page cède toute la place à InventaireStock : c'est lui qui lit les inventaires, donc lui
  // qui sait si un autre est déjà ouvert, et lui qui reprend les conditions du bouton.
  //
  // ⚠ LE PÉRIMÈTRE SE PRÉSENTE SUR LA LISTE DES ARTICLES. Illisible, elle ferait annoncer « les 0
  // articles en service » : on le dit au lieu d'ouvrir le formulaire.
  if (params.inventaire) {
    const fermerInventaire = () => majParams({ inventaire: '' }, { pousser: true })
    return (
      <div className="view large">
        <button className="btn ghost sm" type="button" onClick={fermerInventaire}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour au stock
        </button>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {chargement ? (
          <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
        ) : articles === null ? (
          <div className="banner banner-error">
            La liste des articles n’a pas pu être lue : le périmètre d’un inventaire ne peut pas être
            présenté, et on ne lance pas un comptage sur une liste qu’on n’a pas.
          </div>
        ) : (
          <InventaireStock
            articles={articles}
            droits={droits}
            etabActif={etabActif}
            onErreur={setErreur}
            onFait={apres}
            params={params}
            majParams={majParams}
          />
        )}
      </div>
    )
  }

  // ── CORRIGER UN STOCK, EN ÉCRAN ────────────────────────────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LES CONDITIONS DU BOUTON ET L'ÉCRAN LES REPREND : le droit d'ajuster, et
  // des lots lus EN ENTIER. Le chiffre qu'on corrige est leur somme : sur une lecture échouée,
  // `lots` garde sa valeur précédente et rend un zéro qui a l'air juste ; sur une lecture tronquée,
  // une somme partielle. On ne corrige pas un chiffre qu'on ne connaît pas.
  if (params.corriger) {
    const fermerCorrection = () => majParams({ corriger: '' }, { pousser: true })
    let contenu
    if (!peutAjuster) {
      contenu = (
        <div className="banner banner-warn">
          Corriger le stock demande le droit d’ajuster le stock, que ce compte n’a pas.
        </div>
      )
    } else if (chargement || chargementArticle) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (!articleCorrige) {
      contenu = (
        <div className="banner banner-warn">
          {lectureArticleEchouee
            ? 'Cet article n’a pas pu être lu. Ce n’est pas la même chose que « il n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
            : 'Cet article n’existe pas, ou n’est pas visible depuis cet établissement.'}
        </div>
      )
    } else if (articles === null) {
      contenu = (
        <div className="banner banner-error">
          Les lots de stock n’ont pas pu être lus : cet écran ne sait pas combien il reste de
          « {articleCorrige.libelle} », et on ne corrige pas un chiffre qu’on ne connaît pas.
        </div>
      )
    } else if (lotsTronques) {
      contenu = (
        <div className="banner banner-error">
          Cet établissement a plus de lots que cet écran n’en charge : le stock de
          « {articleCorrige.libelle} » ne peut pas être calculé en entier, et la correction reste
          désactivée, comme dans la liste.
        </div>
      )
    } else {
      contenu = (
        <>
          {erreur && <div className="banner banner-error">{erreur}</div>}
          <AjustementModal
            key={params.corriger}
            etat={etatCorrection}
            onClose={fermerCorrection}
            onFait={(m) => { fermerCorrection(); apres(m) }}
            onErreur={setErreur}
          />
        </>
      )
    }
    return (
      <div className="view large">
        <button className="btn ghost sm" type="button" onClick={fermerCorrection}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour au stock
        </button>
        {contenu}
      </div>
    )
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
          ['transferts', 'Transferts entre sites'],
          ['retours', 'Retours clients'],
          ...(peutValoriser ? [['valorisation', 'Valeur du stock']] : []),
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
      ) : onglet === 'valorisation' && peutValoriser ? (
        <ValorisationStock etabActif={etabActif} onErreur={setErreur} />
      ) : onglet === 'retours' ? (
        <RetoursStock
          etabActif={etabActif}
          articles={articles}
          peutAjuster={peutAjuster}
          onErreur={setErreur}
          onFait={apres}
        />
      ) : onglet === 'transferts' ? (
        <TransfertsSection
          articles={articles}
          peutTransferer={peutTransferer}
          onErreur={setErreur}
          onFait={apres}
        />
      ) : onglet === 'achats' ? (
        <AchatsStock
          articles={articles || []}
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
            total={articles === null ? null : articles.length}
            restantParArticle={restantParArticle}
            lotsCharges={lots.length}
            tronque={lotsTronques}
            recherche={recherche}
            onRecherche={setRecherche}
            peutAjuster={peutAjuster && !lotsTronques}
            onAjuster={(a) => { setErreur(null); setSucces(null); majParams({ corriger: String(a.id) }, { pousser: true }) }}
            onRattacher={peutGererArticle ? (a) => setRattachement(a) : null}
            nonSuivis={(articles || []).filter((a) => !a.produit).length}
          />

          {peutGererArticle && <ArticlesEdition onChange={recharger} />}

          <RegleEcart parametrage={parametrage} lu={parametrageLu} droits={droits} />

          <InventaireStock
            articles={articles || []}
            droits={droits}
            etabActif={etabActif}
            onErreur={setErreur}
            onFait={apres}
            params={params}
            majParams={majParams}
          />

          {/* ⚠ `articles || []` et non `articles` : la liste vaut `null` tant que la lecture
              n'a pas abouti, et le journal doit rendre une colonne vide plutôt que tomber. */}
          <JournalSection mouvements={mouvements} articles={articles || []} nonLu={mouvementsLus === null} />
        </>
      )}


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
  if (alertes === null) {
    return (
      <section className="card">
        <div className="card-h">
          <h3>À recommander</h3>
          <span className="sub">articles passés sous leur seuil</span>
        </div>
        <div className="card-b">
          <div className="banner banner-error">
            Les alertes de réapprovisionnement n’ont pas pu être lues. <b>Ne concluez pas qu’il n’y a
            rien à recommander</b>&nbsp;: cette liste n’a pas été obtenue. Rechargez, ou vérifiez les
            seuils directement sur les fiches article.
          </div>
        </div>
      </section>
    )
  }

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
        <span className="sub">{total === null ? '—' : `${total} référence${total > 1 ? 's' : ''}`}</span>
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
        {total === null ? (
          <div className="banner banner-error">
            La liste des articles n’a pas pu être lue. Ce tableau est vide parce que la lecture a
            échoué, <b>pas</b> parce que cet établissement n’a pas de stock.
          </div>
        ) : total === 0 ? (
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
        nom: 'storageLocation',
        libelle: 'Où il est rangé',
        type: 'text',
        exemple: 'Réserve · étagère B',
        aide:
          'Le lieu PHYSIQUE, celui où on va le chercher. À ne pas confondre avec le rayon de '
          + 'caisse, qui range l’écran de vente et dont un produit peut avoir plusieurs.',
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
      { cle: 'storageLocation', titre: 'Rangé', rendu: (r) => r.storageLocation || '—' },
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

  if (!etat) return null

  return (
    <>
      <h2>{`Corriger — ${etat.article.libelle}`}</h2>
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
    </>
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
      !await confirmer(
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
function RegleEcart({ parametrage, lu, droits }) {
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
        {!lu ? (
          <div className="banner banner-warn">
            Le paramétrage des seuils n’a pas pu être lu. <b>N’en concluez pas qu’aucun seuil n’est
            réglé</b>&nbsp;: cet écran ne sait pas, pour l’instant, à partir de quel écart une
            validation est exigée.
          </div>
        ) : regle ? (
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
function JournalSection({ mouvements, articles = [], nonLu }) {
  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Derniers mouvements</h3>
        <span className="sub">tout ce qui a fait bouger le stock</span>
      </div>
      <div className="card-b">
        {mouvements.length === 0 ? (
          <div className="empty">
            {nonLu ? <b>Le journal des mouvements n’a pas pu être lu : il est vide
            parce que la lecture a échoué, pas parce qu’aucun mouvement n’a eu lieu.</b> : <>
            Aucun mouvement. Chaque entrée, sortie, correction ou perte laisse une ligne ici, avec sa
            raison. Un journal vide peut aussi vouloir dire que vos articles ne sont rattachés à aucun
            produit : dans ce cas les ventes ne les touchent pas, et c'est la colonne « suivi des
            ventes » ci-dessus qui le dit.
            </>}
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
                  {/* `MouvementStock.articleStock` revient en IRI nue : `ArticleStock` n'expose
                      rien dans le groupe `mouvement:read`. La colonne « article » du journal des
                      mouvements était donc vide en permanence — un journal de stock qui ne dit pas
                      SUR QUOI porte le mouvement ne sert à rien. Résolu contre la liste des
                      articles déjà chargée par cet écran. */}
                  <td>{resoudre(m.articleStock, articles)?.libelle || <span className="sub">article non transmis</span>}</td>
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


/**
 * LES TRANSFERTS ENTRE SITES — trois routes serveur, aucun écran jusqu'ici.
 *
 * Un transfert demandé restait dans la base : personne ne pouvait l'expédier, le réceptionner, ni
 * même savoir qu'il attendait. Du stock parti d'un site sans jamais arriver à l'autre ne se voit
 * qu'à l'inventaire suivant, des semaines plus tard.
 *
 * ⚠ LA DIRECTION SE DÉDUIT DU CLOISONNEMENT, ET C'EST JUSTE. Les articles ne sont lisibles que pour
 * l'établissement actif ; les transferts le sont dès que l'un de leurs deux articles nous
 * appartient. Un seul des deux côtés est donc résoluble — celui de chez nous. Source résoluble =
 * j'envoie ; destination résoluble = je reçois.
 *
 * Et ça tombe exactement sur ce que le serveur autorise : `expedier` est réservé à l'établissement
 * SOURCE, `recevoir` à la DESTINATION (RG-STOCK-14). L'écran ne propose donc jamais un geste qui
 * serait refusé.
 */
function TransfertsSection({ articles, peutTransferer, onErreur, onFait }) {
  // `null` = pas encore lu ; `undefined` = lecture impossible ; un tableau = lu.
  const [transferts, setTransferts] = useState(null)
  const [enCours, setEnCours] = useState(null)

  const charger = useCallback(() => {
    setTransferts(null)
    api.stockTransferts()
      .then((r) => setTransferts(membres(r)))
      .catch(() => setTransferts(undefined))
  }, [])

  useEffect(charger, [charger])

  // Les articles de CHEZ NOUS, par identifiant. Ce qui n'est pas dedans est chez l'autre.
  const miens = useMemo(() => {
    const m = {}
    for (const a of articles || []) m[String(a.id)] = a
    return m
  }, [articles])

  // `idDe` vient de `api/iri.js` (§8.3). Cette page en gardait une version LOCALE et DIFFÉRENTE :
  // `ref.split('/').pop()` rend la chaîne VIDE sur une référence terminée par `/`, là où la
  // canonique filtre les segments vides et ne rend jamais autre chose qu'un identifiant ou `null`.
  // Le seul appel est la recherche ci-dessous : `miens['']` vaut `undefined`, et l'écran affichait
  // un blanc à la place du nom de l'article, sans rien signaler.
  function nomArticle(ref) {
    const a = miens[idDe(ref)]
    if (a) return a.libelle || a.reference || a.designation || 'article'
    return null
  }

  async function agir(t, geste) {
    setEnCours(t.id)
    onErreur(null)
    try {
      if (geste === 'expedier') await api.expedierTransfertStock(t.id)
      else await api.recevoirTransfertStock(t.id)
      charger()
      onFait(geste === 'expedier' ? 'Transfert expédié.' : 'Transfert réceptionné.')
    } catch (e) {
      onErreur(e.message || 'Le transfert n’a pas pu être traité.')
    } finally {
      setEnCours(null)
    }
  }

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Transferts entre sites</h3>
        <span className="sub">
          {transferts === null ? 'lecture…' : transferts === undefined ? 'illisible' : `${transferts.length}`}
        </span>
      </div>
      <div className="card-b">
        {/* ⚠ LA CRÉATION N'EST PAS OFFERTE, ET CE N'EST PAS UN OUBLI. Le serveur exige une source et
            une destination dans des établissements DIFFÉRENTS (RG-STOCK-13), or les articles de
            l'autre site ne sont pas lisibles d'ici. Une liste déroulante ne peut pas les proposer.
            Le dire évite de chercher un bouton qui ne peut pas exister en l'état. */}
        <div className="sub" style={{ marginBottom: 'var(--esp-normal)' }}>
          Les transferts se demandent depuis le site qui expédie&nbsp;; cet écran les suit et permet
          de les expédier ou de les réceptionner. La demande elle-même n’est pas encore possible
          ici&nbsp;: elle exige de désigner un article de l’autre établissement, que le
          cloisonnement ne laisse pas voir.
        </div>

        {transferts === undefined && (
          <div className="banner banner-warn">
            Les transferts n’ont pas pu être lus. Il y en a peut-être en attente&nbsp;: cet écran ne
            le sait pas.
          </div>
        )}

        {transferts === null && <div className="empty">Lecture des transferts…</div>}

        {Array.isArray(transferts) && transferts.length === 0 && (
          <div className="empty">Aucun transfert. Ceux qui partent d’ici ou qui y arrivent apparaîtront dans cette liste.</div>
        )}

        {Array.isArray(transferts) && transferts.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Sens</th>
                  <th>Article</th>
                  <th className="num">Quantité</th>
                  <th>Demandé le</th>
                  <th>État</th>
                  {peutTransferer && <th />}
                </tr>
              </thead>
              <tbody>
                {transferts.map((t) => {
                  const source = nomArticle(t.articleStockSource)
                  const destination = nomArticle(t.articleStockDestination)
                  const sortant = source !== null
                  const etat = t.statut
                  return (
                    <tr key={t.id}>
                      <td>
                        {sortant
                          ? <span className="badge warn">sortant</span>
                          : destination !== null
                            ? <span className="badge good">entrant</span>
                            : <span className="sub">—</span>}
                      </td>
                      <td>
                        {source || destination || <span className="sub">article d’un autre site</span>}
                        {/* On ne montre PAS le nom de l'autre côté : il n'est pas lisible d'ici, et
                            inventer « site B » ferait croire qu'on sait lequel. */}
                      </td>
                      <td className="num">{t.quantite}</td>
                      <td>{t.dateDemande ? new Date(t.dateDemande).toLocaleDateString('fr-FR') : '—'}</td>
                      <td>
                        <span className={`badge ${etat === 'recu' ? 'good' : etat === 'expedie' ? 'warn' : 'mut'}`}>
                          {etat === 'recu' ? 'reçu' : etat === 'expedie' ? 'expédié' : 'demandé'}
                        </span>
                      </td>
                      {peutTransferer && (
                        <td>
                          {/* ⚠ UN SEUL GESTE PAR LIGNE, celui que l'état ET le sens autorisent. Le
                              serveur rend 409 sur une transition impossible et refuse le geste du
                              mauvais côté : proposer l'un ou l'autre ferait cliquer pour rien. */}
                          {sortant && etat === 'demande' && (
                            <button
                              className="btn sm"
                              type="button"
                              disabled={enCours === t.id}
                              onClick={() => agir(t, 'expedier')}
                            >
                              {enCours === t.id ? 'Expédition…' : 'Expédier'}
                            </button>
                          )}
                          {!sortant && destination !== null && etat === 'expedie' && (
                            <button
                              className="btn primary sm"
                              type="button"
                              disabled={enCours === t.id}
                              onClick={() => agir(t, 'recevoir')}
                            >
                              {enCours === t.id ? 'Réception…' : 'Réceptionner'}
                            </button>
                          )}
                        </td>
                      )}
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
