// ── L'INTENTION DE RÈGLEMENT (G-2 du ticket opposable, D122) ─────────────────────────────────────
//
// Sorti des écrans pour être testé (`paymentIntent.test.js`) : c'est un chemin d'argent.
//
// Une intention, c'est un règlement voulu sur une vente — un moyen, un montant — et sa clé
// d'idempotence. Elle est écrite dans le `sessionStorage` de l'onglet AVANT l'envoi, et elle y reste
// jusqu'à une issue DÉFINITIVE : encaissé, refusé, ou déclaré par le caissier. Tant qu'elle y est :
// - tout réessai repart avec le MÊME corps et la MÊME clé (le serveur rend ce qu'il a déjà fait) ;
// - « Régler » rejoue l'intention en attente, quoi qu'on ait saisi depuis ;
// - un rechargement de la page la retrouve, et la caisse rouvre sa vente.
//
// ⚠ « Pas de réponse » n'est PAS « pas passé ». Un délai dépassé, une erreur réseau, une passerelle en
// 502 ou 504, un 409 « en cours » : l'argent a peut-être bougé, on ne sait pas. On redemande, même clé.
// Seuls un refus du terminal et un refus de la demande (4xx) disent « rien encaissé ».
//
// ⚠ Un terminal muet ne se relance JAMAIS (Q-A1) : le serveur répond 409 `payment_outcome_unknown`,
// avec la tentative à déclarer. L'écran demande au caissier ce qu'affiche le terminal.

const STORE_KEY = 'fluvia.reglements-en-attente'

export const PENDING_MESSAGE = "Résultat inconnu : le serveur n'a pas encore rendu l'issue de ce règlement. "
  + "Il est peut-être passé — ne l'encaissez pas une seconde fois. « Vérifier » le redemande avec la même clé."

// `crypto.randomUUID()` n'existe qu'en contexte sécurisé (HTTPS) ; `getRandomValues()` partout (MDN).
export function newKey(c = globalThis.crypto) {
  if (typeof c?.randomUUID === 'function') return c.randomUUID()
  const o = c.getRandomValues(new globalThis.Uint8Array(16))
  o[6] = (o[6] & 0x0f) | 0x40 // version 4
  o[8] = (o[8] & 0x3f) | 0x80 // variante RFC 4122
  const h = Array.from(o, (b) => b.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

// Un stockage refusé (onglet privé, quota) n'empêche pas de régler : l'intention vit le temps de l'appel.
function readAll(storage) {
  try {
    return JSON.parse(storage.getItem(STORE_KEY)) || {}
  } catch {
    return {}
  }
}

function writeAll(storage, all) {
  try {
    if (Object.keys(all).length > 0) storage.setItem(STORE_KEY, JSON.stringify(all))
    else storage.removeItem(STORE_KEY)
  } catch {
    // Voir `readAll`.
  }
}

export function pendingIntent(storage, saleId) {
  return readAll(storage)[saleId] || null
}

export function pendingIntents(storage, establishment) {
  return Object.values(readAll(storage)).filter((i) => i.establishment === establishment)
}

export function forgetIntent(storage, saleId) {
  const all = readAll(storage)
  delete all[saleId]
  writeAll(storage, all)
}

// L'intention à envoyer : celle qui attend sur la vente, sinon une neuve, écrite avant l'envoi. Le
// corps est gardé tel quel : un montant absent (« le reste dû ») n'est pas « 45,00 » pour le serveur.
export function intentFor(storage, { saleId, saleNumber, establishment, body, headers }, makeKey = newKey) {
  const pending = pendingIntent(storage, saleId)
  if (pending) return pending
  const intent = { saleId, saleNumber, establishment, body: { ...body, cleIdempotence: makeKey() }, headers: headers || null }
  writeAll(storage, { ...readAll(storage), [saleId]: intent })
  return intent
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

// Envoie l'intention et la redemande, même clé, tant que l'issue n'est pas connue (au plus `tries`
// fois). Rend { outcome, response?, attempt?, message? } :
// - `paid`     : encaissé ; l'intention est close ;
// - `refused`  : le terminal a refusé ; close ;
// - `rejected` : la demande est refusée (4xx), rien d'encaissé ; close ;
// - `unknown`  : le terminal est muet ; `attempt` est la tentative à déclarer ; l'intention reste ;
// - `pending`  : toujours sans issue après les relances ; l'intention reste.
export async function settle(api, storage, intent, { pause = wait, tries = 10, delayMs = 3000, onRetry } = {}) {
  for (let essai = 1; ; essai++) {
    try {
      const res = await api.payer(intent.saleId, intent.body, intent.headers || undefined)
      if (res?.reglementEnregistre) {
        forgetIntent(storage, intent.saleId)
        return { outcome: 'paid', response: res }
      }
      // Un timeout rendu tel quel ne nomme pas sa tentative : la clé, redemandée, répond 409 avec elle
      // — sans repartir au terminal.
      if (res?.statutTPE !== 'timeout') {
        forgetIntent(storage, intent.saleId)
        return { outcome: 'refused', response: res, message: `Transaction ${res?.statutTPE || 'refusée'} — aucun règlement enregistré.` }
      }
    } catch (e) {
      if (e?.payload?.code === 'payment_outcome_unknown') {
        return { outcome: 'unknown', attempt: e.payload.tentative ?? null, message: e.message }
      }
      const sansIssue = e?.payload?.code === 'payment_in_progress' || !e?.status || e.status >= 500
      if (!sansIssue) {
        forgetIntent(storage, intent.saleId)
        return { outcome: 'rejected', message: e?.message || 'Règlement refusé.' }
      }
    }
    if (essai >= tries) return { outcome: 'pending', message: PENDING_MESSAGE }
    onRetry?.(essai)
    await pause(delayMs)
  }
}

// La déclaration du caissier : ce qu'affiche le terminal. Une issue définitive clôt l'intention de la
// vente — aussi quand la tentative a trouvé son issue ailleurs (409 `payment_outcome_known`). Un refus
// de la déclaration (référence manquante, réseau) la garde : rien n'a changé.
export async function declareOutcome(api, storage, saleId, { attemptId, accepted, cardReference }) {
  const corps = accepted
    ? { tentative: attemptId, issue: 'accepte', referenceCarte: cardReference }
    : { tentative: attemptId, issue: 'non_passe' }
  try {
    const res = await api.declarerReglement(saleId, corps)
    forgetIntent(storage, saleId)
    return res
  } catch (e) {
    if (e?.payload?.code === 'payment_outcome_known') forgetIntent(storage, saleId)
    throw e
  }
}
