import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client.js'
import { euros } from '../api/produit.js'
import { attemptPhase, createPoller, isExternal } from '../api/terminalWait.js'
import { DEFAULT_BRIDGE, isBridge, relayBridgePayment } from '../api/localBridge.js'

// « EN ATTENTE DU TERMINAL… » — la carte est partie au terminal, le serveur a rendu la main (spec
// TPE lot 1 §4, §5). Deux formes :
//
// - un terminal RELIÉ (simulé, ou piloté par le pont Caisse-AP du poste, #340) : l'écran scrute la tentative toutes
//   les 1,5 s et passe à l'issue tout seul ; « Annuler » prie le terminal d'abandonner ;
// - le terminal EXTERNE, relié à rien : « Encaissez XX,XX € sur le terminal, puis indiquez le
//   résultat » — Accepté (référence du ticket CB obligatoire), Refusé, Annulé. La scrutation tourne
//   aussi : un autre poste, ou un écran rechargé, voit la déclaration arriver.
//
// L'écran ne bloque pas : rien n'attend une réponse longue, chaque lecture rend la main. Une coupure
// réseau n'est jamais une issue : l'attente continue, et elle le dit.
export default function TerminalWait({ venteId, tentative, libelleMoyen, busy, onIssue, onAnnuler, onDeclarer, pont = DEFAULT_BRIDGE }) {
  const [courante, setCourante] = useState(tentative)
  const [coupure, setCoupure] = useState(null)
  const [reference, setReference] = useState('')
  // Le pont de caisse (#340) : la demande au terminal part d'ici ; son échec se dit et se retente.
  const [pontErreur, setPontErreur] = useState(null)
  const [essaiPont, setEssaiPont] = useState(0)
  // La dernière fonction du parent, sans relancer la scrutation à chaque rendu de la caisse.
  const issue = useRef(onIssue)
  useEffect(() => { issue.current = onIssue })

  useEffect(() => {
    const poller = createPoller({
      read: () => api.reglementTerminal(venteId, tentative.id),
      onResult: (t) => {
        setCoupure(null)
        setCourante(t)
        if (attemptPhase(t) !== 'waiting') issue.current(t)
      },
      onError: (e) => setCoupure(e?.message || 'Connexion perdue.'),
    })
    poller.start()
    // Un onglet revenu au premier plan relit tout de suite : le navigateur ralentit les minuteurs cachés.
    const auRetour = () => { if (document.visibilityState === 'visible') poller.poke() }
    document.addEventListener('visibilitychange', auRetour)
    return () => {
      poller.stop()
      document.removeEventListener('visibilitychange', auRetour)
    }
  }, [venteId, tentative.id])

  // Le relais vit hors de l'écran (`relayBridgePayment`) : démonter l'attente ne l'arrête pas, et une
  // acceptation tardive est encore relayée. Ici, on le lance et on écoute son échec.
  useEffect(() => {
    if (!isBridge(tentative)) return undefined
    let vivant = true
    setPontErreur(null)
    relayBridgePayment({ api, venteId, tentative, pont })
      .catch((e) => { if (vivant) setPontErreur(e?.message || 'Le pont de caisse n’a pas répondu.') })
    return () => { vivant = false }
  }, [venteId, tentative, pont, essaiPont])

  const montant = euros(courante?.montant ?? tentative.montant)

  if (isExternal(courante)) {
    return (
      <div className="terminal-attente">
        <div className="banner banner-info terminal-consigne">
          {/* Une seule phrase : la consigne est en flex (l'indicateur d'attente), elle serait coupée en colonnes. */}
          <span>Encaissez <b>{montant}</b> sur le terminal de paiement, puis indiquez le résultat.</span>
        </div>
        <div className="field terminal-champ">
          <label htmlFor="ref-terminal">Référence du ticket CB (si accepté)</label>
          <input
            id="ref-terminal"
            className="input"
            maxLength={64}
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            autoComplete="off"
          />
        </div>
        <div className="row actions">
          <button
            type="button"
            className="btn primary"
            disabled={busy || reference.trim() === ''}
            onClick={() => onDeclarer('accepte', reference.trim())}
          >
            Accepté
          </button>
          <button type="button" className="btn" disabled={busy} onClick={() => onDeclarer('refuse')}>Refusé</button>
          <button type="button" className="btn" disabled={busy} onClick={() => onDeclarer('annule')}>Annulé</button>
        </div>
        {coupure && <div className="hint terminal-note">{coupure} — le règlement reste en attente, rien n’est perdu.</div>}
      </div>
    )
  }

  return (
    <div className="terminal-attente">
      <div className="banner banner-info terminal-consigne" role="status">
        <span className="spinner" aria-hidden="true" />
        <span>En attente du terminal… {libelleMoyen} {montant}</span>
      </div>
      <div className="hint terminal-note">
        Le client suit les instructions du terminal (code, sans contact). L’écran se met à jour seul.
      </div>
      {isBridge(tentative) && (
        <div className="hint terminal-note">
          Le montant est parti au terminal. Pour abandonner, appuyez sur la touche rouge du terminal.
        </div>
      )}
      {pontErreur && (
        <div className="banner banner-warn terminal-note" role="alert">
          <span>{pontErreur}</span>{' '}
          <button type="button" className="btn" disabled={busy} onClick={() => setEssaiPont((n) => n + 1)}>Réessayer</button>
        </div>
      )}
      <button type="button" className="btn" disabled={busy} onClick={onAnnuler}>Annuler</button>
      {coupure && <div className="hint terminal-note">{coupure} — l’attente continue, rien n’est perdu.</div>}
    </div>
  )
}
