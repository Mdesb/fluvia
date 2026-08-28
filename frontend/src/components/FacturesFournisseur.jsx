import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { dateHeureFr, resoudre } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { euros } from '../api/produit.js'

// Les factures fournisseur, et les gestes qui décident si on paie.
//
// ON NE SIGNE PAS À L'AVEUGLE.
//
// Le serveur sait rapprocher une facture de sa commande et de sa réception : `/reconciliation` rend
// les écarts de quantité et de prix, ligne par ligne, avec un drapeau quand le seuil est dépassé.
// Personne ne l'affichait — et le bouton « approuver » existait quand même.
//
// Approuver, c'est engager le paiement. Le faire sans voir les écarts, c'est signer sans regarder ce
// qu'on a commandé ni ce qu'on a reçu. La fenêtre d'approbation charge donc le rapprochement
// **avant** d'afficher le bouton, et le bouton reste actif — un écart n'interdit pas d'approuver, il
// se peut qu'on ait accepté une livraison partielle. Mais on l'aura vu.
//
// C'est la même règle que l'arrêté comptable de ce soir : un geste irréversible doit montrer ce qu'il
// arrête. La différence est qu'ici le serveur sait déjà le dire.
//
// CONTESTER N'EST PAS ANNULER, ET L'ÉCRAN NE LES MET PAS AU MÊME NIVEAU.
//
// Contester suspend le paiement en attendant une réponse du fournisseur : la facture reste vivante,
// et la contestation se résout. Annuler la retire. Les présenter côte à côte comme deux façons de ne
// pas payer ferait choisir la plus définitive par commodité.

const A_TRAITER = ['draft']
const A_PAYER = ['to_pay', 'partially_paid']

export default function FacturesFournisseur({ etabActif, droits }) {
  const [factures, setFactures] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [approbation, setApprobation] = useState(null)
  const [contestation, setContestation] = useState(null)
  const [resolution, setResolution] = useState(null)

  const peutApprouver = aLeDroit(droits, 'finance.supplier_invoice_approve')
  const peutContester = aLeDroit(droits, 'finance.supplier_invoice_dispute')
  const peutSaisir = aLeDroit(droits, 'finance.supplier_invoice_create')

  const [fournisseurs, setFournisseurs] = useState([])

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      // Le fournisseur d une facture revient en IRI nue : l entite Fournisseur n expose rien
      // dans le groupe qui la porte. La colonne << fournisseur >> etait donc vide sur un ecran
      // dont tout l objet est de savoir A QUI on doit de l argent. On charge la liste pour la
      // resoudre ; son absence ne prive pas des factures.
      const [f, four] = await Promise.all([
        api.facturesFournisseur(),
        api.stockFournisseurs().catch(() => null),
      ])
      setFactures(membres(f))
      setFournisseurs(four ? membres(four) : [])
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function annuler(f) {
    if (
      !window.confirm(
        `Annuler la facture ${f.supplierInvoiceNumber} ?\n\nElle sort du circuit de paiement `
          + `définitivement. Si le désaccord porte sur le montant ou la livraison, contestez-la `
          + `plutôt : une contestation se résout, une annulation ne se reprend pas.`,
      )
    )
      return
    setErreur(null)
    try {
      await api.annulerFactureFournisseur(f.id)
      await recharger()
      setSucces('Facture annulée.')
    } catch (e) {
      setErreur(e.message || "L'annulation n'a pas abouti.")
    }
  }

  const aTraiter = factures.filter((f) => A_TRAITER.includes(f.status))
  const contestees = factures.filter((f) => f.status === 'disputed')
  const aPayer = factures.filter((f) => A_PAYER.includes(f.status))
  const closes = factures.filter((f) => f.status === 'paid' || f.status === 'cancelled')

  if (chargement) {
    return (
      <section className="card">
        <div className="card-b center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      </section>
    )
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {contestees.length > 0 && (
        <div className="banner banner-warn">
          <b>{contestees.length} facture{contestees.length > 1 ? 's' : ''} en litige.</b> Le paiement
          est suspendu en attendant une réponse du fournisseur — et une contestation qu'on oublie de
          résoudre est une facture qu'on ne paiera jamais, sans l'avoir décidé.
        </div>
      )}

      <TableauFactures
        titre="À approuver"
        sous={aTraiter.length === 0 ? 'aucune en attente' : `${aTraiter.length} en attente`}
        factures={aTraiter}
        vide="Aucune facture à approuver. Une facture saisie ou importée arrive ici, et n'entre dans le circuit de paiement qu'une fois approuvée."
        actions={(f) => (
          <>
            {peutApprouver && (
              <button className="btn primary sm" type="button" onClick={() => setApprobation(f)}>
                Examiner et approuver
              </button>
            )}
            {peutContester && (
              <button className="btn ghost sm" type="button" onClick={() => setContestation(f)}>
                Contester
              </button>
            )}
            {peutSaisir && (
              <button className="btn ghost sm" type="button" onClick={() => annuler(f)}>
                Annuler
              </button>
            )}
          </>
        )}
      />

      {contestees.length > 0 && (
        <TableauFactures
          titre="En litige"
          sous="paiement suspendu"
          factures={contestees}
          actions={(f) =>
            peutContester && (
              <button className="btn primary sm" type="button" onClick={() => setResolution(f)}>
                Clore le litige
              </button>
            )}
        />
      )}

      {aPayer.length > 0 && (
        <TableauFactures titre="À payer" sous="approuvées, en attente de règlement" factures={aPayer} />
      )}

      {closes.length > 0 && (
        <TableauFactures titre="Réglées et annulées" sous="pour mémoire" factures={closes} />
      )}

      <ApprobationModal
        facture={approbation}
        onClose={() => setApprobation(null)}
        onFait={(m) => { setApprobation(null); setSucces(m); recharger() }}
        onErreur={setErreur}
      />

      <MotifModal
        facture={contestation}
        titre="Contester une facture"
        avertissement="Le paiement est suspendu tant que le litige n'est pas clos. La facture reste vivante : ce n'est pas une annulation."
        libelle="Sur quoi porte le désaccord ?"
        exemple="Quantité facturée 12, réception 10 — deux cartons manquants au BL-2026-0413."
        onClose={() => setContestation(null)}
        onEnvoyer={(f, motif) => api.contesterFactureFournisseur(f.id, { reason: motif })}
        onFait={(m) => { setContestation(null); setSucces(m); recharger() }}
        messageSucces="Facture contestée, paiement suspendu."
        onErreur={setErreur}
      />

      <MotifModal
        facture={resolution}
        titre="Clore un litige"
        avertissement="La facture repart dans le circuit de paiement. Écrivez ce qui a été convenu : c'est ce qu'on relira si le fournisseur revient dessus."
        libelle="Comment le litige a-t-il été réglé ?"
        exemple="Avoir de 84,00 € reçu le 26/08, facture ramenée à 10 unités."
        onClose={() => setResolution(null)}
        onEnvoyer={(f, motif) => api.resoudreLitigeFactureFournisseur(f.id, { resolutionReason: motif })}
        onFait={(m) => { setResolution(null); setSucces(m); recharger() }}
        messageSucces="Litige clos."
        onErreur={setErreur}
      />
    </>
  )
}

