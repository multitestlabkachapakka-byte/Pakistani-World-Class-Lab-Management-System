/**
 * Picks the ONE stored reference range that applies to a patient (offline clients).
 * Mirrors backend/app/Services/ReferenceRangeResolver.php.
 *
 * Safety rule: if no stored range matches the patient's sex AND age, return null so the
 * report shows "no reference range" - never a range for another population.
 */
export const ADULT_MIN_DAYS = 6574 // 18 years (assumption; pathologist may set exact bounds per range)

export interface RangeCandidate {
  id: string
  gender: 'male' | 'female' | 'both' | null
  ageMinDays: number | null
  ageMaxDays: number | null
}

export function resolveRange<T extends RangeCandidate>(
  ranges: readonly T[],
  gender: string | null,
  ageDays: number | null,
): T | null {
  let best: T | null = null
  let bestGender = -1
  let bestSpan = 0

  for (const range of ranges) {
    const rangeGender = range.gender || 'both'
    if (rangeGender !== 'both' && rangeGender !== gender) continue

    const min = range.ageMinDays
    const max = range.ageMaxDays
    const bounded = min !== null || max !== null

    if (bounded) {
      if (ageDays === null) continue
      if (min !== null && ageDays < min) continue
      if (max !== null && ageDays > max) continue
    }

    // Better = sex-specific over "both", then the narrowest age band, then stable id order.
    const genderRank = rangeGender === 'both' ? 0 : 1
    const span = bounded ? (max ?? 36525) - (min ?? 0) : Number.POSITIVE_INFINITY

    const better =
      best === null ||
      genderRank > bestGender ||
      (genderRank === bestGender && span < bestSpan) ||
      (genderRank === bestGender && span === bestSpan && range.id < best.id)

    if (better) {
      best = range
      bestGender = genderRank
      bestSpan = span
    }
  }

  return best
}
