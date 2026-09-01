import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import ReferentielEditable from './ReferentielEditable.jsx'
import { dateHeureFr, jourLocal, resoudre } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { euros } from '../api/produit.js'

// Le cycle d'achat : fournisseur → commande → envoi → confirmation → réception → validation.
//
// POURQUOI LA RÉCEPTION EST DANS LE MÊME LOT QUE LA COMMANDE.
//
// J'ai failli livrer les commandes seules et ajouter la réception ensuite. C'est D55 qui l'interdit :
// une liste de commandes envoyées est une liste de choses en attente, et sans le geste qui les reçoit
// elle ne descend jamais. On aurait appris à l'exploitant à ne plus la regarder — et brancher la
// réception un mois plus tard n'aurait pas défait l'habitude.
//
// L'ÉTABLISSEMENT N'EST JAMAIS ENVOYÉ, MÊME QUAND LE MODÈLE L'ACCEPTE.
//
// `CommandeAchat` et `ReceptionAchat` déclarent `etablissement` en écriture. On ne l'envoie pas : le
// serveur le tient de la session (D3/D8), et un client qui le choisit est un client qui peut écrire
// chez le voisin. C'est le sens de la garde globale qui gèle ces entités — la respecter ici, c'est
// éviter de la faire sauter par une habitude prise sur un écran.
//
// LE NUMÉRO DE COMMANDE N'EST PAS SAISISSABLE : il n'est pas dans le groupe d'écriture, donc c'est le
// serveur qui le fabrique. Un champ « numéro » à l'écran laisserait croire le contraire et ferait
// saisir une valeur qui serait écrasée sans avertissement.

const OUVERTES = ['brouillon', 'envoyee', 'confirmee', 'partiellement_recue']

