// LE MODE HORS CONNEXION DU POSTE (#338) — les instances partagées par les écrans.
//
// Une seule file, une seule détection de coupure par onglet : la caisse et l'écran « Composter »
// lisent le même état, sinon l'un dirait « hors ligne » pendant que l'autre affiche « en ligne ».

import { indexedDbStore, memoryStore } from './offlineStore.js'
import { createOfflineSales } from './offlineSales.js'
import { createOfflineAccess } from './offlineAccess.js'
import { createConnectivity } from './connectivity.js'
import { estAuGuichet, estVendable, grillesVendables, libelleProduit, libelleTarif } from './produit.js'

// ⚠ SANS INDEXEDDB (navigation privée stricte), LA FILE SERAIT EN MÉMOIRE ET MOURRAIT AVEC L'ONGLET.
// `persistant` le dit à l'écran, qui refuse alors de vendre hors connexion plutôt que de promettre.
const persistante = indexedDbStore()
export const store = persistante ?? memoryStore()
export const persistant = persistante !== null

export const offlineSales = createOfflineSales({ store })
export const offlineAccess = createOfflineAccess({ store })

let connexion = null
/** La détection de coupure, créée au premier usage (jamais pendant un test sans `window`). */
export function connectivity() {
  connexion ??= createConnectivity()
  return connexion
}

const CATALOGUE = 'catalogue:'

/**
 * Le catalogue de la caisse, gardé pour vendre sans réseau.
 *
 * ⚠ CE N'EST PAS UN CACHE D'API MASQUÉ — c'est ce que le service worker refuse à juste titre (« un
 * logiciel de caisse hors ligne qui ment est pire qu'un logiciel indisponible »). Ici la copie est
 * EXPLICITE, DATÉE, et l'écran hors connexion affiche sa date. Seul ce qu'il faut pour vendre est
 * gardé : produit, tarif, prix indicatif, libellés. Rien sur les clients.
 *
 * Sont écartés d'office ce que la vente hors connexion ne sait pas faire sans le serveur : les
 * abonnements (mandat, contrat) et les produits nominatifs (bénéficiaire à choisir parmi les clients).
 * ⚠ Les OPTIONS ne sont pas visibles dans la collection des produits : un produit à option
 * obligatoire vendu hors connexion partira en quarantaine à la remontée, où un responsable le verra.
 */
export async function saveCatalogue(etablissement, produits, moyens) {
  const vendables = []
  for (const p of produits || []) {
    if (!estAuGuichet(p) || !estVendable(p)) continue
    // Une formule ouvre un abonnement à la validation (client payeur exigé), sauf « produit simple ».
    if (p?.formule && p?.champsPerso?.venteSansSouscription !== true) continue
    if (p?.champsPerso?.beneficiaireRequis === true) continue
    // La même règle que `AjoutLigneHandler::estNominatif()` : facette « accès » OU drapeau produit.
    if (Array.isArray(p?.type?.facettes) && p.type.facettes.includes('acces')) continue
    for (const g of grillesVendables(p)) {
      vendables.push({
        produit: p.id,
        typeTarif: g.typeTarif.id,
        libelle: libelleProduit(p),
        tarif: libelleTarif(g),
        prix: String(g.prix),
      })
    }
  }
  const moyensHorsLigne = (moyens || []).filter((m) => MOYENS_HORS_LIGNE.includes(m.code)).map((m) => ({ code: m.code, libelle: m.libelle || m.code }))
  await store.set(`${CATALOGUE}${etablissement}`, { gardeLe: new Date().toISOString(), lignes: vendables, moyens: moyensHorsLigne })
}

export async function readCatalogue(etablissement) {
  return (await store.get(`${CATALOGUE}${etablissement}`)) ?? null
}

/**
 * Les moyens de paiement qu'on peut encaisser sans serveur : espèces et chèque. La carte passe par
 * le terminal relié au serveur (intention de règlement, issue rendue par le connecteur) : hors
 * connexion, elle n'a pas d'issue fiable. Décision prudente, à valider (voir la PR).
 */
export const MOYENS_HORS_LIGNE = ['especes', 'cheque']
