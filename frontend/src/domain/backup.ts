/** Small helpers for the backup screen. The server is the authority; these give instant feedback. */

export const RESTORE_PHRASE = 'RESTORE'
export const RESET_PHRASE = 'RESET ALL DATA'

/** Same rule as the server: at least 10 characters. */
export function passphraseProblem(passphrase: string): string | null {
  return passphrase.length < 10 ? 'The passphrase must be at least 10 characters.' : null
}

/** File name from a Content-Disposition header, or null. */
export function filenameFromDisposition(header: string | null | undefined): string | null {
  if (!header) return null
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header)
  if (!match) return null
  const name = decodeURIComponent(match[1].trim())
  return /^[A-Za-z0-9._-]+$/.test(name) ? name : null
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
