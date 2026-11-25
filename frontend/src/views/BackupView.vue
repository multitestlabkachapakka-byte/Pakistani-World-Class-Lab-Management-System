<template>
  <div class="backup">
    <h2>Backup &amp; Restore</h2>
    <p class="muted">
      A backup is one encrypted file with all your data. <strong>Keep the passphrase safe: without it the
      backup cannot be opened, and nobody can recover it.</strong> Make a backup regularly and keep the
      file on another device.
    </p>

    <!-- 1. backup -->
    <section class="card" data-testid="backup-form">
      <h3>1. Download a backup</h3>
      <form @submit.prevent="makeBackup">
        <label>Passphrase <input v-model="backup.passphrase" type="password" autocomplete="new-password" /></label>
        <label>Repeat passphrase <input v-model="backup.repeat" type="password" autocomplete="new-password" /></label>
        <p v-if="backup.error" class="error">{{ backup.error }}</p>
        <p v-if="backup.notice" class="notice">{{ backup.notice }}</p>
        <button class="btn primary" :disabled="busy">Download backup</button>
      </form>
    </section>

    <!-- 2. restore -->
    <section class="card" data-testid="restore-form">
      <h3>2. Restore from a backup</h3>
      <p class="warn">
        This <strong>replaces all current data</strong> with the contents of the backup, and signs everybody
        out. The current data is saved first as a safety copy on the server.
      </p>
      <form @submit.prevent="restore">
        <label>Backup file <input type="file" accept=".limsbk" @change="onFile" /></label>
        <label>Passphrase <input v-model="restoreForm.passphrase" type="password" autocomplete="off" /></label>
        <label>Your password <input v-model="restoreForm.password" type="password" autocomplete="current-password" /></label>
        <label>Type RESTORE to confirm <input v-model="restoreForm.confirm" autocomplete="off" /></label>
        <p v-if="restoreForm.error" class="error">{{ restoreForm.error }}</p>
        <button class="btn danger" :disabled="busy">Restore</button>
      </form>
    </section>

    <!-- 3. safety copies -->
    <section class="card">
      <h3>3. Safety copies kept on the server</h3>
      <p class="muted">Made automatically before every restore and factory reset. Open them with the passphrase you typed then.</p>
      <p v-if="copiesError" class="error">{{ copiesError }}</p>
      <table v-if="copies.length">
        <thead><tr><th>File</th><th>Size</th><th>Created</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in copies" :key="c.name">
            <td>{{ c.name }}</td>
            <td>{{ formatBytes(c.bytes) }}</td>
            <td>{{ c.created_at.slice(0, 16).replace('T', ' ') }}</td>
            <td><button class="btn small" type="button" @click="downloadCopy(c.name)">Download</button></td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No safety copies yet.</p>
    </section>

    <!-- 4. factory reset -->
    <section class="card danger-zone" data-testid="reset-form">
      <h3>4. Factory reset</h3>
      <p class="warn">
        <strong>Erases everything</strong>: patients, reports, staff accounts, settings and the audit trail. The
        test catalog goes back to its original state (all reference ranges "pending review"). A safety copy is
        saved first. Afterwards the first-time setup starts again.
      </p>
      <form @submit.prevent="reset">
        <label>Passphrase for the safety copy <input v-model="resetForm.passphrase" type="password" autocomplete="new-password" /></label>
        <label>Your password <input v-model="resetForm.password" type="password" autocomplete="current-password" /></label>
        <label>Type RESET ALL DATA to confirm <input v-model="resetForm.confirm" autocomplete="off" /></label>
        <p v-if="resetForm.error" class="error">{{ resetForm.error }}</p>
        <button class="btn danger" :disabled="busy">Erase all data</button>
      </form>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { backupApi } from '../services/api'
import { useAuthStore } from '../stores/auth'
import { RESET_PHRASE, RESTORE_PHRASE, formatBytes, passphraseProblem } from '../domain/backup'

const auth = useAuthStore()
const router = useRouter()
const busy = ref(false)

const backup = reactive({ passphrase: '', repeat: '', error: '', notice: '' })
const restoreForm = reactive({ passphrase: '', password: '', confirm: '', error: '', file: null as File | null })
const resetForm = reactive({ passphrase: '', password: '', confirm: '', error: '' })
const copies = ref<{ name: string; bytes: number; created_at: string }[]>([])
const copiesError = ref('')

