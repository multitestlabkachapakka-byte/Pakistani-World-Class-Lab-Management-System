/**
 * Calculated results (indirect bilirubin, globulin, A/G ratio, LDL and VLDL by Friedewald ...).
 *
 * A formula lives in the catalog and refers to other parameters of the SAME test by short name,
 * e.g. {T.Bil} - {D.Bil}. There is no eval: a small parser handles numbers, {names}, + - * / ( ),
 * comparisons < <= > >=, IF(condition, a, b), ROUND(x, digits) and null.
 *
 * Safety rule: a value is produced only when every value it needs was actually entered.
 * Anything missing, non-numeric, a division by zero or a failed IF condition gives null = "no value".
 */
export class FormulaError extends Error {}

type Token =
  | { t: 'num'; v: number }
  | { t: 'var'; v: string }
  | { t: 'id'; v: string }
  | { t: 'op'; v: string }

type Node =
  | { k: 'num'; v: number }
  | { k: 'null' }
  | { k: 'var'; name: string }
  | { k: 'neg'; a: Node }
  | { k: 'bin'; op: string; a: Node; b: Node }
  | { k: 'if'; c: Node; a: Node; b: Node }
  | { k: 'round'; x: Node; n: Node }

function tokenize(src: string): Token[] {
  const out: Token[] = []
  let i = 0
  while (i < src.length) {
    const c = src[i]
    if (/\s/.test(c)) {
      i++
      continue
    }
    if (c === '{') {
      const end = src.indexOf('}', i)
      if (end < 0) throw new FormulaError('Missing "}"')
      const name = src.slice(i + 1, end).trim()
      if (!name) throw new FormulaError('Empty {}')
      out.push({ t: 'var', v: name })
      i = end + 1
      continue
    }
    const rest = src.slice(i)
    const num = /^(\d+(\.\d+)?|\.\d+)/.exec(rest)
    if (num) {
      out.push({ t: 'num', v: Number(num[0]) })
      i += num[0].length
      continue
    }
    const id = /^[A-Za-z_][A-Za-z0-9_]*/.exec(rest)
    if (id) {
      out.push({ t: 'id', v: id[0].toLowerCase() })
      i += id[0].length
      continue
    }
    const two = src.slice(i, i + 2)
    if (two === '<=' || two === '>=') {
      out.push({ t: 'op', v: two })
      i += 2
      continue
    }
    if ('+-*/(),<>'.includes(c)) {
      out.push({ t: 'op', v: c })
      i++
      continue
    }
    throw new FormulaError(`Unexpected character "${c}"`)
  }
  return out
}

class Parser {
  private pos = 0
  constructor(private readonly tokens: Token[]) {}

  parse(): Node {
    const node = this.comparison()
    if (this.pos < this.tokens.length) throw new FormulaError('Unexpected text after the formula')
    return node
  }

  private peek(): Token | undefined {
    return this.tokens[this.pos]
  }

  private isOp(...ops: string[]): boolean {
    const t = this.peek()
    return t !== undefined && t.t === 'op' && ops.includes(t.v)
  }

  private expectOp(op: string): void {
    if (!this.isOp(op)) throw new FormulaError(`Expected "${op}"`)
    this.pos++
  }

  private comparison(): Node {
    const left = this.additive()
    if (this.isOp('<', '<=', '>', '>=')) {
      const op = (this.tokens[this.pos++] as { v: string }).v
      return { k: 'bin', op, a: left, b: this.additive() }
    }
    return left
  }

  private additive(): Node {
    let node = this.term()
    while (this.isOp('+', '-')) {
      const op = (this.tokens[this.pos++] as { v: string }).v
      node = { k: 'bin', op, a: node, b: this.term() }
    }
    return node
  }

  private term(): Node {
    let node = this.factor()
    while (this.isOp('*', '/')) {
      const op = (this.tokens[this.pos++] as { v: string }).v
      node = { k: 'bin', op, a: node, b: this.factor() }
    }
    return node
  }

  private factor(): Node {
    const t = this.peek()
    if (!t) throw new FormulaError('The formula ends too early')
    this.pos++

    if (t.t === 'num') return { k: 'num', v: t.v }
    if (t.t === 'var') return { k: 'var', name: t.v }
    if (t.t === 'op' && t.v === '-') return { k: 'neg', a: this.factor() }
    if (t.t === 'op' && t.v === '(') {
      const inner = this.comparison()
      this.expectOp(')')
      return inner
    }
    if (t.t === 'id') {
      if (t.v === 'null') return { k: 'null' }
      if (t.v === 'if') {
        this.expectOp('(')
        const c = this.comparison()
        this.expectOp(',')
        const a = this.comparison()
        this.expectOp(',')
        const b = this.comparison()
        this.expectOp(')')
        return { k: 'if', c, a, b }
      }
      if (t.v === 'round') {
        this.expectOp('(')
        const x = this.comparison()
        this.expectOp(',')
        const n = this.comparison()
        this.expectOp(')')
        return { k: 'round', x, n }
      }
      throw new FormulaError(`Unknown function "${t.v}"`)
    }
    throw new FormulaError('Unexpected symbol')
  }
}

