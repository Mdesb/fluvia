import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'
import { EN_LIGNE, HORS_LIGNE } from '../api/connectivity.js'
import { connectivity, offlineSales, persistant, readCatalogue } from '../api/offline.js'
import { printThermal } from './thermalPrint.js'
import Caisse from '../pages/Caisse.jsx'

/**
 * LA CAISSE, ET SA VENTE HORS CONNEXION (#338).
 *
 * ── POURQUOI LE PASSAGE EN MODE HORS CONNEXION EST UN GESTE, PAS UN AUTOMATISME ────────────────
 *
 * Basculer tout seul démonterait la caisse en ligne au milieu d'une vente — panier, attente du
 * terminal de paiement — sur une coupure de trois secondes. Le bandeau DIT la coupure dès qu'elle
 * est détectée ; c'est le caissier qui passe en vente hors connexion, et qui en revient.
 *
 * ── CE QUE LA VENTE HORS CONNEXION SAIT FAIRE, ET RIEN DE PLUS ─────────────────────────────────
 *
 * Vendre les produits simples du catalogue gardé (daté à l'écran), en espèces ou par chèque, dans la
 * session ouverte ; numéroter et imprimer un ticket PROVISOIRE ; garder la vente sur le poste
 * jusqu'à ce que le serveur l'accuse. Au retour du réseau, la file remonte d'elle-même, dans l'ordre.
 * Le ticket définitif (NF525) est celui que le serveur scelle à la remontée.
 */
