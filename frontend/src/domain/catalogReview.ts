/**
 * Pure helpers for the catalog review screen: labels, age bands and the payloads sent to the API.
 * Nothing here contains clinical data - the limits always come from the pathologist's input.
 */
import { ADULT_MIN_DAYS } from './referenceRange'

export type Tone = 'ok' | 'warn'

export function statusInfo(status: string | null | undefined): { label: string; tone: Tone } {
  return status === 'VERIFIED'
    ? { label: 'Verified', tone: 'ok' }
    : { label: 'Pending review', tone: 'warn' }
}

export function sexLabel(gender: string | null | undefined): string {
  if (gender === 'male') return 'Male'
  if (gender === 'female') return 'Female'
  return 'Both'
}

export function formatAge(days: number): string {
  if (days < 60) return `${days} d`
  if (days < 730) return `${Math.round(days / 30.4375)} mo`
  return `${Math.round(days / 365.25)} y`
}

export function ageBandLabel(min: number | null | undefined, max: number | null | undefined): string {
  const lo = min ?? null
  const hi = max ?? null
  if (lo === null && hi === null) return 'All ages'
  if (hi === null) return `${formatAge(lo as number)} and above`
  if (lo === null) return `up to ${formatAge(hi)}`
  return `${formatAge(lo)} – ${formatAge(hi)}`
}

function trimmed(v: number | string): string {
  return String(Number(v))
}

export function limitsLabel(low: number | string | null | undefined, high: number | string | null | undefined): string {
  const hasLow = low !== null && low !== undefined && low !== ''
  const hasHigh = high !== null && high !== undefined && high !== ''
  if (hasLow && hasHigh) return `${trimmed(low as string)} – ${trimmed(high as string)}`
  if (hasHigh) return `≤ ${trimmed(high as string)}`
  if (hasLow) return `≥ ${trimmed(low as string)}`
  return '—'
}

// ---------- age band presets (bands only - the limits are never preset) ----------
export interface AgePreset {
  key: string
  label: string // age_label stored with the range
  text: string // shown in the form
  min: number | null
  max: number | null
}

export const AGE_PRESETS: AgePreset[] = [
  { key: 'all', label: 'All', text: 'All ages', min: null, max: null },
  { key: 'newborn', label: 'Newborn', text: 'Newborn (0 – 28 days)', min: 0, max: 28 },
  { key: 'infant', label: 'Infant', text: 'Infant (0 – 365 days)', min: 0, max: 365 },
  { key: 'child', label: 'Child', text: 'Child (366 days – under 18 years)', min: 366, max: ADULT_MIN_DAYS - 1 },
  { key: 'adult', label: 'Adult', text: 'Adult (18 years and above)', min: ADULT_MIN_DAYS, max: null },
]

export function detectPreset(min: number | null | undefined, max: number | null | undefined): string {
  const lo = min ?? null
  const hi = max ?? null
  return AGE_PRESETS.find((p) => p.min === lo && p.max === hi)?.key ?? 'custom'
}

// ---------- range form -> API payload ----------
export interface RangeForm {
  gender: 'male' | 'female' | 'both'
  band: string
  ageMinDays: string
  ageMaxDays: string
  low: string
  high: string
  criticalLow: string
  criticalHigh: string
  rangeText: string
  unit: string
  population: string
  methodology: string
  source: string
}

/** The fields of a stored range that the form edits (API shape). */
export interface RangeLike {
  gender: string | null
  age_min_days: number | null
  age_max_days: number | null
  low: number | string | null
  high: number | string | null
  critical_low: number | string | null
  critical_high: number | string | null
  range_text: string | null
  unit: string | null
  population: string | null
  methodology: string | null
  source: string | null
}

export function emptyForm(): RangeForm {
  return {
    gender: 'both', band: 'adult', ageMinDays: String(ADULT_MIN_DAYS), ageMaxDays: '',
    low: '', high: '', criticalLow: '', criticalHigh: '', rangeText: '', unit: '',
    population: '', methodology: '', source: '',
  }
}

const s = (v: unknown): string => (v === null || v === undefined ? '' : String(v))
const num = (v: string): number | null => (v.trim() === '' ? null : Number(v))

export function formFromRange(r: RangeLike): RangeForm {
  return {
    gender: (r.gender === 'male' || r.gender === 'female' ? r.gender : 'both'),
    band: detectPreset(r.age_min_days, r.age_max_days),
    ageMinDays: s(r.age_min_days),
    ageMaxDays: s(r.age_max_days),
    low: s(r.low === null ? '' : Number(r.low)),
    high: s(r.high === null ? '' : Number(r.high)),
    criticalLow: s(r.critical_low === null ? '' : Number(r.critical_low)),
    criticalHigh: s(r.critical_high === null ? '' : Number(r.critical_high)),
    rangeText: s(r.range_text),
    unit: s(r.unit),
    population: s(r.population),
    methodology: s(r.methodology),
    source: s(r.source),
  }
}

/** Payload for a NEW range: empty optional text is left out so the server fills in sensible defaults. */
export function createPayload(f: RangeForm): Record<string, unknown> {
  const preset = AGE_PRESETS.find((p) => p.key === f.band)
  const payload: Record<string, unknown> = {
    gender: f.gender,
    age_min_days: num(f.ageMinDays),
    age_max_days: num(f.ageMaxDays),
    low: num(f.low),
    high: num(f.high),
    critical_low: num(f.criticalLow),
    critical_high: num(f.criticalHigh),
    source: f.source.trim(),
  }
  if (preset) payload.age_label = preset.label
  for (const [key, value] of [
    ['range_text', f.rangeText], ['unit', f.unit], ['population', f.population], ['methodology', f.methodology],
  ] as const) {
    if (value.trim() !== '') payload[key] = value.trim()
  }
  return payload
}

/** Payload for an EDIT: only what actually changed, so unchanged text is never overwritten. */
export function updatePayload(original: RangeLike, f: RangeForm): Record<string, unknown> {
  const now = createPayload(f)
  const before = formFromRange(original)
  const was = createPayload(before)
  const out: Record<string, unknown> = {}

  for (const key of Object.keys(now)) {
    if (key === 'age_label') continue
    if (now[key] !== was[key]) out[key] = now[key]
  }
  // A cleared optional text field is not sent (the server keeps the old value).
  if (f.band !== before.band && now.age_label !== undefined) out.age_label = now.age_label
  return out
}
