/**
 * Where the login token lives on this device: localStorage in the browser, memory elsewhere
 * (unit tests, private modes that block storage).
 */
export type Role = 'admin' | 'pathologist' | 'technician' | 'receptionist'

export interface SessionUser {
  id: string
  name: string
  username: string
  email?: string | null
  role: Role
  is_active?: boolean
}

const TOKEN_KEY = 'lims.token'
const USER_KEY = 'lims.user'
const memory = new Map<string, string>()

function browserStorage(): Storage | null {
  try {
    return typeof localStorage !== 'undefined' ? localStorage : null
  } catch {
    return null
  }
}

function read(key: string): string | null {
  const s = browserStorage()
  return s ? s.getItem(key) : (memory.get(key) ?? null)
}

function write(key: string, value: string): void {
  const s = browserStorage()
  if (s) s.setItem(key, value)
  else memory.set(key, value)
}

function remove(key: string): void {
  const s = browserStorage()
  if (s) s.removeItem(key)
  else memory.delete(key)
}

export const session = {
  getToken: (): string | null => read(TOKEN_KEY),

  getUser(): SessionUser | null {
    const raw = read(USER_KEY)
    if (!raw) return null
    try {
      return JSON.parse(raw) as SessionUser
    } catch {
      return null
    }
  },

  save(token: string, user: SessionUser): void {
    write(TOKEN_KEY, token)
    write(USER_KEY, JSON.stringify(user))
  },

  clear(): void {
    remove(TOKEN_KEY)
    remove(USER_KEY)
  },
}
