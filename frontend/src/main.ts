import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { onUnauthorized } from './services/api'
import { useAuthStore } from './stores/auth'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)

// Token expired or revoked on the server: sign out and go to the login screen.
onUnauthorized(() => {
  useAuthStore(pinia).clearSession()
  router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
})
app.mount('#app')
