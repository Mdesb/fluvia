// LA FILE DES VENTES FAITES SANS RÉSEAU (#338).
//
// Le serveur sait déjà recevoir un lot (`POST /api/synchro/operations` : rejeu par séquence locale,
// anti-doublon par clé, scellement NF525 à l'arrivée). Ce fichier est la moitié qui manquait : le
// poste qui ENREGISTRE la vente pendant la coupure, lui donne un numéro de ticket provisoire, et la
// remonte au retour du réseau, dans l'ordre, sans en perdre ni en doubler une.
//
// ── TROIS RÈGLES QUI NE SE DISCUTENT PAS ──────────────────────────────────────────────────────────
//
// 1. **La vente est écrite AVANT que le ticket sorte.** `record()` ne rend la main qu'une fois la
//    transaction IndexedDB terminée. Un ticket remis pour une vente que le poste a oubliée est une
//    recette qui disparaît.
// 2. **La clé est tirée UNE fois, à l'enregistrement, et jamais régénérée.** C'est elle qui fait du
//    second envoi un no-op. Une clé refaite à chaque envoi recréerait la vente à chaque reprise.
// 3. **Rien ne quitte la file sans accusé du serveur.** Une vente sort quand le serveur l'a insérée
//    ou reconnue (doublon). Une erreur réseau laisse tout en place ; une quarantaine la garde, visible.

import { createMutex } from './offlineStore.js'

const PREFIXE = 'vente:'
const SEQUENCE = 'meta:sequence'
const POSTE = 'meta:poste'
const REMONTEES = 'remontee:'
// Combien de temps le poste se souvient qu'un ticket provisoire est devenu telle vente : assez pour
// qu'un client revienne le lendemain avec son ticket, pas assez pour accumuler des mois d'historique.
const MEMOIRE_REMONTEES_MS = 30 * 24 * 3600 * 1000

const tirerUuid = () => globalThis.crypto.randomUUID()

/**
 * @param {{ store: object, now?: () => Date, uuid?: () => string, mutex?: Function }} deps
 */
