import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { resoudre } from './Liste.jsx'
import { api, membres, ApiError } from '../api/client.js'
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
  const [saisie, setSaisie] = useState(false)

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

      {/* L'ECRAN TRAITAIT UNE FILE QU'AUCUN GESTE NE REMPLISSAIT. Le bouton porte son propre droit :
          `finance.supplier_invoice_create` n'est pas `finance.supplier_invoice_approve`, et c'est
          tout l'interet du controle -- qui saisit une facture n'est pas qui l'approuve. */}
      {peutSaisir && (
        <div className="row" style={{ justifyContent: 'flex-end', marginBottom: 12 }}>
          <button className="btn primary" type="button" onClick={() => setSaisie(true)}>
            Enregistrer une facture
          </button>
        </div>
      )}

      {contestees.length > 0 && (
        <div className="banner banner-warn">
          <b>{contestees.length} facture{contestees.length > 1 ? 's' : ''} en litige.</b> Le paiement
          est suspendu en attendant une réponse du fournisseur — et une contestation qu'on oublie de
          résoudre est une facture qu'on ne paiera jamais, sans l'avoir décidé.
        </div>
      )}

      <TableauFactures
        fournisseurs={fournisseurs}
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
          fournisseurs={fournisseurs}
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
        <TableauFactures fournisseurs={fournisseurs} titre="À payer" sous="approuvées, en attente de règlement" factures={aPayer} />
      )}

      {closes.length > 0 && (
        <TableauFactures fournisseurs={fournisseurs} titre="Réglées et annulées" sous="pour mémoire" factures={closes} />
      )}

      <ApprobationModal
        facture={approbation}
        fournisseurs={fournisseurs}
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

      <SaisieFactureModal
        open={saisie}
        fournisseurs={fournisseurs}
        etabActif={etabActif}
        onClose={() => setSaisie(false)}
        onFait={() => {
          setSaisie(false)
          setSucces('Facture enregistrée en brouillon. Elle attend maintenant son approbation.')
          recharger()
        }}
      />
    </>
  )
}


