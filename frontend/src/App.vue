<template>
  <div id="app-shell">
    <header v-if="auth.isAuthenticated" class="app-header">
      <h1>Pakistani Lab LIMS</h1>
      <nav>
        <router-link to="/">Dashboard</router-link>
        <router-link to="/patients">Patients</router-link>
        <router-link to="/reports/create">Create Report</router-link>
        <router-link to="/catalog">Catalog</router-link>
        <router-link to="/settings">Settings</router-link>
      </nav>
      <div class="who">
        <span>{{ auth.user?.name }} <small>({{ auth.role }})</small></span>
        <button class="link" @click="signOut">Sign out</button>
      </div>
    </header>
    <main>
      <router-view />
    </main>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function signOut() {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<style>
:root {
  --accent: #0d6efd;
  --bg: #f8f9fa;
  --text: #212529;
}
body {
  margin: 0;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  background: var(--bg);
  color: var(--text);
}
.app-header {
  background: #fff;
  border-bottom: 1px solid #dee2e6;
  padding: 0.75rem 1.5rem;
  display: flex;
  align-items: center;
  gap: 2rem;
}
.app-header h1 {
  font-size: 1.15rem;
  margin: 0;
}
.app-header nav {
  display: flex;
  gap: 1rem;
}
.app-header a {
  text-decoration: none;
  color: #495057;
  font-weight: 500;
}
.app-header a.router-link-active {
  color: var(--accent);
}
.who {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.9rem;
}
.who small {
  color: #6c757d;
}
.who .link {
  background: none;
  border: none;
  color: var(--accent);
  cursor: pointer;
  font: inherit;
}
main {
  padding: 1.5rem;
  max-width: 1400px;
  margin: 0 auto;
}
</style>
