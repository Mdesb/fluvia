// DATES, NOMBRES ET MONTANTS DANS LA LANGUE ACTIVE — le seul endroit qui choisit une locale `Intl`.
//
// Remplace `'fr-FR'` écrit en dur : 132 appels dans 71 fichiers le 08/10/2026, laissés aux lots qui
// convertiront leurs écrans (liste dans `COORDINATION/CONTRACT/i18n-traduction.md`). Un écran
// converti formate par ici ; un `'fr-FR'` nouveau dans un fichier converti est refusé par
// `verifier-chaines-traduites.mjs`.
//
// Une valeur vide ou illisible rend « — », comme les helpers de `components/Liste.jsx`.

import { activeCurrency, intlLocale } from './core.js'

const EMPTY = '—'

function dateOf(value) {
  if (value === null || value === undefined || value === '') return null
  const date = value instanceof Date ? value : new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

function numberOf(value) {
  if (value === null || value === undefined || value === '') return null
  const number = typeof value === 'number' ? value : Number(value)
  return Number.isNaN(number) ? null : number
}

export function formatDate(value, options) {
  const date = dateOf(value)
  return date ? date.toLocaleDateString(intlLocale(), options) : EMPTY
}

export function formatDateTime(value, options) {
  const date = dateOf(value)
  return date ? date.toLocaleString(intlLocale(), options) : EMPTY
}

export function formatTime(value, options = { hour: '2-digit', minute: '2-digit' }) {
  const date = dateOf(value)
  return date ? date.toLocaleTimeString(intlLocale(), options) : EMPTY
}

export function formatNumber(value, options) {
  const number = numberOf(value)
  return number === null ? EMPTY : number.toLocaleString(intlLocale(), options)
}

/** Un montant dans la devise de l'établissement actif, sauf devise donnée. */
export function formatMoney(amount, currency = activeCurrency()) {
  const number = numberOf(amount)
  return number === null ? EMPTY : number.toLocaleString(intlLocale(), { style: 'currency', currency })
}

export function formatMoneyCents(cents, currency) {
  const number = numberOf(cents)
  return number === null ? EMPTY : formatMoney(number / 100, currency)
}
