import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { euros, libelleProduit } from '../api/produit.js'
import { mot } from '../api/vocabulaire.js'
import { aLeDroit } from '../api/droits.js'
// `texte` lit un libelle multilingue : le libelle fige d'une ligne est un objet `{ fr: '...' }`.
import { texte } from './Liste.jsx'

// Historique des ventes — `GET /api/ventes` existait et n'était appelé nulle part. Un caissier ne
// pouvait pas retrouver une vente d'hier, ni même celle d'il y a dix minutes.
//
// LE FILTRE PAR DATE EST RÉEL, ET IL A FALLU L'ATTENDRE.
//
// À la première version, l'API n'exposait ni tri ni filtre de date. J'aurais pu poser un champ
// « du … au … » et filtrer en mémoire les résultats déjà chargés : ç'aurait été un mensonge, le
// filtre n'aurait porté que sur la page courante, et un caissier qui ne trouve pas sa vente en
// conclurait qu'elle n'existe pas. L'écran disait donc franchement que ce n'était pas possible.
//
// `claude-G` a livré les trois attributs manquants. Le plus important n'était pas le filtre mais
// `order[date]` : sans tri, « les cinquante dernières » n'est pas une promesse qu'on peut tenir —
// l'ordre est celui que la base rend. Je l'affichais pourtant.
//
// Le cloisonnement n'est pas fait ici : `PerimetreVenteExtension` rattache `Vente` à son établissement
// côté serveur. Un établissement ne voit pas les ventes d'un autre, et ce n'est pas au front d'en
// décider.

const ETATS = [
  ['', 'Tous les états'],
  ['validee', 'Validées'],
  ['en_cours', 'En cours'],
  ['annulee', 'Annulées'],
  ['avoir_emis', 'Avoir émis'],
]

const TON_ETAT = { validee: 'good', en_cours: 'warn', annulee: 'mut', avoir_emis: 'info' }

