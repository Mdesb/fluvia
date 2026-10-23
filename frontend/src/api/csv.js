// CSV POUR EXCEL (FRANCE) : UTF-8 avec BOM, séparateur point-virgule, virgule décimale, CRLF.
//
// Sans BOM, Excel lit l'UTF-8 comme du Windows-1252 : « Entrée » devient « EntrÃ©e ». Avec la
// virgule comme séparateur, un Excel réglé en français met toute la ligne dans la colonne A. Et
// « 12.50 » y est lu comme du texte, pas comme un nombre.

const BOM = '﻿'

/** Une cellule de texte qui commence par = + - @ deviendrait une formule à l'ouverture : on la neutralise. */
function texte(v) {
  const s = v == null ? '' : String(v)
  return /^[=+\-@\t\r]/.test(s) ? `'${s}` : s
}

function nombre(v) {
  if (v == null || v === '') return ''
  const n = typeof v === 'number' ? v : Number.parseFloat(v)
  return Number.isFinite(n) ? String(n).replace('.', ',') : ''
}

function cellule(brut) {
  return /[;"\r\n]/.test(brut) ? `"${brut.replaceAll('"', '""')}"` : brut
}

/**
 * @param {{titre: string, valeur: (ligne: any) => any, nombre?: boolean}[]} colonnes
 * @param {any[]} lignes
 * @returns {string}
 */
export function toCsv(colonnes, lignes) {
  const entete = colonnes.map((c) => cellule(texte(c.titre))).join(';')
  const corps = (lignes || []).map((l) => colonnes
    .map((c) => cellule(c.nombre ? nombre(c.valeur(l)) : texte(c.valeur(l))))
    .join(';'))
  return BOM + [entete, ...corps].join('\r\n') + '\r\n'
}

/** Fait télécharger `contenu` sous `nom` (navigateur seulement). */
export function downloadCsv(nom, contenu) {
  const url = URL.createObjectURL(new Blob([contenu], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = nom
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
