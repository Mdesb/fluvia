// ── L'ATTENTE DU TERMINAL DE PAIEMENT (spec TPE lot 1 §4) ────────────────────────────────────────
//
// Sorti de l'écran pour être testé (`terminalWait.test.js`) : c'est un chemin d'argent.
//
// Une carte part au terminal et le serveur rend la main tout de suite (202, tentative `sent`). L'écran
// scrute la tentative (`GET /ventes/{id}/reglement-terminal?tentative=`) toutes les 1,5 s, comme
// `ScansEnDirect` : jamais deux lectures en vol, une coupure réseau n'est PAS une issue — on relit.
// La scrutation s'arrête d'elle-même dès que la tentative a une issue.

export const POLL_MS = 1500

// Ce que la tentative est devenue, pour l'écran. Rien de lu ou un statut inconnu : « à déclarer »,
// jamais « pas passé » — l'argent a peut-être bougé.
export function attemptPhase(tentative) {
  switch (tentative?.statut) {
    case 'pending':
    case 'sent':
      return 'waiting'
    case 'accepted':
    case 'declared_accepted':
      return 'paid'
    case 'refused':
    case 'failed':
      return 'refused'
    case 'declared_not_processed':
      return 'cancelled'
    case 'cancelled':
      // Acceptée APRÈS « Annuler », alors que la vente était réglée autrement : rien n'est écrit, et
      // le client a été débité. Le serveur le dit dans la raison.
      return /à rembourser/.test(tentative.raison || '') ? 'refund' : 'cancelled'
    default:
      return 'unknown'
  }
}

// Le terminal externe : relié à rien, c'est le caissier qui lit le résultat et le déclare.
export const isExternal = (tentative) => tentative?.connecteur === 'externe'

// Une scrutation : `read()` rend la tentative ; `onResult` la reçoit à chaque lecture. Elle s'arrête
// quand `done(tentative)` — par défaut, à la première issue —, ou sur `stop()`. `poke()` relit tout de
// suite, sauf si une lecture est déjà en vol. `schedule`/`cancel` sont injectables pour les tests.
export function createPoller({
  read, onResult, onError, intervalMs = POLL_MS, done = (t) => attemptPhase(t) !== 'waiting',
  schedule = (fn, ms) => setTimeout(fn, ms), cancel = (id) => clearTimeout(id),
}) {
  let actif = false
  let enVol = false
  let minuteur = null

  const prevoir = () => {
    if (actif) minuteur = schedule(lire, intervalMs)
  }

  async function lire() {
    minuteur = null
    if (!actif || enVol) return
    enVol = true
    try {
      const tentative = await read()
      if (!actif) return
      onResult(tentative)
      if (done(tentative)) {
        actif = false
        return
      }
    } catch (e) {
      if (!actif) return
      onError?.(e)
    } finally {
      enVol = false
    }
    prevoir()
  }

  return {
    start() {
      if (actif) return
      actif = true
      prevoir()
    },
    poke() {
      if (!actif || enVol) return
      if (minuteur !== null) cancel(minuteur)
      minuteur = null
      lire()
    },
    stop() {
      actif = false
      if (minuteur !== null) cancel(minuteur)
      minuteur = null
    },
  }
}
