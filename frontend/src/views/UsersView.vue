<template>
  <div class="staff">
    <h2>Staff accounts</h2>
    <p class="muted">
      Create accounts for your staff and choose what each person may do. Only a <strong>pathologist</strong>
      can verify reference ranges.
    </p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>

    <table>
      <thead>
        <tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr>
      </thead>
      <tbody>
        <template v-for="u in users" :key="u.id">
          <tr :class="{ off: !u.is_active }">
            <td>{{ u.name }} <small v-if="u.id === auth.user?.id" class="muted">(you)</small></td>
            <td>{{ u.username }}</td>
            <td>
              <select :value="u.role" :disabled="busy" @change="changeRole(u, ($event.target as HTMLSelectElement).value)">
                <option v-for="r in ROLE_OPTIONS" :key="r.value" :value="r.value">{{ r.label }}</option>
              </select>
            </td>
            <td>{{ u.is_active ? 'Active' : 'Deactivated' }}</td>
            <td class="muted">{{ u.last_login_at ? u.last_login_at.slice(0, 10) : 'never' }}</td>
            <td class="actions">
              <button v-if="u.id !== auth.user?.id" class="btn small" :disabled="busy" @click="toggleActive(u)">
                {{ u.is_active ? 'Deactivate' : 'Activate' }}
              </button>
              <button class="btn small" @click="resetFor = resetFor === u.id ? null : u.id; newPassword = ''">
                Reset password
              </button>
            </td>
          </tr>
          <tr v-if="resetFor === u.id">
            <td colspan="6">
              <form class="inline" @submit.prevent="resetPassword(u)">
                <input v-model="newPassword" type="password" autocomplete="new-password" placeholder="New password (8+ characters, letters and numbers)" />
                <button class="btn primary small" :disabled="busy">Set new password</button>
                <span class="muted">The user is signed out on all devices.</span>
              </form>
            </td>
          </tr>
        </template>
        <tr v-if="!users.length"><td colspan="6" class="muted">No staff accounts loaded.</td></tr>
      </tbody>
    </table>

    <form class="card" @submit.prevent="create">
      <h3>Add a staff member</h3>
      <label>Full name <input v-model.trim="form.name" required maxlength="255" /></label>
      <label>Username <input v-model.trim="form.username" required autocapitalize="none" maxlength="50" /></label>
      <label>Role
        <select v-model="form.role">
          <option v-for="r in ROLE_OPTIONS" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        <small class="muted">{{ ROLE_OPTIONS.find((r) => r.value === form.role)?.description }}</small>
      </label>
      <label>Password
        <input v-model="form.password" type="password" autocomplete="new-password" required />
        <small class="muted">At least 8 characters, with letters and numbers.</small>
      </label>
      <p v-if="formError" class="error">{{ formError }}</p>
      <div><button class="btn primary" type="submit" :disabled="busy">Create account</button></div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { usersApi } from '../services/api'
import { useAuthStore } from '../stores/auth'
import { ROLE_OPTIONS, passwordProblem, usernameProblem, type StaffUser } from '../domain/staff'
import type { Role } from '../services/session'

const auth = useAuthStore()
const users = ref<StaffUser[]>([])
const busy = ref(false)
const error = ref('')
const notice = ref('')
const formError = ref('')
const resetFor = ref<string | null>(null)
const newPassword = ref('')
const form = reactive<{ name: string; username: string; password: string; role: Role }>({
  name: '', username: '', password: '', role: 'technician',
})

function message(e: any, fallback: string): string {
  const data = e?.response?.data
  const first = data?.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined
  return first || data?.message || fallback
}

async function load() {
  try {
    users.value = (await usersApi.list()).data
  } catch (e) {
    error.value = message(e, 'Could not load the staff list.')
  }
}

async function run(action: () => Promise<unknown>, done: string, failed: string): Promise<boolean> {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await action()
    await load()
    notice.value = done
    return true
  } catch (e) {
    error.value = message(e, failed)
    await load() // show the real state again (e.g. a role select that was refused)
    return false
  } finally {
    busy.value = false
  }
}

const changeRole = (u: StaffUser, role: string) =>
  run(() => usersApi.update(u.id, { role }), `${u.name} is now ${role}.`, 'Could not change the role.')

const toggleActive = (u: StaffUser) =>
  run(
    () => usersApi.update(u.id, { is_active: !u.is_active }),
    u.is_active ? `${u.name} was deactivated and signed out.` : `${u.name} was activated.`,
    'Could not change the account.',
  )

async function resetPassword(u: StaffUser) {
  const problem = passwordProblem(newPassword.value)
  if (problem) {
    error.value = problem
    return
  }
  if (await run(() => usersApi.update(u.id, { password: newPassword.value }), `Password changed for ${u.name}.`, 'Could not change the password.')) {
    resetFor.value = null
    newPassword.value = ''
  }
}

async function create() {
  formError.value = usernameProblem(form.username) || passwordProblem(form.password) || ''
  if (formError.value) return
  busy.value = true
  try {
    await usersApi.create({ name: form.name, username: form.username, password: form.password, role: form.role })
    notice.value = `Account created for ${form.name}.`
    error.value = ''
    Object.assign(form, { name: '', username: '', password: '', role: 'technician' })
    await load()
  } catch (e) {
    formError.value = message(e, 'Could not create the account.')
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<style scoped>
table { width: 100%; max-width: 62rem; border-collapse: collapse; background: #fff; font-size: 0.92rem; margin: 0.75rem 0 1.25rem; }
th, td { text-align: left; padding: 0.45rem 0.6rem; border-bottom: 1px solid #eee; }
tr.off td { color: #868e96; }
.actions { white-space: nowrap; }
.card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.1rem 1.25rem; max-width: 30rem; display: flex; flex-direction: column; gap: 0.7rem; }
.card h3 { margin: 0; }
label { display: flex; flex-direction: column; gap: 0.2rem; font-weight: 500; font-size: 0.9rem; }
input, select { padding: 0.4rem 0.55rem; border: 1px solid #ced4da; border-radius: 4px; font: inherit; }
.inline { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.inline input { flex: 1; min-width: 14rem; max-width: 24rem; }
.btn { border: none; border-radius: 4px; padding: 0.45rem 0.9rem; cursor: pointer; font-weight: 500; background: #e9ecef; margin-right: 0.3rem; }
.btn.primary { background: #0d6efd; color: #fff; }
.btn.small { padding: 0.2rem 0.55rem; font-size: 0.8rem; }
.btn:disabled { opacity: 0.55; cursor: default; }
.muted { color: #6c757d; font-size: 0.85rem; }
.error { color: #b02a37; }
.notice { color: #0f5132; }
</style>