export default function AchatsStock({ articles, droits, etabActif, onErreur, onFait }) {
  const [fournisseurs, setFournisseurs] = useState([])
  const [commandes, setCommandes] = useState([])
  const [lignes, setLignes] = useState([])
  const [receptions, setReceptions] = useState([])
  const [chargement, setChargement] = useState(true)
  const [nouvelle, setNouvelle] = useState(false)
  const [composition, setComposition] = useState(null)
  const [reception, setReception] = useState(null)

  const peutAcheter = aUnDesDroits(droits, ['stock.gerer_achat', 'stock.gerer'])
  const peutReceptionner = aUnDesDroits(droits, ['stock.receptionner', 'stock.gerer'])
  const peutGererFournisseur = aUnDesDroits(droits, ['stock.gerer_fournisseur', 'stock.gerer'])

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      // LE FOURNISSEUR ET L'ARTICLE ARRIVENT EN IRI NUE, ET C'EST TOUT CE QU'ON VOYAIT.
      //
      // `CommandeAchat.fournisseur`, `LigneCommandeAchat.articleStock` : ni `Fournisseur` ni
      // `ArticleStock` n'exposent la moindre propriété dans les groupes qui les portent — vérifié
      // dans les entités, pas supposé. La colonne « Fournisseur » d'un bon de commande sortait donc
      // vide, et une ligne affichait l'UUID de l'article à la place de son nom.
      //
      // Sur un écran d'achats, ce sont les deux seules informations qui disent CE QU'ON COMMANDE et
      // À QUI. On les résout : les fournisseurs sont déjà chargés ici, et les articles arrivent en
      // propriété depuis l'écran Stock — aucune requête de plus.
      const [f, c, l, r] = await Promise.all([
        api.stockFournisseurs(),
        api.stockCommandesAchat(),
        api.stockLignesCommandeAchat(),
        api.stockReceptions(),
      ])
      setFournisseurs(membres(f))
      setCommandes(membres(c))
      setLignes(membres(l))
      setReceptions(membres(r))
    } catch (e) {
      onErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif, onErreur])

  useEffect(() => {
    recharger()
  }, [recharger])

  const lignesParCommande = useMemo(() => {
    const index = {}
    for (const l of lignes) {
      const id = l.commandeAchat?.id || String(l.commandeAchat || '').split('/').pop()
      if (!id) continue
      ;(index[id] ||= []).push(l)
    }
    return index
  }, [lignes])

  const ouvertes = commandes.filter((c) => OUVERTES.includes(c.statut))
  const closes = commandes.filter((c) => !OUVERTES.includes(c.statut))

  async function geste(commande, action, message) {
    try {
      await action(commande.id)
      await recharger()
      onFait(message)
    } catch (e) {
      onErreur(e.message || "L'opération n'a pas abouti.")
    }
  }

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
          <h3>Commandes en cours</h3>
          <span className="sub">
            {ouvertes.length === 0 ? 'aucune commande ouverte' : `${ouvertes.length} en cours`}
          </span>
          {peutAcheter && (
            <div className="r">
              <button
                className="btn primary sm"
                type="button"
                disabled={fournisseurs.length === 0}
                title={fournisseurs.length === 0 ? "Créez d'abord un fournisseur, plus bas." : undefined}
                onClick={() => setNouvelle(true)}
              >
                ＋ Nouvelle commande
              </button>
            </div>
          )}
        </div>
        <div className="card-b">
          {ouvertes.length === 0 ? (
            <div className="empty">
              Aucune commande en cours.
              {fournisseurs.length === 0
                ? " Créez d'abord un fournisseur : une commande est toujours adressée à quelqu'un."
                : ' Une commande se prépare en brouillon, s’envoie au fournisseur, puis se réceptionne à la livraison.'}
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Commande</th>
                  <th>Fournisseur</th>
                  <th className="num">Lignes</th>
                  <th className="num">Total HT</th>
                  <th>État</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {ouvertes.map((c) => {
                  const l = lignesParCommande[c.id] || []
                  const total = l.reduce(
                    (s, x) => s + (parseFloat(x.quantiteCommandee) || 0) * (parseFloat(x.prixAchatUnitaireHT) || 0),
                    0,
                  )
                  const brouillon = c.statut === 'brouillon'
                  return (
                    <tr key={c.id}>
                      <td>
                        <span className="nm">{c.numero || <span className="sub">numéro à l’envoi</span>}</span>
                        <div className="sub">{c.dateCommande ? dateHeureFr(c.dateCommande) : '—'}</div>
                      </td>
                      <td>{resoudre(c.fournisseur, fournisseurs)?.raisonSociale || '—'}</td>
                      <td className="num">{l.length}</td>
                      <td className="num">{euros(total.toFixed(2))}</td>
                      <td><span className={`badge ${etatBadge(c.statut)}`}>{mot(c.statut)}</span></td>
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                          {peutAcheter && brouillon && (
                            <button className="btn ghost sm" type="button" onClick={() => setComposition(c)}>
                              Modifier les lignes
                            </button>
                          )}
                          {peutAcheter && brouillon && (
                            <button
                              className="btn primary sm"
                              type="button"
                              disabled={l.length === 0}
                              title={l.length === 0 ? 'Une commande vide ne s’envoie pas.' : undefined}
                              onClick={() => geste(c, api.stockEnvoyerCommande, 'Commande envoyée au fournisseur.')}
                            >
                              Envoyer
                            </button>
                          )}
                          {peutAcheter && c.statut === 'envoyee' && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              title="Le fournisseur a accusé réception et s’engage sur la livraison."
                              onClick={() => geste(c, api.stockConfirmerCommande, 'Commande confirmée par le fournisseur.')}
                            >
                              Confirmer
                            </button>
                          )}
                          {peutReceptionner && ['envoyee', 'confirmee', 'partiellement_recue'].includes(c.statut) && (
                            <button
                              className="btn primary sm"
                              type="button"
                              onClick={() => setReception({ commande: c, lignes: l })}
                            >
                              Réceptionner
                            </button>
                          )}
                          {peutAcheter && brouillon && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              onClick={() => {
                                if (!window.confirm(
                                  `Annuler cette commande ?\n\nElle ne pourra plus être envoyée ni reçue. `
                                    + `Ses lignes sont conservées pour mémoire.`,
                                )) return
                                geste(c, api.stockAnnulerCommande, 'Commande annulée.')
                              }}
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
          )}
        </div>
      </section>

      {receptions.length > 0 && (
        <ReceptionsSection
          receptions={receptions}
          fournisseurs={fournisseurs}
          peutReceptionner={peutReceptionner}
          onValider={async (r) => {
            try {
              await api.stockValiderReception(r.id)
              await recharger()
              onFait('Réception validée : les articles entrent en stock.')
            } catch (e) {
              onErreur(e.message || "La validation n'a pas abouti.")
            }
          }}
        />
      )}

      {closes.length > 0 && (
        <section className="card" style={{ marginTop: 16 }}>
          <div className="card-h">
            <h3>Commandes terminées</h3>
            <span className="sub">pour mémoire — reçues, clôturées ou annulées</span>
          </div>
          <div className="card-b">
            <table className="tbl">
              <thead>
                <tr><th>Commande</th><th>Fournisseur</th><th>État</th></tr>
              </thead>
              <tbody>
                {closes.map((c) => (
                  <tr key={c.id}>
                    <td><span className="nm">{c.numero || '—'}</span></td>
                    <td>{resoudre(c.fournisseur, fournisseurs)?.raisonSociale || '—'}</td>
                    <td><span className={`badge ${etatBadge(c.statut)}`}>{mot(c.statut)}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {peutGererFournisseur && (
        <div style={{ marginTop: 16 }}>
          <FournisseursSection onChange={recharger} />
        </div>
      )}

      <NouvelleCommandeModal
        open={nouvelle}
        fournisseurs={fournisseurs}
        onClose={() => setNouvelle(false)}
        onFait={async (commande) => {
          setNouvelle(false)
          await recharger()
          onFait('Commande créée en brouillon. Ajoutez ses lignes, puis envoyez-la.')
          setComposition(commande)
        }}
        onErreur={onErreur}
      />

      <CompositionModal
        commande={composition}
        lignes={composition ? lignesParCommande[composition.id] || [] : []}
        articles={articles}
        onClose={() => setComposition(null)}
        onChange={recharger}
        onErreur={onErreur}
      />

      {/* ⚠ `articles` était lu par `ReceptionModal` sans jamais lui être passé : la modale de
          réception mourait à l'ouverture. `CompositionModal`, juste au-dessus, fait la même
          résolution et le reçoit — c'est la copie qui a perdu la prop. */}
      <ReceptionModal
        etat={reception}
        articles={articles}
        onClose={() => setReception(null)}
        onFait={async (m) => {
          setReception(null)
          await recharger()
          onFait(m)
        }}
        onErreur={onErreur}
      />
    </>
  )
}

function etatBadge(statut) {
  if (statut === 'annulee') return 'mut'
  if (statut === 'recue' || statut === 'cloturee') return 'good'
  if (statut === 'brouillon') return 'mut'
  if (statut === 'partiellement_recue') return 'warn'
  return 'info'
}

// --------------------------------------------------------------------------------------------
// Créer la commande : le fournisseur, et rien d'autre.
// --------------------------------------------------------------------------------------------
// Une commande naît vide et en brouillon. On enchaîne directement sur ses lignes plutôt que de la
// laisser dans la liste : une commande sans ligne ne sert à rien, et l'oublier là est le moyen le
// plus sûr de la retrouver un mois plus tard sans savoir ce qu'on voulait acheter.
function NouvelleCommandeModal({ open, fournisseurs, onClose, onFait, onErreur }) {
  const [fournisseur, setFournisseur] = useState('')
  const [date, setDate] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (open) { setFournisseur(''); setDate('') }
  }, [open])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      // `etablissement` volontairement absent : le serveur le tient de la session (D3/D8).
      const cree = await api.creerCommandeAchat({
        fournisseur: `/api/stock_fournisseurs/${fournisseur}`,
        ...(date ? { dateCommande: date } : {}),
      })
      onFait(cree)
    } catch (err) {
      onErreur(err.message || "La commande n'a pas pu être créée.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Nouvelle commande d'achat">
      <form onSubmit={envoyer}>
        <div className="field">
          <label htmlFor="ca-fourn">Fournisseur *</label>
          <select
            id="ca-fourn"
            className="input"
            required
            value={fournisseur}
            onChange={(e) => setFournisseur(e.target.value)}
          >
            <option value="">Choisir…</option>
            {fournisseurs.filter((f) => f.actif !== false).map((f) => (
              <option key={f.id} value={f.id}>{f.raisonSociale}</option>
            ))}
          </select>
        </div>

        <div className="field">
          <label htmlFor="ca-date">Date de commande</label>
          <input id="ca-date" className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          <div className="hint">
            Laissez vide pour aujourd'hui. Le numéro de commande est attribué par le logiciel.
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours || !fournisseur}>
            {enCours ? 'Création…' : 'Créer et ajouter les lignes'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// --------------------------------------------------------------------------------------------
// Les lignes de la commande.
// --------------------------------------------------------------------------------------------
function CompositionModal({ commande, lignes, articles, onClose, onChange, onErreur }) {
  const [article, setArticle] = useState('')
  const [quantite, setQuantite] = useState('')
  const [prix, setPrix] = useState('')
  const [tva, setTva] = useState('20.00')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (commande) { setArticle(''); setQuantite(''); setPrix(''); setTva('20.00') }
  }, [commande])

  // Le prix d'achat de la fiche article sert de proposition : c'est presque toujours le bon, et le
  // retaper à chaque ligne est le meilleur moyen de se tromper d'un facteur dix.
  function choisirArticle(id) {
    setArticle(id)
    const a = articles.find((x) => x.id === id)
    if (a && !prix) setPrix(a.prixAchatHT || '')
  }

  async function ajouter(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.creerLigneCommandeAchat({
        commandeAchat: `/api/stock_commande_achats/${commande.id}`,
        articleStock: `/api/article_stocks/${article}`,
        quantiteCommandee: String(quantite),
        prixAchatUnitaireHT: String(prix),
        tauxTVA: String(tva),
      })
      setArticle('')
      setQuantite('')
      setPrix('')
      await onChange()
    } catch (err) {
      onErreur(err.message || "La ligne n'a pas été ajoutée.")
    } finally {
      setEnCours(false)
    }
  }

  const total = lignes.reduce(
    (s, l) => s + (parseFloat(l.quantiteCommandee) || 0) * (parseFloat(l.prixAchatUnitaireHT) || 0),
    0,
  )

  return (
    <Modal
      open={!!commande}
      onClose={onClose}
      titre={commande ? `Lignes — ${commande.numero || 'commande en brouillon'}` : ''}
      taille="lg"
    >
      {commande && (
        <>
          {lignes.length === 0 ? (
            <div className="empty">
              Cette commande n'a aucune ligne. Ajoutez ce que vous voulez acheter : sans ligne, elle ne
              peut pas être envoyée.
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Article</th>
                  <th className="num">Quantité</th>
                  <th className="num">Prix unitaire HT</th>
                  <th className="num">Total HT</th>
                </tr>
              </thead>
              <tbody>
                {lignes.map((l) => (
                  <tr key={l.id}>
                    <td>{resoudre(l.articleStock, articles)?.libelle || <span className="sub">article non transmis</span>}</td>
                    <td className="num">{l.quantiteCommandee}</td>
                    <td className="num">{euros(l.prixAchatUnitaireHT)}</td>
                    <td className="num">
                      {euros(
                        ((parseFloat(l.quantiteCommandee) || 0) * (parseFloat(l.prixAchatUnitaireHT) || 0)).toFixed(2),
                      )}
                    </td>
                  </tr>
                ))}
                <tr>
                  <td colSpan={3} className="num"><b>Total HT</b></td>
                  <td className="num"><b>{euros(total.toFixed(2))}</b></td>
                </tr>
              </tbody>
            </table>
          )}

          <div className="fiche-sec" style={{ marginTop: 14 }}>Ajouter une ligne</div>
          <form onSubmit={ajouter} className="grid" style={{ gridTemplateColumns: '2fr 1fr 1fr 1fr auto', gap: 10, alignItems: 'end' }}>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="lc-art">Article</label>
              <select id="lc-art" className="input" required value={article} onChange={(e) => choisirArticle(e.target.value)}>
                <option value="">Choisir…</option>
                {articles.filter((a) => a.actif !== false).map((a) => (
                  <option key={a.id} value={a.id}>{a.libelle}</option>
                ))}
              </select>
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="lc-qte">Quantité</label>
              <input id="lc-qte" className="input" type="number" step="0.001" min="0" required value={quantite} onChange={(e) => setQuantite(e.target.value)} />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="lc-prix">Prix HT</label>
              <input id="lc-prix" className="input" type="number" step="0.0001" min="0" required value={prix} onChange={(e) => setPrix(e.target.value)} />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="lc-tva">TVA %</label>
              <input id="lc-tva" className="input" type="number" step="0.01" min="0" required value={tva} onChange={(e) => setTva(e.target.value)} />
            </div>
            <button className="btn primary" type="submit" disabled={enCours || !article}>
              {enCours ? '…' : 'Ajouter'}
            </button>
          </form>

          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Fermer</button>
          </div>
        </>
      )}
    </Modal>
  )
}

// --------------------------------------------------------------------------------------------
// Réceptionner.
// --------------------------------------------------------------------------------------------
// La réception se crée en brouillon avec ses lignes, puis se VALIDE. C'est la validation qui fait
// entrer la marchandise en stock — et l'écran le dit, parce qu'une réception saisie et jamais validée
// est un stock qui n'existe que sur le quai.
function ReceptionModal({ etat, articles, onClose, onFait, onErreur }) {
  const [bl, setBl] = useState('')
  const [quantites, setQuantites] = useState({})
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!etat) return
    setBl('')
    const q = {}
    for (const l of etat.lignes) {
      const reste = (parseFloat(l.quantiteCommandee) || 0) - (parseFloat(l.quantiteRecue) || 0)
      q[l.id] = reste > 0 ? String(reste) : '0'
    }
    setQuantites(q)
  }, [etat])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      const recue = await api.creerReceptionAchat({
        commandeAchat: `/api/stock_commande_achats/${etat.commande.id}`,
        fournisseur: `/api/stock_fournisseurs/${etat.commande.fournisseur?.id}`,
        numeroBonLivraison: bl.trim(),
        // Date locale : en UTC, une réception saisie après minuit portait la date de la veille.
        date: jourLocal(),
      })
      for (const l of etat.lignes) {
        const q = parseFloat(quantites[l.id]) || 0
        if (q <= 0) continue
        const idArticle = l.articleStock?.id || String(l.articleStock || '').split('/').pop()
        await api.creerLigneReceptionAchat({
          reception: `/api/stock_reception_achats/${recue.id}`,
          articleStock: `/api/article_stocks/${idArticle}`,
          ligneCommandeAchat: `/api/stock_ligne_commande_achats/${l.id}`,
          quantiteRecue: String(q),
          prixAchatUnitaireHT: String(l.prixAchatUnitaireHT || '0'),
        })
      }
      onFait(
        'Réception enregistrée en brouillon. Elle n’entre en stock qu’une fois validée — le bouton est '
        + 'dans la liste des réceptions.',
      )
    } catch (err) {
      onErreur(err.message || "La réception n'a pas pu être enregistrée.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal
      open={!!etat}
      onClose={onClose}
      titre={etat ? `Réceptionner — ${etat.commande.numero || 'commande'}` : ''}
      taille="lg"
    >
      {etat && (
        <form onSubmit={envoyer}>
          <div className="field">
            <label htmlFor="rc-bl">Numéro du bon de livraison *</label>
            <input id="rc-bl" className="input" required value={bl} placeholder="BL-2026-0413" onChange={(e) => setBl(e.target.value)} />
            <div className="hint">Celui qui figure sur le papier du transporteur : c'est lui qu'on rapprochera de la facture.</div>
          </div>

          <div className="fiche-sec">Ce qui a été livré</div>
          {etat.lignes.length === 0 ? (
            <div className="empty">Cette commande n'a aucune ligne à réceptionner.</div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Article</th>
                  <th className="num">Commandé</th>
                  <th className="num">Déjà reçu</th>
                  <th className="num">Reçu aujourd'hui</th>
                </tr>
              </thead>
              <tbody>
                {etat.lignes.map((l) => (
                  <tr key={l.id}>
                    <td>{resoudre(l.articleStock, articles)?.libelle || <span className="sub">article non transmis</span>}</td>
                    <td className="num">{l.quantiteCommandee}</td>
                    <td className="num">{l.quantiteRecue || '0'}</td>
                    <td className="num">
                      <input
                        className="input"
                        type="number"
                        step="0.001"
                        min="0"
                        style={{ width: 110, textAlign: 'right' }}
                        value={quantites[l.id] ?? ''}
                        onChange={(e) => setQuantites((s) => ({ ...s, [l.id]: e.target.value }))}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          <div className="hint">
            Les quantités sont pré-remplies avec ce qui reste à recevoir. Mettez à zéro ce qui n'est pas
            arrivé : une livraison partielle est normale, et la commande restera ouverte pour le reste.
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !bl.trim()}>
              {enCours ? 'Enregistrement…' : 'Enregistrer la réception'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

function ReceptionsSection({ receptions, fournisseurs, peutReceptionner, onValider }) {
  const brouillons = receptions.filter((r) => r.statut === 'brouillon')
  const validees = receptions.filter((r) => r.statut !== 'brouillon')

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Réceptions</h3>
        <span className="sub">
          {brouillons.length > 0
            ? `${brouillons.length} en attente de validation`
            : 'toutes validées'}
        </span>
      </div>
      <div className="card-b">
        {brouillons.length > 0 && (
          <div className="banner banner-warn">
            <b>{brouillons.length} réception{brouillons.length > 1 ? 's' : ''} saisie
            {brouillons.length > 1 ? 's' : ''} mais pas validée{brouillons.length > 1 ? 's' : ''}.</b> La
            marchandise n'est pas encore entrée en stock : tant qu'une réception reste en brouillon,
            les quantités saisies n'existent que sur le quai.
          </div>
        )}
        <table className="tbl">
          <thead>
            <tr>
              <th>Bon de livraison</th>
              <th>Fournisseur</th>
              <th>Date</th>
              <th>État</th>
              {peutReceptionner && <th />}
            </tr>
          </thead>
          <tbody>
            {[...brouillons, ...validees].map((r) => (
              <tr key={r.id}>
                <td><span className="nm">{r.numeroBonLivraison || '—'}</span></td>
                <td>{resoudre(r.fournisseur, fournisseurs)?.raisonSociale || '—'}</td>
                <td>{r.date ? dateHeureFr(r.date) : '—'}</td>
                <td>
                  <span className={`badge ${r.statut === 'brouillon' ? 'warn' : 'good'}`}>{mot(r.statut)}</span>
                </td>
                {peutReceptionner && (
                  <td className="num">
                    {r.statut === 'brouillon' && (
                      <button className="btn primary sm" type="button" onClick={() => onValider(r)}>
                        Valider et entrer en stock
                      </button>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

// --------------------------------------------------------------------------------------------
// Les fournisseurs.
// --------------------------------------------------------------------------------------------
function FournisseursSection({ onChange }) {
  // Pas de suppression : un fournisseur porte des commandes et des réceptions. Ce qu'on veut, c'est
  // cesser de lui commander — c'est la case « actif », et elle est réversible.
  const descripteur = {
    titre: 'Fournisseurs',
    aQuoiCaSert: 'À qui vous achetez. Une commande est toujours adressée à l’un d’eux.',
    siVide: 'Aucun fournisseur. Sans fournisseur, aucune commande d’achat ne peut être créée.',
    consequenceSuppression: '',
    charger: api.stockFournisseurs,
    creer: api.creerFournisseur,
    modifier: api.majFournisseur,
    supprimer: null,
    champs: [
      { nom: 'raisonSociale', libelle: 'Raison sociale', type: 'text', requis: true, exemple: 'Sodiglace SARL' },
      { nom: 'contact', libelle: 'Contact', type: 'text', exemple: 'Mme Perrin, service commandes' },
      { nom: 'email', libelle: 'Courriel', type: 'text', exemple: 'commandes@sodiglace.fr' },
      { nom: 'telephone', libelle: 'Téléphone', type: 'text', exemple: '01 23 45 67 89' },
      { nom: 'siret', libelle: 'SIRET', type: 'text', exemple: '81234567800017' },
      { nom: 'adresse', libelle: 'Adresse', type: 'text' },
      {
        nom: 'conditionsPaiement',
        libelle: 'Conditions de paiement',
        type: 'text',
        exemple: '30 jours fin de mois',
        aide: 'Texte libre, repris tel quel sur vos commandes.',
      },
      {
        nom: 'actif',
        libelle: 'En service',
        type: 'bool',
        libelleCase: 'On commande encore chez ce fournisseur',
        aide: 'Décocher le retire des choix de commande sans toucher à son historique.',
      },
    ],
    colonnes: [
      { cle: 'raisonSociale', titre: 'Fournisseur', rendu: (r) => <span className="nm">{r.raisonSociale || '—'}</span> },
      { cle: 'contact', titre: 'Contact', rendu: (r) => r.contact || '—' },
      { cle: 'conditionsPaiement', titre: 'Paiement', rendu: (r) => r.conditionsPaiement || '—' },
      {
        cle: 'actif',
        titre: 'État',
        rendu: (r) => (
          <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'en service' : 'retiré'}</span>
        ),
      },
    ],
  }

  return <ReferentielEditable descripteur={descripteur} peutEcrire onChange={onChange} />
}
