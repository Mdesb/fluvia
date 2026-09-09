import { useEffect, useRef, useState } from 'react'

// Modale réutilisable : fond semi-opaque, fermeture Échap / clic extérieur, focus piégé,
// accessible (role="dialog", aria-modal). Rien n'est rendu tant que `open` est faux.
// `taille` : 'sm' | 'md' | 'lg'.
//
// ── POURQUOI CETTE BOÎTE REFUSE DE SE FERMER DU PREMIER COUP ─────────────────────────────────
//
// Mesuré le 07/09/2026 sur « Ajouter un client » et sur « Nouvelle promotion » : Échap, et un
// clic à huit pixels du bord gauche de la fenêtre, fermaient un formulaire de vingt champs
// remplis et jetaient la saisie sans un mot. Rien ne la rattrape ensuite — aucune modale du
// produit ne vit dans l'URL, donc ni « précédent » ni F5 ne la ramènent (mesuré aussi).
//
// Le garde-fou vit ICI ET NULLE PART AILLEURS : les 139 appels à ce composant en héritent d'un
// coup, sans qu'aucun écran ait une ligne à changer.
//
// ⚠ TROIS SORTIES RESTENT OUVERTES, ET C'EST DÉLIBÉRÉ. Le second Échap, le second clic
// extérieur et le second « × » ferment pour de bon. Une boîte dont on ne peut plus sortir serait
// un défaut pire que celui qu'on corrige — « dans le doute, ne rien faire » et « rester coincé »
// rendent la même chose à l'écran.
//
// ⚠ ET L'ABANDON VOULU RESTE À UN SEUL CLIC. Le bouton « Annuler » des écrans appelle `onClose`
// directement, sans passer par ici : seul l'accident demande deux gestes. C'est ce qui distingue
// ce garde-fou d'un avertissement systématique, qu'on apprendrait à sauter.
//
// ⚠ « MODIFIÉ » SE MESURE AUX ÉVÉNEMENTS DE L'UTILISATEUR, PAS À LA VALEUR DES CHAMPS. Comparer
// à un instantané pris au montage aurait crié « saisie en cours » sur toute modale qui charge son
// contenu APRÈS s'être ouverte — la fiche client se remplit d'un GET, pas d'une frappe. Un
// avertissement qui se déclenche à tort s'apprend à sauter, et ne protège alors plus rien.
// `isTrusted` écarte ce qu'un script déclenche : seule une vraie frappe, un vrai clic de case ou
// un vrai choix de liste comptent.
export default function Modal({ open, onClose, titre, taille = 'md', children }) {
  const ref = useRef(null)
  const dernierFocus = useRef(null)
  // `true` dès que l'utilisateur a touché à un champ de CETTE ouverture.
  const modifie = useRef(false)
  // Le miroir en ref de `avertit` : les écouteurs posés par l'effet capturent la valeur du rendu
  // où ils ont été posés, et liraient un état périmé.
  const avertiRef = useRef(false)
  const [avertit, setAvertit] = useState(false)

  // ⚠ REMISE À ZÉRO SUR `open` SEUL, JAMAIS SUR `onClose`. La plupart des écrans passent une
  // flèche anonyme (`onClose={() => setEdition(null)}`), donc une identité neuve à chaque rendu :
  // un effet qui en dépend se rejoue à chaque frappe, et remettrait `modifie` à faux aussitôt
  // posé. Le garde-fou n'aurait jamais rien retenu, sans que rien ne le signale.
  useEffect(() => {
    modifie.current = false
    avertiRef.current = false
    setAvertit(false)
  }, [open])

  // La fermeture demandée par un geste AMBIGU — Échap, clic sur le fond, croix. Rien d'autre ne
  // passe par ici.
  function fermerOuAvertir() {
    if (!modifie.current || avertiRef.current) {
      onClose?.()
      return
    }
    avertiRef.current = true
    setAvertit(true)
    // ⚠ UN AVERTISSEMENT HORS DE L'ÉCRAN NE PROTÈGE PERSONNE. Ces boîtes dépassent la fenêtre
    // (« Nouvelle promotion » fait 918 px de haut dans une fenêtre de 720), et c'est le FOND qui
    // défile. Sans ce retour en haut, un second Échap réflexe jetterait la saisie sans que
    // l'avertissement ait jamais été vu — le défaut d'origine, avec une ligne de plus.
    // Affectation directe plutôt que `scrollIntoView` : elle ne dépend d'aucune trame d'animation.
    const fond = ref.current?.parentElement
    if (fond) fond.scrollTop = 0
  }

  useEffect(() => {
    if (!open) return undefined
    dernierFocus.current = document.activeElement
    const boite = ref.current

    const focusables = () =>
      [...boite.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')]
        .filter((n) => !n.disabled && n.offsetParent !== null)

    // Focus initial sur le 1er élément interactif (ou la boîte elle-même).
    ;(focusables()[0] || boite).focus()

    function onSaisie(e) {
      if (!e.isTrusted) return
      modifie.current = true

      // ⚠ UN `change` DE CHAMP TEXTE N'EST PAS UNE MODIFICATION DE PLUS : le navigateur l'émet
      // quand le champ PERD le focus. Un clic sur le fond en produit donc un, juste après notre
      // avertissement — et il l'effaçait aussitôt. Mesuré : la boîte restait ouverte sans rien
      // dire, et le clic suivant jetait la saisie. Le garde-fou absorbait le premier geste et
      // rendait le second aussi silencieux qu'avant.
      //
      // On ne réarme donc que sur un geste DÉLIBÉRÉ : une frappe (`input`), ou le changement
      // d'une case, d'un bouton radio ou d'une liste — qui, eux, ne s'émettent pas sur un blur.
      const cible = e.target
      const deliberee = e.type === 'input'
        || cible.type === 'checkbox'
        || cible.type === 'radio'
        || cible.tagName === 'SELECT'
      if (deliberee && avertiRef.current) {
        avertiRef.current = false
        setAvertit(false)
      }
    }

    function onKey(e) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        fermerOuAvertir()
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
    boite.addEventListener('input', onSaisie, true)
    boite.addEventListener('change', onSaisie, true)
    const overflowInitial = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKey, true)
      boite.removeEventListener('input', onSaisie, true)
      boite.removeEventListener('change', onSaisie, true)
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
        if (e.target === e.currentTarget) fermerOuAvertir()
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
          <button className="modal-x" type="button" aria-label="Fermer" onClick={fermerOuAvertir}>×</button>
        </div>
        {avertit && (
          // ⚠ IL DIT QUOI FAIRE, PAS SEULEMENT QUE C'EST RISQUÉ. Un avertissement qui laisse
          // chercher la sortie fait cliquer partout — dont sur le fond, c'est-à-dire sur le geste
          // même qu'on vient d'absorber.
          <div className="banner banner-warn modal-avertissement" role="alert">
            Ce que vous avez saisi ici n’est pas enregistré, et fermer l’abandonnera.
            Refaites le même geste pour fermer quand même.
          </div>
        )}
        <div className="modal-b">{children}</div>
      </div>
    </div>
  )
}
