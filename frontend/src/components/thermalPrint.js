// IMPRIMER UN BILLET OU UN TICKET SUR UNE IMPRIMANTE THERMIQUE 80 MM (Aures ODP 333 au CIAP).
//
// ⚠ POURQUOI UN CADRE À PART, ET PAS LE `@media print` DE L'ÉCRAN.
//
// La règle d'impression de `styles.css` masque l'écran par `visibility: hidden` : le reste de la page
// garde sa hauteur. Sur une feuille A4, cela fait au pire une page blanche de plus ; sur un rouleau,
// c'est un mètre de papier qui sort après chaque billet. Ici, le document est recopié SEUL dans un
// cadre invisible, avec sa propre feuille de style, et la page fait exactement sa hauteur :
// `@page { size: 80mm <hauteur mesurée>; margin: 0 }`. Le pilote coupe en fin de document.
//
// Sans boîte de dialogue : Chrome ou Edge lancés avec `--kiosk-printing` envoient à l'imprimante par
// défaut de Windows (fiche docs/support/installer-imprimante-caisse.md).

import { thermalPageCss } from '../api/receiptPrint.js'

// Noir sur blanc, aucun fond : le papier thermique n'imprime que du noir, et un aplat gris devient
// une bande illisible. 72 mm imprimables sur 80 mm de papier.
const THERMAL_CSS = `
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; color: #000; }
body { width: 80mm; padding: 2mm 4mm; font: 11pt/1.3 Arial, Helvetica, sans-serif; }
.ticket { width: 72mm; border: 0; box-shadow: none; background: none; }
.ticket + .ticket { break-before: page; }
.th { display: block; padding: 0 0 2mm; border-bottom: 1px dashed #000; margin-bottom: 2mm; text-align: center; }
.th .ok, .noprint { display: none !important; }
/* « Vente encaissée » est une confirmation d'écran : sur le ticket, l'en-tête est l'émetteur. */
.th:not(:has(.t-etab)) { display: none; }
.tb { padding: 0; }
.tnum, .t-date, .t-emetteur, .t-code, .t-mention { font-size: 9pt; }
.t-emetteur { text-align: center; margin-bottom: 1mm; }
.t-emetteur div { margin: 0; }
.t-etab { font-size: 13pt; font-weight: 700; }
.t-gratuit { font-size: 16pt; font-weight: 700; text-align: center; margin: 2mm 0; }
.trow { display: flex; justify-content: space-between; gap: 2mm; padding: 0.5mm 0; color: #000 !important; opacity: 1 !important; }
.trow.tt { border-top: 1px solid #000; margin-top: 1mm; padding-top: 1mm; font-weight: 700; font-size: 12pt; }
.t-tva { border-top: 1px dashed #000; margin-top: 1mm; padding-top: 1mm; font-size: 9pt; }
.t-tva .trow span:first-child { flex: 1; }
.tqr { display: flex; flex-direction: column; align-items: center; gap: 1mm; margin-top: 2mm; padding-top: 2mm; border-top: 1px dashed #000; }
.tqr img { width: 38mm !important; height: 38mm !important; border-radius: 0 !important; }
.t-terminal { white-space: pre-wrap; font-family: "Courier New", monospace; font-size: 9pt; border-top: 1px dashed #000; margin-top: 2mm; padding-top: 2mm; }
.t-code { word-break: break-all; text-align: center; font-family: "Courier New", monospace; }
.hint { color: #000; }
`

/**
 * Imprime `noeuds` (un billet, ou un billet par unité) sur le rouleau, chacun sur sa page.
 * @param {Element|Element[]} noeuds
 * @returns {Promise<void>} résolue une fois l'impression demandée
 */
export function printThermal(noeuds, { titre = 'Impression' } = {}) {
  const liste = (Array.isArray(noeuds) ? noeuds : [noeuds]).filter(Boolean)
  if (liste.length === 0) return Promise.resolve()

  const cadre = document.createElement('iframe')
  cadre.setAttribute('aria-hidden', 'true')
  cadre.title = titre
  cadre.style.cssText = 'position:fixed;right:0;bottom:0;width:80mm;height:0;border:0'
  document.body.appendChild(cadre)

  const doc = cadre.contentDocument
  doc.open()
  doc.write('<!doctype html><html lang="fr"><head><meta charset="utf-8"><title></title></head><body></body></html>')
  doc.close()
  doc.title = titre
  const style = doc.createElement('style')
  style.textContent = THERMAL_CSS
  doc.head.appendChild(style)
  for (const n of liste) doc.body.appendChild(doc.importNode(n, true))

  const images = Array.from(doc.images)
  return Promise.all(images.map((img) => (img.complete
    ? null
    : new Promise((ok) => { img.onload = ok; img.onerror = ok }))))
    .then(() => {
      // La page la plus haute fixe la hauteur de toutes : un billet par page, sans rouleau perdu.
      // Mesurée dans la feuille d'impression (marges du corps comprises) : mesurer le seul bloc
      // oubliait les marges, et la dernière ligne du ticket partait sur une seconde page.
      const corps = doc.defaultView.getComputedStyle(doc.body)
      const marges = Number.parseFloat(corps.paddingTop) + Number.parseFloat(corps.paddingBottom)
      const hauteurs = Array.from(doc.body.children).map((e) => e.getBoundingClientRect().height)
      const page = doc.createElement('style')
      page.textContent = thermalPageCss(Math.max(0, ...hauteurs) + (marges || 0))
      doc.head.appendChild(page)
      cadre.contentWindow.focus()
      cadre.contentWindow.print()
    })
    .finally(() => {
      setTimeout(() => cadre.remove(), 1000)
    })
}
