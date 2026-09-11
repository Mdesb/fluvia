import { useEffect, useState, useCallback } from 'react'
import { useEtatUrl } from '../api/url.js'
import { api, membres } from '../api/client.js'
import { libelleProduit, prixIndicatif, euros, statutProduit, actionsStatut } from '../api/produit.js'
import Tabs from '../components/Tabs.jsx'
import ProduitOptionsModal from '../components/ProduitOptionsModal.jsx'
import ProduitFiche from '../components/ProduitFiche.jsx'
import PromotionsCatalogue from '../components/PromotionsCatalogue.jsx'
import GrillesTarifaires from '../components/GrillesTarifaires.jsx'
import { humaniser } from '../api/vocabulaire.js'
import { aLeDroit } from '../api/droits.js'
import { confirmer } from '../components/Confirmation.jsx'

// MEME MOTIF QUE CLIENTS, PARCE QUE MAXIME A DEMANDE LA MEME CHOSE : « je pense que pour le produit
// on devrait faire pareil que pour le client. » Liste large, fiche en page, retour qui rend les
// filtres. `useEtatUrl` (api/url.js) est ecrit pour servir aux deux plutot que recopie ici.
// `nouveau` vit dans l'URL comme `fiche` : recharger la page ne doit pas faire perdre le formulaire
// commence, et le bouton « precedent » du navigateur doit ramener au catalogue.
const DEFAUTS = { tab: 'produits', q: '', statut: '', type: '', fiche: '', nouveau: '', promo: '' }

/**
 * LA FICHE VIERGE — ce qu'on voit apres « Nouveau produit », avant que le produit n'existe.
 *
 * ⚠ LES DEUX CHAMPS PRENNENT LA PLACE DU TITRE : c'est le titre, en cours d'ecriture. Une fois le
 * produit cree, le titre les remplace et on n'a pas change d'ecran.
 *
 * ⚠ RIEN N'EST ECRIT AVANT LE CLIC. Creer des que les deux champs sont remplis paraissait plus
 * fluide, mais un aller-retour aurait laisse des produits fantomes dans le catalogue — et aucun
 * bouton ne permet aujourd'hui d'en supprimer un.
 *
 * ⚠ LES SECTIONS SONT MONTREES ESTOMPEES, NI CACHEES NI ACTIVES. Un tarif s'attache a un produit :
 * tant qu'il n'existe pas, il n'y a rien a quoi l'attacher. Les cacher laisserait croire que la
 * fiche est pauvre ; les activer ferait saisir des valeurs qui ne partiraient nulle part.
 */
