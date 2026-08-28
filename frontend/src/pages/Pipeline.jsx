import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'
import Relances from '../components/Relances.jsx'

/**
 * LES AFFAIRES EN COURS — ce qui vit entre « un client appelle » et « un devis part ».
 *
 * **Ce que le produit ne savait pas voir.** La chaîne devis → commande → facture → relance était déjà
 * écrite et branchée. Ce qui manquait est *avant* : une affaire n'existait qu'au moment où quelqu'un
 * rédigeait un devis — donc **toutes celles qui n'y arrivaient pas n'avaient jamais existé**, et il
 * n'y avait rien à en apprendre.
 *
 * **Deux colonnes se déplacent à la main, trois ne se déplacent pas du tout.** « Devis envoyé » et
 * « Gagnée » sont lues du document commercial : le logiciel sait déjà quand un devis est émis et
 * accepté. Les recopier ici les ferait prendre du retard — et une étape en retard est pire
 * qu'absente, parce qu'elle a l'air d'être à jour.
 *
 * > **Une étape que le logiciel peut déduire ne doit pas être tenue à la main, sinon le pipeline ment.**
 *
 * L'écran le **dit** sur chaque carte concernée, plutôt que de laisser l'utilisateur buter sur un
 * glissement qui ne prend pas et conclure à une panne.
 *
 * **Le montant affiché est prévisionnel, et l'écran l'écrit.** C'est ce que le commercial pense
 * vendre, pas un engagement. Le confondre avec le montant du devis serait la même faute que le prix
 * indicatif corrigé à la caisse cette semaine — un chiffre juste au mauvais endroit.
 */

const MOTIFS = [
  ['price', 'Prix'],
  ['deadline', 'Délai'],
  ['competitor', 'Concurrent'],
  ['no_follow_up', 'Sans suite'],
  ['cancelled', 'Projet abandonné'],
  ['other', 'Autre'],
]

const TON = {
  to_qualify: 'mut',
  qualified: 'warn',
  quote_sent: 'good',
  won: 'good',
  lost: 'crit',
}

function euros(v) {
  const n = parseFloat(v ?? '')
  return Number.isNaN(n) ? '—' : n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

function jour(v) {
  if (!v) return null
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? null : d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })
}

