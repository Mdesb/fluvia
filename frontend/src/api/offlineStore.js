// LE STOCKAGE DU POSTE PENDANT UNE COUPURE (#338) — une seule interface, deux réalisations.
//
// - `indexedDbStore()` dans le navigateur : IndexedDB survit au rechargement, à la fermeture de
//   l'onglet et au redémarrage du poste. `localStorage` aurait suffi en volume, mais il est synchrone,
//   partagé sans transaction entre onglets, et vidé par certains navigateurs sous pression mémoire.
// - `memoryStore()` pour les tests (`node --test` n'a pas d'IndexedDB) : MÊME contrat, pour que la
//   logique de file testée soit celle qui tourne au comptoir.
//
// Le contrat est volontairement minuscule : lire, écrire, effacer une clé, lister un préfixe. Toute
// la logique (numérotation, ordre, anti-doublon) est au-dessus, dans des fichiers testés.
//
// ⚠ CE QUI EST ÉCRIT ICI RESTE SUR LE DISQUE DU POSTE. Voir « Données gardées sur le poste » dans
// l'aide (`app/docs/aide/vente/vendre-hors-connexion.md`) : rien de personnel n'y est écrit, et
// chaque entrée est effacée dès que le serveur en a accusé réception.

const NOM_BASE = 'fluvia-hors-ligne'
const TABLE = 'cles'

/** Un stockage en mémoire, au contrat de `indexedDbStore`. */
export function memoryStore() {
  const m = new Map()
  // Copie profonde à l'écriture ET à la lecture : IndexedDB clone les valeurs (algorithme de clonage
  // structuré). Sans cette copie, un test pourrait passer en modifiant un objet déjà « écrit ».
  const copie = (v) => (v === undefined ? undefined : structuredClone(v))
  return {
    async get(cle) {
      return copie(m.get(cle))
    },
    async set(cle, valeur) {
      m.set(cle, copie(valeur))
    },
    async del(cle) {
      m.delete(cle)
    },
    async list(prefixe) {
      return [...m.keys()].filter((k) => k.startsWith(prefixe)).sort().map((k) => [k, copie(m.get(k))])
    },
  }
}

/** Le stockage persistant du navigateur. Rend `null` s'il n'existe pas (navigation privée stricte). */
export function indexedDbStore(nom = NOM_BASE) {
  const idb = globalThis.indexedDB
  if (!idb) return null
  let ouverture = null
  const base = () => {
    ouverture ??= new Promise((ok, ko) => {
      const req = idb.open(nom, 1)
      req.onupgradeneeded = () => req.result.createObjectStore(TABLE)
      req.onsuccess = () => ok(req.result)
      req.onerror = () => ko(req.error)
    })
    return ouverture
  }
  const operer = async (mode, geste) => {
    const db = await base()
    return new Promise((ok, ko) => {
      const tx = db.transaction(TABLE, mode)
      const resultat = geste(tx.objectStore(TABLE))
      // ⚠ ON RÉSOUT À LA FIN DE LA TRANSACTION, PAS À LA FIN DE LA REQUÊTE. Une écriture n'est sur le
      // disque qu'à `oncomplete` : imprimer le ticket provisoire avant serait promettre une vente qu'une
      // fermeture d'onglet au même instant pourrait perdre.
      tx.oncomplete = () => ok(resultat?.result)
      tx.onerror = () => ko(tx.error)
      tx.onabort = () => ko(tx.error)
    })
  }
  return {
    get: (cle) => operer('readonly', (t) => t.get(cle)),
    set: (cle, valeur) => operer('readwrite', (t) => t.put(valeur, cle)).then(() => undefined),
    del: (cle) => operer('readwrite', (t) => t.delete(cle)).then(() => undefined),
    async list(prefixe) {
      const db = await base()
      return new Promise((ok, ko) => {
        const tx = db.transaction(TABLE, 'readonly')
        const plage = globalThis.IDBKeyRange.bound(prefixe, `${prefixe}\uffff`)
        const sortie = []
        const req = tx.objectStore(TABLE).openCursor(plage)
        req.onsuccess = () => {
          const c = req.result
          if (!c) return
          sortie.push([c.key, c.value])
          c.continue()
        }
        tx.oncomplete = () => ok(sortie)
        tx.onerror = () => ko(tx.error)
      })
    },
  }
}

/**
 * Exclusion mutuelle entre ONGLETS (Web Locks) et, à défaut, dans l'onglet seulement.
 *
 * Deux onglets de caisse ouverts sur le même poste tireraient sinon le même numéro de séquence, ou
 * remonteraient la même file en même temps. Le serveur absorbe le second envoi (verrou par session,
 * anti-doublon), mais deux ventes portant le même numéro provisoire, elles, ne se rattrapent pas.
 */
export function createMutex(locks = globalThis.navigator?.locks) {
  let chaine = Promise.resolve()
  return function exclusif(nom, geste) {
    if (locks?.request) return locks.request(nom, () => geste())
    const suite = chaine.then(() => geste())
    chaine = suite.catch(() => {})
    return suite
  }
}
