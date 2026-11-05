// LE CONTRÔLE MANUEL D'UN BILLET PENDANT UNE COUPURE (#338).
//
// En ligne, l'écran « Composter » demande au serveur. Hors ligne, il décide sur une LISTE LOCALE
// téléchargée tant que le réseau était là (`GET /api/acces/controle-billet/liste-locale`), et garde
// ses décisions dans une file qu'il remonte au retour (`POST /api/acces/controle-billet/lot`).
//
// ⚠ LA LISTE NE CONTIENT AUCUN CODE DE BILLET. Le serveur envoie `sha256(sel + code)` ; l'écran hache
// le code scanné avec le même sel et compare. La base du navigateur, copiée, ne permet pas d'imprimer
// un billet valide.
//
// ⚠ LA LISTE VIEILLIT, ET L'ÉCRAN LE DIT. Elle porte sa date ; un billet vendu après elle y est
// inconnu. L'agent le lit sur le bandeau, et le refus dit « inconnu de la liste locale », pas
// « billet invalide » : ce sont deux gestes différents.

import { createMutex } from './offlineStore.js'

const LISTE = 'acces-liste:'
const FILE = 'acces-controle:'
const TAILLE_PAQUET = 500

/** SHA-256 en hexadécimal, par l'API Web Crypto (navigateur ET Node : `globalThis.crypto.subtle`). */
export async function sha256Hex(texte) {
  const octets = new TextEncoder().encode(texte)
  const empreinte = await globalThis.crypto.subtle.digest('SHA-256', octets)
  return [...new globalThis.Uint8Array(empreinte)].map((o) => o.toString(16).padStart(2, '0')).join('')
}

/**
 * @param {{ store: object, now?: () => Date, uuid?: () => string, mutex?: Function }} deps
 */