export default function Pipeline({ etabActif, droits = [], onNaviguer }) {
  const peutModifier = aLeDroit(droits, 'crm.modifier') || aLeDroit(droits, 'crm.creer')

  const [tableau, setTableau] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [nouvelle, setNouvelle] = useState(false)
  const [aPerdre, setAPerdre] = useState(null)
  const [busy, setBusy] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setTableau(await api.pipeline())
    } catch (e) {
      setErreur(e.message || 'Le pipeline n’a pas pu être chargé.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  async function agir(action, message) {
    setBusy(true)
    setErreur(null)
    try {
      await action()
      if (message) setSucces(message)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  if (chargement) return <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>

  const colonnes = tableau?.colonnes || []

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Affaires</h1>
          <div className="sub">
            {tableau?.total || 0} affaire{(tableau?.total || 0) > 1 ? 's' : ''} · montants prévisionnels
          </div>
        </div>
        {peutModifier && (
          <button className="btn primary" type="button" onClick={() => setNouvelle(true)}>
            + Nouvelle affaire
          </button>
        )}
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {/* CE QU'IL RESTE A FAIRE, AVANT LE TABLEAU DES AFFAIRES.
          Le pipeline dit ou en sont les affaires ; les relances disent ce qu'on doit faire
          aujourd'hui. Mettre les colonnes en premier, c'est demander a l'utilisateur de deduire
          lui-meme sa journee d'un tableau -- et il ne le fait pas. */}
      <Relances
        etabActif={etabActif}
        onOuvrirClient={onNaviguer ? (id) => onNaviguer('clients', { type: 'client', id }) : null}
      />

      <div style={{ display: 'grid', gridTemplateColumns: `repeat(${colonnes.length}, minmax(210px, 1fr))`, gap: 12, overflowX: 'auto' }}>
        {colonnes.map((col) => (
          <section className="card" key={col.etape} style={{ minWidth: 210 }}>
            <div className="card-h" style={{ flexDirection: 'column', alignItems: 'flex-start', gap: 2 }}>
              <span>{col.libelle}</span>
              <span className="sub" style={{ fontVariantNumeric: 'tabular-nums' }}>
                {col.affaires.length} · {euros(col.montantTotal)}
              </span>
            </div>

            <div style={{ display: 'grid', gap: 8, padding: 10 }}>
              {col.affaires.length === 0 ? (
                <div className="sub" style={{ textAlign: 'center', padding: 10, fontSize: 12.5 }}>—</div>
              ) : (
                col.affaires.map((a) => (
                  <article
                    key={a.id}
                    className="card"
                    style={{ padding: 10, border: '1px solid var(--line)', display: 'grid', gap: 4 }}
                  >
                    <span className="nm" style={{ fontSize: 13.5 }}>{a.titre}</span>
                    {a.client && <div className="sub" style={{ fontSize: 12 }}>{a.client}</div>}

                    <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                      <span className={`badge ${TON[col.etape] || 'mut'}`} style={{ fontVariantNumeric: 'tabular-nums' }}>
                        {euros(a.montantEstime)}
                      </span>
                      {jour(a.echeance) && <span className="sub" style={{ fontSize: 12 }}>{jour(a.echeance)}</span>}
                    </div>

                    {a.etapePosePar === 'devis' && (
                      // ON DIT POURQUOI LA CARTE NE BOUGE PAS.
                      // Sans cette ligne, l'utilisateur essaie de la déplacer, échoue, et conclut à
                      // une panne — alors que c'est le devis qui commande.
                      <div className="sub" style={{ fontSize: 11.5 }}>
                        Étape donnée par le devis
                      </div>
                    )}

                    {a.motifPerte && (
                      <div className="sub" style={{ fontSize: 11.5 }}>
                        {a.motifPerte}
                        {a.commentairePerte ? ` — ${a.commentairePerte}` : ''}
                      </div>
                    )}

                    {peutModifier && a.etapePosePar === 'humain' && col.etape !== 'lost' && (
                      <div style={{ display: 'flex', gap: 6, marginTop: 2, flexWrap: 'wrap' }}>
                        {col.etape === 'to_qualify' && (
                          <button
                            className="btn ghost sm"
                            type="button"
                            disabled={busy}
                            style={{ padding: '1px 8px', fontSize: 11.5 }}
                            onClick={() => agir(() => api.majOpportunite(a.id, { stage: 'qualified' }))}
                          >
                            Qualifier
                          </button>
                        )}
                        <button
                          className="btn ghost sm"
                          type="button"
                          disabled={busy}
                          style={{ padding: '1px 8px', fontSize: 11.5 }}
                          onClick={() => setAPerdre(a)}
                        >
                          Perdue
                        </button>
                      </div>
                    )}
                  </article>
                ))
              )}
            </div>
          </section>
        ))}
      </div>

      <NouvelleAffaire
        open={nouvelle}
        busy={busy}
        onFermer={() => setNouvelle(false)}
        onCreer={(corps) =>
          agir(async () => {
            await api.creerOpportunite(corps)
            setNouvelle(false)
          }, 'Affaire créée.')
        }
      />

      <MarquerPerdue
        affaire={aPerdre}
        busy={busy}
        onFermer={() => setAPerdre(null)}
        onValider={(motif, commentaire) =>
          agir(async () => {
            await api.majOpportunite(aPerdre.id, {
              stage: 'lost',
              lossReason: motif,
              ...(commentaire ? { lossComment: commentaire } : {}),
            })
            setAPerdre(null)
          }, 'Affaire close.')
        }
      />
    </div>
  )
}

function NouvelleAffaire({ open, busy, onFermer, onCreer }) {
  const [titre, setTitre] = useState('')
  const [client, setClient] = useState(null)
  const [terme, setTerme] = useState('')
  const [trouves, setTrouves] = useState([])
  const [montant, setMontant] = useState('')
  const [echeance, setEcheance] = useState('')

  useEffect(() => {
    if (!open) return
    setTitre('')
    setClient(null)
    setTerme('')
    setTrouves([])
    setMontant('')
    setEcheance('')
  }, [open])

  // LE CLIENT SE CHERCHE, IL NE SE DEROULE PAS.
  //
  // Une liste deroulante de tous les clients serait inutilisable des la centieme fiche -- et il n'y a
  // pas de raison de charger un annuaire entier pour en designer un. `/crm/clients/recherche` existe
  // et rend deja ce qu'il faut.
  useEffect(() => {
    const q = terme.trim()
    if (q.length < 2 || client) {
      setTrouves([])
      return undefined
    }
    let annule = false
    const t = setTimeout(async () => {
      try {
        const r = await api.rechercheClients({ q, itemsPerPage: 5 })
        if (!annule) setTrouves(Array.isArray(r?.items) ? r.items : [])
      } catch {
        if (!annule) setTrouves([])
      }
    }, 250)
    return () => {
      annule = true
      clearTimeout(t)
    }
  }, [terme, client])

  const nomClient = (c) =>
    c?.raisonSociale || [c?.prenom, c?.nom].filter(Boolean).join(' ').trim() || c?.email || 'Client'

  return (
    <Modal open={open} onClose={onFermer} titre="Nouvelle affaire" taille="md">
      <div style={{ display: 'grid', gap: 12 }}>
        <div>
          <label htmlFor="op-titre">Intitulé *</label>
          <input
            id="op-titre"
            className="input"
            value={titre}
            onChange={(e) => setTitre(e.target.value)}
            placeholder="Ex. Sortie scolaire école Jean-Moulin, juin"
          />
        </div>
        <div>
          <label htmlFor="op-client">Client</label>
          {client ? (
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <span className="nm">{nomClient(client)}</span>
              <button className="btn ghost sm" type="button" onClick={() => { setClient(null); setTerme('') }}>
                Changer
              </button>
            </div>
          ) : (
            <>
              <input
                id="op-client"
                className="input"
                value={terme}
                onChange={(e) => setTerme(e.target.value)}
                placeholder="Nom, raison sociale, courriel…"
              />
              {trouves.length > 0 && (
                <div style={{ display: 'grid', gap: 4, marginTop: 6 }}>
                  {trouves.map((c) => (
                    <button key={c.id} className="btn ghost sm" type="button" style={{ textAlign: 'left' }} onClick={() => setClient(c)}>
                      {nomClient(c)}
                    </button>
                  ))}
                </div>
              )}
              {/* Facultatif, et c'est important : une demande arrive souvent AVANT que le client
                  existe en base. Exiger la fiche ferait renoncer a saisir l'affaire -- donc perdre
                  exactement celles qu'on cherchait a suivre. */}
              <div className="hint" style={{ margin: '2px 0 0' }}>
                Facultatif : une demande arrive souvent avant que le client existe en base.
              </div>
            </>
          )}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div>
            <label htmlFor="op-montant">Montant prévisionnel</label>
            <input
              id="op-montant"
              className="input"
              type="number"
              step="0.01"
              min="0"
              value={montant}
              onChange={(e) => setMontant(e.target.value)}
            />
            <div className="hint" style={{ margin: '2px 0 0' }}>
              Ce que vous pensez vendre. N&rsquo;engage rien — le devis fera foi.
            </div>
          </div>
          <div>
            <label htmlFor="op-echeance">Échéance</label>
            <input id="op-echeance" className="input" type="date" value={echeance} onChange={(e) => setEcheance(e.target.value)} />
          </div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button
            className="btn primary"
            type="button"
            disabled={busy || titre.trim() === ''}
            onClick={() =>
              onCreer({
                title: titre.trim(),
                stage: 'to_qualify',
                estimatedAmount: montant ? Number(montant).toFixed(2) : '0.00',
                ...(client ? { customer: `/api/clients/${client.id}` } : {}),
                ...(echeance ? { expectedCloseDate: echeance } : {}),
              })
            }
          >
            Créer
          </button>
        </div>
      </div>
    </Modal>
  )
}

function MarquerPerdue({ affaire, busy, onFermer, onValider }) {
  const [motif, setMotif] = useState('')
  const [commentaire, setCommentaire] = useState('')

  useEffect(() => {
    if (!affaire) return
    setMotif('')
    setCommentaire('')
  }, [affaire])

  return (
    <Modal open={!!affaire} onClose={onFermer} titre="Affaire perdue" taille="sm">
      <div style={{ display: 'grid', gap: 12 }}>
        <div className="sub">
          {/* Le motif est la seule donnée du pipeline qui serve encore dans six mois : au bout de
              trente affaires, les motifs s'additionnent et disent quelque chose. Le reste du tableau
              est périssable. */}
          Le motif est obligatoire. C&rsquo;est la seule donnée du pipeline qui serve encore dans six
          mois : au bout de trente affaires, les motifs disent ce qu&rsquo;aucun autre écran ne dit.
        </div>
        <div>
          <label htmlFor="op-motif">Motif *</label>
          <select id="op-motif" className="select" value={motif} onChange={(e) => setMotif(e.target.value)}>
            <option value="">Choisir…</option>
            {MOTIFS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor="op-comm">Détail</label>
          <textarea id="op-comm" className="input" rows={2} value={commentaire} onChange={(e) => setCommentaire(e.target.value)} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn" type="button" disabled={busy || !motif} onClick={() => onValider(motif, commentaire.trim())}>
            Clore
          </button>
        </div>
      </div>
    </Modal>
  )
}
