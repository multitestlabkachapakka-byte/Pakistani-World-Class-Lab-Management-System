import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import type { Role } from '../services/session'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      name: 'dashboard',
      component: () => import('../views/DashboardView.vue'),
    },
    {
      path: '/patients',
      name: 'patients',
      component: () => import('../views/PatientsView.vue'),
    },
    {
      path: '/reports/create',
      name: 'create-report',
      component: () => import('../views/CreateReportView.vue'),
    },
    {
      path: '/catalog',
      name: 'catalog',
      component: () => import('../views/CatalogView.vue'),
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('../views/SettingsView.vue'),
    },
    {
      path: '/backup',
      name: 'backup',
      component: () => import('../views/BackupView.vue'),
      meta: { roles: ['admin'] },
    },
    {
      path: '/staff',
      name: 'staff',
      component: () => import('../views/UsersView.vue'),
      meta: { roles: ['admin'] },
    },
  ],
})

// Everything except /login needs a signed-in user.
router.beforeEach((to) => {
  const auth = useAuthStore()
  if (!to.meta.public && !auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }
  if (to.name === 'login' && auth.isAuthenticated) return { path: '/' }
  // Some screens are for certain roles only (the server enforces this too).
  const roles = to.meta.roles as Role[] | undefined
  if (roles && !auth.can(...roles)) return { path: '/' }
})

export default router
