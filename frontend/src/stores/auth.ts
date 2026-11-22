import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authApi } from '../services/api'
import { session, type Role, type SessionUser } from '../services/session'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(session.getToken())
  const user = ref<SessionUser | null>(session.getUser())

  const isAuthenticated = computed(() => token.value !== null && user.value !== null)
  const role = computed<Role | null>(() => user.value?.role ?? null)

  function can(...roles: Role[]): boolean {
    return role.value !== null && roles.includes(role.value)
  }

  function start(newToken: string, newUser: SessionUser): void {
    session.save(newToken, newUser)
    token.value = newToken
    user.value = newUser
  }

  function clearSession(): void {
    session.clear()
    token.value = null
    user.value = null
  }

  async function login(username: string, password: string): Promise<void> {
    const res = await authApi.login(username, password)
    start(res.data.token, res.data.user)
  }

  async function setup(payload: {
    name: string
    username: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    const res = await authApi.setup(payload)
    start(res.data.token, res.data.user)
  }

  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } catch {
      // The server may be unreachable (offline) or the token already invalid: sign out locally anyway.
    } finally {
      clearSession()
    }
  }

  return { token, user, isAuthenticated, role, can, login, setup, logout, clearSession }
})
