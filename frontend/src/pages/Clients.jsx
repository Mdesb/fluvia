import { useEffect, useState, useCallback } from 'react'
import Modal from '../components/Modal.jsx'
import { useEtatUrl, allerA } from '../api/url.js'
import ActivitesClient, { SaisieEchange } from '../components/ActivitesClient.jsx'
import ContactsClient from '../components/ContactsClient.jsx'
import { api, membres } from '../api/client.js'
import { euros } from '../api/produit.js'
import { aLeDroit } from '../api/droits.js'
import FusionClients from '../components/FusionClients.jsx'
import { mot } from '../api/vocabulaire.js'
import ClientEditionModal from '../components/ClientEditionModal.jsx'
import DevisModal from '../components/DevisModal.jsx'
import PassagesClient from '../components/PassagesClient.jsx'

// Nom d'affichage d'un client (physique ou personne morale).
/**
 * LE JOURNAL DES FUSIONS — qui a fusionne quoi, quand, pourquoi, et comment revenir.
 *
 * ⚠ IL N'EST PAS UN CONFORT D'AUDIT : c'est le seul chemin vers `defusionner`. Sans lui, la fusion
 * serait irreversible en pratique meme si le serveur sait la defaire, et personne de sense ne
 * fusionnerait les deux fiches d'un client qui reclame.
 *
 * Une fusion defaite RESTE au journal : c'est l'historique de ce qui a ete tente, et il vaut autant
 * que celui de ce qui a tenu.
 */
function JournalDesFusions() {
  const [entrees, setEntrees] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(null)

  const charger = useCallback(() => {
    api.journalFusions()
      .then((r) => setEntrees(membres(r)))
      .catch((e) => setErreur(e.message || 'Le journal des fusions n’a pas pu être lu.'))
  }, [])

  useEffect(charger, [charger])

  async function defaire(entree) {
    if (!window.confirm(
      'Défusionner ? Les fiches absorbées sont restaurées à l’identique, et cette opération reste '
      + 'au journal.',
    )) return
    setBusy(entree.id)
    setErreur(null)
    try {
      await api.defusionner(entree.id)
      charger()
    } catch (e) {
      setErreur(e.message || 'La défusion n’a pas abouti.')
    } finally {
      setBusy(null)
    }
  }

  if (entrees !== null && entrees.length === 0) return null

  return (
    <section className="card card-espacee">
      <div className="card-h"><h3>Fusions effectuées</h3></div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {entrees === null && !erreur && <div className="empty">Chargement…</div>}
        {(entrees ?? []).map((e) => (
          <div key={e.id} className="sup-acces">
            <div className="sup-acces-t">
              <span className="mono">{String(e.id).slice(0, 8)}</span>
              <span className="badge">{e.portee || 'client'}</span>
              {e.defusionneLe && <span className="badge mut">défusionnée</span>}
            </div>
            <div className="hint">{e.motif || 'sans motif'}</div>
            <div className="hint">{dateHeureFr(e.dateFusion)}</div>
            {!e.defusionneLe && (
              <button
                type="button"
                className="btn ghost sm"
                disabled={busy === e.id}
                onClick={() => defaire(e)}
                title="Restaure les fiches absorbées à l’identique. L’opération reste au journal."
              >
                {busy === e.id ? 'Restauration…' : 'Défusionner'}
              </button>
            )}
          </div>
        ))}
      </div>
    </section>
  )
}

function nomClient(c) {
  if (!c) return '—'
  if (c.raisonSociale) return c.raisonSociale
  const nom = [c.prenom, c.nom].filter(Boolean).join(' ').trim()
  return nom || c.email || 'Client'
}

function dateFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

function dateHeureFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// Adresse structurée côté back : { rue, complement, cp, ville, pays }.
function formatAdresse(a) {
  if (!a) return null
  if (typeof a === 'string') return a
  const l1 = [a.rue, a.complement].filter(Boolean).join(', ')
  const l2 = [a.cp, a.ville].filter(Boolean).join(' ')
  return [l1, l2, a.pays].filter(Boolean).join(' · ') || null
}

// LA LISTE PREND TOUT L'ÉCRAN, ET LA FICHE EST UNE PAGE — demande de Maxime, 29/08.
//
// « Sur client ce que je vois, c'est une liste comme ça mais sur tout l'écran avec plus de champs,
// et quelques actions rapides ; quand on clique sur un client, on va sur sa fiche. Et il faudra un
// bouton retour pour qu'on retourne sur la liste, et s'il y a eu des filtres il faudra qu'ils soient
// encore en place. »
//
// L'écran affichait la liste dans une colonne étroite et la fiche à côté : quatre colonnes de
// données sur un tiers de la largeur, et une fiche riche compressée sur les deux autres tiers. Les
// deux perdaient. Une liste de travail se lit en largeur ; une fiche 360° se lit en hauteur.
//
// L'ÉTAT EST DANS L'URL, ET CE N'EST PAS POUR FAIRE JOLI. Le retour préserve les filtres parce
// qu'ils n'ont jamais quitté l'URL — et par la même occasion la vue survit à une expiration de
// session, qui tombe toutes les heures. Voir `api/url.js` pour le raisonnement complet.
//
// TROIS FILTRES QUE LE SERVEUR ACCEPTAIT ET QUE PERSONNE NE POUVAIT ATTEINDRE. `GET
// /crm/clients/recherche` lit `statut`, `avecPmv`, `mineur`, `carte`, `page` et `itemsPerPage`.
// L'écran n'envoyait que `q`. Le reste existait, testé, cloisonné — et hors de portée.
//
// ⚠ CE QUE CETTE LISTE NE PEUT PAS MONTRER, ET POURQUOI ON NE L'INVENTE PAS. La réponse de la
// recherche est VOLONTAIREMENT minimale : ni e-mail, ni téléphone, ni adresse, ni date de naissance
// (« données perso protégées », §5 plan-crm.md, lu dans `RechercheClientProvider`). Ajouter ces
// colonnes rendrait des cases vides sur toutes les lignes — le défaut le plus fréquent de ce dépôt.
// Le CA cumulé et le solde du porte-monnaie, eux, ne vivent que dans la fiche 360 : une colonne
// coûterait une requête PAR LIGNE. On affiche donc ce que la recherche rend, et rien d'autre.
const DEFAUTS = { q: '', statut: '', pmv: '', mineur: '', carte: '', page: '1', fiche: '' }

