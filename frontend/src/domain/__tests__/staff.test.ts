import { describe, expect, it } from 'vitest'
import { ROLE_OPTIONS, passwordProblem, roleLabel, usernameProblem } from '../staff'

describe('staff helpers', () => {
  it('offers exactly the four roles the server knows', () => {
    expect(ROLE_OPTIONS.map((r) => r.value).sort()).toEqual(['admin', 'pathologist', 'receptionist', 'technician'])
  })

  it('labels roles', () => {
    expect(roleLabel('pathologist')).toBe('Pathologist')
    expect(roleLabel('something-else')).toBe('something-else')
  })

  it('checks passwords like the server does', () => {
    expect(passwordProblem('short1')).toMatch(/at least 8/)
    expect(passwordProblem('onlyletters')).toMatch(/letters and numbers/)
    expect(passwordProblem('12345678')).toMatch(/letters and numbers/)
    expect(passwordProblem('Good-pass1')).toBeNull()
  })

  it('checks usernames like the server does', () => {
    expect(usernameProblem('ab')).toMatch(/3 to 50/)
    expect(usernameProblem('has space')).toMatch(/letters, numbers/)
    expect(usernameProblem('dr_ahmed-1')).toBeNull()
  })
})