export function parseFormula(src: string): Node {
  return new Parser(tokenize(src)).parse()
}

type Value = number | boolean | null

function roundTo(x: number, digits: number): number {
  const f = 10 ** digits
  return Math.round((x + Math.sign(x) * Number.EPSILON) * f) / f
}

function evaluate(node: Node, vars: Map<string, number | null>): Value {
  switch (node.k) {
    case 'num':
      return node.v
    case 'null':
      return null
    case 'var':
      return vars.get(node.name.toLowerCase()) ?? null
    case 'neg': {
      const a = evaluate(node.a, vars)
      return typeof a === 'number' ? -a : null
    }
    case 'bin': {
      const a = evaluate(node.a, vars)
      const b = evaluate(node.b, vars)
      if (typeof a !== 'number' || typeof b !== 'number') return null
      switch (node.op) {
        case '+': return a + b
        case '-': return a - b
        case '*': return a * b
        case '/': return b === 0 ? null : a / b
        case '<': return a < b
        case '<=': return a <= b
        case '>': return a > b
        default: return a >= b
      }
    }
    case 'if': {
      const c = evaluate(node.c, vars)
      return typeof c === 'boolean' ? evaluate(c ? node.a : node.b, vars) : null
    }
    case 'round': {
      const x = evaluate(node.x, vars)
      const n = evaluate(node.n, vars)
      return typeof x === 'number' && typeof n === 'number' ? roundTo(x, n) : null
    }
  }
}

/** The number the formula gives, or null when it cannot be calculated from the values entered. */
export function evaluateFormula(src: string, vars: Record<string, number | null | undefined>): number | null {
  const map = new Map<string, number | null>()
  for (const [name, value] of Object.entries(vars)) map.set(name.trim().toLowerCase(), value ?? null)

  const result = evaluate(parseFormula(src), map)
  return typeof result === 'number' && Number.isFinite(result) ? result : null
}

/** Names of the parameters a formula needs (as written in the formula). */
export function formulaVariables(src: string): string[] {
  return [...new Set(tokenize(src).filter((t) => t.t === 'var').map((t) => t.v))]
}

// ---------------------------------------------------------------------------------------------

export interface CalcRow {
  id: string
  short_name_snapshot?: string | null
  calculation_formula_snapshot?: string | null
  result_value?: string | null
  was_auto_filled?: boolean | null
}

export interface CalcUpdate {
  id: string
  value: string
  auto: boolean
}

const NUMERIC = /^[-+]?(\d+\.?\d*|\.\d+)$/

function numeric(v: string | null | undefined): number | null {
  const s = (v ?? '').trim()
  return NUMERIC.test(s) ? Number(s) : null
}

/**
 * New values for the calculated rows of ONE test, given the values entered so far.
 * A row the user typed over by hand (isOverridden) is never touched. A value this function filled in
 * earlier is cleared again when its inputs are no longer all present.
 */
export function recalculate(rows: CalcRow[], isOverridden: (row: CalcRow) => boolean): CalcUpdate[] {
  const vars: Record<string, number | null> = {}
  for (const r of rows) {
    if (r.short_name_snapshot) vars[r.short_name_snapshot] = numeric(r.result_value)
  }

  const updates: CalcUpdate[] = []
  for (const r of rows) {
    const formula = r.calculation_formula_snapshot
    if (!formula || isOverridden(r)) continue

    let value: number | null = null
    try {
      value = evaluateFormula(formula, vars)
    } catch {
      value = null // a broken formula never produces a value
    }

    const current = (r.result_value ?? '').trim()
    if (value !== null) {
      const text = String(value)
      if (current !== text || !r.was_auto_filled) updates.push({ id: r.id, value: text, auto: true })
      if (r.short_name_snapshot) vars[r.short_name_snapshot] = value
    } else if (r.was_auto_filled && current !== '') {
      updates.push({ id: r.id, value: '', auto: false })
      if (r.short_name_snapshot) vars[r.short_name_snapshot] = null
    }
  }
  return updates
}
