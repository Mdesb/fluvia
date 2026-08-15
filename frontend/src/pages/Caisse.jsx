import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import {
  libelleProduit,
  prixIndicatif,
  typeTarifId,
  estVendable,
  raisonNonVendable,
  euros,
} from '../api/produit.js'

export default function Caisse({ me, etabActif, etablissements }) {
  const [produits, setProduits] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const [panier, setPanier] = useState([]) // { produit, quantite }
  const [montantRecu, setMontantRecu] = useState('')
  const [encaissement, setEncaissement] = useState(false)
  const [ticket, setTicket] = useState(null)

  const nomEtab = etablissements.find((e) => e.id === etabActif)?.nom || ''

  useEffect(() => {
    let annule = false
    setChargement(true)
    setErreur(null)
    setPanier([])
    setTicket(null)
    api
      .produits()
      .then((c) => {
        if (!annule) setProduits(membres(c))
      })
      .catch((e) => {
        if (!annule) setErreur(e.message)
      })
      .finally(() => {
        if (!annule) setChargement(false)
      })
    return () => {
      annule = true
    }
  }, [etabActif])

  const total = useMemo(
    () =>
      panier.reduce((s, l) => {
        const pu = parseFloat(prixIndicatif(l.produit) || '0') || 0
        return s + pu * l.quantite
      }, 0),
    [panier],
  )

  function ajouter(produit) {
    setTicket(null)
    setPanier((p) => {
      const i = p.findIndex((l) => l.produit.id === produit.id)
      if (i >= 0) {
        const copie = [...p]
        copie[i] = { ...copie[i], quantite: copie[i].quantite + 1 }
        return copie
      }
      return [...p, { produit, quantite: 1 }]
    })
  }

  function changerQte(id, delta) {
    setPanier((p) =>
      p
        .map((l) => (l.produit.id === id ? { ...l, quantite: l.quantite + delta } : l))
        .filter((l) => l.quantite > 0),
    )
  }

  function retirer(id) {
    setPanier((p) => p.filter((l) => l.produit.id !== id))
  }

  async function trouverOuOuvrirSession() {
    // Réutilise une session déjà ouverte sur le périmètre courant si possible.
    const sessions = membres(await api.sessionsCaisse())
    const ouverte = sessions.find((s) => s.etat === 'ouverte')
    if (ouverte) return ouverte.id

    // Sinon on ouvre une session : point de vente + caisse du périmètre, régisseur = utilisateur courant.
    const pdvs = membres(await api.pointDeVentes())
    if (pdvs.length === 0) {
      throw new Error(`Aucun point de vente configuré pour « ${nomEtab} ». Vente impossible ici.`)
    }
    const pdv = pdvs[0]
    const caisses = membres(await api.caisses())
    const caisse =
      caisses.find((c) => (c.pointDeVente?.id || c.pointDeVente) === pdv.id) || caisses[0]
    if (!caisse) {
      throw new Error(`Aucune caisse configurée pour « ${nomEtab} ». Vente impossible ici.`)
    }
    const session = await api.ouvrirSession({
      pointDeVente: pdv.id,
      caisse: caisse.id,
      fondDeCaisse: '50.00',
      regisseur: me.id,
      codeRegisseur: '0000',
    })
    return session.id
  }

  async function encaisser() {
    if (panier.length === 0) return
    setEncaissement(true)
    setErreur(null)
    setTicket(null)
    try {
      const sessionId = await trouverOuOuvrirSession()

      const vente = await api.creerVente({ session: sessionId })
      const venteId = vente.id

      for (const l of panier) {
        const tarif = typeTarifId(l.produit)
        if (!tarif) {
          throw new Error(`« ${libelleProduit(l.produit)} » n'a pas de tarif au guichet.`)
        }
        await api.ajouterLigne(venteId, {
          produit: l.produit.id,
          typeTarif: tarif,
          quantite: l.quantite,
        })
      }

      // Paiement espèces : si le montant reçu couvre le dû, on le transmet (rendu calculé par l'API),
      // sinon on règle le montant exact.
      const recu = parseFloat(montantRecu)
      const corpsPaiement = { moyen: 'especes' }
      if (!Number.isNaN(recu) && recu >= total && total > 0) {
        corpsPaiement.montant = recu.toFixed(2)
      }
      const paiement = await api.payer(venteId, corpsPaiement)

      const venteValidee = await api.valider(venteId)
      const infoTicket = await api.ticket(venteId, 'imprimer')

      setTicket({
        numero: infoTicket.numero || venteValidee.numero,
        lignes: panier.map((l) => ({
          libelle: libelleProduit(l.produit),
          quantite: l.quantite,
          pu: prixIndicatif(l.produit),
        })),
        total: venteValidee.total ?? total.toFixed(2),
        moyen: 'Espèces',
        montant: paiement.montant,
        rendu: paiement.rendu,
      })
      setPanier([])
      setMontantRecu('')
    } catch (e) {
      setErreur(e.message || "Échec de l'encaissement.")
    } finally {
      setEncaissement(false)
    }
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Caisse</h1>
          <p>Session au guichet · {nomEtab}</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="caisse-grid">
        <aside style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="card">
            <div className="card-h">
              <h3>Panier</h3>
              {panier.length > 0 && (
                <div className="r">
                  <button className="btn ghost sm" onClick={() => setPanier([])}>Vider</button>
                </div>
              )}
            </div>
            <div className="card-b">
              {panier.length === 0 ? (
                <div className="empty">Cliquez un produit pour l'ajouter.</div>
              ) : (
                <>
                  {panier.map((l) => {
                    const pu = parseFloat(prixIndicatif(l.produit) || '0') || 0
                    return (
                      <div className="cline" key={l.produit.id}>
                        <div className="cn">
                          <span className="nm">{libelleProduit(l.produit)}</span>
                          <div className="cp">{euros(pu)}</div>
                        </div>
                        <div className="qty">
                          <button onClick={() => changerQte(l.produit.id, -1)}>−</button>
                          <span>{l.quantite}</span>
                          <button onClick={() => changerQte(l.produit.id, 1)}>+</button>
                        </div>
                        <span className="num" style={{ minWidth: 58, fontWeight: 600 }}>
                          {euros(pu * l.quantite)}
                        </span>
                        <button className="rm" onClick={() => retirer(l.produit.id)} title="Retirer">×</button>
                      </div>
                    )
                  })}

                  <div className="cline cart-total">
                    <span>Total</span>
                    <span className="num">{euros(total)}</span>
                  </div>

                  <div className="field" style={{ marginTop: 12 }}>
                    <label htmlFor="recu">Montant reçu (espèces, optionnel)</label>
                    <input
                      id="recu"
                      className="input"
                      type="number"
                      step="0.01"
                      min="0"
                      placeholder={`${total.toFixed(2)}`}
                      value={montantRecu}
                      onChange={(e) => setMontantRecu(e.target.value)}
                    />
                  </div>

                  <button className="btn primary lg" onClick={encaisser} disabled={encaissement}>
                    {encaissement ? 'Encaissement…' : `Encaisser ${euros(total)}`}
                  </button>
                </>
              )}
            </div>
          </div>

          {ticket && (
            <div className="ticket">
              <div className="th">
                <span className="ok">✓</span>
                <h3>Vente encaissée</h3>
              </div>
              <div className="tb">
                <div className="tnum">Ticket {ticket.numero}</div>
                {ticket.lignes.map((l, i) => (
                  <div className="trow" key={i}>
                    <span>{l.quantite} × {l.libelle}</span>
                    <span className="num">{euros(parseFloat(l.pu || '0') * l.quantite)}</span>
                  </div>
                ))}
                <div className="trow tt">
                  <span>Total</span>
                  <span className="num">{euros(ticket.total)}</span>
                </div>
                <div className="trow">
                  <span>Règlement ({ticket.moyen})</span>
                  <span className="num">{euros(ticket.montant)}</span>
                </div>
                {parseFloat(ticket.rendu || '0') > 0 && (
                  <div className="trow">
                    <span>Rendu</span>
                    <span className="num">{euros(ticket.rendu)}</span>
                  </div>
                )}
                <div
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 12,
                    marginTop: 14,
                    paddingTop: 14,
                    borderTop: '1px solid var(--line)',
                  }}
                >
                  <div className="qr" />
                  <div className="hint" style={{ margin: 0 }}>Billet + QR édités · appairage support à la validation</div>
                </div>
              </div>
            </div>
          )}
        </aside>

        <section className="card">
          <div className="card-h">
            <h3>Produits</h3>
            <span className="sub">au guichet</span>
          </div>
          <div className="card-b">
            {chargement ? (
              <div className="center" style={{ minHeight: 200 }}>
                <div className="spinner" />
              </div>
            ) : produits.length === 0 ? (
              <div className="empty">Aucun produit disponible.</div>
            ) : (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 10 }}>
                {produits.map((p) => {
                  const vendable = estVendable(p)
                  const raison = raisonNonVendable(p)
                  return (
                    <button
                      key={p.id}
                      className="prodtile"
                      disabled={!vendable}
                      onClick={() => ajouter(p)}
                      title={vendable ? 'Ajouter au panier' : raison || ''}
                    >
                      <span className="pn">{libelleProduit(p)}</span>
                      {p.code && <span className="pc">{p.code}</span>}
                      {raison ? (
                        <span className="pw">{raison}</span>
                      ) : (
                        <span className="pp">{euros(prixIndicatif(p))}</span>
                      )}
                    </button>
                  )
                })}
              </div>
            )}
            <div className="hint">Cliquez un produit pour l'ajouter · scan QR / recherche client · appairage support à la validation</div>
          </div>
        </section>
      </div>
    </div>
  )
}