function NouveauProduit({ types = [], onAnnule, onCree }) {
  const [libelle, setLibelle] = useState('')
  const [typeId, setTypeId] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  const pret = libelle.trim() !== '' && typeId !== ''

  async function creer() {
    setEnCours(true)
    setErreur(null)
    try {
      await onCree({
        libelle: { fr: libelle.trim() },
        type: `/api/type_produits/${typeId}`,
        canaux: ['guichet'],
      })
    } catch (e) {
      setErreur(e?.message || 'La création n’a pas abouti.')
      setEnCours(false)
    }
  }

  return (
    <>
      <button
        className="btn ghost sm"
        type="button"
        style={{ marginBottom: 'var(--esp-normal)' }}
        onClick={onAnnule}
      >
        ← Retour au catalogue
      </button>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="row" style={{ gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <div className="field" style={{ margin: 0, flex: '2 1 260px' }}>
          <label htmlFor="np-lib">Libellé *</label>
          <input
            id="np-lib"
            className="input"
            value={libelle}
            onChange={(e) => setLibelle(e.target.value)}
            placeholder="Ex. Entrée adulte"
            autoFocus
          />
        </div>
        <div className="field" style={{ margin: 0, flex: '1 1 180px' }}>
          <label htmlFor="np-type">Type *</label>
          <select id="np-type" className="select" value={typeId} onChange={(e) => setTypeId(e.target.value)}>
            <option value="">Choisir…</option>
            {types.map((t) => (
              <option key={t.id} value={t.id}>{t.libelle}</option>
            ))}
          </select>
        </div>
        <span className="badge mut">Nouveau</span>
      </div>

      <div className="banner" style={{ marginTop: 'var(--esp-normal)' }}>
        Donnez-lui un nom et un type : le produit sera créé, et tout le reste de cette fiche
        deviendra modifiable. Il naîtra en <b>brouillon</b> — invisible du guichet et de la boutique
        tant que vous ne l’aurez pas publié.
      </div>

      <div className="row" style={{ gap: 10, marginTop: 'var(--esp-normal)' }}>
        <button className="btn primary" type="button" disabled={!pret || enCours} onClick={creer}>
          {enCours ? 'Création…' : 'Créer le produit'}
        </button>
        <button className="btn ghost" type="button" onClick={onAnnule}>Annuler</button>
      </div>

      {/* ⚠ APERCU INERTE, ET IL EST MARQUE COMME TEL. `aria-hidden` le retire de la lecture d'un
          lecteur d'ecran : annoncer « Photos, Description » a quelqu'un qui ne peut rien y faire
          serait la version sonore du formulaire qui ment. */}
      <div className="fiche-endormie" aria-hidden="true">
        <div className="fiche-sec">Photos</div>
        <div className="hint">La première est celle qu’affiche la boutique en ligne.</div>
        <div className="fiche-sec">Description</div>
        <div className="hint">Ce qu’on voit, ce qu’on fait, combien de temps ça dure.</div>
        <div className="fiche-sec">Produits complémentaires</div>
        <div className="hint">Le casier avec l’entrée, l’audioguide avec la visite.</div>
      </div>
    </>
  )
}

export default function Catalogue({ etabActif, cible = null, onCibleConsommee, droits = [], capacites = [] }) {
  const [params, majParams] = useEtatUrl('catalogue', DEFAUTS)
  const tab = params.tab
  // Le droit de creer se lit ICI et plus dans `OngletProduits` : le bouton qu'il commande vit
  // desormais sur la ligne des onglets, qui appartient a cet ecran-ci.
  const peutCreerProduit = aLeDroit(droits, 'offre.creer') || aLeDroit(droits, 'offre.gerer')
  // ⚠ ET IL EFFACE LES ECRANS DE NIVEAU 2. Un `promo` ou un `fiche` laisse dans l'adresse
  // rouvrirait un formulaire d'un AUTRE onglet des qu'on y revient — un ecran surgi de nulle
  // part, sur des donnees qu'on ne regardait plus.
  const setTab = (v) => majParams({ tab: v, fiche: '', nouveau: '', promo: '' })

  // Une cible « produit » arrive de la recherche globale : on s'assure d'être sur le bon onglet
  // avant que la liste ne tente de l'ouvrir.
  useEffect(() => {
    if (cible?.type === 'produit') majParams({ tab: 'produits' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cible])

  // DEUX NIVEAUX DE NAVIGATION EMPILÉS, ET LE SECOND NE PARLAIT PLUS DE CE QU'ON REGARDAIT.
  //
  // Sur la fiche d'un produit, la page affichait encore le titre « Catalogue / Produits et
  // options » et les onglets « Produits | Options » du niveau LISTE, au-dessus du bouton
  // « ← Retour au catalogue » de la fiche. Relevé tel quel dans le texte de la page :
  //
  //     Catalogue / Produits et options
  //     Produits   Options            ← les onglets de la LISTE
  //     ← Retour au catalogue         ← le retour de la FICHE
  //     Audioguide …
  //
  // Cliquer « Options » depuis une fiche produit fait donc quitter la fiche sans le dire, et
  // « Produits », qui a l'air actif, ne ramène nulle part. Ce sont les onglets d'un écran qu'on a
  // quitté.
  //
  // C'est le préalable à la refonte de la fiche : y ajouter ses propres onglets sans retirer ceux
  // du parent donnerait deux rangées superposées qui ne désignent pas la même chose — pire que
  // l'état d'avant.
  //
  // ⚠ ET LA REGLE VAUT POUR LES TROIS ECRANS DE NIVEAU 2, PAS POUR LA SEULE FICHE. La fiche
  // vierge (`nouveau=1`) gardait le titre et les onglets au-dessus d'elle, et le formulaire
  // d'une promotion aurait fait pareil : le meme defaut que celui decrit ci-dessus, aux memes
  // endroits, pour n'avoir nomme qu'un seul cas.
  const ecranDeNiveau2 = (tab === 'produits' && (!!params.fiche || params.nouveau === '1'))
    || (tab === 'promotions' && !!params.promo)

  return (
    <div className="view large">
      {!ecranDeNiveau2 && (
        <>
          <div className="view-head">
            <div className="ttl">
              <h1>Catalogue</h1>
              <p>Produits et options</p>
            </div>
          </div>

          <Tabs
            onglets={[
              ['produits', 'Produits'],
              ['grilles', 'Grilles tarifaires'],
              ['promotions', 'Promotions'],
              ['options', 'Options'],
            ]}
            actif={tab}
            onChange={setTab}
            actions={tab === 'produits' && peutCreerProduit ? (
              <button
                className="btn primary"
                type="button"
                onClick={() => majParams({ nouveau: '1' }, { pousser: true })}
              >
                ＋ Nouveau produit
              </button>
            ) : null}
          />
        </>
      )}

      {tab === 'produits' ? (
        <OngletProduits
          etabActif={etabActif}
          cible={cible}
          onCibleConsommee={onCibleConsommee}
          droits={droits}
          capacites={capacites}
          params={params}
          majParams={majParams}
        />
      ) : tab === 'grilles' ? (
        <GrillesTarifaires etabActif={etabActif} majParams={majParams} />
      ) : tab === 'promotions' ? (
        <PromotionsCatalogue etabActif={etabActif} droits={droits} params={params} majParams={majParams} />
      ) : (
        <OngletOptions />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ Produits */

function OngletProduits({ etabActif, cible = null, onCibleConsommee, droits = [], capacites = [], params, majParams }) {
  // ⚠ `null` = PAS LU, `[]` = LU ET VIDE. Sur une lecture refusee, ce tableau affichait
  // « Aucun produit. » — un exploitant lit alors que son catalogue est vide. Le message du serveur
  // etait bien la, mais dans un bandeau separe que rien ne relie a la ligne du tableau.
  const [produits, setProduits] = useState(null)
  const [total, setTotal] = useState(0)
  const [saisie, setSaisie] = useState(params.q)
  const [types, setTypes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)


  const [produitOptions, setProduitOptions] = useState(null) // produit dont on gère les options
  const [actionEnCours, setActionEnCours] = useState(null) // id du produit dont une action tourne
  const selId = params.fiche || null

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // QUATRE FILTRES DECLARES COTE SERVEUR, AUCUN ATTEIGNABLE.
      //
      // `Produit` porte un `SearchFilter` sur `code` (partiel), `libelleRecherche` (partiel),
      // `statut` et `typeCode`. L'ecran appelait `api.produits()` sans le moindre parametre, et
      // n'offrait meme pas un champ de recherche : au-dela de trente produits on ne retrouvait plus
      // rien, puisque le serveur coupe la a defaut de pagination cliente.
      const [pc, tc] = await Promise.all([
        api.produits({
          libelleRecherche: params.q || '',
          statut: params.statut || '',
          typeCode: params.type || '',
        }),
        api.typeProduits(),
      ])
      setProduits(membres(pc))
      setTotal(pc?.totalItems ?? pc?.['hydra:totalItems'] ?? membres(pc).length)
      const t = membres(tc)
      // ⚠ Plus de preselection de type : elle servait le formulaire de creation, qui n'existe
      // plus. `NouveauProduit` ouvre son choix sur « Choisir… », ce qui vaut mieux qu'un type
      // impose que personne n'a regarde.
      setTypes(t)
    } catch (e) {
      setErreur(e.message)
      // On ne garde pas la liste precedente : elle donnerait un catalogue d'avant pour un
      // catalogue d'aujourd'hui, ce qui est pire qu'un vide annonce.
      setProduits(null)
      setTotal(0)
    } finally {
      setChargement(false)
    }
  }, [params.q, params.statut, params.type])

  useEffect(() => {
    recharger()
  }, [etabActif, recharger])

  // Recherche differee a la frappe : l'URL ne bouge qu'une fois la saisie posee.
  useEffect(() => {
    if (saisie === params.q) return undefined
    const t = setTimeout(() => majParams({ q: saisie }), 300)
    return () => clearTimeout(t)
  }, [saisie, params.q, majParams])

  // Ouverture de la fiche demandée par la recherche globale. On prend l'objet complet s'il est déjà
  // chargé, sinon on ouvre avec le seul identifiant : la fiche va chercher le détail de toute façon,
  // et attendre la liste entière pour afficher un nom ferait patienter sans raison.
  useEffect(() => {
    if (cible?.type !== 'produit') return
    majParams({ fiche: String(cible.id) }, { pousser: true })
    onCibleConsommee?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cible])

  // Appels de cycle de vie, dans l'ordre exact des actions déclarées par `actionsStatut`.
  const APPELS = {
    publier: api.publierProduit,
    depublier: api.depublierProduit,
    archiver: api.archiverProduit,
    reactiver: api.reactiverProduit,
  }

  // Une action peut échouer pour une raison métier parfaitement légitime — publier un produit sans
  // tarif ou sans site de commercialisation. On affiche le message du serveur TEL QUEL plutôt qu'un
  // « échec » générique : c'est lui qui dit ce qui manque, et c'est la seule chose sur laquelle
  // l'exploitant peut agir.
  async function agir(produit, action) {
    if (action.confirmation && !await confirmer(action.confirmation.replace('%s', libelleProduit(produit)))) return
    setErreur(null)
    setSucces(null)
    setActionEnCours(produit.id)
    try {
      await APPELS[action.id](produit.id)
      setSucces(`« ${libelleProduit(produit)} » : ${action.confirme}`)
      await recharger()
    } catch (err) {
      setErreur(err.message || "L'action n'a pas abouti.")
    } finally {
      setActionEnCours(null)
    }
  }

  // La fiche prend la page entiere : on n'affiche ni la liste ni le formulaire de creation derriere.
  if (selId) {
    // ⚠ `produits` vaut `null` tant que la lecture n'a pas repondu, et `selId` peut deja
    // etre renseigne a CE rendu-la : il vient de l'URL (`?fiche=`), donc d'un lien partage,
    // d'un signet ou d'un simple F5. Sans le repli, ouvrir une fiche par son URL passe tout
    // le Catalogue a la frontiere d'erreur -- liste, fiche et formulaire avec elle.
    // `ProduitFiche` sait se rendre sans l'objet complet : c'est le sens de `connu || { id }`
    // juste dessous, et c'est ce chemin-la que le repli rend atteignable.
    const connu = (produits || []).find((p) => String(p.id) === String(selId))
    return (
      <>
        <button
          className="btn ghost sm"
          type="button"
          style={{ marginBottom: 12 }}
          onClick={() => majParams({ fiche: '' }, { pousser: true })}
        >
          ← Retour au catalogue
        </button>
        <ProduitFiche
          produit={connu || { id: selId }}
          etabActif={etabActif}
          peutModifier={aLeDroit(droits, 'offre.modifier') || aLeDroit(droits, 'offre.gerer')}
          peutModifierCompta={aLeDroit(droits, 'offre.modifier_compta') || aLeDroit(droits, 'offre.gerer')}
          droits={droits}
          capacites={capacites}
          onModifie={recharger}
          peutCreer={aLeDroit(droits, 'offre.creer') || aLeDroit(droits, 'offre.gerer')}
          onDuplique={(copie) => {
            // Même geste que la création : on pousse dans l'historique pour que « précédent »
            // ramène à l'original, et on ouvre la copie — qui attend son vrai libellé.
            recharger()
            majParams({ fiche: String(copie.id) }, { pousser: true })
          }}
        />
      </>
    )
  }

  // ── LA FICHE VIERGE ─────────────────────────────────────────────────────────────────────────
  //
  // Elle prend la page entiere, comme une fiche ordinaire : c'est le meme geste, au meme endroit,
  // et on n'a pas change d'ecran quand le produit existe.
  if (params.nouveau === '1') {
    return (
      <NouveauProduit
        types={types}
        onAnnule={() => majParams({ nouveau: '' }, { pousser: true })}
        onCree={async (corps) => {
          const cree = await api.creerProduit(corps)
          await recharger()
          // On enchaine sur la vraie fiche : le produit existe, il a son code, et on reste au
          // meme endroit — seul le titre remplace les deux champs.
          majParams({ nouveau: '', fiche: String(cree.id) }, { pousser: true })
        }}
      />
    )
  }

  const tronquee = total > (produits || []).length

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card" style={{ marginBottom: 16 }}>
        <div className="card-b">
          <div className="row row-champs" style={{ gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <div className="field" style={{ margin: 0, flex: '2 1 260px' }}>
              <label htmlFor="cat-q">Rechercher un produit</label>
              <input
                id="cat-q"
                className="input"
                placeholder="Nom du produit…"
                value={saisie}
                onChange={(e) => setSaisie(e.target.value)}
              />
            </div>
            <div className="field" style={{ margin: 0, flex: '1 1 160px' }}>
              <label htmlFor="cat-statut">État</label>
              <select id="cat-statut" className="input" value={params.statut} onChange={(e) => majParams({ statut: e.target.value })}>
                <option value="">Tous les états</option>
                <option value="brouillon">Brouillons</option>
                <option value="publie">Publiés</option>
                <option value="archive">Archivés</option>
              </select>
            </div>
            <div className="field" style={{ margin: 0, flex: '1 1 180px' }}>
              <label htmlFor="cat-type">Type</label>
              <select id="cat-type" className="input" value={params.type} onChange={(e) => majParams({ type: e.target.value })}>
                <option value="">Tous les types</option>
                {types.map((t) => (
                  <option key={t.id} value={t.code || t.id}>{t.libelle}</option>
                ))}
              </select>
            </div>
          </div>
          {/* Le serveur coupe a 30 et ignore `itemsPerPage` (voir `api/client.js`). Sur un catalogue,
              une liste coupee en silence fait conclure qu'un produit n'existe pas. */}
          {tronquee && (
            <p className="hint" style={{ marginBottom: 0 }}>
              {(produits || []).length} produits affichés sur {total}. Affinez la recherche pour voir les autres.
            </p>
          )}
        </div>
      </section>

      <div className="card">
        {chargement ? (
          <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
        ) : (
          <div className="card-b" style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Libellé</th>
                  <th>Type</th>
                  <th>État</th>
                  <th className="num">Tarif</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {(produits || []).map((p) => (
                  <tr key={p.id}>
                    <td>
                      <button
                        type="button"
                        className="lnk"
                        onClick={() => majParams({ fiche: String(p.id) }, { pousser: true })}
                        title="Ouvrir la fiche du produit"
                        style={{
                          background: 'none',
                          border: 0,
                          padding: 0,
                          font: 'inherit',
                          color: 'inherit',
                          cursor: 'pointer',
                          textAlign: 'left',
                          textDecoration: 'underline',
                          textUnderlineOffset: 3,
                        }}
                      >
                        <span className="nm">{libelleProduit(p)}</span>
                      </button>
                    </td>
                    <td>{p.type?.libelle || humaniser(p.typeCode)}</td>
                    <td>
                      <span className={`badge ${statutProduit(p).ton}`} title={statutProduit(p).aide}>
                        {statutProduit(p).libelle}
                      </span>
                    </td>
                    <td className="num">{euros(prixIndicatif(p))}</td>
                    <td className="num">
                      <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                        {actionsStatut(p.statut).map((a) => {
                          // ⚠ LE DROIT VIENT DE L'ACTION, ET IL EST CELUI DU SERVEUR. Ces quatre
                          // boutons se rendaient pour tout le monde ; l'echec arrivait apres le
                          // clic, sur un ecran ou « Publier » met un produit EN VENTE. On ne cache
                          // pas l'action — savoir qu'elle existe fait partie du travail — on la
                          // neutralise et on dit le droit qui manque.
                          const autorise = !a.droit || aLeDroit(droits, a.droit)
                          return (
                          <button
                            key={a.id}
                            className={`btn ${a.ton} sm`}
                            type="button"
                            title={autorise
                              ? a.aide
                              : `Ce compte n’a pas le droit « ${a.droit} ». Ce n’est pas une panne : demandez-le à un administrateur.`}
                            disabled={actionEnCours === p.id || !autorise}
                            onClick={() => agir(p, a)}
                          >
                            {actionEnCours === p.id ? '…' : a.libelle}
                          </button>
                          )
                        })}
                        <button className="btn ghost sm" type="button" onClick={() => setProduitOptions(p)}>
                          Options
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
                {(produits || []).length === 0 && (
                  <tr>
                    <td colSpan={5} className="empty">
                      {produits === null
                        ? 'La liste des produits n’a pas pu être lue. Ce tableau est vide parce que la lecture a échoué, pas parce que le catalogue l’est.'
                        : 'Aucun produit.'}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <ProduitOptionsModal
        open={!!produitOptions}
        produit={produitOptions}
        onClose={() => setProduitOptions(null)}
      />

    </>
  )
}

/* ------------------------------------------------------------------- Options */

// Impact tarifaire signé d'une valeur d'option (RG-OPT-04).
function impactLabel(v) {
  const n = parseFloat(v.impactValeur)
  if (Number.isNaN(n)) return '—'
  const signe = n > 0 ? '+' : ''
  return v.impactType === 'pourcentage'
    ? `${signe}${n.toLocaleString('fr-FR')} %`
    : `${signe}${euros(n)}`
}

function OngletOptions() {
  const [groupes, setGroupes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [selId, setSelId] = useState(null)

  const [libelle, setLibelle] = useState('')
  const [mode, setMode] = useState('unique')
  const [enCours, setEnCours] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const gc = await api.groupeOptions()
      setGroupes(membres(gc))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function creerGroupe(e) {
    e.preventDefault()
    if (!libelle.trim()) return
    setEnCours(true)
    setErreur(null)
    try {
      await api.creerGroupeOption({ libelle: libelle.trim(), modeSelection: mode, actif: true })
      setLibelle('')
      setMode('unique')
      await recharger()
    } catch (err) {
      setErreur(err.message || 'Échec de la création du groupe.')
    } finally {
      setEnCours(false)
    }
  }

  async function basculerActif(g) {
    setErreur(null)
    try {
      await api.majGroupeOption(g.id, { actif: !g.actif })
      await recharger()
    } catch (err) {
      setErreur(err.message || 'Échec de la mise à jour.')
    }
  }

  const selectionne = groupes.find((g) => g.id === selId) || null

  return (
    <div className="clients-grid">
      <section className="card">
        <div className="card-h"><h3>Groupes d'options</h3></div>
        <div className="card-b">
          <form onSubmit={creerGroupe} style={{ marginBottom: 14 }}>
            <div className="field" style={{ marginBottom: 10 }}>
              <label htmlFor="go-lib">Libellé *</label>
              <input
                id="go-lib"
                className="input"
                value={libelle}
                onChange={(e) => setLibelle(e.target.value)}
                placeholder="Ex. Taille, Extras…"
                required
              />
            </div>
            <div className="field" style={{ marginBottom: 10 }}>
              <label>Mode de sélection</label>
              <div className="seg">
                <button type="button" className={mode === 'unique' ? 'on' : ''} onClick={() => setMode('unique')}>
                  Choix unique
                </button>
                <button type="button" className={mode === 'multiple' ? 'on' : ''} onClick={() => setMode('multiple')}>
                  Choix multiple
                </button>
              </div>
            </div>
            <button className="btn primary" type="submit" disabled={enCours || !libelle.trim()}>
              {enCours ? 'Création…' : '＋ Créer le groupe'}
            </button>
          </form>

          {erreur && <div className="banner banner-error">{erreur}</div>}

          {chargement ? (
            <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr><th>Groupe</th><th>Choix</th><th>Actif</th></tr>
                </thead>
                <tbody>
                  {groupes.map((g) => (
                    <tr
                      key={g.id}
                      onClick={() => setSelId(g.id)}
                      className={`row-click${selId === g.id ? ' row-active' : ''}`}
                    >
                      <td>
                        {/* Le clic sur la ligne reste ; le clavier a besoin d'une cible réelle. */}
                        <button type="button" className="lnk nm" onClick={() => setSelId(g.id)}>
                          {g.libelle}
                        </button>
                      </td>
                      <td><span className="badge mut">{g.modeSelection === 'multiple' ? 'multiple' : 'unique'}</span></td>
                      <td>
                        <button
                          className={`btn sm${g.actif ? ' primary' : ''}`}
                          type="button"
                          onClick={(e) => { e.stopPropagation(); basculerActif(g) }}
                        >
                          {g.actif ? 'Actif' : 'Inactif'}
                        </button>
                      </td>
                    </tr>
                  ))}
                  {groupes.length === 0 && (
                    <tr><td colSpan={3} className="empty">Aucun groupe d'options.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </section>

      <section className="card">
        <div className="card-h">
          <h3>Valeurs</h3>
          {selectionne && <span className="sub" style={{ marginLeft: 'auto' }}>{selectionne.libelle}</span>}
        </div>
        <div className="card-b">
          {!selectionne ? (
            <div className="empty">Sélectionnez un groupe pour gérer ses valeurs.</div>
          ) : (
            <ValeursGroupe groupe={selectionne} />
          )}
        </div>
      </section>
    </div>
  )
}

function ValeursGroupe({ groupe }) {
  const [valeurs, setValeurs] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const [libelle, setLibelle] = useState('')
  const [impactType, setImpactType] = useState('montant')
  const [impactValeur, setImpactValeur] = useState('0')
  const [enCours, setEnCours] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const vc = await api.valeurOptions(groupe.id)
      // Garde-fou : refiltre côté client si le filtre serveur est ignoré.
      const liste = membres(vc)
        .filter((v) => String(v.groupeOption || '').endsWith(String(groupe.id)))
        .sort((a, b) => (a.ordreAffichage ?? 0) - (b.ordreAffichage ?? 0))
      setValeurs(liste)
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [groupe.id])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function creer(e) {
    e.preventDefault()
    if (!libelle.trim()) return
    setEnCours(true)
    setErreur(null)
    try {
      await api.creerValeurOption({
        groupeOption: `/api/groupe_options/${groupe.id}`,
        libelle: libelle.trim(),
        impactType,
        impactValeur: (parseFloat(impactValeur) || 0).toFixed(2),
        ordreAffichage: valeurs.length,
        actif: true,
      })
      setLibelle('')
      setImpactValeur('0')
      await recharger()
    } catch (err) {
      setErreur(err.message || 'Échec de la création de la valeur.')
    } finally {
      setEnCours(false)
    }
  }

  async function basculerActif(v) {
    setErreur(null)
    try {
      await api.majValeurOption(v.id, { actif: !v.actif })
      await recharger()
    } catch (err) {
      setErreur(err.message || 'Échec de la mise à jour.')
    }
  }

  return (
    <div>
      <form onSubmit={creer} style={{ marginBottom: 14 }}>
        <div className="field" style={{ marginBottom: 10 }}>
          <label htmlFor="vo-lib">Libellé de la valeur *</label>
          <input
            id="vo-lib"
            className="input"
            value={libelle}
            onChange={(e) => setLibelle(e.target.value)}
            placeholder="Ex. Grande taille"
            required
          />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 10 }}>
          <div className="field" style={{ margin: 0 }}>
            <label>Type d'impact</label>
            <div className="seg">
              <button type="button" className={impactType === 'montant' ? 'on' : ''} onClick={() => setImpactType('montant')}>
                Montant €
              </button>
              <button type="button" className={impactType === 'pourcentage' ? 'on' : ''} onClick={() => setImpactType('pourcentage')}>
                %
              </button>
            </div>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="vo-val">Valeur (signée)</label>
            <input
              id="vo-val"
              className="input"
              type="number"
              step="0.01"
              value={impactValeur}
              onChange={(e) => setImpactValeur(e.target.value)}
            />
          </div>
        </div>
        <button className="btn primary" type="submit" disabled={enCours || !libelle.trim()}>
          {enCours ? 'Ajout…' : '＋ Ajouter la valeur'}
        </button>
      </form>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 100 }}><div className="spinner" /></div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr><th>Valeur</th><th className="num">Impact</th><th>Actif</th></tr>
            </thead>
            <tbody>
              {valeurs.map((v) => (
                <tr key={v.id}>
                  <td><span className="nm">{v.libelle}</span></td>
                  <td className="num">{impactLabel(v)}</td>
                  <td>
                    <button
                      className={`btn sm${v.actif ? ' primary' : ''}`}
                      type="button"
                      onClick={() => basculerActif(v)}
                    >
                      {v.actif ? 'Actif' : 'Inactif'}
                    </button>
                  </td>
                </tr>
              ))}
              {valeurs.length === 0 && (
                <tr><td colSpan={3} className="empty">Aucune valeur.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
