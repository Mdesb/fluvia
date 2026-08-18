import { useEffect, useRef } from 'react'

// Modale réutilisable : fond semi-opaque, fermeture Échap / clic extérieur, focus piégé,
// accessible (role="dialog", aria-modal). Rien n'est rendu tant que `open` est faux.
// `taille` : 'sm' | 'md' | 'lg'.
export default function Modal({ open, onClose, titre, taille = 'md', children }) {
  const ref = useRef(null)
  const dernierFocus = useRef(null)

  useEffect(() => {
    if (!open) return undefined
    dernierFocus.current = document.activeElement
    const boite = ref.current

    const focusables = () =>
      [...boite.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')]
        .filter((n) => !n.disabled && n.offsetParent !== null)

    // Focus initial sur le 1er élément interactif (ou la boîte elle-même).
    ;(focusables()[0] || boite).focus()

    function onKey(e) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose?.()
        return
      }
      if (e.key !== 'Tab') return
      const items = focusables()
      if (items.length === 0) {
        e.preventDefault()
        return
      }
      const premier = items[0]
      const dernier = items[items.length - 1]
      if (e.shiftKey && document.activeElement === premier) {
        e.preventDefault()
        dernier.focus()
      } else if (!e.shiftKey && document.activeElement === dernier) {
        e.preventDefault()
        premier.focus()
      }
    }

    document.addEventListener('keydown', onKey, true)
    const overflowInitial = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKey, true)
      document.body.style.overflow = overflowInitial
      dernierFocus.current?.focus?.()
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div
      className="modal-backdrop"
      // onMouseDown (et non onClick) pour ne pas fermer si un glisser démarre dans la boîte
      // et se relâche sur le fond.
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose?.()
      }}
    >
      <div
        className={`modal modal-${taille}`}
        role="dialog"
        aria-modal="true"
        aria-label={titre}
        ref={ref}
        tabIndex={-1}
      >
        <div className="modal-h">
          <h3>{titre}</h3>
          <button className="modal-x" type="button" aria-label="Fermer" onClick={onClose}>×</button>
        </div>
        <div className="modal-b">{children}</div>
      </div>
    </div>
  )
}
