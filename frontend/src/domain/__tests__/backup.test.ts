import { describe, expect, it } from 'vitest'
import { RESET_PHRASE, RESTORE_PHRASE, filenameFromDisposition, formatBytes, passphraseProblem } from '../backup'

describe('backup helpers', () => {
  it('requires a passphrase of at least 10 characters', () => {
    expect(passphraseProblem('short')).toMatch(/at least 10/)
    expect(passphraseProblem('1234567890')).toBeNull()
  })

  it('reads the file name the server suggests', () => {
    expect(filenameFromDisposition('attachment; filename="lims-backup-20261122-101500.limsbk"')).toBe('lims-backup-20261122-101500.limsbk')
    expect(filenameFromDisposition('attachment; filename=plain.limsbk')).toBe('plain.limsbk')
    expect(filenameFromDisposition(null)).toBeNull()
    expect(filenameFromDisposition('attachment')).toBeNull()
  })

  it('never accepts a file name with path characters', () => {
    expect(filenameFromDisposition('attachment; filename="../../etc/passwd"')).toBeNull()
    expect(filenameFromDisposition('attachment; filename="a/b.limsbk"')).toBeNull()
  })

  it('formats sizes', () => {
    expect(formatBytes(500)).toBe('500 B')
    expect(formatBytes(2048)).toBe('2.0 KB')
    expect(formatBytes(5 * 1024 * 1024)).toBe('5.0 MB')
  })

  it('uses the same confirmation phrases as the server', () => {
    expect(RESTORE_PHRASE).toBe('RESTORE')
    expect(RESET_PHRASE).toBe('RESET ALL DATA')
  })
})