export default function CounterWithOffline(props) {
  const { etabActif, session, droits = [] } = props
  const [reseau, setReseau] = useState(() => connectivity().state)
  const [mode, setMode] = useState('caisse')
  const [file, setFile] = useState([])
  const [bilan, setBilan] = useState(null)
  const [remontee, setRemontee] = useState(false)

  async function relire() {
    try {
      setFile(await offlineSales.pending(etabActif))
    } catch {
      setFile([])
    }
  }

  async function remonter() {
    if (remontee) return
    setRemontee(true)
    try {
      const r = await offlineSales.sync(etabActif, (corps) => api.synchroOperations(corps))
      if (r.erreur) connectivity().reportFailure(r.erreur)
      setBilan(r)
    } finally {
      setRemontee(false)
      relire()
    }
  }

  useEffect(() => connectivity().subscribe(setReseau), [])
  useEffect(() => {
    relire()
  }, [etabActif])
  // LA REMONTÉE PART D'ELLE-MÊME AU RETOUR DU RÉSEAU — et à l'ouverture de l'écran, pour une file
  // laissée par un poste éteint pendant la coupure.
  useEffect(() => {
    if (reseau === EN_LIGNE && etabActif) remonter()
  }, [reseau, etabActif])

  const enAttente = file.filter((v) => v.etat === 'en_attente')
  const aTraiter = file.filter((v) => v.etat !== 'en_attente')
  const peutVendreHorsLigne = persistant && session?.etat === 'ouverte' && aLeDroit(droits, 'vente.encaisser')

  const bandeau = (reseau === HORS_LIGNE || file.length > 0 || bilan?.remontees > 0) && (
    <div className="card">
      <div className="card-b">
        {reseau === HORS_LIGNE && (
          <div className="banner banner-warn" role="status">
            <b>Hors connexion.</b> Le serveur ne répond pas.{' '}
            {mode === 'caisse' && peutVendreHorsLigne && (
              <button type="button" className="btn primary sm" onClick={() => setMode('hors_ligne')}>
                Vendre hors connexion
              </button>
            )}
            {mode === 'caisse' && !peutVendreHorsLigne && (
              <span>
                {!persistant
                  ? ' Ce navigateur ne garde rien sur le disque (navigation privée ?) : la vente hors connexion est désactivée.'
                  : session?.etat !== 'ouverte'
                    ? ' Aucune session de caisse ouverte : la vente hors connexion exige une session ouverte avant la coupure.'
                    : ' Ce compte n’a pas le droit d’encaisser (vente.encaisser).'}
              </span>
            )}
          </div>
        )}
        {reseau === EN_LIGNE && mode === 'hors_ligne' && (
          <div className="banner banner-ok" role="status">
            Le réseau est revenu.{' '}
            <button type="button" className="btn sm" onClick={() => setMode('caisse')}>Revenir à la caisse</button>
          </div>
        )}
        {enAttente.length > 0 && (
          <div className="banner banner-info">
            {enAttente.length} vente(s) hors connexion à remonter.{' '}
            <button type="button" className="btn sm" disabled={remontee || reseau === HORS_LIGNE} onClick={remonter}>
              {remontee ? 'Remontée…' : 'Remonter maintenant'}
            </button>
          </div>
        )}
        {bilan?.remontees > 0 && (
          <div className="banner banner-ok">{bilan.remontees} vente(s) hors connexion remontée(s) et scellée(s).</div>
        )}
        {bilan?.erreur && reseau === EN_LIGNE && (
          <div className="banner banner-error">La remontée n’a pas abouti : {bilan.erreur.message}</div>
        )}
        {aTraiter.length > 0 && (
          <div className="banner banner-error">
            <b>{aTraiter.length} vente(s) hors connexion refusée(s) par le serveur — à voir avec un responsable.</b>
            {aTraiter.map((v) => (
              <div key={v.cleIdempotence}>
                Ticket {v.numeroLocal} ({euros(v.total)}) : {v.raison || 'refusée'}{' '}
                <button type="button" className="btn ghost sm" onClick={() => offlineSales.retry(v.cleIdempotence).then(relire)}>
                  Renvoyer
                </button>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )

  if (mode === 'hors_ligne') {
    return (
      <div className="view large">
        {bandeau}
        <OfflineCounter etabActif={etabActif} session={session} nomEtab={props.etablissements?.find((e) => e.id === etabActif)?.nom || ''} onVendu={relire} />
      </div>
    )
  }

  return (
    <>
      {bandeau && <div className="view large">{bandeau}</div>}
      <Caisse {...props} droits={droits} />
    </>
  )
}

function OfflineCounter({ etabActif, session, nomEtab, onVendu }) {
  const [catalogue, setCatalogue] = useState(undefined)
  const [panier, setPanier] = useState([])
  const [moyen, setMoyen] = useState('especes')
  const [recu, setRecu] = useState('')
  const [ticket, setTicket] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)
  // Un double clic arrive avant que `busy` ne grise le bouton : il enregistrerait deux ventes.
  const enCours = useRef(false)
  const papier = useRef(null)

  useEffect(() => {
    readCatalogue(etabActif).then(setCatalogue).catch(() => setCatalogue(null))
  }, [etabActif])

  useEffect(() => {
    if (ticket && papier.current) printThermal(papier.current, { titre: `Ticket ${ticket.numeroLocal}` }).catch(() => {})
  }, [ticket])

  const cle = (l) => `${l.produit}|${l.typeTarif}`
  // En centimes : une somme de flottants afficherait 14,999999 € sur un ticket.
  const totalCentimes = panier.reduce((s, l) => s + Math.round(parseFloat(l.prix) * 100) * l.quantite, 0)
  const total = (totalCentimes / 100).toFixed(2)
  const rendu = moyen === 'especes' && recu !== '' ? Math.round(parseFloat(recu) * 100) - totalCentimes : null

  function ajouter(l) {
    setTicket(null)
    setPanier((p) => {
      const i = p.findIndex((x) => cle(x) === cle(l))
      if (i < 0) return [...p, { ...l, quantite: 1 }]
      const copie = [...p]
      copie[i] = { ...copie[i], quantite: copie[i].quantite + 1 }
      return copie
    })
  }

  function changer(l, delta) {
    setPanier((p) => p.map((x) => (cle(x) === cle(l) ? { ...x, quantite: x.quantite + delta } : x)).filter((x) => x.quantite > 0))
  }

  async function encaisser() {
    if (enCours.current || panier.length === 0) return
    if (rendu !== null && rendu < 0) {
      setErreur('Le montant reçu est inférieur au total.')
      return
    }
    enCours.current = true
    setBusy(true)
    setErreur(null)
    try {
      // ÉCRITE AVANT D'ÊTRE IMPRIMÉE : `record` ne rend la main qu'une fois la vente sur le disque.
      const op = await offlineSales.record({
        etablissement: etabActif,
        session: session.id,
        lignes: panier.map((l) => ({ produit: l.produit, typeTarif: l.typeTarif, quantite: l.quantite, libelle: l.libelle, prix: l.prix })),
        paiements: [{ moyen, montant: total }],
        total,
      })
      setTicket({ ...op, rendu: rendu !== null && rendu > 0 ? (rendu / 100).toFixed(2) : null })
      setPanier([])
      setRecu('')
      onVendu()
    } catch (e) {
      setErreur(`La vente n’a pas pu être enregistrée sur ce poste : ${e.message}. Ne remettez pas de ticket.`)
    } finally {
      enCours.current = false
      setBusy(false)
    }
  }

  if (catalogue === undefined) return <div className="center"><div className="spinner" /></div>
  if (!catalogue || catalogue.lignes.length === 0) {
    return (
      <div className="banner banner-error">
        Aucun catalogue gardé sur ce poste pour cet établissement. La vente hors connexion utilise le
        catalogue chargé par la caisse lors de sa dernière ouverture en ligne.
      </div>
    )
  }

  const moyens = catalogue.moyens.length > 0 ? catalogue.moyens : [{ code: 'especes', libelle: 'Espèces' }]

  return (
    <div className="caisse-grid">
      <aside>
        <div className="card">
          <div className="card-h">
            <h3>Vente hors connexion</h3>
            <span className="sub">session {session?.numero}</span>
          </div>
          <div className="card-b">
            {panier.length === 0 && <div className="empty">Panier vide</div>}
            {panier.map((l) => (
              <div className="cline" key={cle(l)}>
                <div className="cn">
                  <span className="nm">{l.libelle}</span>
                  <div className="cp">{l.tarif} · {euros(l.prix)}</div>
                </div>
                <div className="qty">
                  <button type="button" onClick={() => changer(l, -1)}>−</button>
                  <span>{l.quantite}</span>
                  <button type="button" onClick={() => changer(l, 1)}>+</button>
                </div>
                <span className="num">{euros(((Math.round(parseFloat(l.prix) * 100) * l.quantite) / 100).toFixed(2))}</span>
              </div>
            ))}
            <div className="cline cart-total">
              <span>Total</span>
              <span className="num">{euros(total)}</span>
            </div>
            <div className="field">
              <label htmlFor="hl-moyen">Moyen de paiement</label>
              <select id="hl-moyen" className="input" value={moyen} onChange={(e) => setMoyen(e.target.value)}>
                {moyens.map((m) => <option key={m.code} value={m.code}>{m.libelle}</option>)}
              </select>
            </div>
            {moyen === 'especes' && (
              <div className="field">
                <label htmlFor="hl-recu">Montant reçu (facultatif, pour le rendu)</label>
                <input id="hl-recu" className="input" type="number" step="0.01" min="0" value={recu} onChange={(e) => setRecu(e.target.value)} />
                {rendu !== null && rendu >= 0 && <div className="hint">À rendre : {euros((rendu / 100).toFixed(2))}</div>}
              </div>
            )}
            {erreur && <div className="banner banner-error">{erreur}</div>}
            <button type="button" className="btn primary lg" disabled={busy || panier.length === 0} onClick={encaisser}>
              {busy ? 'Enregistrement…' : `Encaisser ${euros(total)}`}
            </button>
            <div className="hint">
              Carte bancaire indisponible hors connexion : le terminal de paiement passe par le serveur.
            </div>
          </div>
        </div>

        {ticket && (
          <div className="ticket doc-imprimer" ref={papier}>
            <div className="th">
              <div className="t-emetteur"><div className="t-etab">{nomEtab}</div></div>
            </div>
            <div className="tb">
              <div className="t-date">{new Date(ticket.horodatageLocal).toLocaleString('fr-FR')}</div>
              <div className="tnum">Ticket provisoire {ticket.numeroLocal}</div>
              {ticket.lignes.map((l, i) => (
                <div className="trow" key={i}>
                  <span>{l.quantite} × {l.libelle}</span>
                  <span className="num">{euros(((Math.round(parseFloat(l.prix) * 100) * l.quantite) / 100).toFixed(2))}</span>
                </div>
              ))}
              <div className="trow tt"><span>Total</span><span className="num">{euros(ticket.total)}</span></div>
              {ticket.paiements.map((p, i) => (
                <div className="trow" key={i}><span>{p.moyen === 'especes' ? 'Espèces' : p.moyen === 'cheque' ? 'Chèque' : p.moyen}</span><span className="num">{euros(p.montant)}</span></div>
              ))}
              {ticket.rendu && <div className="trow"><span>Rendu</span><span className="num">{euros(ticket.rendu)}</span></div>}
              <div className="t-mention">
                Vente enregistrée hors connexion. Le ticket définitif sera émis à la reprise du
                réseau ; il peut être demandé à la caisse avec ce numéro.
              </div>
            </div>
          </div>
        )}
      </aside>

      <div className="card">
        <div className="card-h">
          <h3>Catalogue gardé</h3>
          <span className="sub">du {new Date(catalogue.gardeLe).toLocaleString('fr-FR')}</span>
        </div>
        <div className="card-b">
          <div className="hint">
            Prix du catalogue gardé. Si un prix a changé depuis, la vente sera signalée à un responsable
            à la remontée. Les abonnements et les titres nominatifs ne se vendent pas hors connexion.
          </div>
          <div className="hl-grille">
            {catalogue.lignes.map((l) => (
              <button type="button" key={cle(l)} className="prodtile" onClick={() => ajouter(l)}>
                <span className="pn">{l.libelle}</span>
                <span className="pc">{l.tarif}</span>
                <span className="pp">{euros(l.prix)}</span>
              </button>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
