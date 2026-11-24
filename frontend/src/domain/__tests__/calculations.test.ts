import { readdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  FormulaError, evaluateFormula, formulaVariables, parseFormula, recalculate, type CalcRow,
} from '../calculations'

// All numbers in these tests are synthetic.
describe('formula language', () => {
  it('follows the usual arithmetic rules', () => {
    expect(evaluateFormula('2 + 3 * 4', {})).toBe(14)
    expect(evaluateFormula('(2 + 3) * 4', {})).toBe(20)
    expect(evaluateFormula('10 / 4', {})).toBe(2.5)
    expect(evaluateFormula('-3 + 5', {})).toBe(2)
    expect(evaluateFormula('{a} - {b}', { a: 5, b: 2 })).toBe(3)
  })

  it('looks up names ignoring case and spaces', () => {
    expect(evaluateFormula('{T.Bil} - {D.Bil}', { 't.bil': 1.2, ' D.BIL ': 0.3 })).toBeCloseTo(0.9)
  })

  it('rounds', () => {
    expect(evaluateFormula('ROUND(2.345, 2)', {})).toBe(2.35)
    expect(evaluateFormula('round(1.005, 2)', {})).toBe(1.01)
    expect(evaluateFormula('ROUND(120.4, 0)', {})).toBe(120)
    expect(evaluateFormula('ROUND({x} - {y}, 2)', { x: 1.2, y: 0.3 })).toBe(0.9)
  })

  it('supports comparisons and IF', () => {
    expect(evaluateFormula('IF({a} < 400, 1, 2)', { a: 399 })).toBe(1)
    expect(evaluateFormula('IF({a} < 400, 1, 2)', { a: 400 })).toBe(2)
    expect(evaluateFormula('IF({a} <= 400, 1, 2)', { a: 400 })).toBe(1)
    expect(evaluateFormula('IF({a} >= 5, 1, 2)', { a: 5 })).toBe(1)
    expect(evaluateFormula('IF({a} > 5, 1, null)', { a: 5 })).toBeNull()
  })

  it('gives no value when something is missing, zero or unknown', () => {
    expect(evaluateFormula('{a} + 1', {})).toBeNull()
    expect(evaluateFormula('{a} + 1', { a: null })).toBeNull()
    expect(evaluateFormula('1 / {a}', { a: 0 })).toBeNull()
    expect(evaluateFormula('IF({a} < 5, 1, 2)', {})).toBeNull() // unknown condition
    expect(evaluateFormula('ROUND({a}, 2)', {})).toBeNull()
    expect(evaluateFormula('null', {})).toBeNull()
    expect(evaluateFormula('1 < 2', {})).toBeNull() // not a number
  })

  it('rejects malformed formulas', () => {
    for (const bad of ['1 +', '{x', '{}', 'foo(1)', '1 @ 2', '(1 + 2', 'IF(1, 2)', '1 2', 'ROUND(1)']) {
      expect(() => parseFormula(bad), bad).toThrow(FormulaError)
    }
  })

  it('lists the parameters a formula needs', () => {
    expect(formulaVariables('IF({TG} < 400, ROUND({TC} - {HDL} - {TG} / 5, 0), null)')).toEqual(['TG', 'TC', 'HDL'])
  })
})

