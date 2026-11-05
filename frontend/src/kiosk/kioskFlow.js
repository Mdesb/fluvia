// LA BORNE EN LOGIQUE PURE (#341) : le panier, les étapes, le verrou du clavier.
//
// Tout ce qui décide se trouve ici, sans React ni navigateur, pour être testé par `node --test`.
// L'écran (`KioskApp.jsx`) ne fait qu'afficher ce que ces fonctions rendent.
//
// ── TROIS ÉTAPES, PAS UNE DE PLUS ────────────────────────────────────────────────────────────
//
// L'usager d'une borne n'a personne à qui demander. Chaque parcours tient en trois écrans, toujours
// les mêmes et toujours numérotés : 1. Choisir · 2. Payer · 3. Retirer. Un écran = une décision ; le
// bouton qui avance dit ce qu'il fait (« Payer 11,00 € »), jamais « Suivant ».

export const STEPS = ['choose', 'pay', 'collect']

export const STEP_LABELS = {
  choose: 'Choisir',
  pay: 'Payer',
  collect: 'Retirer',
}

export const MAX_LINES = 6
export const MAX_QUANTITY = 10

/** « 11,00 € » à partir d'un décimal du serveur (« 11.00 ») ou de centimes. */
export function euros(value, { cents = false } = {}) {
  const n = cents ? Number(value) / 100 : Number.parseFloat(value ?? '0')
  const safe = Number.isFinite(n) ? n : 0
  return safe.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

export function toCents(decimal) {
  const n = Number.parseFloat(decimal ?? '0')
  return Number.isFinite(n) ? Math.round(n * 100) : 0
}

/** Ce que la borne propose pour un parcours : l'achat montre tout, la recharge les seules formules de carte. */
export function offersFor(mode, offers) {
  const list = Array.isArray(offers) ? offers : []
  return mode === 'recharge' ? list.filter((o) => o.card) : list
}

/** La recharge n'apparaît que si la borne l'autorise ET propose au moins une formule de carte. */
export function canRecharge(config) {
  return Boolean(config?.rechargeEnabled) && offersFor('recharge', config?.offers).length > 0
}

/**
 * Le panier : `{ [offer]: quantité }`. Ajouter au-delà des bornes ne fait rien (le bouton se grise) ;
 * descendre à zéro retire la ligne.
 */
export function changeQuantity(cart, offer, delta) {
  const current = cart[offer] || 0
  const next = Math.max(0, Math.min(MAX_QUANTITY, current + delta))
  const copy = { ...cart }
  if (next === 0) {
    delete copy[offer]
    return copy
  }
  if (current === 0 && Object.keys(cart).length >= MAX_LINES) return cart
  copy[offer] = next
  return copy
}

/** Le total affiché AVANT la commande, au prix de la grille. Le serveur recalcule, et c'est lui qui fait foi. */
export function cartTotalCents(cart, offers) {
  const byKey = new Map((offers || []).map((o) => [o.offer, o]))
  return Object.entries(cart).reduce((sum, [key, qty]) => sum + (byKey.has(key) ? toCents(byKey.get(key).price) * qty : 0), 0)
}

export function cartItems(cart) {
  return Object.entries(cart)
    .filter(([, qty]) => qty > 0)
    .map(([offer, quantity]) => ({ offer, quantity }))
}

export function cartCount(cart) {
  return Object.values(cart).reduce((n, q) => n + q, 0)
}

/** Ce que l'usager lit après la réponse du terminal, et ce qu'il peut faire. */
export function paymentOutcome(status) {
  switch (status) {
    case 'accepted':
      return { kind: 'ok' }
    case 'waiting':
      return { kind: 'wait', message: 'Suivez les instructions du terminal de paiement.' }
    case 'refused':
      return { kind: 'retry', message: 'Paiement refusé. Rien n’a été débité. Vous pouvez réessayer avec une autre carte.' }
    case 'cancelled':
      return { kind: 'retry', message: 'Paiement annulé. Rien n’a été débité.' }
    case 'failed':
      return { kind: 'retry', message: 'Le paiement n’a pas pu partir au terminal. Rien n’a été débité.' }
    case 'unresolved':
      return {
        kind: 'help',
        message: 'Le terminal n’a pas répondu. Votre carte a peut-être été débitée : adressez-vous à l’accueil avec le numéro ci-dessous.',
      }
    default:
      return { kind: 'retry', message: 'Le paiement n’a pas abouti. Rien n’a été débité.' }
  }
}

/** Un numéro de carte tel qu'un lecteur (douchette, lecteur de badge) le tape : sans espaces ni retour. */
export function normalizeCardCode(raw) {
  return String(raw ?? '').replace(/[\s\r\n]+/g, '').trim()
}

/** Le libellé traduisible du serveur (`{ fr: … }`) ou une chaîne. */
export function labelOf(value) {
  if (value && typeof value === 'object') return value.fr || Object.values(value)[0] || ''
  return value || ''
}

// ── LE VERROU DU CLAVIER ─────────────────────────────────────────────────────────────────────
//
// Ce que la page PEUT empêcher : recharger (F5, Ctrl+R), revenir en arrière (Alt+←, Retour arrière
// hors d'un champ), ouvrir les outils (F12, Ctrl+Maj+I), imprimer à la main, quitter le plein écran
// (Échap, F11), ouvrir un onglet ou une fenêtre (Ctrl+T, Ctrl+N), chercher, enregistrer, zoomer.
//
// ⚠ CE QU'ELLE NE PEUT PAS EMPÊCHER, ET L'AIDE LE DIT : Ctrl+Alt+Suppr, Alt+F4, Alt+Tab, la touche
// Windows. Le navigateur ne les reçoit pas. Ils se ferment au niveau du système : navigateur lancé en
// mode kiosque (`--kiosk`) et session Windows à accès attribué — sans clavier physique accessible,
// l'usager n'a de toute façon que l'écran tactile.

const BLOCKED_KEYS = new Set(['F1', 'F3', 'F5', 'F6', 'F7', 'F10', 'F11', 'F12', 'Escape', 'BrowserBack', 'BrowserForward', 'BrowserRefresh', 'BrowserHome', 'ContextMenu'])

/**
 * Faut-il bloquer cette touche ?
 * @param {{key: string, ctrlKey?: boolean, metaKey?: boolean, altKey?: boolean}} event
 * @param {boolean} inField la frappe vise un champ de saisie (le numéro de carte)
 */
export function isBlockedKey(event, inField = false) {
  if (!event || typeof event.key !== 'string') return false
  if (BLOCKED_KEYS.has(event.key)) return true
  if (event.altKey && (event.key === 'ArrowLeft' || event.key === 'ArrowRight' || event.key === 'Home')) return true
  if (event.ctrlKey || event.metaKey) {
    // Dans le champ de la carte, coller reste permis (certains lecteurs passent par le presse-papiers).
    if (inField && ['v', 'V'].includes(event.key)) return false
    return true
  }
  if (event.key === 'Backspace' && !inField) return true
  return false
}

/** Appui long dans le coin (personnel) : combien de millisecondes avant d'ouvrir le pavé du code. */
export const STAFF_HOLD_MS = 3000

/** Après la remise, l'écran revient seul à l'accueil. */
export const COLLECT_RETURN_SECONDS = 20
