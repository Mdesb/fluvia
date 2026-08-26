import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import Qr from '../components/Qr.jsx'
// `texte` lit un libelle multilingue : le serveur rend `{ fr: '...' }`, pas une chaine.
import { texte } from '../components/Liste.jsx'
import HistoriqueVentesModal from '../components/HistoriqueVentesModal.jsx'
import Modal from '../components/Modal.jsx'
import ClientPicker, { nomClient } from '../components/ClientPicker.jsx'
import SessionCaisse from './SessionCaisse.jsx'
import {
  libelleProduit,
  prixIndicatif,
  grillesVendables,
  libelleTarif,
  typeTarifId,
  estVendable,
  raisonNonVendable,
  expliqueNonVendable,
  euros,
} from '../api/produit.js'

// Ordre de présentation préféré des moyens de paiement au guichet.
const ORDRE_MOYENS = ['especes', 'cb', 'cheque', 'pmv']

export default function Caisse({ me, etabActif, etablissements, session, capacites = [], droits = [], onSessionRefresh }) {
  const [caisseModale, setCaisseModale] = useState(false)
  const [historique, setHistorique] = useState(false)
  const [choixTarif, setChoixTarif] = useState(null)
  const [produits, setProduits] = useState([])
  const [moyens, setMoyens] = useState([])
  const [pdvs, setPdvs] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const [panier, setPanier] = useState([]) // { produit, quantite }
  const [ticket, setTicket] = useState(null)

  // Client rattaché à la vente (bénéficiaire des produits nominatifs, RG-M2-04 / CA-7).
  const [client, setClient] = useState(null)
  const [pickerOuvert, setPickerOuvert] = useState(false)
  const [besoinClient, setBesoinClient] = useState(false)

  // Phase de paiement (encaissement scindé sur une vente ouverte).
  const [vente, setVente] = useState(null) // { id, reste }
  const [paiements, setPaiements] = useState([]) // règlements acceptés
  const [moyenSel, setMoyenSel] = useState('especes')
  const [montant, setMontant] = useState('')
  const [tpeSimule, setTpeSimule] = useState('accepte')
  const [busy, setBusy] = useState(false)
  const [avis, setAvis] = useState(null) // message TPE refusé, etc.

  const nomEtab = etablissements.find((e) => e.id === etabActif)?.nom || ''

  useEffect(() => {
    let annule = false
    setChargement(true)
    setErreur(null)
    setPanier([])
    setTicket(null)
    setVente(null)
    setPaiements([])
    setClient(null)
    setBesoinClient(false)
    Promise.all([api.produits(), api.moyensPaiement(), api.pointDeVentes()])
      .then(([pc, mc, dc]) => {
        if (annule) return
        setProduits(membres(pc))
        setMoyens(membres(mc).filter((m) => m.actif !== false))
        setPdvs(membres(dc))
      })
      .catch((e) => !annule && setErreur(e.message))
      .finally(() => !annule && setChargement(false))
    return () => {
      annule = true
    }
  }, [etabActif])

  const total = useMemo(
    () =>
      panier.reduce((s, l) => {
        const pu = parseFloat((l.prix ?? prixIndicatif(l.produit)) || '0') || 0
        return s + pu * l.quantite
      }, 0),
    [panier],
  )

  // Moyens réellement proposables : actifs et autorisés sur le point de vente de la session.
  const moyensDispo = useMemo(() => {
    const pdvId = session?.pointDeVente?.id || session?.pointDeVente
    const pdv = pdvs.find((p) => p.id === pdvId)
    const autorises = pdv?.moyensAutorises || []
    let liste = moyens.filter((m) => autorises.length === 0 || autorises.includes(m.code))
    // PMV : uniquement si la capacité porte-monnaie est active sur l'établissement.
    if (!capacites.includes('porte_monnaie')) liste = liste.filter((m) => m.code !== 'pmv')
    return liste.sort((a, b) => {
      const ia = ORDRE_MOYENS.indexOf(a.code)
      const ib = ORDRE_MOYENS.indexOf(b.code)
      return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib)
    })
  }, [moyens, pdvs, session, capacites])

  const moyenCourant = moyensDispo.find((m) => m.code === moyenSel) || null
  const reste = vente ? parseFloat(vente.reste || '0') : total

  // Une ligne de panier est un produit ET un tarif : deux tarifs du meme produit sont deux lignes.
  // Les fusionner obligerait a ressaisir pour vendre un adulte et un enfant ensemble, ce qui est la
  // vente courante d'une famille au guichet.
  const cleLigne = (produitId, tarifId) => `${produitId}|${tarifId}`

  function ajouter(produit, grille) {
    setTicket(null)
    const g = grille || grillesVendables(produit)[0]
    if (!g) return
    const cle = cleLigne(produit.id, g.typeTarif.id)
    setPanier((p) => {
      const i = p.findIndex((l) => l.cle === cle)
      if (i >= 0) {
        const copie = [...p]
        copie[i] = { ...copie[i], quantite: copie[i].quantite + 1 }
        return copie
      }
      return [
        ...p,
        {
          cle,
          produit,
          quantite: 1,
          typeTarifId: g.typeTarif.id,
          tarifLibelle: libelleTarif(g),
          prix: g.prix,
        },
      ]
    })
  }

  // Un clic reste un clic quand il n'y a rien a choisir : on ne fait payer le choix qu'a ceux qui en
  // ont un. Une caisse se juge au nombre de gestes par vente.
  function choisirPuisAjouter(produit) {
    const grilles = grillesVendables(produit)
    if (grilles.length <= 1) ajouter(produit, grilles[0])
    else setChoixTarif({ produit, grilles })
  }

  function changerQte(cle, delta) {
    setPanier((p) =>
      p.map((l) => (l.cle === cle ? { ...l, quantite: l.quantite + delta } : l)).filter((l) => l.quantite > 0),
    )
  }
  function retirer(cle) {
    setPanier((p) => p.filter((l) => l.cle !== cle))
  }

  // Démarre l'encaissement : crée la vente, ajoute les lignes, passe en phase paiement.
  async function demarrerPaiement() {
    if (panier.length === 0 || !session) return
    setBusy(true)
    setErreur(null)
    setAvis(null)
    setBesoinClient(false)
    setTicket(null)
    try {
      const v = await api.creerVente({ session: session.id })
      // Rattache le client à la vente (M2, CA-7) — préalable au bénéficiaire des lignes nominatives.
      if (client) {
        try {
          await api.rattacherClientVente(v.id, { client: client.id })
        } catch {
          /* le rattachement échoue silencieusement : la garde bénéficiaire ci-dessous prendra le relais */
        }
      }
      let courant = v
      for (const l of panier) {
        // Le tarif choisi sur la ligne, et non plus un tarif devine pour tout le panier.
        const tarif = l.typeTarifId || typeTarifId(l.produit)
        if (!tarif) throw new Error(`« ${libelleProduit(l.produit)} » n'a pas de tarif au guichet.`)
        const corps = { produit: l.produit.id, typeTarif: tarif, quantite: l.quantite }
        // Bénéficiaire requis pour les produits nominatifs (RG-M2-04) : on passe le client rattaché.
        if (client) corps.beneficiaire = client.id
        courant = await api.ajouterLigne(v.id, corps)
      }
      // LES LIGNES S'ALIGNENT SUR LE SERVEUR, PAS SEULEMENT LE TOTAL.
      //
      // Le total faisait déjà foi ici. Les lignes, elles, gardaient leur prix indicatif — si bien que
      // le panier pouvait afficher « 1 × Test 10,00 € » au-dessus d'un total de 15,00 €. C'est le
      // même défaut que celui du ticket, un cran plus tôt : **c'est ce montant que le caissier
      // annonce à voix haute avant d'encaisser.**
      //
      // Le tarif choisi par l'écran est la première grille vendable ; le serveur applique celui qui
      // est réellement dû — saison, quotient familial. Les deux peuvent différer sans que personne
      // ne soit en faute. Tant que la vente n'existe pas, l'écran ne peut qu'estimer ; dès qu'elle
      // existe, il n'a plus aucune raison de le faire.
      const lignesServeur = courant?.lignes || []
      if (lignesServeur.length > 0) {
        setPanier((p) =>
          p.map((l) => {
            const ls = lignesServeur.find(
              (x) => String(x.produit) === String(l.produit?.id)
                && String(x.typeTarif || '') === String(l.typeTarifId || ''),
            ) || lignesServeur.find((x) => String(x.produit) === String(l.produit?.id))
            return ls?.prixUnitaire != null ? { ...l, prix: ls.prixUnitaire } : l
          }),
        )
      }

      const totalServeur = courant?.total ?? total.toFixed(2)
      const resteServeur = courant?.resteAPayer ?? totalServeur
      setVente({ id: v.id, reste: resteServeur, total: totalServeur })
      setPaiements([])
      setMoyenSel(moyensDispo[0]?.code || 'especes')
      setMontant(parseFloat(resteServeur) > 0 ? parseFloat(resteServeur).toFixed(2) : '')
    } catch (e) {
      const msg = e.message || "Impossible d'ouvrir la vente."
      // Garde « bénéficiaire requis » (RG-M2-04) : on invite à rattacher un client via la modale.
      if (/b[ée]n[ée]ficiaire/i.test(msg)) {
        setBesoinClient(true)
        setErreur('Ce panier contient un produit nominatif : rattachez un client bénéficiaire pour encaisser.')
        setPickerOuvert(true)
      } else {
        setErreur(msg)
      }
    } finally {
      setBusy(false)
    }
  }

  // Ajoute un règlement (paiement scindé). CB/chèque exigeant une référence => passage TPE simulé.
  async function reglerUnMoyen() {
    if (!vente || !moyenCourant) return
    setBusy(true)
    setErreur(null)
    setAvis(null)
    try {
      const corps = { moyen: moyenCourant.code }
      const m = parseFloat(montant)
      if (!Number.isNaN(m) && m > 0) corps.montant = m.toFixed(2)
      const headers = moyenCourant.exigeReference ? { 'X-Tpe-Simule': tpeSimule } : undefined
      const res = await api.payer(vente.id, corps, headers)

      if (!res.reglementEnregistre) {
        // TPE refusé / timeout : aucun règlement ajouté, reste inchangé.
        setAvis(`Transaction ${moyenCourant.libelle} ${res.statutTPE || 'refusée'} — aucun règlement enregistré.`)
        return
      }
      setPaiements((p) => [
        ...p,
        { moyen: res.moyen, libelle: moyenCourant.libelle, montant: res.montant, rendu: res.rendu, statutTPE: res.statutTPE },
      ])
      const nouveauReste = res.resteAPayer ?? '0.00'
      setVente((v) => ({ ...v, reste: nouveauReste }))
      setMontant(parseFloat(nouveauReste) > 0 ? parseFloat(nouveauReste).toFixed(2) : '')
    } catch (e) {
      setErreur(e.message || 'Règlement refusé.')
    } finally {
      setBusy(false)
    }
  }

  // Le ticket vient ENTIÈREMENT du serveur, y compris les mots.
