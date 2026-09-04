import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'

// MARQUER UNE VENTE « IMPAYÉE RÉGIE » — l'écran lisait la liste sans permettre d'y ajouter.
//
// ── CE QUE C'EST ────────────────────────────────────────────────────────────────────────────────
//
// Une régie de recettes encaisse pour le compte du Trésor. Quand une vente reste due — un chèque
// sans provision, un encaissement annoncé qui n'arrive pas — le régisseur doit la déclarer :
// c'est cette déclaration qui la sort du solde d'encaisse attendu et qui ouvre le recouvrement.
//
// `POST /compta/ventes/{id}/marquer-impayee-regie` existait, testé, appelé par personne. L'onglet
// Régie affichait les ventes déjà marquées et n'offrait aucun moyen d'en marquer une : la liste ne
// pouvait donc que rester vide, ce qui se lit « aucun impayé » au lieu de « rien ne peut en créer ».
//
// ── ⚠ ON CHERCHE PAR NUMÉRO EXACT, ET CE N'EST PAS UN PIS-ALLER ────────────────────────────────
//
// `Vente` déclare un `SearchFilter` sur `numero`, `statut` et `session`, un `OrderFilter` sur la
// date, et **rien sur `resteAPayer`**. Proposer « les ventes qui restent dues » demanderait donc de
// filtrer en mémoire sur la page chargée — exactement ce que D48 interdit, et pour la bonne raison :
// une liste partielle qui se présente comme exhaustive fait conclure à un caissier que sa vente
// n'existe pas.
//
// Le numéro de vente est imprimé sur le ticket. C'est ce que le régisseur a sous les yeux.
//
// ── ⚠ ET ON NE DÉCLARE JAMAIS QU'UNE VENTE N'EST PAS MARQUÉE ──────────────────────────────────
//
// Le serveur refuse en 409 une vente déjà marquée. On peut le prévenir quand la vente figure dans
// la liste chargée — mais l'inverse ne se déduit pas : ne pas l'y trouver ne prouve rien, la liste
// est paginée. L'écran dit donc « déjà marquée » quand il le sait, et se tait sinon, en laissant le
// serveur trancher.
export default function MarquerImpayeeRegie({ etabActif, droits = [] }) {
  const peutMarquer = aLeDroit(droits, 'compta.gerer')

  const [marquees, setMarquees] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [numero, setNumero] = useState('')
  const [vente, setVente] = useState(undefined)
  const [motif, setMotif] = useState('')
  const [busy, setBusy] = useState(false)
  const [succes, setSucces] = useState(null)

  const charger = useCallback(() => {
    api.ventesImpayeesRegie()
      .then((r) => setMarquees(membres(r)))
      .catch((e) => {
        setErreur(e.message || 'Les ventes impayées n’ont pas pu être lues.')
        setMarquees([])
      })
  }, [])

  useEffect(() => { charger() }, [etabActif, charger])

  async function chercher() {
    const n = numero.trim()
    if (!n) return
    setBusy(true)
    setErreur(null)
    setSucces(null)
    setVente(undefined)
    try {
      const trouvees = membres(await api.ventes({ numero: n, itemsPerPage: 5 }))
      setVente(trouvees[0] ?? null)
    } catch (e) {
      setErreur(e.message || 'La recherche n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  async function marquer() {
    setBusy(true)
    setErreur(null)
    try {
      await api.marquerImpayeeRegie(vente.id, motif.trim() ? { motif: motif.trim() } : {})
      setSucces(`Vente ${vente.numero} marquée « impayée régie ».`)
      setVente(undefined)
      setNumero('')
      setMotif('')
      charger()
    } catch (e) {
      setErreur(e.message || 'Le marquage n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  // ⚠ Un seul sens est concluant : trouvée dans la liste chargée => déjà marquée. L'absence, elle,
  // ne prouve rien — la liste est paginée.
  const dejaMarquee = vente && (marquees ?? []).some((m) => m.venteOrigine === vente.id)

  return (
    <section className="card">
      <div className="card-h">
        <h3>Ventes impayées</h3>
        <span className="sub">à recouvrer par la régie</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {peutMarquer && (
          <>
            <div className="resa-part-form">
              <label className="field">
                <span className="field-lbl">Numéro de vente</span>
                <input
                  className="input mono"
                  value={numero}
                  onChange={(e) => { setNumero(e.target.value); setVente(undefined) }}
                  onKeyDown={(e) => { if (e.key === 'Enter') chercher() }}
                  placeholder="tel qu’imprimé sur le ticket"
                />
              </label>
              <button type="button" className="btn" disabled={busy || !numero.trim()} onClick={chercher}>
                {busy ? 'Recherche…' : 'Chercher la vente'}
              </button>
            </div>

            {/* ⚠ « PAS TROUVÉE » NE VEUT PAS DIRE « N'EXISTE PAS ». La recherche porte sur le
                périmètre de l'établissement actif : une vente d'un autre établissement est
                introuvable ici, et c'est voulu. On le dit plutôt que de laisser conclure. */}
            {vente === null && (
              <div className="banner">
                Aucune vente ne porte ce numéro dans l’établissement actif. Vérifiez le numéro, ou
                l’établissement sélectionné.
              </div>
            )}

            {vente && (
              <div className="sup-acces">
                <div className="sup-acces-t">
                  <span className="nm mono">{vente.numero}</span>
                  <span className="badge mut">{vente.statut || '—'}</span>
                  {dejaMarquee && <span className="badge crit">déjà marquée</span>}
                </div>
                <div className="hint">
                  Total <span className="mono">{euros(vente.total)}</span> · reste à payer{' '}
                  <span className="mono">{euros(vente.resteAPayer)}</span>
                </div>

                {!dejaMarquee && (
                  <>
                    <label className="field">
                      <span className="field-lbl">Motif</span>
                      <input
                        className="input"
                        value={motif}
                        onChange={(e) => setMotif(e.target.value)}
                        placeholder="Chèque rejeté — provision insuffisante"
                      />
                      <span className="hint">
                        Facultatif. Sans motif, le serveur enregistre « Recette de régie » — ce qui
                        ne dira rien à qui relira la ligne dans six mois.
                      </span>
                    </label>
                    <button type="button" className="btn primary" disabled={busy} onClick={marquer}>
                      {busy ? 'Marquage…' : 'Marquer impayée (régie)'}
                    </button>
                  </>
                )}
              </div>
            )}
          </>
        )}

        {marquees === null && !erreur && <div className="empty">Chargement…</div>}
        {marquees !== null && marquees.length === 0 && !erreur && (
          <div className="empty">Aucune vente marquée impayée.</div>
        )}
        {(marquees ?? []).map((m) => (
          <div key={m.id} className="sup-acces">
            <div className="sup-acces-t">
              <span className="mono sub">{String(m.venteOrigine || '').slice(0, 8)}</span>
              <span className="nm">{m.motif || '—'}</span>
            </div>
            <div className="hint">{dateFr(m.dateMarquage)}</div>
          </div>
        ))}
      </div>
    </section>
  )
}

function dateFr(valeur) {
  const s = String(valeur || '').slice(0, 10)
  const [a, m, j] = s.split('-')
  return j ? `${j}/${m}/${a}` : '—'
}
