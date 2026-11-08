// DÉTECTER LA COUPURE (#338) — et surtout LE RETOUR.
//
// `navigator.onLine` ne suffit pas, dans les deux sens : il vaut `true` derrière un câble branché sur
// une box sans internet, et le navigateur le met parfois à jour avec plusieurs secondes de retard.
// On croise donc trois signaux :
//   1. les événements `offline`/`online` du navigateur (immédiats quand ils existent) ;
//   2. l'échec RÉSEAU d'un appel réel (`code: network_unreachable` de `client.js`) — un refus 4xx/5xx
//      prouve au contraire que le serveur répond ;
//   3. une sonde périodique PENDANT la coupure seulement, pour voir revenir le serveur sans attendre
//      que le caissier clique.
//
// La sonde vise `/index.html` (pas l'API) : elle ne demande ni jeton ni droit, et le service worker ne
// l'intercepte pas (ce n'est ni une navigation ni un asset). `cache: 'no-store'` évite qu'un cache
// HTTP réponde à la place du serveur.

export const EN_LIGNE = 'en_ligne'
export const HORS_LIGNE = 'hors_ligne'

/**
 * @param {{ probe?: () => Promise<boolean>, intervalMs?: number, win?: object, timers?: {set: Function, clear: Function} }} deps
 */
export function createConnectivity({
  probe = defaultProbe,
  intervalMs = 15000,
  win = typeof window !== 'undefined' ? window : null,
  timers = { set: (f, ms) => setInterval(f, ms), clear: (t) => clearInterval(t) },
} = {}) {
  let etat = win?.navigator?.onLine === false ? HORS_LIGNE : EN_LIGNE
  const abonnes = new Set()
  let minuteur = null

  function changer(nouvel) {
    if (nouvel === etat) return
    etat = nouvel
    if (etat === HORS_LIGNE) demarrerSonde()
    else arreterSonde()
    for (const f of abonnes) f(etat)
  }

  function demarrerSonde() {
    if (minuteur !== null) return
    minuteur = timers.set(() => { check() }, intervalMs)
  }

  function arreterSonde() {
    if (minuteur === null) return
    timers.clear(minuteur)
    minuteur = null
  }

  async function check() {
    let joignable = false
    try {
      joignable = await probe()
    } catch {
      joignable = false
    }
    changer(joignable ? EN_LIGNE : HORS_LIGNE)
    return etat
  }

  /**
   * À appeler sur l'échec d'un appel : seule une erreur RÉSEAU fait basculer. Un délai dépassé
   * (`status: 0` sans code) ne suffit pas : le serveur peut être lent sans être absent.
   */
  function reportFailure(erreur) {
    if (erreur?.payload?.code === 'network_unreachable') changer(HORS_LIGNE)
  }

  const surOffline = () => changer(HORS_LIGNE)
  // L'événement `online` n'est qu'un indice (la box peut être joignable sans internet) : on vérifie.
  const surOnline = () => { check() }
  win?.addEventListener?.('offline', surOffline)
  win?.addEventListener?.('online', surOnline)
  if (etat === HORS_LIGNE) demarrerSonde()

  return {
    get state() {
      return etat
    },
    subscribe(f) {
      abonnes.add(f)
      return () => abonnes.delete(f)
    },
    check,
    reportFailure,
    reportSuccess: () => changer(EN_LIGNE),
    dispose() {
      arreterSonde()
      win?.removeEventListener?.('offline', surOffline)
      win?.removeEventListener?.('online', surOnline)
    },
  }
}

async function defaultProbe() {
  const r = await fetch(`/index.html?sonde=${Date.now()}`, { method: 'HEAD', cache: 'no-store' })
  return r.ok
}
