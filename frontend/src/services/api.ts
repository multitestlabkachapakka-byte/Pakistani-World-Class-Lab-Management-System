import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    'Content-Type': 'application/json',
    'X-Device-Code': localStorage.getItem('deviceCode') || 'P',
  },
})

api.interceptors.response.use(
  (res) => res,
  (err) => {
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
  finalize: (id: string) =>
    api.post(`/reports/${id}/finalize`).then((r) => r.data),
}

// ---------- Dashboard ----------
export const dashboardApi = {
  summary: () => api.get('/dashboard').then((r) => r.data),
}