export function createOfflineSales({ store, now = () => new Date(), uuid = tirerUuid, mutex = createMutex() }) {
  /** L'étiquette du poste : quatre caractères tirés une fois, pour que deux postes ne se croisent pas. */
  async function poste() {
    let tag = await store.get(POSTE)
    if (!tag) {
      tag = uuid().replace(/-/g, '').slice(0, 4).toUpperCase()
      await store.set(POSTE, tag)
    }
    return tag
  }

  /**
   * Enregistre une vente faite hors connexion et rend ce qu'il faut pour le ticket provisoire.
   *
   * @param {{ etablissement: string, session: string, lignes: Array<{produit: string, typeTarif: string, quantite: number, libelle?: string, prix?: string}>, paiements: Array<{moyen: string, montant: string}>, total: string }} vente
   */
  async function record(vente) {
    if (!vente?.etablissement || !vente?.session) throw new Error('Établissement et session requis pour vendre hors connexion.')
    if (!Array.isArray(vente.lignes) || vente.lignes.length === 0) throw new Error('Le panier est vide.')
    return mutex('fluvia-hl-sequence', async () => {
      // ⚠ LA SÉQUENCE NE REDESCEND JAMAIS, même quand la file se vide. Elle numérote les tickets
      // provisoires du poste : la réutiliser donnerait deux tickets au même numéro.
      const sequence = ((await store.get(SEQUENCE)) || 0) + 1
      const tag = await poste()
      const op = {
        cleIdempotence: uuid(),
        sequenceLocale: sequence,
        numeroLocal: `HL-${tag}-${String(sequence).padStart(5, '0')}`,
        horodatageLocal: now().toISOString(),
        etablissement: vente.etablissement,
        session: vente.session,
        lignes: vente.lignes.map((l) => ({
          produit: l.produit,
          typeTarif: l.typeTarif,
          quantite: l.quantite,
          libelle: l.libelle ?? null,
          prix: l.prix ?? null,
        })),
        paiements: vente.paiements.map((p) => ({ moyen: p.moyen, montant: p.montant })),
        total: vente.total,
        etat: 'en_attente',
      }
      // La séquence d'abord : si l'écriture de la vente échouait ensuite, on aurait un numéro sauté
      // (visible, et le serveur l'accepte d'un lot à l'autre), jamais un numéro donné deux fois.
      await store.set(SEQUENCE, sequence)
      await store.set(cleDe(op), op)
      return op
    })
  }

  /** Les ventes du poste encore à remonter ou bloquées, dans l'ordre du comptoir. */
  async function pending(etablissement) {
    const toutes = (await store.list(PREFIXE)).map(([, v]) => v)
    return toutes
      .filter((v) => !etablissement || v.etablissement === etablissement)
      .sort((a, b) => a.sequenceLocale - b.sequenceLocale)
  }

  /** Ce que sont devenus les tickets provisoires déjà remontés (numéro provisoire → définitif). */
  async function recentlySynced() {
    const limite = now().getTime() - MEMOIRE_REMONTEES_MS
    const sortie = []
    for (const [cle, v] of await store.list(REMONTEES)) {
      if (Date.parse(v.remonteeLe) < limite) await store.del(cle)
      else sortie.push(v)
    }
    return sortie
  }

  /**
   * Remonte la file : par session, en suites de séquences CONTIGUËS, dans l'ordre.
   *
   * ⚠ POURQUOI DÉCOUPER AUX TROUS. Le serveur rejette en bloc (422) un lot dont les séquences ont un
   * trou — c'est son alerte de contrôle. Une vente mise en quarantaine reste dans la file, hors des
   * envois suivants : sans découpage, elle creuserait un trou qui bloquerait toutes les autres.
   *
   * @param {string} etablissement
   * @param {(corps: object) => Promise<object>} envoyer `api.synchroOperations`
   * @returns {Promise<{ remontees: number, quarantaine: number, erreur: Error|null }>}
   */
  async function sync(etablissement, envoyer) {
    return mutex('fluvia-hl-remontee', async () => {
      const bilan = { remontees: 0, quarantaine: 0, erreur: null }
      const enAttente = (await pending(etablissement)).filter((v) => v.etat === 'en_attente')
      for (const suite of suites(enAttente)) {
        let reponse
        try {
          reponse = await envoyer({
            session: suite[0].session,
            operations: suite.map((v) => ({
              cleIdempotence: v.cleIdempotence,
              sequenceLocale: v.sequenceLocale,
              numeroLocal: v.numeroLocal,
              horodatageLocal: v.horodatageLocal,
              lignes: v.lignes.map(({ produit, typeTarif, quantite }) => ({ produit, typeTarif, quantite })),
              paiements: v.paiements,
            })),
          })
        } catch (e) {
          // Réseau absent, verrou déjà pris (409), serveur en panne (5xx) : RIEN ne bouge, on
          // réessaiera. Un refus définitif du lot (404 session d'un autre site, 422) bloque la
          // suite pour qu'un responsable la voie, au lieu de la renvoyer sans fin.
          bilan.erreur = e
          const definitif = e?.status === 404 || e?.status === 422
          if (definitif) {
            for (const v of suite) await store.set(cleDe(v), { ...v, etat: 'bloque', raison: e.message })
            continue
          }
          break
        }
        bilan.remontees += await appliquer(suite, reponse)
        bilan.quarantaine += (reponse?.quarantaine || []).filter((q) => q.cle).length
      }
      return bilan
    })
  }

  async function appliquer(suite, reponse) {
    const parCle = new Map(suite.map((v) => [v.cleIdempotence, v]))
    const numeros = new Map((reponse?.correspondances || []).map((c) => [c.cle, c]))
    let sorties = 0
    for (const cle of [...(reponse?.doublons || []), ...accuses(reponse)]) {
      const v = parCle.get(cle)
      if (!v) continue
      await store.del(cleDe(v))
      await store.set(`${REMONTEES}${v.numeroLocal}`, {
        numeroLocal: v.numeroLocal,
        numero: numeros.get(cle)?.numero ?? null,
        total: v.total,
        remonteeLe: now().toISOString(),
      })
      parCle.delete(cle)
      sorties += 1
    }
    for (const q of reponse?.quarantaine || []) {
      const v = q.cle ? parCle.get(q.cle) : null
      if (v) await store.set(cleDe(v), { ...v, etat: 'quarantaine', raison: q.raison || 'refusée par le serveur' })
    }
    return sorties
  }

  /**
   * Les clés INSÉRÉES. Le serveur rend des identifiants de vente dans `inseres` ; c'est
   * `correspondances` qui porte la clé — on ne déduit jamais une insertion d'un simple compte.
   */
  function accuses(reponse) {
    const inseres = new Set(reponse?.inseres || [])
    return (reponse?.correspondances || []).filter((c) => inseres.has(c.vente)).map((c) => c.cle)
  }

  /** Remettre une vente bloquée ou en quarantaine dans la file (après correction par un responsable). */
  async function retry(cleIdempotence) {
    const v = (await pending()).find((x) => x.cleIdempotence === cleIdempotence)
    if (v) await store.set(cleDe(v), { ...v, etat: 'en_attente', raison: null })
  }

  return { record, pending, sync, recentlySynced, retry, poste }
}

const cleDe = (v) => `${PREFIXE}${v.etablissement}:${String(v.sequenceLocale).padStart(10, '0')}`

/** Découpe en suites : même session, séquences consécutives. Entrée déjà triée par séquence. */
export function suites(ventes) {
  const sortie = []
  for (const v of ventes) {
    const derniere = sortie[sortie.length - 1]
    const precedente = derniere?.[derniere.length - 1]
    if (precedente && precedente.session === v.session && precedente.sequenceLocale + 1 === v.sequenceLocale) {
      derniere.push(v)
    } else {
      sortie.push([v])
    }
  }
  return sortie
}
