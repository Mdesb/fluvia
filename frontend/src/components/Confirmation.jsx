import { useCallback, useEffect, useRef, useState } from 'react'
import Modal from './Modal.jsx'

// LA CONFIRMATION D'UN GESTE, DANS LA LANGUE ET L'HABIT DE LA MAISON.
//
// Vingt et une confirmations passaient par `window.confirm`. Ce que ça coûtait, et qui ne se voit
// qu'à l'usage :
//
//   - la boîte est celle du NAVIGATEUR : police système, largeur imposée, aucune couleur. Un geste
//     irréversible (« Archiver ») et un geste anodin y ont exactement la même apparence.
//   - ses boutons sont « OK » et « Annuler », traduits par le SYSTÈME et pas par nous : sur un poste
//     configuré en anglais, un exploitant français lit « OK / Cancel » au moment de supprimer.
//   - `\n\n` est le seul moyen d'y mettre un paragraphe, et la conséquence de l'acte s'y retrouve
//     collée au titre.
//
// ── POURQUOI UN HÔTE UNIQUE, ET PAS UN CROCHET PAR ÉCRAN ────────────────────────────────────────
//
// La forme évidente est un `useConfirmation()` qui rend son propre dialogue. Elle a un défaut qu'on
// ne découvre qu'en production : si un écran oublie de RENDRE l'élément retourné, la promesse ne se
// résout jamais — le bouton ne fait plus rien, en silence, et personne ne sait pourquoi. On aurait
// remplacé une boîte laide par un geste qui n'arrive pas.
//
// Un hôte monté une fois par application supprime la question : les appelants importent une
// fonction, il n'y a rien à câbler et donc rien à oublier.
//
// ⚠ ET S'IL N'EST PAS MONTÉ, ON RETOMBE SUR `window.confirm`, DÉLIBÉRÉMENT. Un repli qui « échoue
// fermé » (refuser le geste) transformerait un oubli de montage en écran mort ; un repli qui
// « réussit » (accepter sans demander) ferait supprimer sans question. Le seul repli sûr est
// l'ancien comportement : laid, mais exact.

let poser = null

/** Le texte tel que `window.confirm` le montrerait, pour le repli. */
function texteBrut({ titre, texte, consequence }) {
  return [titre, texte, consequence].filter(Boolean).join('\n\n')
}

/**
 * Demande une confirmation. Rend une promesse : `true` si l'exploitant confirme.
 *
 * Usage — la forme reprend celle qu'elle remplace, à un `await` près :
 *
 *     if (!(await confirmer({ titre: 'Archiver « X » ?' }))) return
 *
 * `danger` colore l'action de la couleur des choses critiques ; `libelleOk` nomme le geste plutôt
 * que de dire « OK », parce qu'un bouton qui dit ce qu'il fait se relit sans remonter au titre.
 */
export function confirmer(demande) {
  const d = typeof demande === 'string' ? { titre: demande } : (demande || {})
  if (!poser) return Promise.resolve(window.confirm(texteBrut(d)))
  return poser(d)
}

/** Monté une fois par application, au plus haut. Ne rend rien tant que personne ne demande. */
export function HoteConfirmation() {
  const [demande, setDemande] = useState(null)
  const resoudre = useRef(null)

  useEffect(() => {
    poser = (d) => new Promise((ok) => {
      resoudre.current = ok
      setDemande(d)
    })
    return () => {
      // ⚠ Si l'hôte est démonté alors qu'une demande est en cours, la promesse doit se résoudre :
      // sans ça, l'appelant reste suspendu pour toujours. On répond NON — ne rien faire est le
      // seul choix sûr quand on ne peut plus poser la question.
      resoudre.current?.(false)
      resoudre.current = null
      poser = null
    }
  }, [])

  const repondre = useCallback((reponse) => {
    const r = resoudre.current
    resoudre.current = null
    setDemande(null)
    r?.(reponse)
  }, [])

  if (!demande) return null

  const { titre, texte, consequence, libelleOk = 'Confirmer', danger = false } = demande

  return (
    <Modal open onClose={() => repondre(false)} titre={titre} taille="sm">
      <div className="modal-b">
        {texte && <p style={{ margin: 0 }}>{texte}</p>}
        {consequence && (
          <p className="hint" style={{ marginTop: 'var(--esp-normal)', marginBottom: 0 }}>
            {consequence}
          </p>
        )}
        <div className="modal-actions">
          {/* ⚠ « Annuler » d'abord, et il n'est pas `primary` : le geste par défaut d'une
              confirmation est de NE PAS agir.

              Sur OÙ VA LE FOCUS, mesuré plutôt que supposé — j'avais d'abord écrit ici qu'il
              revenait à « Annuler », c'était faux : `Modal.jsx` le donne à son premier
              focusable, qui est sa croix de fermeture. La propriété qui compte tient quand
              même — Échap et Entrée ferment tous deux sans agir — mais elle ne tient pas
              pour la raison que j'avais écrite. */}
          <button type="button" className="btn" onClick={() => repondre(false)}>
            Annuler
          </button>
          <button
            type="button"
            className={danger ? 'btn danger' : 'btn primary'}
            onClick={() => repondre(true)}
          >
            {libelleOk}
          </button>
        </div>
      </div>
    </Modal>
  )
}
