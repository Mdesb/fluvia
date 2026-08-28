import { useEffect, useState, useCallback } from 'react'
import { useEtatUrl } from '../api/url.js'
import { api, membres } from '../api/client.js'
import { libelleProduit, prixIndicatif, euros, statutProduit, actionsStatut } from '../api/produit.js'
import Tabs from '../components/Tabs.jsx'
import ProduitOptionsModal from '../components/ProduitOptionsModal.jsx'
import ProduitFiche from '../components/ProduitFiche.jsx'
import { humaniser } from '../api/vocabulaire.js'
import { aLeDroit } from '../api/droits.js'

// MEME MOTIF QUE CLIENTS, PARCE QUE MAXIME A DEMANDE LA MEME CHOSE : « je pense que pour le produit
// on devrait faire pareil que pour le client. » Liste large, fiche en page, retour qui rend les
// filtres. `useEtatUrl` (api/url.js) est ecrit pour servir aux deux plutot que recopie ici.
const DEFAUTS = { tab: 'produits', q: '', statut: '', type: '', fiche: '' }

export default function Catalogue({ etabActif, cible = null, onCibleConsommee, droits = [] }) {
  const [params, majParams] = useEtatUrl('catalogue', DEFAUTS)
  const tab = params.tab
  const setTab = (v) => majParams({ tab: v, fiche: '' })

  // Une cible « produit » arrive de la recherche globale : on s'assure d'être sur le bon onglet
  // avant que la liste ne tente de l'ouvrir.
  useEffect(() => {
    if (cible?.type === 'produit') majParams({ tab: 'produits' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cible])

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Catalogue</h1>
          <p>Produits et options</p>
        </div>
      </div>

      <Tabs
        onglets={[['produits', 'Produits'], ['options', 'Options']]}
        actif={tab}
        onChange={setTab}
      />

      {tab === 'produits' ? (
        <OngletProduits
          etabActif={etabActif}
          cible={cible}
          onCibleConsommee={onCibleConsommee}
          droits={droits}
          params={params}
          majParams={majParams}
        />
      ) : (
        <OngletOptions />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ Produits */

function OngletProduits({ etabActif, cible = null, onCibleConsommee, droits = [], params, majParams }) {
  const [produits, setProduits] = useState([])
  const [total, setTotal] = useState(0)
  const [saisie, setSaisie] = useState(params.q)
  const [types, setTypes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [libelle, setLibelle] = useState('')
  const [typeId, setTypeId] = useState('')
  const [enCours, setEnCours] = useState(false)

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
      setTypes(t)
      setTypeId((prev) => prev || (t.length ? t[0].id : ''))
    } catch (e) {
      setErreur(e.message)
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

  async function creer(e) {
    e.preventDefault()
    setErreur(null)
    setSucces(null)
    if (!libelle.trim() || !typeId) return
    setEnCours(true)
    try {
      // Le code produit est généré automatiquement côté back : il n'est plus saisi ni envoyé.
      await api.creerProduit({
        libelle: { fr: libelle.trim() },
        type: `/api/type_produits/${typeId}`,
        canaux: ['guichet'],
        etablissements: etabActif ? [`/api/etablissements/${etabActif}`] : [],
      })
      setSucces(`Produit « ${libelle.trim()} » créé.`)
      setLibelle('')
      await recharger()
    } catch (err) {
      setErreur(err.message || 'Échec de la création du produit.')
    } finally {
      setEnCours(false)
    }
  }

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
    if (action.confirmation && !window.confirm(action.confirmation.replace('%s', libelleProduit(produit)))) return
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
    const connu = produits.find((p) => String(p.id) === String(selId))
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
          peutModifier={aLeDroit(droits, 'offre.modifier') || aLeDroit(droits, 'offre.gerer')}
          peutModifierCompta={aLeDroit(droits, 'offre.modifier_compta') || aLeDroit(droits, 'offre.gerer')}
          onModifie={recharger}
        />
      </>
    )
  }

  const tronquee = total > produits.length

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card" style={{ marginBottom: 16 }}>
        <div className="card-b">
          <div className="row" style={{ gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
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
              {produits.length} produits affichés sur {total}. Affinez la recherche pour voir les autres.
            </p>
          )}
        </div>
      </section>

      <form className="card" onSubmit={creer} style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>Nouveau produit</h3></div>
        <div className="card-b">
          <div
            style={{ display: 'grid', gridTemplateColumns: '2fr 1fr auto', gap: 12, alignItems: 'end' }}
            className="cat-form-row"
          >
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="lib">Libellé *</label>
              <input
                id="lib"
                className="input"
                value={libelle}
                onChange={(e) => setLibelle(e.target.value)}
                placeholder="Ex. Entrée adulte"
                required
              />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="type">Type *</label>
              <select id="type" className="select" value={typeId} onChange={(e) => setTypeId(e.target.value)}>
                {types.map((t) => (
                  <option key={t.id} value={t.id}>{t.libelle}</option>
                ))}
              </select>
            </div>
            <button className="btn primary" type="submit" disabled={enCours}>
              {enCours ? 'Création…' : '＋ Créer'}
            </button>
          </div>
          <div className="hint">Le code produit est généré automatiquement.</div>
        </div>
      </form>

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
                {produits.map((p) => (
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
                        {actionsStatut(p.statut).map((a) => (
                          <button
                            key={a.id}
                            className={`btn ${a.ton} sm`}
                            type="button"
                            title={a.aide}
                            disabled={actionEnCours === p.id}
                            onClick={() => agir(p, a)}
                          >
                            {actionEnCours === p.id ? '…' : a.libelle}
                          </button>
                        ))}
                        <button className="btn ghost sm" type="button" onClick={() => setProduitOptions(p)}>
                          Options
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
                {produits.length === 0 && (
                  <tr><td colSpan={5} className="empty">Aucun produit.</td></tr>
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
                      <td><span className="nm">{g.libelle}</span></td>
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
