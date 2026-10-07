import { useState } from 'react'
import Modal from './Modal.jsx'
import { euros } from '../api/produit.js'

// « QU'AFFICHE LE TERMINAL ? » — la seule sortie d'un règlement resté sans issue (Q-A1, D122).
//
// Le terminal n'a pas répondu à temps : la carte a peut-être été débitée. Rien ne repart au
// terminal, et la vente ne se valide pas, tant que le caissier n'a pas lu le terminal et déclaré :
// - « Accepté » : il recopie la référence du ticket CB, le règlement est écrit (sans terminal) ;
// - « Non passé » : rien n'est écrit, un nouvel envoi est permis.
// Le geste est signé (qui, quand) sur la tentative. Fermer la fenêtre ne déclare rien : la vente
// reste tenue, « Déclarer » la rouvre.
export default function DeclarationReglement({ tentative, busy, erreur, onDeclarer, onFermer }) {
  const [reference, setReference] = useState('')
  const depuis = tentative?.depuis ? new Date(tentative.depuis).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : null

  return (
    <Modal open onClose={onFermer} titre="Qu'affiche le terminal ?" taille="sm">
      <p>
        Le terminal n'a pas rendu d'issue pour le règlement
        {tentative ? ` de ${euros(tentative.montant)} (${tentative.moyen})` : ''}
        {depuis ? `, commencé à ${depuis}` : ''} : la carte a peut-être été débitée. Lisez l'écran ou le
        ticket du terminal, puis déclarez ce qu'il affiche.
      </p>
      {tentative?.raison && <p className="sub">{tentative.raison}</p>}
      <div className="field">
        <label htmlFor="ref-cb">Référence du ticket CB (si accepté)</label>
        <input
          id="ref-cb"
          className="input"
          maxLength={64}
          value={reference}
          onChange={(e) => setReference(e.target.value)}
          autoComplete="off"
        />
      </div>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <div className="row" style={{ gap: 'var(--esp-serre)', justifyContent: 'flex-end', flexWrap: 'wrap' }}>
        <button type="button" className="btn" onClick={() => onDeclarer({ accepted: false })} disabled={busy}>
          Non passé — rien n'a été débité
        </button>
        <button
          type="button"
          className="btn primary"
          onClick={() => onDeclarer({ accepted: true, cardReference: reference.trim() })}
          disabled={busy || reference.trim() === ''}
        >
          Accepté — enregistrer le règlement
        </button>
      </div>
    </Modal>
  )
}