//
// CE QUE CETTE FONCTION A CESSÉ DE FAIRE, ET POURQUOI C'EST UNE BONNE NOUVELLE.
//
// Première version : le ticket se construisait depuis le panier, avec un repli sur le prix indicatif
// du catalogue. Le serveur facturait 15 €, le ticket imprimait 10 € et un total de 15 € — sur le
// document que le client emporte, et qu'il a le droit de contester.
//
// Deuxième version : les montants venaient de la vente, mais les libellés restaient ceux du panier,
// appariés par (produit, tarif), parce que `LigneVente` ne sérialisait aucun nom. Ça marchait tant
// que le ticket était édité dans la foulée — et jamais pour un duplicata, où il n'y a plus de panier.
//
// `claude-G` a livré le libellé **figé au moment de la vente**, et `TicketProcessor` rend désormais
// ses lignes. L'appariement disparaît : **l'argent et le mot viennent tous les deux du serveur**, et
// un produit renommé six mois plus tard ne change pas ce qu'un ticket d'hier affirme.
//
// UNE LIMITE QUI NE BOUGERA PAS, ET QU'IL FAUT CONNAÎTRE.
//
// Les ventes antérieures à la migration portent le nom que le produit a *aujourd'hui* : cette
// information n'avait jamais été écrite et ne se reconstitue pas. Un duplicata n'est réellement
// opposable qu'à partir de cette migration.
function construireTicket(infoTicket, paiements, support) {
  const lignes = (infoTicket.lignes || []).map((l) => {
    const nom = texte(l.libelle, 'Article')
    return {
      // Le tarif figure sur le ticket : sans lui, deux lignes du même produit à des prix différents
      // sont illisibles, pour le client comme pour le caissier qui le relit.
      libelle: l.tarif ? `${nom} — ${l.tarif}` : nom,
      quantite: l.quantite ?? 1,
      pu: l.prixUnitaire ?? '0.00',
      // `montantLigne` est le montant réellement facturé pour la ligne : options et remises
      // comprises. C'est lui qu'on affiche à droite, et non un produit qu'on recalculerait.
      montant: l.montantLigne ?? null,
    }
  })

  // Un ticket qui ne s'additionne pas est un ticket qu'un client conteste, et il a raison.
  const somme = lignes.reduce(
    (s, l) => s + (l.montant != null ? parseFloat(l.montant) || 0 : (parseFloat(l.pu) || 0) * (l.quantite || 0)),
    0,
  )
  const total = parseFloat(infoTicket.total ?? '0') || 0

  return {
    numero: infoTicket.numero,
    lignes,
    // Le détail manque plutôt qu'il ne ment : si le serveur n'a rendu aucune ligne, on le dit et le
    // total reste affiché — c'est lui qui engage.
    detailIndisponible: lignes.length === 0,
    total: infoTicket.total ?? '0.00',
    ecartDetail: lignes.length > 0 && Math.abs(somme - total) > 0.005,
    duplicata: !!infoTicket.duplicata,
    paiements,
    codeSupport: support?.identifiantSupport || null,
  }
}