const STATUTS = [
  ['', 'Tous les statuts'],
  ['actif', 'Actifs'],
  ['inactif', 'Inactifs'],
  ['archive', 'Archivés'],
  ['anonymise', 'Anonymisés'],
  ['fusionne', 'Fusionnés'],
]

const PAR_PAGE = 50

// Écran Clients (CRM) : liste large, fiche en page, état porté par l'URL.
export default function Clients({ etabActif, cible = null, onCibleConsommee, droits = [] }) {
  const [params, majParams] = useEtatUrl('clients', DEFAUTS)
  const [edition, setEdition] = useState(null) // { id } = modification, { creation: true } = ajout

  const [items, setItems] = useState([])
  const [total, setTotal] = useState(0)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  // Saisie locale, pour ne pas réécrire l'URL à chaque touche.
  const [saisie, setSaisie] = useState(params.q)

  const [fiche, setFiche] = useState(null)
  const [mouvements, setMouvements] = useState(null) // null = non chargé, [] = vide
  const [ficheLoading, setFicheLoading] = useState(false)
  const [ficheErr, setFicheErr] = useState(null)
  const [fidelite, setFidelite] = useState(null)
  const [echange, setEchange] = useState(null) // client dont on note un échange, depuis la liste
  const [devisPour, setDevisPour] = useState(null)

  const selId = params.fiche || null
  const page = Math.max(1, parseInt(params.page, 10) || 1)

  const rechercher = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const res = await api.rechercheClients({
        q: params.q || '',
        carte: params.carte || '',
        statut: params.statut || '',
        // `avecPmv` et `mineur` sont des booléens côté serveur : une chaîne vide ne veut pas dire
        // « faux », elle veut dire « ne filtre pas ». `qs()` retire les valeurs vides, donc le
        // paramètre n'est pas envoyé du tout — ce qui est exactement le sens voulu.
        avecPmv: params.pmv,
        mineur: params.mineur,
        page,
        itemsPerPage: PAR_PAGE,
      })
      setItems(res.items || [])
      setTotal(res.total ?? (res.items || []).length)
    } catch (e) {
      setErreur(e.message)
      // ⚠ `null` = PAS LU. L'ecran annoncait << 0 fiche(s) >> puis << Aucun client enregistre.
      // "Ajouter un client" cree la premiere fiche. >> -- le message d'accueil d'un fichier vide,
      // servi a quelqu'un dont le fichier client existe et n'a pas pu etre lu.
      setItems(null)
      setTotal(null)
    } finally {
      setChargement(false)
    }
  }, [params.q, params.carte, params.statut, params.pmv, params.mineur, page])

  useEffect(() => { rechercher() }, [rechercher, etabActif])

  // Recherche différée à la frappe : l'URL ne bouge qu'une fois la saisie posée.
  useEffect(() => {
    if (saisie === params.q) return undefined
    const t = setTimeout(() => majParams({ q: saisie, page: '1' }), 300)
    return () => clearTimeout(t)
  }, [saisie, params.q, majParams])

  // Le solde se relit APRÈS chaque geste : il se recalcule côté serveur, et le recopier ici ferait
  // diverger l'écran de la vérité au premier arrondi.
  const rechargerFidelite = useCallback(async (id) => {
    if (!id || !aLeDroit(droits, 'fidelite.lire')) { setFidelite(null); return }
    try {
      setFidelite(await api.fidelite(id))
    } catch {
      // Sans programme de fidélité, ou sans droit : la fiche vit très bien sans ce bloc.
      setFidelite(null)
    }
  }, [droits])

  const chargerFiche = useCallback(async (id) => {
    setFiche(null)
    setMouvements(null)
    setFicheErr(null)
    setFicheLoading(true)
    try {
      rechargerFidelite(id)
      const f = await api.ficheClient(id)
      const complet = await api.client(id).catch(() => null)
      setFiche(complet ? { ...f, client: { ...(f.client || {}), ...complet } } : f)
      // Relevé PMV chargé séparément (US-L5-04) uniquement si un porte-monnaie existe.
      if (f?.pmv) {
        try {
          const mv = await api.pmvMouvements(id)
          setMouvements(mv?.mouvements || [])
        } catch {
          setMouvements([])
        }
      } else {
        setMouvements([])
      }
    } catch (e) {
      setFicheErr(e.message || 'Fiche indisponible.')
    } finally {
      setFicheLoading(false)
    }
  }, [rechargerFidelite])

  useEffect(() => {
    if (selId) chargerFiche(selId)
  }, [selId, chargerFiche])

  // Ouvrir une fiche POUSSE une entrée d'historique : le « Précédent » du navigateur ramène alors à
  // la liste, filtres compris, au lieu de quitter l'application.
  function ouvrirFiche(id) {
    majParams({ fiche: id }, { pousser: true })
  }

  function retourListe() {
    majParams({ fiche: '' }, { pousser: true })
  }

  // Fiche demandee par la recherche globale.
  useEffect(() => {
    if (cible?.type !== 'client') return
    majParams({ fiche: cible.id }, { pousser: true })
    onCibleConsommee?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cible])

  const peutModifier = aLeDroit(droits, 'crm.modifier')
  const peutCreer = aLeDroit(droits, 'crm.creer')
  const peutFacturer = aLeDroit(droits, 'facturation.gerer')
  // Le droit de l'API, et lui seul : `crm.fusionner` garde les trois operations.
  const peutFusionner = aLeDroit(droits, 'crm.fusionner')
  const [fusionPour, setFusionPour] = useState(null)

  // ---------------------------------------------------------------- La fiche, en page
  if (selId) {
    return (
      <div className="view large">
        <div className="view-head">
          <div className="ttl">
            <button className="btn ghost sm" type="button" onClick={retourListe} style={{ marginBottom: 8 }}>
              ← Retour à la liste
            </button>
            <h1>{fiche?.client ? nomClient(fiche.client) : 'Fiche client'}</h1>
            <p>Fiche 360° · CRM</p>
          </div>
          {fiche?.client && peutModifier && (
            <div className="actions">
              <button className="btn" type="button" onClick={() => setEdition({ id: selId })}>Modifier</button>
            </div>
          )}
        </div>

        {ficheLoading ? (
          <div className="center" style={{ minHeight: 240 }}><div className="spinner" /></div>
        ) : ficheErr ? (
          <div className="banner banner-error">{ficheErr}</div>
        ) : fiche ? (
          <section className="card"><div className="card-b">
            <FicheContenu
              fiche={fiche}
              mouvements={mouvements}
              fidelite={fidelite}
              droits={droits}
              onMouvement={() => rechargerFidelite(selId)}
            />
          </div></section>
        ) : null}

        <ClientEditionModal
          open={!!edition}
          clientId={edition?.id || null}
          onClose={() => setEdition(null)}
          onEnregistre={() => chargerFiche(selId)}
        />
      </div>
    )
  }

  // ---------------------------------------------------------------- La liste, sur toute la largeur
  const pages = Math.max(1, Math.ceil(total / PAR_PAGE))
  const filtre = (cle, valeur) => majParams({ [cle]: valeur, page: '1' })

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Clients</h1>
          <p>{total === null ? 'fichier non lu — la lecture n’a pas abouti' : `${total} fiche(s) · CRM`}</p>
        </div>
        <div className="actions">
          {peutCreer && (
            // ON NE POUVAIT PAS CRÉER UN CLIENT DEPUIS L'ÉCRAN CLIENTS.
            //
            // `api.creerClient` existe et poste bien, mais n'était appelé que par `ClientPicker` —
            // lui-même utilisé au milieu d'une vente, d'un mandat SEPA ou d'un devis. On ne pouvait
            // donc créer un client qu'en train de faire autre chose, et sur un établissement sans
            // caisse, pas du tout. L'écran dont le métier est de gérer les clients était le seul
            // d'où l'on ne pouvait pas en ajouter un.
            //
            // « Ajouter un client » et non « Nouveau client » : la carte « Nouveau client · Ouvrir
            // une structure » des Paramètres désigne une SOCIÉTÉ cliente de l'éditeur, pas un
            // contact. Deux boutons du même nom pour deux objets sans rapport, c'est la collision
            // qu'on n'aggrave pas.
            <button className="btn primary" type="button" onClick={() => setEdition({ creation: true })}>
              Ajouter un client
            </button>
          )}
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      <section className="card">
        <div className="card-b">
          <div className="row" style={{ gap: 10, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 14 }}>
            <div className="field" style={{ margin: 0, flex: '2 1 260px' }}>
              <label htmlFor="cl-q">Rechercher</label>
              <input
                id="cl-q"
                className="input"
                placeholder="Nom, prénom, raison sociale, e-mail, téléphone…"
                value={saisie}
                onChange={(e) => setSaisie(e.target.value)}
                autoFocus
              />
            </div>
            <div className="field" style={{ margin: 0, flex: '1 1 150px' }}>
              <label htmlFor="cl-carte">N° de carte</label>
              <input
                id="cl-carte"
                className="input"
                placeholder="Le numéro lu par le lecteur"
                value={params.carte}
                onChange={(e) => filtre('carte', e.target.value)}
              />
            </div>
            <div className="field" style={{ margin: 0, flex: '1 1 150px' }}>
              <label htmlFor="cl-statut">Statut</label>
              <select id="cl-statut" className="input" value={params.statut} onChange={(e) => filtre('statut', e.target.value)}>
                {STATUTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
            <div className="field" style={{ margin: 0, flex: '1 1 150px' }}>
              <label htmlFor="cl-pmv">Porte-monnaie</label>
              <select id="cl-pmv" className="input" value={params.pmv} onChange={(e) => filtre('pmv', e.target.value)}>
                <option value="">Peu importe</option>
                <option value="true">Avec porte-monnaie</option>
                <option value="false">Sans porte-monnaie</option>
              </select>
            </div>
            <div className="field" style={{ margin: 0, flex: '1 1 150px' }}>
              <label htmlFor="cl-mineur">Âge</label>
              <select id="cl-mineur" className="input" value={params.mineur} onChange={(e) => filtre('mineur', e.target.value)}>
                <option value="">Peu importe</option>
                <option value="true">Mineurs</option>
                <option value="false">Majeurs</option>
              </select>
            </div>
          </div>

          {/* Les fiches fusionnées sont masquées par défaut CÔTÉ SERVEUR, sauf demande explicite.
              Le dire ici évite de chercher pourquoi un doublon connu n'apparaît pas. */}
          {params.statut === '' && (
            <p className="hint" style={{ marginTop: 0 }}>
              Les fiches fusionnées sont masquées ; choisissez « Fusionnés » pour les voir.
            </p>
          )}

          {chargement ? (
            <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th>Type</th>
                    <th>Statut</th>
                    <th>Porte-monnaie</th>
                    <th>Dernière visite</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {(items || []).map((c) => (
                    <tr key={c.id} className="row-click" onClick={() => ouvrirFiche(c.id)}>
                      <td>
                        {/* ⚠ C'ÉTAIT LE SEUL CHEMIN VERS LA FICHE, ET IL PASSAIT PAR LA SOURIS.
                            Les boutons de la ligne font autre chose — Devis, Échange. Un
                            utilisateur au clavier ne pouvait donc pas ouvrir un client, sur
                            l'écran principal du CRM. Le clic sur `<tr>` reste ; le nom devient
                            un vrai bouton. */}
                        <button type="button" className="lnk nm" onClick={() => ouvrirFiche(c.id)}>
                          {nomClient(c)}
                        </button>
                        {c.estMineur && <span className="badge warn" style={{ marginLeft: 6 }}>mineur</span>}
                      </td>
                      <td>{c.type === 'morale' ? 'Personne morale' : 'Particulier'}</td>
                      <td><span className={`badge ${c.statut === 'actif' ? 'good' : 'mut'}`}>{c.statut}</span></td>
                      <td>{c.avecPmv ? <span className="badge info">oui</span> : <span className="sub">—</span>}</td>
                      <td>
                        {c.dateDerniereVisite
                          ? dateFr(c.dateDerniereVisite)
                          : <span className="sub">jamais venu</span>}
                      </td>
                      {/* `stopPropagation` : sans lui, chaque action rapide ouvrirait AUSSI la
                          fiche derrière la modale qu'elle vient d'ouvrir. */}
                      <td className="row" style={{ justifyContent: 'flex-end', gap: 6 }} onClick={(e) => e.stopPropagation()}>
                        {peutFacturer && (
                          <button className="btn sm" type="button" onClick={() => setDevisPour(c)}>Devis</button>
                        )}
                        {peutModifier && (
                          <button className="btn sm" type="button" onClick={() => setEchange(c)}>Échange</button>
                        )}
                        {/* ⚠ FUSIONNER EST UN GESTE D'EXPLOITATION COURANT, PAS UNE OPERATION RARE.
                            Le meme adherent inscrit deux fois — une fois en ligne par lui-meme, une
                            fois au guichet par un agent qui n'a pas trouve sa fiche — produit deux
                            cartes, deux soldes, deux historiques. Tout deploiement reel en accumule,
                            et rien ne les resorbe sans ce bouton. */}
                        {peutFusionner && (
                          <button className="btn sm" type="button" onClick={() => setFusionPour(c)}>Fusionner</button>
                        )}
                      </td>
                    </tr>
                  ))}
                  {items === null && (
                    <tr>
                      <td colSpan={6} className="empty">
                        Le fichier client n’a pas pu être lu&nbsp;: ce tableau est vide parce que la
                        lecture a échoué, pas parce qu’aucune fiche n’existe.
                      </td>
                    </tr>
                  )}
                  {items !== null && items.length === 0 && (
                    <tr>
                      <td colSpan={6} className="empty">
                        {params.q || params.carte || params.statut || params.pmv || params.mineur
                          ? 'Aucun client ne correspond à cette recherche.'
                          : 'Aucun client enregistré. « Ajouter un client » crée la première fiche.'}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}

          {/* CETTE LISTE-CI SE PAGINE VRAIMENT, ET C'EST UNE EXCEPTION DANS LE DÉPÔT.
              `RechercheClientProvider` lit `page` et `itemsPerPage` (plafonné à 100) et les
              applique. Les collections API Platform, elles, ignorent `itemsPerPage` et coupent à 30
              — d'où l'avertissement « 30 sur 47 » ailleurs. Ici on peut réellement aller plus loin. */}
          {pages > 1 && (
            <div className="row" style={{ justifyContent: 'center', gap: 10, marginTop: 14 }}>
              <button
                className="btn sm"
                type="button"
                disabled={page <= 1}
                onClick={() => majParams({ page: String(page - 1) })}
              >
                ← Précédents
              </button>
              <span className="sub">page {page} sur {pages}</span>
              <button
                className="btn sm"
                type="button"
                disabled={page >= pages}
                onClick={() => majParams({ page: String(page + 1) })}
              >
                Suivants →
              </button>
            </div>
          )}
        </div>
      </section>

      {/* ⚠ LE CHEMIN DU RETOUR, DANS LE MEME LOT QUE LE BOUTON QUI FUSIONNE.
          Le serveur sait defaire une fusion — les fiches sources sont restaurees a l'identique —
          mais sans cet ecran, personne ne saurait ou cliquer. On aurait donne le pouvoir d'ecraser
          deux fiches en une sans donner celui de revenir, ce qui est pire que de ne rien livrer. */}
      {peutFusionner && <JournalDesFusions />}

      <ClientEditionModal
        open={!!edition}
        clientId={edition?.id || null}
        onClose={() => setEdition(null)}
        onEnregistre={(cree) => {
          rechercher()
          // Une fiche qu'on vient de créer s'ouvre : c'est ce qu'on veut faire ensuite.
          if (cree?.id) majParams({ fiche: String(cree.id) }, { pousser: true })
        }}
      />

      <DevisModal
        open={!!devisPour}
        client={devisPour}
        onClose={() => setDevisPour(null)}
        onCree={() => setDevisPour(null)}
      />

      <FusionClients
        open={!!fusionPour}
        client={fusionPour}
        onClose={() => setFusionPour(null)}
        onFusionnee={() => { setFusionPour(null); rechercher() }}
      />

      <Modal open={!!echange} onClose={() => setEchange(null)} titre={`Noter un échange — ${nomClient(echange)}`}>
        {echange && (
          <SaisieEchange
            clientId={echange.id}
            busy={false}
            setBusy={() => {}}
            onFini={async () => setEchange(null)}
            onAnnuler={() => setEchange(null)}
            onErreur={(m) => m && setErreur(m)}
          />
        )}
      </Modal>
    </div>
  )
}

function FicheContenu({ fiche, mouvements, fidelite, droits, onMouvement }) {
  const c = fiche.client || {}
  const [devis, setDevis] = useState(false)
  const historique = fiche.historique || []
  const famille = fiche.famille || []
  const consentements = fiche.consentements || []
  const adresse = formatAdresse(c.adresse)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
      {/* En-tête identité */}
      <div className="fiche-ident">
        <div className="fiche-avatar" aria-hidden="true">
          {(nomClient(c)[0] || '?').toUpperCase()}
        </div>
        <div style={{ minWidth: 0 }}>
          <div className="fiche-nom">{nomClient(c)}</div>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 4 }}>
            <span className="badge mut">{c.type === 'morale' ? 'Personne morale' : 'Particulier'}</span>
            <span className={`badge ${c.statut === 'actif' ? 'good' : 'mut'}`}>{c.statut || '—'}</span>
            {c.estMineur && <span className="badge warn">mineur</span>}
          </div>
        </div>

        {/* FACTURER SE DÉCIDE ICI, EN REGARDANT LE CLIENT — demande de Maxime, 28/08.
            On facture QUELQU'UN : on regarde ce qu'il a acheté, ce qu'il doit, et on part de là.
            Jusqu'ici il fallait ouvrir l'écran Facturation et RETAPER son nom en texte libre — le
            devis n'était alors rattaché à aucune fiche, et n'apparaissait dans l'historique de
            personne. La modale est la même que celle de l'écran Facturation ; partant d'ici, elle
            envoie en plus `clientRef`, ce qui rattache la pièce à ce client. */}
        {/* LE MOMENT OÙ L'ON APPREND QU'UNE PERSONNE VEUT ÊTRE EFFACÉE, C'EST EN REGARDANT SA
            FICHE — au téléphone, au guichet, un courrier à la main. L'écran des données
            personnelles existe depuis ce matin, mais on n'y arrivait que par le menu, en
            retrouvant la personne une seconde fois dans un sélecteur.
            Le libellé reste neutre : « Demander l'effacement » posé à côté de « Établir un
            devis » ferait d'un geste irréversible un bouton de fiche comme un autre. On mène à
            l'écran qui explique ; on n'efface pas d'ici. */}
        <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
          {(aLeDroit(droits, 'crm.rgpd_gerer') || aLeDroit(droits, 'crm.rgpd_demander')) && (
            <button
              className="btn ghost sm"
              type="button"
              onClick={() => allerA('rgpd', { client: c.id })}
            >
              Données personnelles
            </button>
          )}
          {aLeDroit(droits, 'facturation.gerer') && (
            <button className="btn sm" type="button" onClick={() => setDevis(true)}>
              Établir un devis
            </button>
          )}
        </div>
      </div>

      <DevisModal
        open={devis}
        client={c}
        onClose={() => setDevis(false)}
        onCree={() => setDevis(false)}
      />

      <BlocFidelite
        fidelite={fidelite}
        clientId={c.id}
        droits={droits}
        onMouvement={onMouvement}
      />

      <BlocParrainage clientId={c.id} droits={droits} onMouvement={onMouvement} />

      {/* Indicateurs clés */}
      <div className="fiche-stats">
        <div className="stat-tile">
          <div className="st-val num">{euros(c.caCumule)}</div>
          <div className="st-lbl">CA cumulé</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">{historique.length}</div>
          <div className="st-lbl">Achats</div>
        </div>
        <div className="stat-tile">
          <div className="st-val">{dateFr(c.dateDerniereVisite)}</div>
          <div className="st-lbl">Dernière visite</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">{fiche.pmv ? euros(fiche.pmv.solde) : '—'}</div>
          <div className="st-lbl">Solde PMV</div>
        </div>
      </div>

      {/* Coordonnées (toutes les lignes, état vide propre) */}
      <div>
        <div className="fiche-sec">Coordonnées</div>
        <dl className="deflist">
          <div><dt>Type</dt><dd>{c.type === 'morale' ? 'Entreprise ou association' : 'Particulier'}</dd></div>
          {c.type === 'morale' ? (
            <>
              <div><dt>Raison sociale</dt><dd>{c.raisonSociale || '—'}</dd></div>
              <div><dt>SIRET</dt><dd>{c.siret || '—'}</dd></div>
            </>
          ) : (
            <>
              <div><dt>Civilité</dt><dd>{c.civilite || '—'}</dd></div>
              <div>
                <dt title="Sert aux tarifs liés à l'âge, quand vous en proposez.">Date de naissance</dt>
                <dd>{c.dateNaissance ? dateFr(c.dateNaissance) : '—'}</dd>
              </div>
            </>
          )}
          <div><dt>E-mail</dt><dd>{c.email || '—'}</dd></div>
          <div><dt>Téléphone</dt><dd>{c.telephone || '—'}</dd></div>
          <div><dt>Adresse</dt><dd>{adresse || '—'}</dd></div>
        </dl>
      </div>

      {/* LES CONTACTS, JUSTE APRES LES COORDONNEES.
          Une societe avait une raison sociale, un SIRET, UN courriel et UN telephone. Une entreprise
          n'est pas une personne : c'est une directrice, une comptabilite, quelqu'un qui signe -- et
          ils n'ont pas la meme adresse. Le bloc ne s'affiche que pour un client moral. */}
      <ContactsClient client={c} peutModifier />

      {/* LES ECHANGES, JUSTE APRES LES CONTACTS.
          Savoir A QUI parler ne sert a rien si l'on ne sait plus CE QU'ON S'EST DIT. Le bloc porte
          aussi la relance en attente -- sans case a cocher : on ne coche pas une relance, on la
          remplace en notant l'echange suivant. */}
      <ActivitesClient client={c} peutModifier />

      {/* Porte-monnaie PMV + mouvements */}
      <div>
        <div className="fiche-sec">Porte-monnaie (PMV)</div>
        {fiche.pmv ? (
          <>
            <div className="pmv-box" style={{ marginBottom: 12 }}>
              <div>
                <div className="pmv-solde">{euros(fiche.pmv.solde)}</div>
                <div className="hint" style={{ margin: 0 }}>
                  {fiche.pmv.statut}
                  {fiche.pmv.dateEcheance ? ` · échéance ${dateFr(fiche.pmv.dateEcheance)}` : ''}
                </div>
              </div>
            </div>
            {mouvements === null ? (
              <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
            ) : mouvements.length > 0 ? (
              <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead>
                    <tr><th>Date</th><th>Type</th><th className="num">Montant</th><th className="num">Solde</th></tr>
                  </thead>
                  <tbody>
                    {mouvements.map((m) => (
                      <tr key={m.id}>
                        <td>{dateHeureFr(m.dateMouvement)}</td>
                        <td>
                          <span className="badge mut">{m.type}</span>
                          {m.motif ? <span className="hint" style={{ margin: 0, marginLeft: 6 }}>{m.motif}</span> : null}
                        </td>
                        <td className="num">{euros(m.montant)}</td>
                        <td className="num">{euros(m.soldeApres)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="empty" style={{ padding: 12 }}>Aucun mouvement enregistré.</div>
            )}
          </>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun porte-monnaie.</div>
        )}
      </div>

      {/* Famille / bénéficiaires */}
      <div>
        <div className="fiche-sec">Famille / bénéficiaires</div>
        {famille.length > 0 ? (
          <div>
            {famille.map((f, i) => (
              <span key={i} className="chip">
                {f.libelle} · {f.role}{f.actif === false ? ' (inactif)' : ''}
              </span>
            ))}
          </div>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun rattachement familial.</div>
        )}
      </div>

      {/* Consentements RGPD */}
      <div>
        <div className="fiche-sec">Consentements RGPD</div>
        {consentements.length > 0 ? (
          <div>
            {consentements.map((c2, i) => (
              <span key={i} className={`badge ${c2.exploitable ? 'good' : 'mut'}`} style={{ marginRight: 6, marginBottom: 4 }}>
                {c2.canal} : {c2.etat}
              </span>
            ))}
          </div>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun consentement enregistré.</div>
        )}
      </div>

      {/* Historique d'achats */}
      <div>
        <div className="fiche-sec">Historique d'achats ({historique.length})</div>
        {historique.length > 0 ? (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr><th>Date</th><th>Ticket</th><th className="num">Montant</th></tr>
              </thead>
              <tbody>
                {historique.map((h, i) => (
                  <tr key={i}>
                    <td>{dateFr(h.date)}</td>
                    <td className="mono">{h.numero || '—'}</td>
                    <td className="num">{euros(h.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="empty" style={{ padding: 12 }}>Aucun achat enregistré.</div>
        )}
      </div>

      {/* La fiche savait ce que le client a ACHETÉ, jamais s'il est ENTRÉ. Les deux questions du
          comptoir sont pourtant celles-là : « a-t-il utilisé sa carte ? » et « il dit que la borne
          l'a refusé hier ». Le bloc ne s'affiche pas pour un compte sans droit sur les accès. */}
      <PassagesClient clientId={c.id} droits={droits} />
    </div>
  )
}

/**
 * LA FIDÉLITÉ AU COMPTOIR.
 *
 * Trois chiffres, dans cet ordre, parce que ce sont trois questions différentes :
 *
 *   - le **solde** — ce que le client peut échanger maintenant, la seule chose qu'il demande ;
 *   - le **palier** — ce qu'il est, qui ne baisse pas quand il dépense ;
 *   - ce qui **manque au palier suivant** — le seul chiffre qui fasse revenir. « Il vous manque
 *     40 points » agit ; « vous avez 260 points » n'agit pas.
 *
 * Et une phrase que l'écran doit dire tout haut : les points **n'expirent pas**. Une expiration
 * silencieuse se découvre au comptoir, et c'est ce jour-là qu'on perd le client qu'on voulait
 * fidéliser.
 */
function BlocFidelite({ fidelite, clientId, droits, onMouvement }) {
  const [ouvert, setOuvert] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [points, setPoints] = useState('')
  const [motif, setMotif] = useState('')
  const [sens, setSens] = useState('depense')

  if (!fidelite) return null

  const peutGerer = aLeDroit(droits, 'fidelite.gerer')

  async function enregistrer() {
    setBusy(true)
    setErr(null)
    try {
      await api.mouvementFidelite({
        customerRef: clientId,
        points: Number(points),
        movement: sens,
        reason: motif,
      })
      setOuvert(false)
      setPoints('')
      setMotif('')
      onMouvement?.()
    } catch (e) {
      setErr(e.message || 'Le mouvement n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card">
      <div className="card-h">
        <span>Fidélité</span>
        {fidelite.baremeCourant ? (
          <span className="sub" style={{ marginLeft: 'auto' }}>
            {fidelite.baremeCourant.pointsParEuro} point(s) par euro
          </span>
        ) : (
          // << AUCUN BAREME DEFINI >> DISAIT LE MANQUE SANS DIRE OU LE COMBLER.
          //
          // Le bareme SE CREE, depuis l'ecran Campagnes (`api.creerBaremeFidelite`) : ce n'est pas
          // une capacite absente, c'est un chemin invisible. Sans le dire, on lit la ligne comme
          // << ce logiciel ne fait pas de fidelite >> -- et le compteur de points juste en dessous
          // reste a zero sans qu'on sache pourquoi.
          <span className="sub" style={{ marginLeft: 'auto' }}>
            Aucun barème défini — il se règle dans Campagnes, onglet Fidélité
          </span>
        )}
      </div>

      <div className="fiche-stats">
        <div className="stat-tile">
          <div className="st-val num">{fidelite.solde}</div>
          <div className="st-lbl">Points disponibles</div>
        </div>
        <div className="stat-tile">
          <div className="st-val">{fidelite.palier?.libelle || '—'}</div>
          <div className="st-lbl">Palier</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">
            {fidelite.palierSuivant ? fidelite.palierSuivant.pointsManquants : '—'}
          </div>
          <div className="st-lbl">
            {fidelite.palierSuivant ? `Pour « ${fidelite.palierSuivant.libelle} »` : 'Palier maximal'}
          </div>
        </div>
      </div>

      {!fidelite.expirationDesPoints && (
        <div className="sub" style={{ marginTop: 6 }}>
          Ces points <strong>n’expirent pas</strong>. Une expiration que le client découvrirait au
          comptoir coûterait davantage que les points qu’elle économise.
        </div>
      )}

      {peutGerer && !ouvert && (
        <button
          className="btn ghost sm"
          type="button"
          style={{ marginTop: 10 }}
          onClick={() => setOuvert(true)}
        >
          Dépenser ou ajuster
        </button>
      )}

      {ouvert && (
        <div style={{ display: 'grid', gap: 8, marginTop: 10 }}>
          {err && <div className="banner banner-error">{err}</div>}
          <div className="seg">
            {[['depense', 'Dépense'], ['ajustement', 'Ajustement']].map(([k, l]) => (
              <button key={k} className={sens === k ? 'on' : ''} onClick={() => setSens(k)}>{l}</button>
            ))}
          </div>
          <div className="field">
            <label>Points</label>
            <input type="number" value={points} onChange={(e) => setPoints(e.target.value)} />
          </div>
          <div className="field">
            <label>Motif — le client demandera</label>
            <input
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              placeholder="Entrée offerte, geste commercial…"
            />
          </div>
          <div className="r" style={{ gap: 8 }}>
            <button className="btn ghost sm" type="button" onClick={() => setOuvert(false)}>Annuler</button>
            <button className="btn primary sm" type="button" disabled={busy} onClick={enregistrer}>
              Enregistrer
            </button>
          </div>
        </div>
      )}

      {(fidelite.historique || []).length > 0 && (
        <div style={{ overflowX: 'auto', marginTop: 10 }}>
          <table className="tbl">
            <thead><tr><th>Date</th><th>Mouvement</th><th>Motif</th><th className="num">Points</th></tr></thead>
            <tbody>
              {fidelite.historique.map((m, i) => (
                <tr key={`${m.le}-${i}`}>
                  <td>{dateFr(m.le)}</td>
                  <td>{m.mouvement}</td>
                  <td>{m.motif}</td>
                  <td className="num">{m.points > 0 ? `+${m.points}` : m.points}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

/**
 * LE PARRAINAGE, AU COMPTOIR.
 *
 * Le code s'affiche à la demande — le charger d'office créerait un code de parrainage à tous les
 * clients dont on ouvre la fiche, y compris ceux qui ne parraineront jamais.
 *
 * Deux chiffres suffisent : combien de filleuls, et combien sont **à récompenser**. Le second est
 * le seul qui fasse agir ; « 47 parrainages » se regarde, « 3 à récompenser » se traite.
 *
 * L'écran dit aussi ce qu'il ne fait pas : le code ne part par aucun canal automatique. L'agent le
 * donne. Le taire laisserait croire que le filleul l'a reçu.
 */
function BlocParrainage({ clientId, droits, onMouvement }) {
  const [code, setCode] = useState(null)
  const [liste, setListe] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [codeParrain, setCodeParrain] = useState('')

  const peutLire = aLeDroit(droits, 'fidelite.lire')
  const peutGerer = aLeDroit(droits, 'fidelite.gerer')

  const charger = useCallback(async () => {
    if (!clientId || !peutLire) { setListe(null); return }
    try {
      setListe(await api.parrainages(clientId))
    } catch {
      setListe(null)
    }
  }, [clientId, peutLire])

  useEffect(() => { setCode(null); setErr(null); charger() }, [charger])

  if (!peutLire || !liste) return null

  async function afficherCode() {
    setBusy(true)
    setErr(null)
    try {
      setCode((await api.codeParrainage(clientId)).code)
    } catch (e) {
      setErr(e.message || 'Le code n’a pas pu être obtenu.')
    } finally {
      setBusy(false)
    }
  }

  async function recompenser(id) {
    setBusy(true)
    setErr(null)
    try {
      await api.recompenserParrainage(id)
      await charger()
      onMouvement?.()
    } catch (e) {
      setErr(e.message || 'La récompense n’a pas pu être versée.')
    } finally {
      setBusy(false)
    }
  }

  async function declarer() {
    setBusy(true)
    setErr(null)
    try {
      await api.declarerParrainage({ code: codeParrain.trim().toUpperCase(), refereeRef: clientId })
      setCodeParrain('')
      await charger()
    } catch (e) {
      setErr(e.message || 'Ce parrainage n’a pas pu être déclaré.')
    } finally {
      setBusy(false)
    }
  }

  const filleuls = liste.parrainages || []

  return (
    <div className="card">
      <div className="card-h">
        <span>Parrainage</span>
        {liste.aRecompenser > 0 && (
          <span className="badge warn" style={{ marginLeft: 'auto' }}>
            {liste.aRecompenser} à récompenser
          </span>
        )}
      </div>

      <div className="r" style={{ gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
        {code ? (
          <>
            <span className="sub">Son code :</span>
            <strong style={{ fontFamily: 'monospace', fontSize: 18, letterSpacing: 2 }}>{code}</strong>
          </>
        ) : (
          <button className="btn ghost sm" type="button" disabled={busy} onClick={afficherCode}>
            Afficher son code de parrainage
          </button>
        )}
      </div>

      {code && (
        <div className="sub" style={{ marginTop: 6 }}>
          Ce code n’est envoyé par <strong>aucun canal automatique</strong> : donnez-le au client. Le
          jour où la boutique en ligne saura le porter, elle appellera la même adresse.
        </div>
      )}

      {err && <div className="banner banner-error" style={{ marginTop: 8 }}>{err}</div>}

      {filleuls.length > 0 && (
        <div style={{ overflowX: 'auto', marginTop: 10 }}>
          <table className="tbl">
            <thead>
              <tr><th>Filleul</th><th>Depuis</th><th>État</th><th className="num">Points</th><th /></tr>
            </thead>
            <tbody>
              {filleuls.map((p) => (
                <tr key={p.id}>
                  <td style={{ fontFamily: 'monospace', fontSize: 12 }}>{p.filleul.slice(0, 8)}…</td>
                  <td>{dateFr(p.le)}</td>
                  <td>
                    <span className={`badge ${p.etat === 'eligible' ? 'warn' : p.etat === 'recompense' ? 'good' : 'mut'}`}>
                      {p.libelle}
                    </span>
                  </td>
                  <td className="num">{p.pointsVerses || '—'}</td>
                  <td>
                    {p.etat === 'eligible' && peutGerer && (
                      <button
                        className="btn primary sm"
                        type="button"
                        disabled={busy}
                        onClick={() => recompenser(p.id)}
                      >
                        Récompenser
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {peutGerer && (
        <div className="r" style={{ gap: 8, marginTop: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Ce client a été parrainé — code du parrain</label>
            <input
              value={codeParrain}
              onChange={(e) => setCodeParrain(e.target.value)}
              placeholder="ABCD2345"
              style={{ fontFamily: 'monospace', letterSpacing: 2 }}
            />
          </div>
          <button
            className="btn ghost sm"
            type="button"
            disabled={busy || codeParrain.trim().length < 4}
            onClick={declarer}
          >
            Déclarer
          </button>
        </div>
      )}
    </div>
  )
}
