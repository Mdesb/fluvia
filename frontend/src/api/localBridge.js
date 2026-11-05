// ── LE PONT LOCAL DU POSTE DE CAISSE (#340) : terminal Caisse-AP et tiroir-caisse ────────────────
//
// Le serveur ne joint pas le terminal (il est derrière la box du site) ; le navigateur de la caisse,
// lui, joint le pont qui tourne sur le même poste (`bridge/caisse-ap`, http://127.0.0.1:8765).
//
//   1. la carte part (202, tentative `sent`, connecteur `caisse_ap_pont`) ;
//   2. `relayBridgePayment` demande le débit au pont, qui parle Caisse-AP au terminal et rend une
//      issue SIGNÉE — la réponse arrive quand le client a fini (code PIN : jusqu'à 3 minutes) ;
//   3. l'issue est relayée telle quelle au serveur (`POST /ventes/{id}/issue-pont`), qui vérifie la
//      signature ; la scrutation de `TerminalWait` voit ensuite l'issue arriver.
//
// Le relais vit HORS de l'écran : « Annuler » démonte l'attente, mais une acceptation qui arrive après
// est encore relayée — le client a été débité, elle doit s'écrire (CA-3). Une même tentative n'est
// demandée qu'une fois par onglet ; après un F5, la redemander au pont rend la même issue signée, le
// pont ne débite jamais deux fois une tentative.
//
// Sorti de l'écran pour être testé (`localBridge.test.js`) : c'est un chemin d'argent.

export const DEFAULT_BRIDGE = 'http://127.0.0.1:8765'
export const isBridge = (tentative) => tentative?.connecteur === 'caisse_ap_pont'

// L'adresse du pont de ce point de vente (son réglage `tpe.pont`, sinon le défaut).
export const bridgeAddress = (pdv) => (typeof pdv?.tpe?.pont === 'string' && pdv.tpe.pont) || DEFAULT_BRIDGE

const BRIDGE_DOWN = 'Le pont de caisse ne répond pas sur ce poste : vérifiez qu’il est démarré (fiche « Installer le pont de caisse »).'

async function callBridge(fetchImpl, url, body) {
  let res
  try {
    res = await fetchImpl(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
  } catch {
    const e = new Error(BRIDGE_DOWN)
    e.code = 'bridge_unreachable'
    throw e
  }
  let payload = null
  try { payload = await res.json() } catch { /* empty body */ }
  if (!res.ok) {
    const e = new Error(payload?.message || `Le pont a répondu ${res.status}.`)
    e.code = payload?.code || 'bridge_error'
    e.status = res.status
    throw e
  }
  return payload
}

const relays = new Map()

// Demande le débit au pont puis relaie l'issue signée au serveur. Une seule fois par tentative et par
// onglet : un second appel rend la même promesse. Un échec (pont éteint) se retente en rappelant.
export function relayBridgePayment({ api, venteId, tentative, pont, fetchImpl = globalThis.fetch?.bind(globalThis), pause = (ms) => new Promise((r) => setTimeout(r, ms)) }) {
  const cle = tentative.id
  if (relays.has(cle)) return relays.get(cle)
  const run = (async () => {
    const signee = await callBridge(fetchImpl, `${pont}/v1/payments`, { attempt: tentative.id, amount: tentative.montant, currency: 'EUR' })
    // L'issue est précieuse (le client a peut-être été débité) : une coupure vers le serveur se retente.
    let derniere
    for (let essai = 0; essai < 5; essai += 1) {
      try {
        return await api.issuePont(venteId, { tentative: tentative.id, resultat: signee.result, signature: signee.signature })
      } catch (e) {
        derniere = e
        if (e?.status && e.status < 500) throw e
        await pause(1000 * (essai + 1))
      }
    }
    throw derniere
  })()
  relays.set(cle, run)
  run.catch(() => relays.delete(cle))
  return run
}

// Ouvre le tiroir-caisse (impulsion ESC/POS envoyée par le pont à l'imprimante ticket).
export function openDrawer(pont, fetchImpl = globalThis.fetch?.bind(globalThis)) {
  return callBridge(fetchImpl, `${pont}/v1/drawer/open`, {})
}

// Pour les tests : oublie les relais en cours.
export function resetBridgeRelays() {
  relays.clear()
}