// Finalise : valide la vente et édite le ticket.
  async function validerVente() {
    if (!vente) return
    setBusy(true)
    setErreur(null)
    try {
      const venteValidee = await api.valider(vente.id)
      const infoTicket = await api.ticket(vente.id, 'imprimer')
      // Code de support signé (HMAC) émis à la validation : 1er support porteur d'un identifiant.
      const support = (venteValidee.supports || []).find((s) => s.identifiantSupport)
      setTicket(construireTicket(infoTicket, paiements, support))
      setPanier([])
      setVente(null)
      setPaiements([])
      setAvis(null)
      setClient(null)
      setBesoinClient(false)
    } catch (e) {
      setErreur(e.message || 'Échec de la validation.')
    } finally {
      setBusy(false)
    }
  }

  async function abandonner() {
    if (!vente) return
    setBusy(true)
    try {
      await api.annulerVente(vente.id)
    } catch {
      /* on réinitialise l'UI quoi qu'il arrive */
    } finally {
      setVente(null)
      setPaiements([])
      setAvis(null)
      setBusy(false)
    }
  }

  // Retenu depuis la modale : rattache le client (bénéficiaire) et lève la garde nominative.
  function choisirClient(c) {
    setClient(c)
    setPickerOuvert(false)
    setBesoinClient(false)
    if (besoinClient) setErreur(null)
  }

  // Modale d'ouverture / clôture Z, déclenchée depuis l'écran Caisse. Réutilise SessionCaisse
  // et ses appels API existants ; se ferme seule après ouverture, laisse le récap Z après clôture.
  const modaleSession = (
    <Modal
      open={caisseModale}
      onClose={() => setCaisseModale(false)}
      taille={session ? 'lg' : 'md'}
      titre={session ? 'Clôture de caisse (Z)' : 'Ouvrir la caisse'}
    >
      <SessionCaisse
        modale
        me={me}
        etabActif={etabActif}
        session={session}
        onRefresh={onSessionRefresh}
        onClose={() => setCaisseModale(false)}
      />
    </Modal>
  )

  const modaleClient = (
    <ClientPicker
      open={pickerOuvert}
      onClose={() => setPickerOuvert(false)}
      onSelect={choisirClient}
    />
  )

  // --- Rendu : pas de session ouverte ---
  if (!chargement && !session) {
    return (
      <div className="view">
        <div className="view-head">
          <div className="ttl"><h1>Caisse</h1><p>{nomEtab}</p></div>
        </div>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="card">
          <div className="card-b" style={{ textAlign: 'center', padding: '40px 20px' }}>
            <div style={{ fontSize: 40, marginBottom: 8 }}>🔒</div>
            <h3 style={{ marginBottom: 6 }}>Aucune caisse ouverte</h3>
            <p className="hint" style={{ marginBottom: 18 }}>
              Ouvrez la caisse (point de vente, fond de caisse, régisseur) pour encaisser.
            </p>
            <button className="btn primary" onClick={() => setCaisseModale(true)}>Ouvrir la caisse</button>
          </div>
        </div>
        {modaleSession}
      </div>
    )
  }

  const enPaiement = !!vente

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Caisse</h1>
          <p>
            Session {session?.numero ? `n° ${session.numero}` : 'au guichet'} · {nomEtab}
          </p>
        </div>
        <div className="actions">
          <button className="btn" onClick={() => setHistorique(true)} disabled={enPaiement}>Historique</button>
          <button className="btn" onClick={() => setCaisseModale(true)} disabled={enPaiement}>Clôture Z</button>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="caisse-grid">
        <aside style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="card">
            <div className="card-h">
              <h3>{enPaiement ? 'Encaissement' : 'Panier'}</h3>
              {!enPaiement && panier.length > 0 && (
                <div className="r">
                  <button className="btn ghost sm" onClick={() => setPanier([])}>Vider</button>
                </div>
              )}
            </div>
            <div className="card-b">
              <div className={`client-bar${besoinClient ? ' besoin' : ''}`}>
                {client ? (
                  <>
                    <span className="cb-info">
                      <span className="cb-ic" aria-hidden="true">👤</span>
                      <span className="nm">{nomClient(client)}</span>
                    </span>
                    {!enPaiement && (
                      <button className="btn ghost sm" type="button" onClick={() => setClient(null)}>
                        Retirer
                      </button>
                    )}
                  </>
                ) : (
                  <>
                    <span className="cb-info hint" style={{ margin: 0 }}>
                      Vente au comptoir — aucun client rattaché
                    </span>
                    <button
                      className={`btn sm${besoinClient ? ' primary' : ''}`}
                      type="button"
                      onClick={() => setPickerOuvert(true)}
                      disabled={enPaiement}
                    >
                      ＋ Rattacher un client
                    </button>
                  </>
                )}
              </div>

              {panier.length === 0 && !enPaiement ? (
                <div className="empty">Cliquez un produit pour l'ajouter.</div>
              ) : (
                <>
                  {panier.map((l) => {
                    const pu = parseFloat((l.prix ?? prixIndicatif(l.produit)) || '0') || 0
                    return (
                      <div className="cline" key={l.cle}>
                        <div className="cn">
                          <span className="nm">{libelleProduit(l.produit)}</span>
                          <div className="cp">
                            {l.tarifLibelle && (
                              <span className="badge mut" style={{ marginRight: 6 }}>{l.tarifLibelle}</span>
                            )}
                            {euros(pu)}
                          </div>
                        </div>
                        {!enPaiement ? (
                          <div className="qty">
                            <button onClick={() => changerQte(l.cle, -1)}>−</button>
                            <span>{l.quantite}</span>
                            <button onClick={() => changerQte(l.cle, 1)}>+</button>
                          </div>
                        ) : (
                          <span style={{ color: 'var(--ink-soft)' }}>× {l.quantite}</span>
                        )}
                        <span className="num" style={{ minWidth: 58, fontWeight: 600 }}>{euros(pu * l.quantite)}</span>
                        {!enPaiement && (
                          <button className="rm" onClick={() => retirer(l.cle)} title="Retirer">×</button>
                        )}
                      </div>
                    )
                  })}

                  <div className="cline cart-total">
                    <span>Total</span>
                    <span className="num">{euros(enPaiement && vente?.total != null ? vente.total : total)}</span>
                  </div>

                  {!enPaiement ? (
                    <button className="btn primary lg" onClick={demarrerPaiement} disabled={busy}>
                      {busy ? 'Ouverture…' : `Encaisser ${euros(total)}`}
                    </button>
                  ) : (
                    <PanneauPaiement
                      moyensDispo={moyensDispo}
                      moyenSel={moyenSel}
                      setMoyenSel={(c) => { setMoyenSel(c); setAvis(null) }}
                      moyenCourant={moyenCourant}
                      montant={montant}
                      setMontant={setMontant}
                      tpeSimule={tpeSimule}
                      setTpeSimule={setTpeSimule}
                      reste={reste}
                      paiements={paiements}
                      avis={avis}
                      busy={busy}
                      onRegler={reglerUnMoyen}
                      onValider={validerVente}
                      onAbandon={abandonner}
                    />
                  )}
                </>
              )}
            </div>
          </div>

          {ticket && <TicketVente ticket={ticket} />}
        </aside>

        <section className="card">
          <div className="card-h">
            <h3>Produits</h3>
            <span className="sub">au guichet</span>
          </div>
          <div className="card-b">
            {chargement ? (
              <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
            ) : produits.length === 0 ? (
              <div className="empty">Aucun produit disponible.</div>
            ) : (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 10 }}>
                {produits.map((p) => {
                  const vendable = estVendable(p) && !enPaiement
                  const raison = raisonNonVendable(p)
                  return (
                    <button
                      key={p.id}
                      className="prodtile"
                      disabled={!vendable}
                      onClick={() => choisirPuisAjouter(p)}
                      title={
                        enPaiement
                          ? 'Encaissement en cours'
                          : vendable
                            ? 'Ajouter au panier'
                            : expliqueNonVendable(p) || ''
                      }
                    >
                      <span className="pn">{libelleProduit(p)}</span>
                      {p.code && <span className="pc">{p.code}</span>}
                      {raison ? <span className="pw">{raison}</span> : <span className="pp">{euros(prixIndicatif(p))}</span>}
                    </button>
                  )
                })}
              </div>
            )}
            <div className="hint">
              {enPaiement ? 'Encaissement en cours — finalisez ou abandonnez la vente.' : 'Cliquez un produit pour l\'ajouter · paiement scindé et rendu à l\'encaissement'}
            </div>
          </div>
        </section>
      </div>
      {/* Choix du tarif : n'apparait que si le produit en a plusieurs. Un produit a tarif unique
          s'ajoute en un clic, exactement comme avant — on ne fait payer le choix qu'a ceux qui en
          ont un. */}
      <Modal
        open={!!choixTarif}
        onClose={() => setChoixTarif(null)}
        titre={choixTarif ? `${libelleProduit(choixTarif.produit)} — quel tarif ?` : ''}
        taille="sm"
      >
        {choixTarif && (
          <div style={{ display: 'grid', gap: 8 }}>
            {choixTarif.grilles.map((g) => (
              <button
                key={g.id}
                type="button"
                className="prodtile"
                onClick={() => {
                  ajouter(choixTarif.produit, g)
                  setChoixTarif(null)
                }}
              >
                <span className="pn">{libelleTarif(g)}</span>
                {g.saison?.nom && <span className="pc">{g.saison.nom}</span>}
                <span className="pp">{euros(g.prix)}</span>
              </button>
            ))}
          </div>
        )}
      </Modal>

      <HistoriqueVentesModal
        open={historique}
        onClose={() => setHistorique(false)}
        droits={droits}
        onDuplicata={async (vente) => {
          setErreur(null)
          try {
            const info = await api.ticket(vente.id, 'duplicata')
            setTicket(construireTicket(info, [], null))
            setHistorique(false)
          } catch (e) {
            setErreur(e.message || "Le duplicata n'a pas pu être édité.")
          }
        }}
      />
      {modaleSession}
      {modaleClient}
    </div>
  )
}

