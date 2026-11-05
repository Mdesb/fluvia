import assert from 'node:assert/strict'
import { test } from 'node:test'
import { decode, encode, fromMinorUnits, interpretAnswer, paymentRequest, toMinorUnits } from '../src/protocol.js'

test('a payment request is TAG + 3-digit length + value, CZ first', () => {
  assert.equal(
    paymentRequest({ amount: '12.50' }),
    'CZ0040300CJ012012345678901CA00201CE003978BA0010CD0010CB0041250',
  )
})

test('encode puts CZ first and refuses non ASCII values', () => {
  assert.equal(encode([['CA', '01'], ['CZ', '0300']]), 'CZ0040300CA00201')
  assert.throws(() => encode([['CA', 'é']]))
})

test('decode reads every field and says when the buffer is cut', () => {
  assert.deepEqual(decode('AE00210AC006123456'), { fields: { AE: '10', AC: '123456' }, complete: true })
  assert.equal(decode('AE00210AC0061234').complete, false)
  assert.throws(() => decode('A?00210'))
})

test('amounts go to and from cents without floating point', () => {
  assert.equal(toMinorUnits('0.10'), '10')
  assert.equal(toMinorUnits('45'), '4500')
  assert.equal(toMinorUnits('1234.5'), '123450')
  assert.throws(() => toMinorUnits('0.00'))
  assert.throws(() => toMinorUnits('-1.00'))
  assert.throws(() => toMinorUnits('1.234'))
  assert.equal(fromMinorUnits('4500'), '45.00')
  assert.equal(fromMinorUnits('5'), '0.05')
  assert.equal(fromMinorUnits('x'), null)
})

test('AE=10 is accepted, with the amount the terminal processed', () => {
  const r = interpretAnswer({ AE: '10', CB: '3000', CD: '0', CE: '978', AC: '987654', CC: '001', CI: '1' }, { amount: '45.00' })
  assert.equal(r.status, 'accepte')
  assert.equal(r.debitedAmount, '30.00')
  assert.equal(r.authorization, '987654')
  assert.equal(r.network, 'CB')
})

test('a refusal (AE=01) is never an acceptance', () => {
  assert.equal(interpretAnswer({ AE: '01', AF: '04' }, { amount: '45.00' }).status, 'refuse')
  assert.equal(interpretAnswer({ AE: '01', AF: '05' }, { amount: '45.00' }).status, 'refuse')
  assert.equal(interpretAnswer({ AE: '01' }, { amount: '45.00' }).status, 'refuse')
  assert.equal(interpretAnswer({ AE: '01', AF: '11' }, { amount: '45.00' }).status, 'annule')
  assert.equal(interpretAnswer({ AE: '01', AF: '08' }, { amount: '45.00' }).status, 'annule')
})

test('anything unreadable is "unknown" (timeout), never a refusal nor an acceptance', () => {
  assert.equal(interpretAnswer({}, { amount: '45.00' }).status, 'timeout')
  assert.equal(interpretAnswer({ AE: '11' }, { amount: '45.00' }).status, 'timeout')
  assert.equal(interpretAnswer({ AE: '10', CD: '1' }, { amount: '45.00' }).status, 'timeout', 'a credit is not our debit')
  assert.equal(interpretAnswer({ AE: '10', CE: '840' }, { amount: '45.00' }).status, 'timeout', 'another currency')
  assert.equal(interpretAnswer({ AE: '10', CB: 'abc' }, { amount: '45.00' }).status, 'timeout')
})
