import axios from 'axios'
import { session, type SessionUser } from './session'
import { brandingFromSettings, renderReportHtml, type LabSettings, type ReportData } from '../domain/reportHtml'

function deviceCode(): string {
  try {
    return (typeof localStorage !== 'undefined' && localStorage.getItem('deviceCode')) || 'P'
  } catch {
    return 'P'
  }
}

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    'Content-Type': 'application/json',
    'X-Device-Code': deviceCode(),
  },
})

// Every request carries the login token (if there is one).
api.interceptors.request.use((config) => {
  const token = session.getToken()
  if (token) config.headers.set('Authorization', `Bearer ${token}`)
  return config
})

// A 401 while we hold a token means it expired or was revoked: the app signs the user out.
let unauthorizedHandler: (() => void) | null = null
export function onUnauthorized(handler: () => void): void {
  unauthorizedHandler = handler
}

api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err?.response?.status === 401 && session.getToken()) unauthorizedHandler?.()
    console.error('API error', err?.response?.data || err.message)
    return Promise.reject(err)
  }
)

export default api

// ---------- Patients ----------
export const patientsApi = {
  search: (q: string, limit = 50) =>
    api.get('/patients', { params: { q, limit } }).then((r) => r.data),
  get: (id: string) => api.get(`/patients/${id}`).then((r) => r.data),
  create: (payload: Record<string, unknown>) =>
    api.post('/patients', payload).then((r) => r.data),
  update: (id: string, payload: Record<string, unknown>) =>
    api.put(`/patients/${id}`, payload).then((r) => r.data),
}

// ---------- Catalog ----------
export const catalogApi = {
  search: (q: string, department?: string) =>
    api.get('/catalog/search', { params: { q, department } }).then((r) => r.data),
  departments: () => api.get('/catalog/departments').then((r) => r.data),
  get: (id: string) => api.get(`/catalog/${id}`).then((r) => r.data),
  // Review status + range management (admin / pathologist) and verification (pathologist only)
  reviewQueue: () => api.get('/review/catalog').then((r) => r.data),
  createRange: (parameterId: string, payload: Record<string, unknown>) =>
    api.post(`/parameters/${parameterId}/ranges`, payload).then((r) => r.data),
  updateRange: (id: string, payload: Record<string, unknown>) =>
    api.put(`/ranges/${id}`, payload).then((r) => r.data),
  deleteRange: (id: string) => api.delete(`/ranges/${id}`).then((r) => r.data),
  verifyRange: (id: string) => api.post(`/ranges/${id}/verify`).then((r) => r.data),
  unverifyRange: (id: string) => api.post(`/ranges/${id}/unverify`).then((r) => r.data),
  verifyTest: (id: string) => api.post(`/catalog/${id}/verify`, { confirm: true }).then((r) => r.data),
}

// ---------- Reports ----------
export const reportsApi = {
  list: (params: Record<string, string | number> = {}) =>
    api.get('/reports', { params }).then((r) => r.data),
  get: (id: string) => api.get(`/reports/${id}`).then((r) => r.data),
  create: (payload: Record<string, unknown>) =>
    api.post('/reports', payload).then((r) => r.data),
  updateResults: (id: string, results: unknown[]) =>
    api.put(`/reports/${id}/results`, { results }).then((r) => r.data),
  finalize: (id: string, allowIncomplete = false) =>
    api
      .post(`/reports/${id}/finalize`, allowIncomplete ? { allow_incomplete: true } : {})
      .then((r) => r.data),
  // Reports are rendered in the app by ONE engine (works offline); only the data comes from the API.
  async openReport(id: string): Promise<void> {
    const win = window.open('', '_blank') // opened synchronously so popup blockers allow it
    try {
      const [report, settings] = await Promise.all([
        api.get(`/reports/${id}`).then((r) => r.data.data as ReportData),
        api.get('/settings').then((r) => r.data.data as Partial<LabSettings>),
      ])
      const html = renderReportHtml(report, brandingFromSettings(settings), { interactive: true })
      const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }))
      if (win) win.location.href = url
      else window.open(url, '_blank')
      setTimeout(() => URL.revokeObjectURL(url), 5 * 60 * 1000)
    } catch (e) {
      win?.close()
      throw e
    }
  },
}

// ---------- Dashboard ----------
export const dashboardApi = {
  summary: () => api.get('/dashboard').then((r) => r.data),
}

// ---------- Auth ----------
export interface LoginResult {
  data: { token: string; user: SessionUser }
}

export const authApi = {
  setupStatus: () =>
    api.get('/setup/status').then((r) => r.data as { data: { needs_setup: boolean } }),
  setup: (payload: {
    name: string
    username: string
    password: string
    password_confirmation: string
  }) => api.post('/setup', payload).then((r) => r.data as LoginResult),
  login: (username: string, password: string, deviceName = 'web') =>
    api
      .post('/auth/login', { username, password, device_name: deviceName })
      .then((r) => r.data as LoginResult),
  me: () => api.get('/auth/me').then((r) => r.data as { data: SessionUser }),
  logout: () => api.post('/auth/logout').then((r) => r.data),
  changePassword: (payload: {
    current_password: string
    password: string
    password_confirmation: string
  }) => api.put('/auth/password', payload).then((r) => r.data),
}

// ---------- Laboratory settings (letterhead, notes) ----------
export const settingsApi = {
  get: () => api.get('/settings').then((r) => r.data as { data: LabSettings }),
  update: (payload: Partial<LabSettings>) =>
    api.put('/settings', payload).then((r) => r.data as { data: LabSettings }),
}
