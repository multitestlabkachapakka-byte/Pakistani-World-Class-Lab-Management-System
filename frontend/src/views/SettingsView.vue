<template>
  <div class="settings">
    <h2>Settings</h2>
    <p class="muted">Letterhead and notes printed on every report.</p>
    <p v-if="!canEdit" class="muted">Only an administrator can change these settings.</p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>

    <form v-if="form" class="card" @submit.prevent="save">
      <label>Laboratory name
        <input v-model="form.lab_name" :disabled="!canEdit" maxlength="120" />
      </label>
      <label>Address
        <input v-model="form.lab_address" :disabled="!canEdit" maxlength="255" />
      </label>
      <div class="row">
        <label>Phone <input v-model="form.lab_phone" :disabled="!canEdit" maxlength="50" /></label>
        <label>E-mail <input v-model="form.lab_email" type="email" :disabled="!canEdit" maxlength="120" /></label>
      </div>
      <label>Header note (under the lab name)
        <input v-model="form.header_note" :disabled="!canEdit" maxlength="255" />
      </label>
      <label>Footer note (printed under the results)
        <textarea v-model="form.footer_note" :disabled="!canEdit" maxlength="500" rows="3" />
      </label>
      <label>Watermark text on final reports (optional)
        <input v-model="form.watermark_text" :disabled="!canEdit" maxlength="40" />
      </label>
      <label class="check">
        <input v-model="form.show_unverified_notice" type="checkbox" :disabled="!canEdit" />
        Mark reference ranges that no pathologist has verified yet (recommended)
      </label>

      <div class="logo">
        <strong>Logo</strong>
        <img v-if="form.logo_data_uri" :src="form.logo_data_uri" alt="Logo preview" />
        <span v-else class="muted">No logo</span>
        <template v-if="canEdit">
          <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" @change="onLogo" />
          <button v-if="form.logo_data_uri" type="button" class="btn small" @click="form.logo_data_uri = ''">Remove logo</button>
        </template>
      </div>

      <h3>Signatories (printed at the end of the report)</h3>
      <div v-for="(s, i) in form.signatories" :key="i" class="sig">
        <input v-model="s.name" placeholder="Name" :disabled="!canEdit" maxlength="120" />
        <input v-model="s.qualification" placeholder="Qualification" :disabled="!canEdit" maxlength="160" />
        <input v-model="s.designation" placeholder="Designation" :disabled="!canEdit" maxlength="120" />
        <input v-model="s.pmdc_number" placeholder="PMDC no." :disabled="!canEdit" maxlength="40" />
        <button v-if="canEdit" type="button" class="btn small" @click="form.signatories.splice(i, 1)">Remove</button>
      </div>
      <button v-if="canEdit && form.signatories.length < 4" type="button" class="btn small" @click="addSignatory">
        + Add signatory
      </button>

      <p v-if="canEdit"><button class="btn primary" type="submit" :disabled="busy">Save settings</button></p>
    </form>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { settingsApi } from '../services/api'
import { useAuthStore } from '../stores/auth'
import type { LabSettings } from '../domain/reportHtml'

const auth = useAuthStore()
const canEdit = computed(() => auth.can('admin'))

const form = ref<LabSettings | null>(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')

function message(e: any, fallback: string): string {
  const data = e?.response?.data
  const first = data?.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined
  return first || data?.message || fallback
}

onMounted(async () => {
  try {
    form.value = (await settingsApi.get()).data
  } catch (e) {
    error.value = message(e, 'Could not load the settings.')
  }
})

function addSignatory() {
  form.value?.signatories.push({ name: '', qualification: '', designation: '', pmdc_number: '' })
}

function onLogo(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file || !form.value) return
  error.value = ''
  if (!/^image\/(png|jpeg|gif|webp)$/.test(file.type)) {
    error.value = 'The logo must be a PNG, JPEG, GIF or WebP image.'
    return
  }
  if (file.size > 250 * 1024) {
    error.value = 'The logo is too large (maximum 250 KB). Please use a smaller image.'
    return
  }
  const reader = new FileReader()
  reader.onload = () => {
    if (form.value) form.value.logo_data_uri = String(reader.result)
  }
  reader.readAsDataURL(file)
}

async function save() {
  if (!form.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const payload = {
      ...form.value,
      signatories: form.value.signatories.filter((s) => s.name.trim() !== ''),
    }
    form.value = (await settingsApi.update(payload)).data
    notice.value = 'Settings saved.'
  } catch (e) {
    error.value = message(e, 'Could not save the settings.')
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.25rem; max-width: 46rem; display: flex; flex-direction: column; gap: 0.8rem; }
label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 500; font-size: 0.9rem; }
input, textarea { padding: 0.45rem 0.6rem; border: 1px solid #ced4da; border-radius: 4px; font: inherit; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
.check { flex-direction: row; align-items: center; gap: 0.5rem; font-weight: 400; }
.check input { width: auto; }
.logo { display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; }
.logo img { max-height: 64px; max-width: 160px; border: 1px solid #dee2e6; padding: 2px; }
.sig { display: grid; grid-template-columns: repeat(4, 1fr) auto; gap: 0.4rem; }
h3 { margin: 0.5rem 0 0; font-size: 1rem; }
.btn { border: none; border-radius: 4px; padding: 0.45rem 0.9rem; cursor: pointer; font-weight: 500; background: #e9ecef; }
.btn.primary { background: #0d6efd; color: #fff; }
.btn.small { padding: 0.2rem 0.55rem; font-size: 0.8rem; align-self: flex-start; }
.muted { color: #6c757d; font-size: 0.9rem; }
.error { color: #b02a37; }
.notice { color: #0f5132; }
@media (max-width: 700px) { .row, .sig { grid-template-columns: 1fr; } }
</style>