export function createOfflineAccess({ store, now = () => new Date(), uuid = () => globalThis.crypto.randomUUID(), mutex = createMutex() }) {
  /**
   * Télécharge la liste complète (toutes les pages, même sel) et la remplace d'un bloc. Une liste à
   * moitié téléchargée n'est jamais écrite : elle refuserait à tort tout ce qui manque.
   *
   * @param {string} etablissement
   * @param {(query: object) => Promise<object>} lirePage `api.listeControleHorsLigne`
   */
  async function refreshList(etablissement, lirePage) {
    const premiere = await lirePage({ page: 1 })
    const entrees = [...(premiere.entrees || [])]
    let page = premiere
    while (page.pageSuivante) {
      page = await lirePage({ page: page.page + 1, sel: premiere.sel })
      entrees.push(...(page.entrees || []))
    }
    const parEmpreinte = {}
    for (const e of entrees) parEmpreinte[e.empreinte] = { credit: e.credit ?? null, dejaControleLe: e.dejaControleLe ?? null }
    // ⚠ UNE LISTE NEUVE NE CONNAÎT PAS LES ENTRÉES PAS ENCORE REMONTÉES. Un billet admis hors ligne et
    // pas encore remonté y redeviendrait valide : on rejoue donc la file sur la nouvelle liste.
    for (const c of await pendingChecks(etablissement)) {
      if (c.verdictLocal !== 'valide') continue
      const e = parEmpreinte[await sha256Hex(premiere.sel + c.identifiantSupport)]
      if (!e) continue
      if (e.credit === null) e.dejaControleLe ??= c.horodatage
      else e.credit = Math.max(0, e.credit - 1)
    }
    const liste = { sel: premiere.sel, genereeLe: premiere.genereeLe, entrees: parEmpreinte, taille: entrees.length }
    await store.set(`${LISTE}${etablissement}`, liste)
    return { genereeLe: liste.genereeLe, taille: liste.taille }
  }

  /** L'âge de la liste, pour le bandeau : `null` si le poste n'en a jamais téléchargé. */
  async function listInfo(etablissement) {
    const l = await store.get(`${LISTE}${etablissement}`)
    return l ? { genereeLe: l.genereeLe, taille: l.taille } : null
  }

  /**
   * Décide sur la liste locale, consomme localement, et met la décision en file.
   * Rend un verdict au format de `POST /api/acces/controle-billet`, plus `horsLigne: true`.
   */
  async function check(etablissement, identifiant) {
    const code = String(identifiant || '').trim()
    if (code === '') throw new Error('Code du billet requis.')
    return mutex(`fluvia-hl-acces-${etablissement}`, async () => {
      const liste = await store.get(`${LISTE}${etablissement}`)
      if (!liste) {
        return { resultat: 'refuse', codeMotif: 'liste_absente', libelleMotif: 'Aucune liste locale sur ce poste : contrôle impossible sans réseau.', horsLigne: true, enFile: false }
      }
      const empreinte = await sha256Hex(liste.sel + code)
      const entree = liste.entrees[empreinte]
      const heure = now().toISOString()

      let verdict
      if (!entree) {
        verdict = { resultat: 'refuse', codeMotif: 'droit_invalide', libelleMotif: 'Inconnu de la liste locale (vendu après elle, bloqué, ou d’un autre site).' }
      } else if (entree.credit === null && entree.dejaControleLe) {
        verdict = { resultat: 'refuse', codeMotif: 'deja_consomme', libelleMotif: 'Billet deja controle.', dejaControleLe: entree.dejaControleLe }
      } else if (entree.credit !== null && entree.credit <= 0) {
        verdict = { resultat: 'refuse', codeMotif: 'credit_epuise', libelleMotif: 'Credit epuise.', credit: { restant: 0 } }
      } else {
        verdict = { resultat: 'valide', codeMotif: null, libelleMotif: 'Billet valide (liste locale).', consomme: true }
        // Consommation LOCALE : le même billet présenté deux fois pendant la coupure est refusé la
        // seconde, comme en ligne — sans attendre le serveur.
        if (entree.credit === null) entree.dejaControleLe = heure
        else {
          entree.credit -= 1
          verdict.credit = { restant: entree.credit }
        }
        await store.set(`${LISTE}${etablissement}`, liste)
      }

      // Le code en clair n'est écrit QUE dans la file, pour la remontée, et effacé à l'accusé.
      const controle = {
        cleIdempotence: uuid(),
        identifiantSupport: code,
        horodatage: heure,
        verdictLocal: verdict.resultat,
        motifLocal: verdict.codeMotif,
      }
      await store.set(`${FILE}${etablissement}:${heure}:${controle.cleIdempotence}`, controle)
      return { ...verdict, horsLigne: true, enFile: true }
    })
  }

  async function pendingChecks(etablissement) {
    return (await store.list(`${FILE}${etablissement}:`)).map(([, v]) => v)
  }

  /**
   * Remonte les contrôles en file, dans l'ordre. Ne retire que ce que le serveur a accepté ou
   * reconnu ; un rejet (clé illisible, code vide) est retiré aussi — il ne passera jamais — mais
   * compté, pour être dit.
   */
  async function syncChecks(etablissement, envoyer) {
    return mutex(`fluvia-hl-acces-${etablissement}`, async () => {
      const toutes = await store.list(`${FILE}${etablissement}:`)
      const bilan = { remontes: 0, conflits: 0, rejetes: 0, erreur: null }
      // Par paquets : le serveur refuse un lot de plus de 2 000 contrôles, et une file qu'il refuse
      // en bloc ne se viderait jamais.
      for (let i = 0; i < toutes.length; i += TAILLE_PAQUET) {
        const lignes = toutes.slice(i, i + TAILLE_PAQUET)
        let reponse
        try {
          reponse = await envoyer({ controles: lignes.map(([, v]) => v) })
        } catch (e) {
          bilan.erreur = e
          break
        }
        const parCle = new Map(lignes.map(([k, v]) => [v.cleIdempotence, k]))
        for (const r of reponse?.resultats || []) {
          const k = parCle.get(r.cleIdempotence)
          if (!k) continue
          await store.del(k)
          if (r.statut === 'rejete') bilan.rejetes += 1
          else bilan.remontes += 1
          if (r.enConflit) bilan.conflits += 1
        }
      }
      return bilan
    })
  }

  return { refreshList, listInfo, check, pendingChecks, syncChecks }
}