function PanneauPaiement({
  moyensDispo, moyenSel, setMoyenSel, moyenCourant, montant, setMontant,
  tpeSimule, setTpeSimule, reste, paiements, avis, busy, onRegler, onValider, onAbandon,
}) {
  const solde = parseFloat((reste || 0).toFixed ? reste.toFixed(2) : reste) || 0
  const paye = solde <= 0.0001

  return (
    <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 12 }}>
      <div className="reste-box">
        <span>Reste à payer</span>
        <span className={`num reste-val${paye ? ' paye' : ''}`}>{euros(Math.max(0, solde))}</span>
      </div>

      {paiements.length > 0 && (
        <div className="pay-list">
          {paiements.map((p, i) => (
            <div className="pay-row" key={i}>
              <span>{p.libelle || p.moyen}</span>
              <span className="num">{euros(p.montant)}{parseFloat(p.rendu || '0') > 0 ? ` (rendu ${euros(p.rendu)})` : ''}</span>
            </div>
          ))}
        </div>
      )}

      {avis && <div className="banner banner-error" style={{ margin: 0 }}>{avis}</div>}

      {!paye && (
        <>
          <div className="pay-moyens">
            {moyensDispo.map((m) => (
              <button
                key={m.code}
                className={`pay-chip${moyenSel === m.code ? ' on' : ''}`}
                onClick={() => setMoyenSel(m.code)}
                type="button"
              >
                {m.libelle}
              </button>
            ))}
          </div>

          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="mtt">Montant{moyenCourant?.autoriseRendu ? ' (rendu possible)' : ''}</label>
            <input
              id="mtt"
              className="input"
              type="number"
              step="0.01"
              min="0"
              value={montant}
              onChange={(e) => setMontant(e.target.value)}
              placeholder={Math.max(0, solde).toFixed(2)}
            />
          </div>

          {moyenCourant?.exigeReference && (
            <div className="field" style={{ margin: 0 }}>
              <label>Simulation TPE</label>
              <div className="seg">
                {['accepte', 'refuse', 'timeout'].map((s) => (
                  <button key={s} type="button" className={tpeSimule === s ? 'on' : ''} onClick={() => setTpeSimule(s)}>
                    {s === 'accepte' ? 'Accepté' : s === 'refuse' ? 'Refusé' : 'Timeout'}
                  </button>
                ))}
              </div>
            </div>
          )}

          <button className="btn primary lg" onClick={onRegler} disabled={busy}>
            {busy ? 'Traitement…' : `Régler ${moyenCourant?.libelle || ''}`}
          </button>
          {busy && moyenCourant?.exigeReference && (
            <div className="hint" style={{ margin: 0, textAlign: 'center' }}>
              Transaction en cours au terminal de paiement… (quelques secondes)
            </div>
          )}
        </>
      )}

      {paye && (
        <button className="btn primary lg" onClick={onValider} disabled={busy}>
          {busy ? 'Validation…' : 'Valider & imprimer'}
        </button>
      )}

      <button className="btn ghost sm" onClick={onAbandon} disabled={busy} style={{ alignSelf: 'center' }}>
        Abandonner la vente
      </button>
    </div>
  )
}