function message(e: any, fallback: string): string {
  const data = e?.response?.data
  const first = data?.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined
  return first || data?.message || fallback
}

function save(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

async function loadCopies() {
  try {
    copies.value = (await backupApi.list()).data
    copiesError.value = ''
  } catch (e) {
    copiesError.value = message(e, 'Could not load the safety copies.')
  }
}

async function makeBackup() {
  backup.error = backup.notice = ''
  const problem = passphraseProblem(backup.passphrase)
  if (problem) return void (backup.error = problem)
  if (backup.passphrase !== backup.repeat) return void (backup.error = 'The two passphrases are not the same.')

  busy.value = true
  try {
    const { blob, filename } = await backupApi.create(backup.passphrase, backup.repeat)
    save(blob, filename)
    backup.notice = `Backup downloaded: ${filename}. Keep the file and the passphrase safe.`
    backup.passphrase = backup.repeat = ''
  } catch (e: any) {
    // The error body of a blob response is a Blob too: read it.
    let text = ''
    try { text = JSON.parse(await (e?.response?.data as Blob).text()).message } catch { /* not a blob */ }
    backup.error = text || message(e, 'Could not make the backup.')
  } finally {
    busy.value = false
  }
}

function onFile(e: Event) {
  restoreForm.file = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function restore() {
  restoreForm.error = ''
  if (!restoreForm.file) return void (restoreForm.error = 'Choose the backup file first.')
  if (restoreForm.confirm !== RESTORE_PHRASE) return void (restoreForm.error = `Type ${RESTORE_PHRASE} to confirm.`)

  busy.value = true
  try {
    await backupApi.restore(restoreForm.file, restoreForm.passphrase, restoreForm.password, restoreForm.confirm)
    // Everybody was signed out on the server: sign in again.
    auth.clearSession()
    await router.replace({ name: 'login' })
  } catch (e) {
    restoreForm.error = message(e, 'Could not restore the backup.')
  } finally {
    busy.value = false
  }
}

async function reset() {
  resetForm.error = ''
  const problem = passphraseProblem(resetForm.passphrase)
  if (problem) return void (resetForm.error = problem)
  if (resetForm.confirm !== RESET_PHRASE) return void (resetForm.error = `Type ${RESET_PHRASE} to confirm.`)

  busy.value = true
  try {
    await backupApi.factoryReset(resetForm.passphrase, resetForm.password, resetForm.confirm)
    auth.clearSession()
    await router.replace({ name: 'login' }) // no users left: the first-time setup starts
  } catch (e) {
    resetForm.error = message(e, 'Could not reset.')
  } finally {
    busy.value = false
  }
}

async function downloadCopy(name: string) {
  try {
    save(await backupApi.download(name), name)
  } catch (e) {
    copiesError.value = message(e, 'Could not download the file.')
  }
}

onMounted(loadCopies)
</script>

<style scoped>
.backup { max-width: 44rem; }
.card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 1rem 1.25rem; margin: 1rem 0; }
.card h3 { margin: 0 0 0.5rem; }
.danger-zone { border-color: #f1aeb5; }
form { display: flex; flex-direction: column; gap: 0.6rem; }
label { display: flex; flex-direction: column; gap: 0.2rem; font-weight: 500; font-size: 0.9rem; }
input { padding: 0.45rem 0.6rem; border: 1px solid #ced4da; border-radius: 4px; font: inherit; }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
th, td { text-align: left; padding: 0.35rem 0.5rem; border-bottom: 1px solid #eee; }
.btn { align-self: flex-start; border: none; border-radius: 4px; padding: 0.45rem 0.9rem; cursor: pointer; font-weight: 500; background: #e9ecef; }
.btn.primary { background: #0d6efd; color: #fff; }
.btn.danger { background: #b02a37; color: #fff; }
.btn.small { padding: 0.15rem 0.5rem; font-size: 0.8rem; }
.btn:disabled { opacity: 0.55; cursor: default; }
.muted { color: #6c757d; font-size: 0.9rem; }
.warn { background: #fff3cd; color: #664d03; padding: 0.5rem 0.7rem; border-radius: 6px; font-size: 0.9rem; }
.error { color: #b02a37; margin: 0; }
.notice { color: #0f5132; margin: 0; }
</style>
