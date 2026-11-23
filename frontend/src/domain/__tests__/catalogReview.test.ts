import { describe, expect, it } from 'vitest'
import {
  AGE_PRESETS, ageBandLabel, createPayload, detectPreset, emptyForm, formatAge, formFromRange,
  limitsLabel, statusInfo, updatePayload, type RangeLike,
} from '../catalogReview'
import { ADULT_MIN_DAYS } from '../referenceRange'

// All numbers are synthetic test values, not clinical reference data.
const stored: RangeLike = {
  gender: 'male', age_min_days: ADULT_MIN_DAYS, age_max_days: null,
  low: '10.0000', high: '20.0000', critical_low: null, critical_high: null,
  range_text: '10 – 20', unit: 'u', population: null, methodology: null, source: 'fixture',
}

describe('labels', () => {
  it('shows verified and pending status', () => {
    expect(statusInfo('VERIFIED')).toEqual({ label: 'Verified', tone: 'ok' })
    expect(statusInfo('VERIFY REQUIRED').tone).toBe('warn')
    expect(statusInfo(null).tone).toBe('warn')
  })

  it('formats ages and bands', () => {
    expect(formatAge(28)).toBe('28 d')
    expect(formatAge(365)).toBe('12 mo')
    expect(formatAge(ADULT_MIN_DAYS)).toBe('18 y')
    expect(ageBandLabel(null, null)).toBe('All ages')
    expect(ageBandLabel(ADULT_MIN_DAYS, null)).toBe('18 y and above')
    expect(ageBandLabel(0, 28)).toBe('0 d – 28 d')
    expect(ageBandLabel(null, 365)).toBe('up to 12 mo')
  })

  it('formats limits', () => {
    expect(limitsLabel('12.3000', '16.6000')).toBe('12.3 – 16.6')
    expect(limitsLabel(null, '4.0000')).toBe('≤ 4')
    expect(limitsLabel(30, null)).toBe('≥ 30')
    expect(limitsLabel(null, null)).toBe('—')
  })

  it('recognises the age presets', () => {
    for (const p of AGE_PRESETS) expect(detectPreset(p.min, p.max)).toBe(p.key)
    expect(detectPreset(5, 77)).toBe('custom')
  })
})

describe('range form payloads', () => {
  it('a new range omits empty optional text and clears empty numbers', () => {
    const f = { ...emptyForm(), gender: 'female' as const, high: '20', source: ' fixture ' }
    const p = createPayload(f)
    expect(p).toMatchObject({ gender: 'female', high: 20, low: null, source: 'fixture', age_label: 'Adult', age_min_days: ADULT_MIN_DAYS })
    expect(p).not.toHaveProperty('range_text')
    expect(p).not.toHaveProperty('unit')
  })

  it('an edit sends only what changed', () => {
    const f = formFromRange(stored)
    expect(updatePayload(stored, f)).toEqual({})

    f.high = '21'
    expect(updatePayload(stored, f)).toEqual({ high: 21 })

    f.source = 'new source'
    expect(updatePayload(stored, f)).toEqual({ high: 21, source: 'new source' })
  })

  it('changing the age band sends the new bounds and label', () => {
    const f = formFromRange(stored)
    const infant = AGE_PRESETS.find((p) => p.key === 'infant')!
    f.band = infant.key
    f.ageMinDays = String(infant.min)
    f.ageMaxDays = String(infant.max)
    expect(updatePayload(stored, f)).toEqual({ age_min_days: 0, age_max_days: 365, age_label: 'Infant' })
  })

  it('the form starts from the stored values without trailing zeros', () => {
    const f = formFromRange(stored)
    expect(f.low).toBe('10')
    expect(f.high).toBe('20')
    expect(f.band).toBe('adult')
  })
})
