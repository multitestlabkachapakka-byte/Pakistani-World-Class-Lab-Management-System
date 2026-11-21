/**
 * Result flagging for offline clients. Mirrors backend/app/Services/ResultFlagger.php:
 * numeric entries only, only against limits that are actually stored, never invents a limit.
 * Entries such as "<5" or "Positive" get no automatic flag.
 */
export type Flag = 'L' | 'H' | 'LL' | 'HH' | null

export interface FlagResult {
  flag: Flag
  isAbnormal: boolean
  isCritical: boolean
}

type Limit = number | string | null | undefined

const NUMERIC = /^[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/

function limit(v: Limit): number | null {
  if (v === null || v === undefined || v === '') return null
  const n = Number(v)
  return Number.isFinite(n) ? n : null
}

function result(flag: Flag): FlagResult {
  return { flag, isAbnormal: flag !== null, isCritical: flag === 'LL' || flag === 'HH' }
}

export function evaluateFlag(
  value: string | null | undefined,
  low: Limit,
  high: Limit,
  criticalLow: Limit = null,
  criticalHigh: Limit = null,
): FlagResult {
  const text = (value ?? '').trim()
  if (text === '' || !NUMERIC.test(text)) return result(null)

  const v = Number(text)
  const cl = limit(criticalLow)
  const ch = limit(criticalHigh)
  const l = limit(low)
  const h = limit(high)

  if (cl !== null && v < cl) return result('LL')
  if (ch !== null && v > ch) return result('HH')
  if (l !== null && v < l) return result('L')
  if (h !== null && v > h) return result('H')
  return result(null)
}