// L'historique des ventes du guichet : un écran de la caisse (#caisse?historique=1), plus une
// modale (le nom du fichier est resté). Le détail d'une vente s'ouvre DANS cet écran.
export default function HistoriqueVentes({ onClose, droits = [], sessionId, onDuplicata, onFacture, onReprendre }) {
  const [numero, setNumero] = useState('')
  const [statut, setStatut] = useState('')
  const [du, setDu] = useState('')
  const [au, setAu] = useState('')
  const [ventes, setVentes] = useState([])
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [detail, setDetail] = useState(null)
  const [detailChargement, setDetailChargement] = useState(false)
  const [produits, setProduits] = useState({})

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // `order[date]=desc` d'abord : sans tri, « les cinquante dernières » n'est pas une promesse
      // qu'on peut tenir — l'ordre est celui que la base rend. Je l'affichais pourtant.
      const params = { itemsPerPage: 50, 'order[date]': 'desc' }
      if (numero.trim()) params.numero = numero.trim()
      if (statut) params.statut = statut
      if (du) params['date[after]'] = du
      if (au) params['date[before]'] = au
      setVentes(membres(await api.ventes(params)))
    } catch (e) {
      setErreur(e.message)
      setVentes([])
    } finally {
      setChargement(false)
    }
  }, [numero, statut, du, au])

  useEffect(() => {
    if (!open) return
    setDetail(null)
    charger()
  }, [open, charger])

  // Les lignes de vente ne portent que l'identifiant du produit, pas son nom : le nom est résolu ici.
  // Un produit archivé depuis la vente ne sera pas trouvé — on affiche alors « produit retiré » plutôt
  // qu'un identifiant, qui n'apprendrait rien à personne.
  useEffect(() => {
    if (!open || Object.keys(produits).length > 0) return
    api
      .produits()
      .then((c) => {
        const parId = {}
        membres(c).forEach((p) => {
          parId[String(p.id)] = libelleProduit(p)
        })
        setProduits(parId)
      })
      .catch(() => {})
  }, [open, produits])

  async function ouvrirDetail(v) {
    setDetailChargement(true)
    setErreur(null)
    try {
      setDetail(await api.vente(v.id))
    } catch (e) {
      setErreur(e.message || 'Détail indisponible.')
    } finally {
      setDetailChargement(false)
    }
  }

  return (
    <>
      <h2>{detail ? `Vente ${detail.numero}` : 'Historique des ventes'}</h2>
      {erreur && <div className="banner banner-error">{erreur}</div>}

      {detail ? (
        <DetailVente
          detail={detail}
          produits={produits}
          droits={droits}
          sessionId={sessionId}
          onRetour={() => setDetail(null)}
          onRembourse={async () => {
            setDetail(await api.vente(detail.id).catch(() => detail))
            charger()
          }}
          onDuplicata={onDuplicata}
          onFacture={onFacture}
          onReprendre={onReprendre}
        />
      ) : (
        <>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              charger()
            }}
            className="grid"
            style={{ gridTemplateColumns: '2fr 1fr 1fr 1fr auto', gap: 10, alignItems: 'end', marginBottom: 12 }}
          >
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="hv-num">Numéro de ticket</label>
              <input
                id="hv-num"
                className="input"
                value={numero}
                placeholder="Tel qu'il figure sur le ticket"
                onChange={(e) => setNumero(e.target.value)}
              />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="hv-etat">État</label>
              <select id="hv-etat" className="select" value={statut} onChange={(e) => setStatut(e.target.value)}>
                {ETATS.map(([v, l]) => (
                  <option key={v || 'tous'} value={v}>{l}</option>
                ))}
              </select>
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="hv-du">Du</label>
              <input id="hv-du" className="input" type="date" value={du} onChange={(e) => setDu(e.target.value)} />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="hv-au">Au</label>
              <input id="hv-au" className="input" type="date" value={au} onChange={(e) => setAu(e.target.value)} />
            </div>
            <button className="btn primary" type="submit" disabled={chargement}>Rechercher</button>
          </form>

          <div className="hint" style={{ marginTop: 0 }}>
            Les cinquante ventes les plus récentes, de la plus récente à la plus ancienne. Affinez par
            numéro de ticket, par état ou par période.
          </div>

          {chargement ? (
            <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
          ) : ventes.length === 0 ? (
            <div className="empty" style={{ padding: 18 }}>
              {numero.trim()
                ? `Aucune vente ne porte le numéro « ${numero.trim()} ».`
                : du || au
                  ? 'Aucune vente sur cette période.'
                  : 'Aucune vente enregistrée pour cet établissement.'}
            </div>
          ) : (
            <div style={{ overflowX: 'auto', maxHeight: '50vh' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Ticket</th>
                    <th>Date</th>
                    <th>État</th>
                    <th className="num">Total</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {ventes.map((v) => (
                    <tr key={v.id}>
                      <td><span className="nm">{v.numero || '—'}</span></td>
                      <td>{dateHeure(v.date)}</td>
                      <td><span className={`badge ${TON_ETAT[v.statut] || 'mut'}`}>{mot(v.statut)}</span></td>
                      <td className="num">{euros(v.total)}</td>
                      <td className="num">
                        <button
                          className="btn ghost sm"
                          type="button"
                          disabled={detailChargement}
                          onClick={() => ouvrirDetail(v)}
                        >
                          Détail
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </>
  )
}

function DetailVente({ detail, produits, droits, sessionId, onRetour, onRembourse, onDuplicata, onFacture, onReprendre }) {
  const [remboursement, setRemboursement] = useState(null)
  const [annulation, setAnnulation] = useState(null) // null | 'formulaire' | { numero }
  const [facture, setFacture] = useState(null) // null | 'en_cours' | message d'erreur
  const lignes = detail.lignes || []
  const paiements = detail.paiements || []
  const annulable = !!sessionId && detail.statut === 'validee' && aLeDroit(droits, 'vente.annuler') && detail.session?.id === sessionId

  return (
    <>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
        <button className="btn ghost sm" type="button" onClick={onRetour}>← Retour à la liste</button>
        <span className={`badge ${TON_ETAT[detail.statut] || 'mut'}`} style={{ marginLeft: 'auto' }}>
          {mot(detail.statut)}
        </span>
        {/* Le remboursement n'a de sens que sur une vente validée : proposer le bouton sur une vente
            annulée ou déjà remboursée ferait cliquer pour rien, et le refus viendrait du serveur
            après coup. Une action qui n'a pas de sens est absente, jamais grisée. */}
        {/* Une vente EN COURS de la session ouverte se reprend en caisse : c'est la sortie d'un règlement
            resté sans issue dans un autre onglet (ticket opposable, G-6). Jamais une vente d'une
            session close : son règlement tomberait hors de tout Z. */}
        {detail.statut === 'en_cours' && !!sessionId && detail.session?.id === sessionId
          && onReprendre && aLeDroit(droits, 'vente.encaisser') && (
          <button className="btn ghost sm" type="button" onClick={() => onReprendre(detail)}>
            Reprendre en caisse
          </button>
        )}
        {detail.statut === 'validee' && onDuplicata && !remboursement && (
          <button className="btn ghost sm" type="button" onClick={() => onDuplicata(detail)}>
            Réimprimer le ticket
          </button>
        )}
        {/* ── LA FACTURE JUSTIFICATIVE DU TICKET ───────────────────────────────────────────────
            Le client paie au comptoir puis demande une facture. Le serveur savait l'emettre depuis
            le debut ; aucun ecran ne l'appelait, donc personne ne pouvait la lui donner.

            ⚠ TROIS CONDITIONS, ET AUCUNE N'EST DECORATIVE. Le serveur exige une vente SCELLEE et
            INTEGRALEMENT PAYEE (RG-FACT-03.1) : une facture justificative atteste d'un encaissement,
            elle ne le remplace pas. On reprend donc ses deux conditions ici pour ne pas proposer un
            geste qui sera refuse devant le client, plus le droit qui le porte. Comme le
            remboursement au-dessus : une action qui n'a pas de sens est absente, jamais grisee. */}
        {detail.statut === 'validee'
          && Number(detail.resteAPayer) <= 0
          && aLeDroit(droits, 'facturation.emettre_justificative')
          && onFacture
          && !remboursement && (
          <button
            className="btn ghost sm"
            type="button"
            disabled={facture === 'en_cours'}
            onClick={async () => {
              setFacture('en_cours')
              try {
                onFacture(await api.factureDepuisVente(detail.id))
              } catch (e) {
                // ⚠ CE MESSAGE SE LIT VERBATIM, ET C'EST TOUT SON INTERET. Le refus le plus frequent
                // est une fiche client incomplete, et le serveur NOMME les mentions manquantes
                // (RG-FACT-08). Le remplacer par « emission impossible » retirerait la seule
                // information qui dit ou aller corriger.
                setFacture(e?.message || "La facture n'a pas pu être émise.")
              }
            }}
          >
            {facture === 'en_cours' ? 'Émission…' : 'Établir la facture'}
          </button>
        )}
        {detail.statut === 'validee' && aLeDroit(droits, 'vente.rembourser') && !remboursement && !annulable && (
          <button className="btn ghost sm" type="button" onClick={() => setRemboursement({ etape: 'saisie' })}>
            Rembourser
          </button>
        )}
        {annulable && !annulation && (
          <button className="btn ghost sm" type="button" onClick={() => setAnnulation('formulaire')}>
            Annuler cette vente
          </button>
        )}
      </div>

      {annulation?.numero && (
        <div className="banner banner-ok" style={{ marginBottom: 'var(--esp-large)' }}>
          Vente annulée. Avoir n° {annulation.numero} émis.
        </div>
      )}
      {annulation === 'formulaire' && (
        <FormulaireAnnulation
          vente={detail}
          onFermer={() => setAnnulation(null)}
          onAnnulee={(r) => { setAnnulation(r); onRembourse() }}
        />
      )}

      {typeof facture === 'string' && facture !== 'en_cours' && (
        <div className="banner banner-error" style={{ marginBottom: 12 }}>
          {facture}
        </div>
      )}

      {remboursement && (
        <FormulaireRemboursement
          detail={detail}
          etat={remboursement}
          setEtat={setRemboursement}
          onRembourse={onRembourse}
        />
      )}

      <div className="fiche-stats">
        <div>
          <div className="st-lib">Date</div>
          <div className="st-val">{dateHeure(detail.date)}</div>
        </div>
        <div>
          <div className="st-lib">Total</div>
          <div className="st-val num">{euros(detail.total)}</div>
        </div>
        <div>
          <div className="st-lib" title="Ce qui reste dû sur cette vente.">Reste à payer</div>
          <div className="st-val num">{euros(detail.resteAPayer)}</div>
        </div>
      </div>

      <div className="fiche-sec" style={{ marginTop: 14 }}>Ce qui a été vendu</div>
      {lignes.length === 0 ? (
        <div className="empty">Aucune ligne.</div>
      ) : (
        <table className="tbl">
          <thead>
            <tr>
              <th>Produit</th>
              <th className="num">Quantité</th>
              <th className="num">Prix unitaire</th>
              <th className="num">Total</th>
            </tr>
          </thead>
          <tbody>
            {lignes.map((l) => (
              <tr key={l.id}>
                <td>
                  <span className="nm">
                    {texte(l.libelleProduit, produits[String(l.produit)] || 'Produit retiré du catalogue')}
                  </span>
                  {l.libelleTypeTarif && (
                    <span className="badge mut" style={{ marginLeft: 6 }}>{l.libelleTypeTarif}</span>
                  )}
                  {l.prixForce && (
                    <span className="badge warn" style={{ marginLeft: 8 }} title="Le prix a été saisi à la main au moment de la vente.">
                      prix forcé
                    </span>
                  )}
                </td>
                <td className="num">{l.quantite}</td>
                <td className="num">{euros(l.prixUnitaire)}</td>
                <td className="num">{euros(l.montantLigne)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <div className="fiche-sec" style={{ marginTop: 14 }}>Comment ça a été payé</div>
      {paiements.length === 0 ? (
        <div className="empty">Aucun paiement enregistré.</div>
      ) : (
        <table className="tbl">
          <thead>
            <tr>
              <th>Moyen</th>
              <th>Quand</th>
              <th className="num">Montant</th>
              <th className="num">Rendu</th>
            </tr>
          </thead>
          <tbody>
            {paiements.map((p) => (
              <tr key={p.id}>
                <td>{mot(p.moyenCode)}</td>
                <td>{dateHeure(p.dateHeure)}</td>
                <td className="num">{euros(p.montant)}</td>
                <td className="num">{Number(p.rendu) > 0 ? euros(p.rendu) : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  )
}

// Remboursement — trois issues possibles côté serveur, et l'écran doit les distinguer.
//
// Accordé, refusé, ou **escalade requise** : dans ce dernier cas le serveur répond 403 avec le plafond
// dépassé et un jeton de demande. Traiter les deux 403 de la même façon dirait à un caissier « vous
// n'avez pas le droit » alors qu'une demande vient d'être créée et attend un responsable. Ce n'est
// pas la même information, et ce n'est pas la même suite à donner.
function FormulaireRemboursement({ detail, etat, setEtat, onRembourse }) {
  const total = Number(detail.total) || 0
  const [montant, setMontant] = useState(String(total.toFixed(2)))
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  const moyens = (detail.paiements || []).map((p) => mot(p.moyenCode))
  const partiel = Number(montant) > 0 && Number(montant) < total

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    setEtat((s) => ({ ...s, erreur: null, escalade: null }))
    try {
      const r = await api.rembourserVente(detail.id, {
        motif: motif.trim(),
        montant: Number(montant).toFixed(2),
      })
      setEtat({ etape: 'fait', avoir: r })
      onRembourse?.()
    } catch (err) {
      const p = err.payload || {}
      if (p.decision === 'escalade_requise') {
        setEtat({ etape: 'saisie', escalade: p })
      } else {
        setEtat({ etape: 'saisie', erreur: err.message || "Le remboursement n'a pas abouti." })
      }
    } finally {
      setEnCours(false)
    }
  }

  if (etat.etape === 'fait') {
    return (
      <div className="banner banner-ok" style={{ marginBottom: 12 }}>
        Avoir {etat.avoir?.numero} émis pour {euros(etat.avoir?.montant)}. La vente est désormais
        « {mot(etat.avoir?.statutVente)} ».
      </div>
    )
  }

  return (
    <form onSubmit={envoyer} className="card" style={{ marginBottom: 12 }}>
      <div className="card-b">
        {etat.erreur && <div className="banner banner-error">{etat.erreur}</div>}
        {etat.escalade && (
          <div className="banner">
            Ce remboursement dépasse votre plafond
            {etat.escalade.plafond ? ` de ${euros(etat.escalade.plafond)}` : ''}. Une demande
            d'autorisation a été créée : un responsable doit la valider avant que le remboursement
            puisse être effectué.
          </div>
        )}

        <div className="grid g2" style={{ gap: 12 }}>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="rb-montant">Montant à rembourser</label>
            <input
              id="rb-montant"
              className="input"
              type="number"
              step="0.01"
              min="0.01"
              max={total}
              required
              value={montant}
              onChange={(e) => setMontant(e.target.value)}
            />
            <div className="hint">
              {partiel
                ? `Remboursement partiel : ${euros(montant)} sur ${euros(total)}.`
                : `Montant total de la vente : ${euros(total)}.`}
            </div>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="rb-motif">Motif *</label>
            <input
              id="rb-motif"
              className="input"
              required
              value={motif}
              placeholder="Article rendu, erreur de caisse…"
              onChange={(e) => setMotif(e.target.value)}
            />
            <div className="hint">Conservé dans le journal : c'est ce qui explique le geste plus tard.</div>
          </div>
        </div>

        {/* Ce qui va réellement se passer, en toutes lettres. Un remboursement n'est pas un
            re-crédit automatique du moyen de paiement : le système émet un avoir. Le dire évite
            qu'on attende un virement qui n'arrivera pas. */}
        <div className="hint" style={{ marginTop: 10 }}>
          Un avoir de {euros(montant)} sera émis sur le ticket {detail.numero}
          {moyens.length > 0 ? `, payé par ${[...new Set(moyens)].join(', ')}` : ''}. Le remboursement
          effectif au client se fait selon vos règles de caisse ; le logiciel enregistre l'avoir.
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={() => setEtat(null)}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours}>
            {enCours ? 'En cours…' : 'Confirmer le remboursement'}
          </button>
        </div>
      </div>
    </form>
  )
}

export function FormulaireAnnulation({ vente, onAnnulee, onFermer }) {
  const [motif, setMotif] = useState('Erreur de saisie')
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(false)

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    setErreur(null)
    try {
      onAnnulee({ numero: (await api.annulerVente(vente.id, motif)).numero })
    } catch (err) {
      setErreur(err.payload?.decision === 'escalade_requise'
        ? "Demandez au régisseur de valider. Rien n'est annulé pour l'instant."
        : err.message || "L'annulation n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <form onSubmit={envoyer} className="card" style={{ marginBottom: 'var(--esp-large)' }}>
      <div className="card-b">
        <div className="nm">Annuler la vente n° {vente.numero}</div>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <ul className="hint">
          {(vente.lignes || []).map((l) => (
            <li key={l.id}>{l.quantite} × {texte(l.libelleProduit, 'Produit')} — {euros(l.montantLigne)}</li>
          ))}
        </ul>
        <div className="nm">Total : {euros(vente.total)}</div>
        {['Erreur de saisie', 'Client parti', 'Doublon'].map((m) => (
          <label key={m} style={{ display: 'block', marginTop: 'var(--esp-serre)' }}>
            <input type="radio" name="motif-annulation" checked={motif === m} onChange={() => setMotif(m)} /> {m}
          </label>
        ))}
        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end', marginTop: 'var(--esp-large)' }}>
          <button className="btn" type="button" onClick={onFermer}>Non</button>
          <button className="btn primary" type="submit" disabled={enCours}>
            {enCours ? 'En cours…' : 'Oui, annuler'}
          </button>
        </div>
      </div>
    </form>
  )
}

function dateHeure(v) {
  if (!v) return '—'
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return String(v)
  return d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
}
