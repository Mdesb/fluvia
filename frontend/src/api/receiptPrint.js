// Ce qui s'imprime sur le billet et sur le ticket de caisse (80 mm), sorti de l'écran pour être testé.
//
// Le serveur rend les mentions (`TicketProcessor` : `emetteur`, `date`, `ventilationTva`, `total`) ;
// ici, on ne décide que de leur forme sur le papier. Rien n'est deviné : une mention absente du
// serveur n'est pas imprimée, elle n'est pas remplacée par une valeur plausible.

const nombre = (x) => {
  const n = Number.parseFloat(x ?? '0')
  return Number.isFinite(n) ? n : 0
}

/** Le nom qui ouvre le billet : l'établissement, à défaut la raison sociale. */
export function venueName(emetteur) {
  return (emetteur?.nom || emetteur?.raisonSociale || '').trim()
}

/**
 * L'en-tête du ticket de caisse : raison sociale (ou nom de l'établissement), adresse, SIRET, n° TVA.
 * @returns {string[]}
 */
export function issuerLines(emetteur) {
  if (!emetteur) return []
  const lignes = []
  const raison = (emetteur.raisonSociale || '').trim()
  const nom = (emetteur.nom || '').trim()
  if (raison) lignes.push(raison)
  if (nom && nom !== raison) lignes.push(nom)
  if (emetteur.adresse) lignes.push(emetteur.adresse.trim())
  if (emetteur.siret) lignes.push(`SIRET ${emetteur.siret.trim()}`)
  if (emetteur.numeroTva) lignes.push(`TVA intracom. ${emetteur.numeroTva.trim()}`)
  return lignes
}

/**
 * La date et l'heure de la vente, dans le fuseau de l'établissement (Perpignan : Europe/Paris).
 * Une date illisible n'imprime rien plutôt qu'« Invalid Date ».
 */
export function printedDateTime(iso, timeZone = 'Europe/Paris') {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return new Intl.DateTimeFormat('fr-FR', {
    timeZone, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(d)
}

/** Un billet dont le prix est nul dit « Gratuit » ; sinon il ne porte aucun montant (R5). */
export function isFree(total) {
  return Math.abs(nombre(total)) < 0.005
}

/**
 * La page d'un rouleau 80 mm : la largeur du papier, la hauteur du document (mesurée en px CSS,
 * 96 px = 25,4 mm), sans marge. C'est l'équivalent de `size: 80mm auto`, que CSS n'admet pas : sans
 * hauteur exacte, le pilote ferait sortir une page entière de papier blanc après chaque billet.
 * Plancher de 40 mm, plus 4 mm de garde pour la coupe.
 */
export function thermalPageCss(hauteurPx) {
  const mm = Math.max(40, Math.ceil(((Number(hauteurPx) || 0) * 25.4) / 96) + 4)
  return `@page { size: 80mm ${mm}mm; margin: 0; }`
}

/**
 * Les lignes de TVA du ticket, une par taux. Un taux inconnu du serveur (`taux: null`) se dit
 * « TVA non renseignée » : on n'imprime pas « 0 % » sur une recette peut-être taxée.
 * @returns {{libelle: string, ht: number, tva: number, ttc: number}[]}
 */
export function vatRows(ventilation) {
  return (ventilation || []).map((v) => {
    const taux = v?.taux == null ? null : nombre(v.taux)
    const libelle = taux == null
      ? 'TVA non renseignée'
      : `TVA ${taux.toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`
    return { libelle, ht: nombre(v?.totalHt), tva: nombre(v?.totalTva), ttc: nombre(v?.totalTtc) }
  })
}