function TicketVente({ ticket }) {
  return (
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
            <span className="num">
              {euros(l.montant != null ? l.montant : parseFloat(l.pu || '0') * l.quantite)}
            </span>
          </div>
        ))}
        {ticket.duplicata && (
          <div className="trow" style={{ color: 'var(--warn)' }}>
            <span><b>DUPLICATA</b> — ce ticket a déjà été édité.</span>
          </div>
        )}
        {ticket.detailIndisponible && (
          <div className="trow" style={{ color: 'var(--warn)' }}>
            <span>Le détail des lignes n'a pas pu être relu. Le total ci-dessous fait foi.</span>
          </div>
        )}
        {ticket.ecartDetail && (
          <div className="trow" style={{ color: 'var(--warn)' }}>
            <span>
              Le détail ci-dessus ne fait pas le total : des remises, options ou promotions
              s'appliquent. <b>Le montant dû est le total.</b>
            </span>
          </div>
        )}
        <div className="trow tt">
          <span>Total</span>
          <span className="num">{euros(ticket.total)}</span>
        </div>
        {ticket.paiements.map((p, i) => (
          <div className="trow" key={`p${i}`}>
            <span>Règlement · {p.libelle || p.moyen}</span>
            <span className="num">{euros(p.montant)}</span>
          </div>
        ))}
        {ticket.paiements.some((p) => parseFloat(p.rendu || '0') > 0) && (
          <div className="trow">
            <span>Rendu</span>
            <span className="num">
              {euros(ticket.paiements.reduce((s, p) => s + (parseFloat(p.rendu || '0') || 0), 0))}
            </span>
          </div>
        )}
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 14, paddingTop: 14, borderTop: '1px solid var(--line)' }}>
          {ticket.codeSupport && (
            <Qr value={ticket.codeSupport} size={84} title={`QR billet ${ticket.numero}`} />
          )}
          <div className="hint" style={{ margin: 0 }}>
            {ticket.codeSupport ? (
              <>Billet + QR édités · support appairé
                <br />
                <span className="mono" style={{ fontSize: 10.5, wordBreak: 'break-all' }}>{ticket.codeSupport}</span>
              </>
            ) : (
              'Billet édité · aucun support QR sur cette vente'
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
