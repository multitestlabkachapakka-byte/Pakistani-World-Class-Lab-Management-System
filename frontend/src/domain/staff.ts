/** Staff account helpers. The server is the authority; these checks only give instant feedback. */
import type { Role } from '../services/session'

export interface StaffUser {
  id: string
  name: string
  username: string
  email?: string | null
  role: Role
  is_active: boolean
  last_login_at?: string | null
}

export const ROLE_OPTIONS: { value: Role; label: string; description: string }[] = [
  { value: 'admin', label: 'Administrator', description: 'Manages staff, settings and reference ranges. Cannot verify ranges.' },
  { value: 'pathologist', label: 'Pathologist', description: 'Enters results, finalizes reports and verifies reference ranges.' },
  { value: 'technician', label: 'Technician', description: 'Registers patients, enters results and finalizes reports.' },
  { value: 'receptionist', label: 'Receptionist', description: 'Registers patients and books tests only.' },
]

export function roleLabel(role: string): string {
  return ROLE_OPTIONS.find((r) => r.value === role)?.label ?? role
}

/** Same rule as the server: at least 8 characters, with letters and numbers. */
export function passwordProblem(password: string): string | null {
  if (password.length < 8) return 'The password must be at least 8 characters.'
  if (!/[A-Za-z]/.test(password) || !/\d/.test(password)) return 'The password needs both letters and numbers.'
  return null
}

/** Same rule as the server: 3 to 50 characters, letters, numbers, dash and underscore. */
export function usernameProblem(username: string): string | null {
  const u = username.trim()
  if (u.length < 3 || u.length > 50) return 'The username must be 3 to 50 characters.'
  if (!/^[A-Za-z0-9_-]+$/.test(u)) return 'Use only letters, numbers, dash and underscore in the username.'
  return null
}
