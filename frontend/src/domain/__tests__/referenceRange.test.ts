import { describe, expect, it } from 'vitest'
import { ADULT_MIN_DAYS, resolveRange, type RangeCandidate } from '../referenceRange'

let n = 0
const range = (over: Partial<RangeCandidate> = {}): RangeCandidate => ({
  id: `id-${++n}`,
  gender: 'both',
  ageMinDays: null,
  ageMaxDays: null,
  ...over,
})

describe('resolveRange', () => {
  it('prefers a sex-specific range over "both"', () => {
    const both = range({ ageMinDays: ADULT_MIN_DAYS })
    const male = range({ gender: 'male', ageMinDays: ADULT_MIN_DAYS })
    expect(resolveRange([both, male], 'male', 10000)).toBe(male)
    expect(resolveRange([both, male], 'female', 10000)).toBe(both)
  })

  it('never returns an adult range for a child', () => {
    const adultOnly = range({ ageMinDays: ADULT_MIN_DAYS })
    expect(resolveRange([adultOnly], 'male', 1800)).toBeNull()
    expect(resolveRange([adultOnly], 'male', ADULT_MIN_DAYS)).toBe(adultOnly)
  })

  it('treats age bounds as inclusive', () => {
    const band = range({ ageMinDays: 100, ageMaxDays: 200 })
    expect(resolveRange([band], 'male', 99)).toBeNull()
    expect(resolveRange([band], 'male', 100)).toBe(band)
    expect(resolveRange([band], 'male', 200)).toBe(band)
    expect(resolveRange([band], 'male', 201)).toBeNull()
  })

  it('picks the narrowest matching age band', () => {
    const infant = range({ ageMinDays: 0, ageMaxDays: 365 })
    const child = range({ ageMinDays: 0, ageMaxDays: 6000 })
    expect(resolveRange([child, infant], 'male', 100)).toBe(infant)
    expect(resolveRange([infant, child], 'male', 1000)).toBe(child)
  })

  it('applies an unbounded range to every age, including unknown', () => {
    const all = range()
    expect(resolveRange([all], 'male', 5)).toBe(all)
    expect(resolveRange([all], 'male', null)).toBe(all)
    expect(resolveRange([all], null, 5)).toBe(all)
  })

  it('never matches an age-bounded range when the age is unknown', () => {
    expect(resolveRange([range({ ageMinDays: ADULT_MIN_DAYS })], 'male', null)).toBeNull()
  })

  it('gives "other"/unknown sex only ranges that apply to everyone', () => {
    const male = range({ gender: 'male' })
    const female = range({ gender: 'female' })
    const both = range()
    expect(resolveRange([male, female], 'other', 10000)).toBeNull()
    expect(resolveRange([male, female], null, 10000)).toBeNull()
    expect(resolveRange([male, female, both], 'other', 10000)).toBe(both)
  })

  it('breaks ties by id so the result never depends on row order', () => {
    const a = range({ id: 'a' })
    const b = range({ id: 'b' })
    expect(resolveRange([a, b], 'male', 100)).toBe(a)
    expect(resolveRange([b, a], 'male', 100)).toBe(a)
  })
})