function TableauFactures({ titre, sous, factures, vide, actions }) {
  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>{titre}</h3>
        <span className="sub">{sous}</span>
      </div>
      <div className="card-b">
        {factures.length === 0 ? (
          <div className="empty">{vide}</div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Facture</th>
                <th>Fournisseur</th>
                <th className="num">Montant TTC</th>
                <th>Échéance</th>
                <th>État</th>
                {actions && <th />}
              </tr>
            </thead>
            <tbody>
              {factures.map((f) => {
                const enRetard =
                  f.dueDate && A_PAYER.includes(f.status) && new Date(f.dueDate) < new Date()
                return (
                  <tr key={f.id}>
                    <td>
                      <span className="nm">{f.supplierInvoiceNumber || '—'}</span>
                      {f.invoiceDate && <div className="sub">{dateHeureFr(f.invoiceDate)}</div>}
                    </td>
                    <td>{resoudre(f.supplier, fournisseurs)?.raisonSociale || <span className="sub">non transmis</span>}</td>
                    <td className="num">{euros(f.amountInclTax)}</td>
                    <td>
                      {f.dueDate ? String(f.dueDate).slice(0, 10) : '—'}
                      {enRetard && (
                        <div>
                          <span className="badge crit">échue</span>
                        </div>
                      )}
                    </td>
                    <td><span className={`badge ${tonStatut(f.status)}`}>{mot(f.status)}</span></td>
                    {actions && (
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                          {actions(f)}
                        </div>
                      </td>
                    )}
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}

function tonStatut(s) {
  if (s === 'paid') return 'good'
  if (s === 'disputed') return 'crit'
  if (s === 'cancelled') return 'mut'
  if (s === 'to_pay' || s === 'partially_paid') return 'info'
  return 'warn'
}

// L'approbation charge le rapprochement AVANT d'afficher le bouton.
function ApprobationModal({ facture, onClose, onFait, onErreur }) {
  const [ecarts, setEcarts] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!facture) return
    setEcarts(null)
    setChargement(true)
    api
      .rapprochementFactureFournisseur(facture.id)
      .then((r) => setEcarts(Array.isArray(r) ? r : []))
      .catch(() => setEcarts(null))
      .finally(() => setChargement(false))
  }, [facture])

  async function approuver() {
    setEnCours(true)
    try {
      await api.approuverFactureFournisseur(facture.id)
      onFait('Facture approuvée : elle entre dans le circuit de paiement.')
    } catch (err) {
      onErreur(err.message || "L'approbation n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  const depasses = (ecarts || []).filter((e) => e.thresholdExceeded)

  return (
    <Modal open={!!facture} onClose={onClose} titre="Examiner et approuver" taille="lg">
      {facture && (
        <>
          <p style={{ marginTop: 0 }}>
            Facture <b>{facture.supplierInvoiceNumber}</b> de{' '}
            <b>{resoudre(facture.supplier, fournisseurs)?.raisonSociale || 'fournisseur non transmis'}</b> —{' '}
            <b>{euros(facture.amountInclTax)}</b> TTC.
          </p>

          <div className="fiche-sec" style={{ marginTop: 0 }}>
            Rapprochement avec la commande et la réception
          </div>

          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : ecarts === null ? (
            <div className="banner banner-warn">
              Le rapprochement n'a pas pu être calculé. <b>Vous approuvez donc sans confrontation à la
              commande ni à la réception</b> — vérifiez le bon de livraison avant de continuer.
            </div>
          ) : ecarts.length === 0 ? (
            <div className="banner banner-ok">
              Aucun écart : les quantités et les prix facturés correspondent à ce qui a été commandé et
              reçu.
            </div>
          ) : (
            <>
              {depasses.length > 0 && (
                <div className="banner banner-error">
                  <b>{depasses.length} écart{depasses.length > 1 ? 's dépassent' : ' dépasse'} le seuil
                  toléré.</b> Approuver reste possible — une livraison partielle acceptée est un cas
                  normal — mais vous engagez le paiement de ce que vous voyez ci-dessous.
                </div>
              )}
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Ligne</th>
                    <th className="num">Écart de quantité</th>
                    <th className="num">Écart de prix unitaire</th>
                    <th className="num">En %</th>
                  </tr>
                </thead>
                <tbody>
                  {ecarts.map((e, i) => (
                    <tr key={e.lineId || i}>
                      <td><span className="mono">{String(e.lineId || '').slice(0, 8) || '—'}</span></td>
                      <td className="num">{e.quantityGap ?? '—'}</td>
                      <td className="num">{e.unitPriceGap != null ? euros(e.unitPriceGap) : '—'}</td>
                      <td className="num">
                        {e.unitPriceGapPercent != null ? (
                          <span className={`badge ${e.thresholdExceeded ? 'crit' : 'warn'}`}>
                            {e.unitPriceGapPercent} %
                          </span>
                        ) : (
                          '—'
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="hint">
                Un écart de quantité positif veut dire qu'on facture plus qu'il n'a été reçu. Si le
                désaccord est réel, <b>contestez</b> plutôt que d'approuver : la contestation suspend
                le paiement et se résout, l'approbation l'engage.
              </div>
            </>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Fermer</button>
            <button className="btn primary" type="button" disabled={enCours || chargement} onClick={approuver}>
              {enCours ? 'Approbation…' : 'Approuver le paiement'}
            </button>
          </div>
        </>
      )}
    </Modal>
  )
}

// Contester et clore un litige demandent tous deux un texte, et le texte est ce qui compte : c'est
// lui qu'on relira dans six mois, quand plus personne ne se souviendra du différend.
function MotifModal({
  facture, titre, avertissement, libelle, exemple, onClose, onEnvoyer, onFait, messageSucces, onErreur,
}) {
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (facture) setMotif('')
  }, [facture])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await onEnvoyer(facture, motif.trim())
      onFait(messageSucces)
    } catch (err) {
      onErreur(err.message || "L'opération n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!facture} onClose={onClose} titre={titre}>
      {facture && (
        <form onSubmit={envoyer}>
          <p style={{ marginTop: 0 }}>
            Facture <b>{facture.supplierInvoiceNumber}</b> — {euros(facture.amountInclTax)} TTC.
          </p>

          <div className="banner banner-warn">{avertissement}</div>

          <div className="field">
            <label htmlFor="ff-motif">{libelle} *</label>
            <textarea
              id="ff-motif"
              className="input"
              rows={3}
              required
              value={motif}
              placeholder={exemple}
              onChange={(e) => setMotif(e.target.value)}
            />
            <div className="hint">
              Obligatoire. C'est ce texte qu'on relira dans six mois, quand plus personne ne se
              souviendra du différend.
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !motif.trim()}>
              {enCours ? 'Envoi…' : 'Enregistrer'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}
