import { describe, expect, it } from 'vitest'
import { evaluateFlag } from '../resultFlags'

// All numbers are synthetic test values, not clinical reference data.
describe('evaluateFlag', () => {
  it.each([
    ['inside', '15', 10, 20, null, null, null],
    ['below', '9', 10, 20, null, null, 'L'],
    ['above', '21', 10, 20, null, null, 'H'],
    ['on low limit', '10', 10, 20, null, null, null],
    ['on high limit', '20', 10, 20, null, null, null],
    ['critical low', '4', 10, 20, 5, 30, 'LL'],
    ['critical high', '31', 10, 20, 5, 30, 'HH'],
    ['on critical limit is only L', '5', 10, 20, 5, 30, 'L'],
    ['empty', '', 10, 20, null, null, null],
    ['null', null, 10, 20, null, null, null],
    ['text', 'Positive', 10, 20, null, null, null],
    ['qualifier', '<5', 10, 20, null, null, null],
    ['no limits stored', '999', null, null, null, null, null],
    ['only high limit', '25', null, 20, null, null, 'H'],
    ['only low limit', '1', 10, null, null, null, 'L'],
    ['decimal strings as stored', '12.5', '12.3000', '16.6000', null, null, null],
    ['surrounding spaces', ' 9 ', 10, 20, null, null, 'L'],
  ])('%s', (_name, value, low, high, critLow, critHigh, expected) => {
    const r = evaluateFlag(value as string | null, low, high, critLow, critHigh)
    expect(r.flag).toBe(expected)
    expect(r.isAbnormal).toBe(expected !== null)
    expect(r.isCritical).toBe(expected === 'LL' || expected === 'HH')
  })
})
