<template>
  <div class="login-wrap">
    <form class="card login" @submit.prevent="submit">
      <h2>{{ needsSetup ? 'First-time setup' : 'Sign in' }}</h2>
      <p v-if="needsSetup" class="muted">
        Create the administrator account. This only works on the server computer, and only once.
      </p>

      <label v-if="needsSetup">
        Full name
        <input v-model.trim="form.name" autocomplete="name" required />
      </label>
      <label>
        Username
        <input v-model.trim="form.username" autocomplete="username" autocapitalize="none" required />
      </label>
      <label>
        Password
        <input v-model="form.password" type="password" autocomplete="current-password" required />
      </label>
      <label v-if="needsSetup">
        Confirm password
        <input v-model="form.confirm" type="password" autocomplete="new-password" required />
      </label>
      <p v-if="needsSetup" class="muted">At least 8 characters, with letters and numbers.</p>

      <p v-if="error" class="error">{{ error }}</p>
      <button class="btn" type="submit" :disabled="busy">
        {{ busy ? 'Please wait…' : needsSetup ? 'Create administrator' : 'Sign in' }}
      </button>
    </form>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '../services/api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const needsSetup = ref(false)
const busy = ref(false)
const error = ref('')
const form = reactive({ name: '', username: '', password: '', confirm: '' })

// Only ever redirect inside the app (never to another site).
function safeRedirect(): string {
  const r = route.query.redirect
  return typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') ? r : '/'
}

function messageFrom(e: any): string {
  const data = e?.response?.data
  const first = data?.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined
  return first || data?.message || 'Could not reach the server. Check the connection and try again.'
}

onMounted(async () => {
  try {
    needsSetup.value = (await authApi.setupStatus()).data.needs_setup
  } catch {
    needsSetup.value = false
  }
})

async function submit() {
  error.value = ''
  busy.value = true
  try {
    if (needsSetup.value) {
      await auth.setup({
        name: form.name,
        username: form.username,
        password: form.password,
        password_confirmation: form.confirm,
      })
    } else {
      await auth.login(form.username, form.password)
    }
    await router.replace(safeRedirect())
  } catch (e) {
    error.value = messageFrom(e)
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.login-wrap {
  display: flex;
  justify-content: center;
  padding-top: 3rem;
}
.login {
  width: 100%;
  max-width: 24rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1.5rem;
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 0.5rem;
}
.login h2 {
  margin: 0;
}
.login label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-weight: 500;
}
.login input {
  padding: 0.55rem 0.7rem;
  border: 1px solid #ced4da;
  border-radius: 0.375rem;
  font-size: 1rem;
}
.muted {
  color: #6c757d;
  font-size: 0.9rem;
  margin: 0;
}
.error {
  color: #b02a37;
  margin: 0;
}
</style>