describe('the shipped formulas', () => {
  const calc = (formula: string, vars: Record<string, number>) => evaluateFormula(formula, vars)

  const catalog = readdirSync(fileURLToPath(new URL('../../../../backend/database/seeders/catalog/', import.meta.url)))
    .filter((f) => f.endsWith('.json'))
    .flatMap((f) => JSON.parse(readFileSync(fileURLToPath(new URL(`../../../../backend/database/seeders/catalog/${f}`, import.meta.url)), 'utf8')) as any[])

  const parameters = catalog.flatMap((t) => t.parameters.map((p: any) => ({ test: t.code, p, siblings: t.parameters })))
  const calculated = parameters.filter((x) => x.p.is_calculated)

  it('exist for every calculated parameter and only refer to parameters of the same test', () => {
    expect(calculated.length).toBeGreaterThanOrEqual(5)
    for (const { test, p, siblings } of calculated) {
      expect(p.calculation_formula, `${test} ${p.name}`).toBeTruthy()
      const names = siblings.filter((s: any) => s !== p).map((s: any) => String(s.short_name).toLowerCase())
      for (const v of formulaVariables(p.calculation_formula)) {
        expect(names, `${test} ${p.name} uses {${v}}`).toContain(v.toLowerCase())
      }
      // It evaluates without error for sample inputs (a number or "no value").
      const inputs = Object.fromEntries(formulaVariables(p.calculation_formula).map((v) => [v, 10]))
      expect(() => calc(p.calculation_formula, inputs)).not.toThrow()
    }
  })

  const formulaOf = (short: string): string =>
    calculated.find((x) => x.p.short_name === short)!.p.calculation_formula

  it('compute the textbook results', () => {
    expect(calc(formulaOf('I.Bil'), { 'T.Bil': 1.2, 'D.Bil': 0.3 })).toBe(0.9)
    expect(calc(formulaOf('Glob'), { TP: 7.2, Alb: 4.4 })).toBe(2.8)
    expect(calc(formulaOf('A/G'), { TP: 7.0, Alb: 4.0 })).toBe(1.33)
    expect(calc(formulaOf('LDL'), { TC: 200, HDL: 50, TG: 150 })).toBe(120)
    expect(calc(formulaOf('VLDL'), { TG: 150 })).toBe(30)
  })

  it('refuse to calculate where the method is not valid', () => {
    expect(calc(formulaOf('LDL'), { TC: 200, HDL: 50, TG: 400 })).toBeNull() // Friedewald needs TG below 400 mg/dL
    expect(calc(formulaOf('VLDL'), { TG: 450 })).toBeNull()
    expect(calc(formulaOf('A/G'), { TP: 4.0, Alb: 4.0 })).toBeNull() // no globulin
    expect(calc(formulaOf('LDL'), { TC: 200, HDL: 50 })).toBeNull() // triglycerides not entered
  })
})

describe('recalculate (one test at a time)', () => {
  const never = () => false
  const row = (id: string, short: string, value: string | null, formula?: string, auto = false): CalcRow => ({
    id, short_name_snapshot: short, result_value: value, calculation_formula_snapshot: formula ?? null, was_auto_filled: auto,
  })
  const set = (rows: CalcRow[]) => rows

  it('fills calculated rows once their inputs are entered', () => {
    const rows = set([row('a', 'T.Bil', '1.2'), row('b', 'D.Bil', '0.3'), row('c', 'I.Bil', '', 'ROUND({T.Bil} - {D.Bil}, 2)')])
    expect(recalculate(rows, never)).toEqual([{ id: 'c', value: '0.9', auto: true }])
  })

  it('does nothing while an input is missing or not a plain number', () => {
    expect(recalculate(set([row('a', 'T.Bil', '1.2'), row('b', 'D.Bil', ''), row('c', 'I.Bil', '', '{T.Bil} - {D.Bil}')]), never)).toEqual([])
    expect(recalculate(set([row('a', 'T.Bil', '>5'), row('b', 'D.Bil', '1'), row('c', 'I.Bil', '', '{T.Bil} - {D.Bil}')]), never)).toEqual([])
  })

  it('leaves a value typed by hand alone', () => {
    const rows = set([row('a', 'T.Bil', '1.2'), row('b', 'D.Bil', '0.3'), row('c', 'I.Bil', '5', '{T.Bil} - {D.Bil}')])
    expect(recalculate(rows, (r) => r.id === 'c')).toEqual([])
  })

  it('updates its own value and clears it when an input disappears', () => {
    const filled = set([row('a', 'T.Bil', '2'), row('b', 'D.Bil', '0.5'), row('c', 'I.Bil', '0.9', '{T.Bil} - {D.Bil}', true)])
    expect(recalculate(filled, never)).toEqual([{ id: 'c', value: '1.5', auto: true }])

    const cleared = set([row('a', 'T.Bil', ''), row('b', 'D.Bil', '0.5'), row('c', 'I.Bil', '0.9', '{T.Bil} - {D.Bil}', true)])
    expect(recalculate(cleared, never)).toEqual([{ id: 'c', value: '', auto: false }])
  })

  it('does not touch a typed value just because its inputs are missing', () => {
    const rows = set([row('a', 'T.Bil', ''), row('c', 'I.Bil', '0.9', '{T.Bil} - {D.Bil}', false)])
    expect(recalculate(rows, never)).toEqual([])
  })

  it('never writes a value from a broken formula', () => {
    expect(recalculate(set([row('a', 'X', '1'), row('c', 'Y', '', '{X} +')]), never)).toEqual([])
  })

  it('can chain calculated values within one pass', () => {
    const rows = set([
      row('a', 'A', '2'),
      row('b', 'B', '', '{A} * 3'),
      row('c', 'C', '', '{B} + 1'),
    ])
    expect(recalculate(rows, never)).toEqual([
      { id: 'b', value: '6', auto: true },
      { id: 'c', value: '7', auto: true },
    ])
  })
})
