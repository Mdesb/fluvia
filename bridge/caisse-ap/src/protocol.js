// Caisse-AP over IP: message encoding and the meaning of the terminal's answer.
//
// SOURCES (consulted on 2026-11-05):
// - Association du paiement, « Protocole Caisse » page,
//   https://www.associationdupaiement.fr/protocoles/protocole-caisse/ : the protocol is published by
//   the AP, version 3.30 available since July 2026. The full specification is NOT downloadable from
//   the public site (members' area / terminal vendors). IP transport exists since version 3.x.
// - Two public implementations that agree with each other, used to cross-check the subset below:
//   akretion/caisse-ap-ip (client + terminal simulator, GPL-3.0) and OCA l10n-france
//   l10n_fr_pos_caisse_ap_ip (Odoo, used in production with Ingenico and Verifone terminals).
//   No code is copied from them: only the wire format, which is the protocol itself.
//
// Wire format: ASCII, a sequence of fields TAG (2 chars) + LENGTH (3 digits, zero padded) + VALUE,
// no separator. The message starts with CZ (protocol version). One request per TCP connection.
//
// Request tags used here:
//   CZ version ("0300")            CJ protocol identifier (12 digits, required, not interpreted)
//   CA cash register number (2)    CE currency, ISO 4217 numeric ("978" = EUR)
//   CD action: "0" debit, "1" credit (refund)    CB amount in minor units (cents)
//   BA "0" = answer at the end of the transaction (not the immediate acknowledgement "AE=11")
// Answer tags read here:
//   AE action status: "10" done, "01" not done, "11" request taken into account (immediate ack)
//   AF complement of a failure: "04" refused, "05" forbidden, "08" input time-out, "11" operator abort,
//      "13" unsupported currency, ...
//   CB amount actually processed, CD, CE, CA echoed; AC authorisation number; CC payment mode;
//   CI reading mode ("1" contact, "2" contactless); AA masked card number (NEVER kept by the bridge).

export const PROTOCOL_VERSION = '0300'
export const PROTOCOL_ID = '012345678901'
export const CURRENCIES = { EUR: '978' }

export const AF_LABELS = {
  '00': 'inconnu',
  '04': 'refusée',
  '05': 'interdite',
  '08': 'non effectuée : délai de saisie dépassé',
  '11': 'non effectuée : abandon opérateur',
  '13': 'devise non supportée',
}

const PAYMENT_MODES = {
  '1': 'CB', '001': 'CB', 'B': 'CB sans contact', '00B': 'CB sans contact',
  '2': 'American Express', '002': 'American Express', 'D': 'American Express sans contact', '00D': 'American Express sans contact',
  'V': 'ANCV', '00V': 'ANCV',
}

/** Encode an ordered list of [tag, value] pairs; CZ is always put first. */
export function encode(fields) {
  const list = Array.isArray(fields) ? fields : Object.entries(fields)
  const ordered = [...list.filter(([t]) => t === 'CZ'), ...list.filter(([t]) => t !== 'CZ')]
  return ordered.map(([tag, raw]) => {
    const value = String(raw)
    if (!/^[A-Z0-9]{2}$/.test(tag)) throw new Error(`Caisse-AP: invalid tag ${tag}`)
    if (value.length > 999) throw new Error(`Caisse-AP: value too long for ${tag}`)
    if (!/^[\x20-\x7E]*$/.test(value)) throw new Error(`Caisse-AP: non ASCII value for ${tag}`)
    return tag + String(value.length).padStart(3, '0') + value
  }).join('')
}

/**
 * Decode a message. Returns { fields, complete }: `complete` is false when the buffer stops in the
 * middle of a field (more bytes to come). Throws on a malformed message.
 */
export function decode(text) {
  const fields = {}
  let i = 0
  while (i < text.length) {
    if (text.length - i < 5) return { fields, complete: false }
    const tag = text.slice(i, i + 2)
    const len = text.slice(i + 2, i + 5)
    if (!/^[A-Z0-9]{2}$/.test(tag) || !/^\d{3}$/.test(len)) throw new Error(`Caisse-AP: malformed field at ${i}`)
    const size = Number(len)
    if (text.length - i - 5 < size) return { fields, complete: false }
    fields[tag] = text.slice(i + 5, i + 5 + size)
    i += 5 + size
  }
  return { fields, complete: true }
}

/** "12.50" -> "1250". Refuses anything that is not a positive amount with at most 2 decimals. */
export function toMinorUnits(amount) {
  const s = String(amount)
  if (!/^\d{1,10}(\.\d{1,2})?$/.test(s)) throw new Error('Montant illisible')
  const [units, dec = ''] = s.split('.')
  const cents = BigInt(units) * 100n + BigInt((dec + '00').slice(0, 2))
  if (cents <= 0n) throw new Error('Montant nul')
  return cents.toString().padStart(2, '0')
}

/** "1250" -> "12.50" */
export function fromMinorUnits(cents) {
  if (!/^\d{1,12}$/.test(String(cents))) return null
  const n = BigInt(cents)
  return `${n / 100n}.${String(n % 100n).padStart(2, '0')}`
}

export function paymentRequest({ amount, currency = 'EUR', register = '01' }) {
  const ce = CURRENCIES[currency]
  if (!ce) throw new Error(`Devise non prise en charge : ${currency}`)
  if (!/^\d{2}$/.test(register)) throw new Error('Numéro de caisse : deux chiffres')
  return encode([
    ['CZ', PROTOCOL_VERSION],
    ['CJ', PROTOCOL_ID],
    ['CA', register],
    ['CE', ce],
    ['BA', '0'],
    ['CD', '0'],
    ['CB', toMinorUnits(amount)],
  ])
}

/**
 * What the answer means for the sale. Status values are Fluvia's (StatutTPE):
 * - accepte : AE=10, debit, same currency; the amount is the one the terminal processed (CB).
 * - annule  : AE=01 with AF=11 (operator abort) or AF=08 (input time-out): the operation was NOT done.
 * - refuse  : any other AE=01.
 * - timeout : anything we cannot read with certainty (no AE, AE=11 acknowledgement only, wrong
 *             echo). The card MAY have been debited: Fluvia then asks the cashier to declare.
 */
export function interpretAnswer(fields, { amount, currency = 'EUR' }) {
  const ae = fields.AE
  const af = fields.AF ?? null
  const base = { ae: ae ?? null, af, reason: null }
  if (ae === '10') {
    if ((fields.CD ?? '0') !== '0' || (fields.CE && fields.CE !== CURRENCIES[currency])) {
      return { ...base, status: 'timeout', reason: 'Réponse du terminal incohérente (sens ou devise) : lisez le terminal.' }
    }
    const debited = fields.CB !== undefined ? fromMinorUnits(fields.CB) : amount
    if (debited === null) {
      return { ...base, status: 'timeout', reason: 'Montant rendu par le terminal illisible : lisez le terminal.' }
    }
    return {
      ...base,
      status: 'accepte',
      debitedAmount: debited,
      authorization: fields.AC || null,
      network: PAYMENT_MODES[fields.CC] ?? (fields.CC ? `mode ${fields.CC}` : null),
      contactless: fields.CI === '2',
    }
  }
  if (ae === '01') {
    const status = af === '11' || af === '08' ? 'annule' : 'refuse'
    return { ...base, status, reason: AF_LABELS[af] ?? (af ? `code ${af}` : null) }
  }
  return { ...base, status: 'timeout', reason: 'Le terminal n\'a pas rendu d\'issue lisible : lisez le terminal.' }
}