// ENREGISTRER UNE FACTURE FOURNISSEUR — l'entrée qui manquait à toute la partie dépenses.
//
// Maxime : « Achats & trésorerie — on ne peut pas enregistrer une facture fournisseur, il faut donc
// créer le module fournisseur. » L'écran savait rapprocher, approuver, contester, résoudre un litige
// et annuler : il traitait une file qu'aucun geste ne remplissait.
//
// Le « module fournisseur » au sens des tiers existe déjà — les fournisseurs se créent depuis le
// cycle d'achat du Stock. Ce qui manquait est l'ENTRÉE de la facture : un formulaire, pas une chaîne
// d'extraction. `POST /api/supplier_invoices` et `POST /api/supplier_invoice_lines` existent depuis
// le début, protégées par `finance.supplier_invoice_create`, et n'étaient appelées de nulle part.
//
// LES LIGNES SE POSENT APRÈS, UNE À UNE, ET CE N'EST PAS UN CHOIX D'ÉCRAN.
//
// `SupplierInvoice.lines` ne porte aucun groupe d'écriture : les lignes ne s'embarquent pas dans le
// corps de la facture. Lu dans l'entité avant d'écrire ce formulaire — un envoi groupé aurait été
// accepté en 201 avec une facture à zéro euro et aucune ligne, c'est-à-dire un succès faux.
//
// ⚠ ET UNE LIGNE NE SE SUPPRIME PAS : `SupplierInvoiceLine` ne déclare ni `Delete` ni désactivation.
// D'où l'ordre du formulaire — on compose tout à l'écran, on relit, puis on crée. Se tromper coûte
// une facture à annuler, pas une ligne à retirer. C'est une lacune du serveur, signalée, pas une
// préférence d'ici.
function SaisieFactureModal({ open, fournisseurs, etabActif, onClose, onFait }) {
  const [profil, setProfil] = useState('')
  const [profils, setProfils] = useState([])
  const [mappings, setMappings] = useState([])
  const [taux, setTaux] = useState([])
  const [fournisseur, setFournisseur] = useState('')
  const [numero, setNumero] = useState('')
  const [dateFacture, setDateFacture] = useState('')
  const [echeance, setEcheance] = useState('')
  const [lignes, setLignes] = useState([{ ...LIGNE_FOURNISSEUR_VIDE }])
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return undefined
    setNumero('')
    setDateFacture(new Date().toISOString().slice(0, 10))
    setEcheance('')
    setLignes([{ ...LIGNE_FOURNISSEUR_VIDE }])
    setErreur(null)
    setFournisseur(fournisseursActifs[0]?.id || '')
    let annule = false
    Promise.allSettled([api.profilsExploitant(), api.mappingsDepense(), api.tauxTvas()]).then(([p, m, t]) => {
      if (annule) return
      const lp = p.status === 'fulfilled' ? membres(p.value) : []
      setProfils(lp)
      setProfil(lp[0]?.id || '')
      setMappings(m.status === 'fulfilled' ? membres(m.value) : [])
      setTaux(t.status === 'fulfilled' ? membres(t.value).filter((x) => x.actif !== false) : [])
    })
    return () => { annule = true }
  }, [open, fournisseurs])

  // UN FOURNISSEUR DESACTIVE NE SE PROPOSE PAS : c'est le sens du drapeau. La preprod en porte un,
  // nomme << Fournisseur Finance Inactif SARL >>, et il figurait dans la liste.
  const fournisseursActifs = fournisseurs.filter((f) => f.actif !== false)

  function majLigne(i, champ, valeur) {
    setLignes((p) => p.map((l, j) => (i === j ? { ...l, [champ]: valeur } : l)))
  }

  // Les natures de dépense viennent du référentiel d'imputation du profil comptable : c'est lui qui
  // dit sur quel compte la ligne s'impute. Une nature absente d'ici existerait quand même côté
  // serveur — mais sans mapping, la facture ne pourrait pas être passée en comptabilité.
  const naturesDuProfil = mappings.filter(
    (m) => m.active !== false && (!profil || String(m.businessProfile || '').endsWith(profil)),
  )

  const pret = fournisseur && profil && numero.trim() && dateFacture && echeance
    && lignes.every((l) => l.description.trim() && l.quantity && l.unitPriceExclTax && l.vatRate && l.expenseNatureCode)

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      const facture = await api.creerFactureFournisseur({
        establishment: `/api/etablissements/${etabActif}`,
        businessProfile: `/api/profil_exploitants/${profil}`,
        supplier: `/api/stock_fournisseurs/${fournisseur}`,
        supplierInvoiceNumber: numero.trim(),
        invoiceDate: dateFacture,
        dueDate: echeance,
      })
      // La facture existe ; les lignes se posent ensuite. Si l'une échoue, on ne prétend pas que
      // rien n'a été fait — la facture est là, en brouillon, et on le dit.
      for (const l of lignes) {
        await api.creerLigneFactureFournisseur({
          supplierInvoice: facture['@id'] || `/api/supplier_invoices/${facture.id}`,
          description: l.description.trim(),
          quantity: String(l.quantity),
          unitPriceExclTax: String(l.unitPriceExclTax),
          vatRate: l.vatRate,
          expenseNatureCode: l.expenseNatureCode,
        })
      }
      onFait()
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : "La facture n'a pas pu être enregistrée.")
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Enregistrer une facture fournisseur" taille="lg">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {fournisseursActifs.length === 0 && (
          <div className="banner banner-warn">
            Aucun fournisseur n’est enregistré sur cet établissement. Une facture appartient à un
            fournisseur : créez-le d’abord dans <b>Stock › Achats</b>.
          </div>
        )}

        <div className="row" style={{ gap: 10, flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="sf-fourn">Fournisseur *</label>
            <select id="sf-fourn" className="input" value={fournisseur} onChange={(e) => setFournisseur(e.target.value)}>
              {fournisseursActifs.map((f) => (
                <option key={f.id} value={f.id}>{f.raisonSociale || f.nom || f.id}</option>
              ))}
            </select>
          </div>
          <div className="field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="sf-num">Numéro de la facture *</label>
            <input
              id="sf-num"
              className="input"
              value={numero}
              maxLength={64}
              placeholder="Tel qu’il figure sur le document reçu"
              onChange={(e) => setNumero(e.target.value)}
            />
            <p className="hint">
              C’est ce numéro qui permet de retrouver la pièce chez le fournisseur en cas de litige.
              Recopiez-le, ne l’inventez pas.
            </p>
          </div>
        </div>

        <div className="row" style={{ gap: 10, flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="sf-date">Date de la facture *</label>
            <input id="sf-date" className="input" type="date" value={dateFacture} onChange={(e) => setDateFacture(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="sf-ech">Échéance *</label>
            <input id="sf-ech" className="input" type="date" value={echeance} onChange={(e) => setEcheance(e.target.value)} />
            <p className="hint">La date à laquelle le fournisseur attend son paiement.</p>
          </div>
          {profils.length > 1 && (
            <div className="field" style={{ flex: '1 1 220px' }}>
              <label htmlFor="sf-profil">Profil comptable *</label>
              <select id="sf-profil" className="input" value={profil} onChange={(e) => setProfil(e.target.value)}>
                {profils.map((p) => <option key={p.id} value={p.id}>{p.referentielComptable || p.type || p.id}</option>)}
              </select>
            </div>
          )}
        </div>

        <div className="fiche-sec" style={{ marginTop: 14 }}>Lignes</div>
        {lignes.map((l, i) => (
          <div className="card" key={i} style={{ padding: 12, marginBottom: 10 }}>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor={`sf-desc-${i}`}>Désignation *</label>
              <input
                id={`sf-desc-${i}`}
                className="input"
                value={l.description}
                placeholder="Ce que le fournisseur a facturé"
                onChange={(e) => majLigne(i, 'description', e.target.value)}
              />
            </div>
            <div className="row" style={{ gap: 10, flexWrap: 'wrap', marginTop: 8 }}>
              <div className="field" style={{ margin: 0, flex: '0 1 110px' }}>
                <label htmlFor={`sf-qte-${i}`}>Quantité *</label>
                <input id={`sf-qte-${i}`} className="input" type="number" step="0.001" min="0"
                  value={l.quantity} onChange={(e) => majLigne(i, 'quantity', e.target.value)} />
              </div>
              <div className="field" style={{ margin: 0, flex: '0 1 140px' }}>
                <label htmlFor={`sf-pu-${i}`}>Prix unitaire HT *</label>
                <input id={`sf-pu-${i}`} className="input" type="number" step="0.0001" min="0"
                  value={l.unitPriceExclTax} onChange={(e) => majLigne(i, 'unitPriceExclTax', e.target.value)} />
              </div>
              <div className="field" style={{ margin: 0, flex: '1 1 160px' }}>
                <label htmlFor={`sf-tva-${i}`}>TVA *</label>
                {/* `vatRate` pointe un `TauxTva`, pas une nature. Ma premiere version listait les
                    codes de nature du referentiel d'imputation : le menu affichait
                    << travel_expense_report_test -- TVA deductible >> pour ce qui doit dire
                    << Taux normal 20 % >>. Vu a l'ecran. */}
                <select id={`sf-tva-${i}`} className="input" value={l.vatRate} onChange={(e) => majLigne(i, 'vatRate', e.target.value)}>
                  <option value="">— choisir —</option>
                  {taux.map((t) => (
                    <option key={t.id} value={t['@id'] || `/api/taux_tvas/${t.id}`}>
                      {t.libelle} — {t.taux} %
                    </option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ margin: 0, flex: '1 1 200px' }}>
                <label htmlFor={`sf-nat-${i}`}>Nature de dépense *</label>
                <select
                  id={`sf-nat-${i}`}
                  className="input"
                  value={l.expenseNatureCode}
                  onChange={(e) => {
                    const code = e.target.value
                    // Le referentiel d'imputation porte un taux deductible par nature : le proposer
                    // evite de le rechercher, sans l'imposer -- une facture peut porter un autre taux.
                    const m = naturesDuProfil.find((x) => x.expenseNatureCode === code)
                    setLignes((p) => p.map((ligne, j) => (i === j
                      ? { ...ligne, expenseNatureCode: code, vatRate: m?.deductibleVatRate || ligne.vatRate }
                      : ligne)))
                  }}
                >
                  <option value="">— choisir —</option>
                  {naturesDuProfil.map((m) => (
                    <option key={m.id} value={m.expenseNatureCode}>{m.expenseNatureCode}</option>
                  ))}
                </select>
              </div>
            </div>
          </div>
        ))}

        {naturesDuProfil.length === 0 && (
          <div className="banner banner-warn">
            Aucune nature de dépense n’est associée à un compte pour ce profil comptable. Une facture
            saisie sans imputation ne pourra pas être passée en comptabilité — le rapprochement et
            l’approbation fonctionneront, la ligne comptable non.
          </div>
        )}

        <button className="btn ghost sm" type="button" onClick={() => setLignes((p) => [...p, { ...LIGNE_FOURNISSEUR_VIDE }])}>
          + Ajouter une ligne
        </button>

        <p className="hint">
          Relisez avant d’enregistrer : une ligne posée ne peut pas être retirée. La facture, elle,
          reste modifiable tant qu’elle est en brouillon, et s’annule tant qu’elle n’est pas approuvée.
        </p>

        <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
          <button type="button" className="btn" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn primary" disabled={envoi || !pret}>
            {envoi ? 'Enregistrement…' : 'Enregistrer la facture'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

const LIGNE_FOURNISSEUR_VIDE = {
  description: '',
  quantity: '1',
  unitPriceExclTax: '',
  vatRate: '',
  expenseNatureCode: '',
}

// ⚠ CES DEUX COMPOSANTS LISAIENT `fournisseurs` SANS LE RECEVOIR, ET PERSONNE NE L'AVAIT VU.
//
// `resoudre(f.supplier, fournisseurs)` ne s'execute que sur une LIGNE, et il n'y avait jamais eu de
// ligne : aucune facture fournisseur ne pouvait etre enregistree. L'ecran plantait donc a la
// premiere facture de son existence -- constate en enregistrant la premiere, ce qui est exactement
// ce qu'aucune relecture n'aurait montre.
//
// Le garde-fou des imports ne le voit pas non plus : il surveille les composants et utilitaires
// importes, pas une variable de portee absente. Le build non plus -- Vite ne fait pas d'analyse de
// portee sur le JSX.
// UNE DATE SANS HEURE NE S'AFFICHE PAS AVEC UNE HEURE.
//
// `invoiceDate` et `dueDate` sont des `date_immutable` cote serveur : elles n'ont pas d'heure. Le
// tableau les rendait avec `dateHeureFr`, qui affichait << 28/08/2026 02:00:00 >> -- deux heures du
// matin, c'est-a-dire le decalage UTC du minuit local, presente comme un fait. Et l'echeance
// s'affichait en ISO brut, << 2026-09-30 >>.
function jourFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('fr-FR')
}

function TableauFactures({ titre, sous, factures, vide, actions, fournisseurs = [] }) {
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
                      {f.invoiceDate && <div className="sub">{jourFr(f.invoiceDate)}</div>}
                    </td>
                    <td>{resoudre(f.supplier, fournisseurs)?.raisonSociale || <span className="sub">non transmis</span>}</td>
                    <td className="num">{euros(f.amountInclTax)}</td>
                    <td>
                      {jourFr(f.dueDate)}
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
function ApprobationModal({ facture, fournisseurs = [], onClose, onFait, onErreur }) {
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
