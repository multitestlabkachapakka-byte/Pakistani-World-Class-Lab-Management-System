import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('../../services/api', () => ({
  authApi: { login: vi.fn(), setup: vi.fn(), logout: vi.fn(), setupStatus: vi.fn() },
  onUnauthorized: vi.fn(),
}))

import { authApi } from '../../services/api'
import { session, type SessionUser } from '../../services/session'
import { useAuthStore } from '../auth'

const tech: SessionUser = { id: '1', name: 'Tech One', username: 'tech1', role: 'technician' }

beforeEach(() => {
  session.clear()
  setActivePinia(createPinia())
  vi.resetAllMocks()
})

describe('auth store', () => {
  it('starts signed out', () => {
    const auth = useAuthStore()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.role).toBeNull()
  })

  it('login keeps the token and user on this device', async () => {
    vi.mocked(authApi.login).mockResolvedValue({ data: { token: 'tok', user: tech } })
    const auth = useAuthStore()

    await auth.login('tech1', 'secret-pass1')

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.role).toBe('technician')
    expect(session.getToken()).toBe('tok')
    expect(session.getUser()?.username).toBe('tech1')
  })

  it('a failed login leaves the store signed out and rethrows', async () => {
    vi.mocked(authApi.login).mockRejectedValue(new Error('bad credentials'))
    const auth = useAuthStore()

    await expect(auth.login('tech1', 'wrong')).rejects.toThrow('bad credentials')
    expect(auth.isAuthenticated).toBe(false)
    expect(session.getToken()).toBeNull()
  })

  it('restores a saved session when the app starts', () => {
    session.save('tok', tech)
    setActivePinia(createPinia())
    const auth = useAuthStore()
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user?.name).toBe('Tech One')
  })

  it('logout signs out locally even if the server cannot be reached', async () => {
    vi.mocked(authApi.login).mockResolvedValue({ data: { token: 'tok', user: tech } })
    vi.mocked(authApi.logout).mockRejectedValue(new Error('offline'))
    const auth = useAuthStore()
    await auth.login('tech1', 'secret-pass1')

    await auth.logout()

    expect(auth.isAuthenticated).toBe(false)
    expect(session.getToken()).toBeNull()
    expect(session.getUser()).toBeNull()
  })

  it('can() checks the role', async () => {
    vi.mocked(authApi.login).mockResolvedValue({ data: { token: 'tok', user: tech } })
    const auth = useAuthStore()
    expect(auth.can('technician')).toBe(false) // signed out
    await auth.login('tech1', 'secret-pass1')
    expect(auth.can('admin', 'technician')).toBe(true)
    expect(auth.can('admin')).toBe(false)
  })

  it('first-time setup signs the new administrator in', async () => {
    const admin: SessionUser = { ...tech, id: '2', name: 'Owner', username: 'owner', role: 'admin' }
    vi.mocked(authApi.setup).mockResolvedValue({ data: { token: 'tok2', user: admin } })
    const auth = useAuthStore()

    await auth.setup({ name: 'Owner', username: 'owner', password: 'Strong-pass1', password_confirmation: 'Strong-pass1' })

    expect(auth.role).toBe('admin')
    expect(session.getToken()).toBe('tok2')
  })
})
