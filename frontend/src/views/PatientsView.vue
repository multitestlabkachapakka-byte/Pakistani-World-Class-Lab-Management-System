<template>
  <div class="patients-page">
    <div class="page-header">
      <h2>Patients</h2>
      <button class="btn primary" @click="showForm = !showForm">
        {{ showForm ? 'Cancel' : '+ Register Patient' }}
      </button>
    </div>

    <div v-if="showForm" class="card form-card">
      <h3>New Patient</h3>
      <div class="form-grid">
        <label>Name * <input v-model="form.name" type="text" required /></label>
        <label>Guardian Name <input v-model="form.guardian_name" type="text" /></label>
        <label>
          Relation
          <select v-model="form.guardian_relation">
            <option value="">—</option>
            <option value="S/O">S/O</option>
            <option value="D/O">D/O</option>
            <option value="W/O">W/O</option>
            <option value="F/O">F/O</option>
          </select>
        </label>
        <label>
          Gender *
          <select v-model="form.gender" required>
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label>Age (years) <input v-model.number="form.age_years" type="number" min="0" max="150" /></label>
        <label>Phone * <input v-model="form.phone" type="text" placeholder="03XXXXXXXXX" required /></label>
        <label>CNIC <input v-model="form.cnic" type="text" placeholder="12345-1234567-1" /></label>
        <label>City <input v-model="form.city" type="text" /></label>
        <label class="full">Address * <input v-model="form.address" type="text" required /></label>
      </div>
      <div class="form-actions">
        <button class="btn primary" :disabled="saving" @click="register">
          {{ saving ? 'Saving…' : 'Save Patient' }}
        </button>
        <span v-if="formError" class="error">{{ formError }}</span>
      </div>
    </div>

    <div class="search-bar">
      <input
        v-model="searchQ"
        type="search"
        placeholder="Search by name, phone, MR No, CNIC, address…"
        @input="debouncedSearch"
      />
    </div>

    <div class="card">
      <table v-if="patients.length">
        <thead>
          <tr>
            <th>MR No</th>
            <th>Name</th>
            <th>Gender</th>
            <th>Age</th>
            <th>Phone</th>
            <th>Address</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in patients" :key="p.id">
            <td>{{ p.mr_number }}</td>
            <td>
              <strong>{{ p.name }}</strong>
              <div v-if="p.guardian_name" class="muted">{{ p.guardian_relation }} {{ p.guardian_name }}</div>
            </td>
            <td>{{ p.gender }}</td>
            <td>{{ p.age_years ?? '—' }}</td>
            <td>{{ p.phone }}</td>
            <td class="truncate">{{ p.address }}</td>
            <td>
              <router-link :to="`/reports/create?patient=${p.id}`" class="btn small">New Report</router-link>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">{{ loading ? 'Loading…' : 'No patients found.' }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { patientsApi } from '../services/api'

const patients = ref<any[]>([])
const searchQ = ref('')
const loading = ref(false)
const showForm = ref(false)
const saving = ref(false)
const formError = ref('')

const form = ref({
  name: '',
  guardian_name: '',
  guardian_relation: '',
  gender: 'male',
  age_years: null as number | null,
  phone: '',
  cnic: '',
  city: '',
  address: '',
})

let searchTimer: number | undefined

function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = window.setTimeout(loadPatients, 250)
}

async function loadPatients() {
  loading.value = true
  try {
    const res = await patientsApi.search(searchQ.value)
    patients.value = res.data || []
  } catch {
    patients.value = []
  } finally {
    loading.value = false
  }
}

async function register() {
  formError.value = ''
  if (!form.value.name || !form.value.phone || !form.value.address) {
    formError.value = 'Name, Phone and Address are required.'
    return
  }
  saving.value = true
  try {
    await patientsApi.create(form.value)
    showForm.value = false
    form.value = { name: '', guardian_name: '', guardian_relation: '', gender: 'male', age_years: null, phone: '', cnic: '', city: '', address: '' }
    await loadPatients()
  } catch (e: any) {
    formError.value = e?.response?.data?.message || 'Could not save patient.'
  } finally {
    saving.value = false
  }
}

onMounted(loadPatients)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
.form-grid label { display: flex; flex-direction: column; font-size: 0.85rem; gap: 0.25rem; }
.form-grid .full { grid-column: 1 / -1; }
input, select { padding: 0.45rem 0.6rem; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.95rem; }
.form-actions { margin-top: 1rem; display: flex; align-items: center; gap: 1rem; }
.search-bar { margin-bottom: 1rem; }
.search-bar input { width: 100%; max-width: 480px; padding: 0.55rem 0.75rem; }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
th, td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid #eee; }
th { font-weight: 600; color: #495057; }
.btn { border: none; border-radius: 4px; padding: 0.45rem 0.9rem; cursor: pointer; font-weight: 500; }
.btn.primary { background: #0d6efd; color: #fff; }
.btn.small { padding: 0.25rem 0.5rem; font-size: 0.8rem; background: #e9ecef; text-decoration: none; color: #212529; }
.muted { color: #6c757d; font-size: 0.85rem; }
.error { color: #dc3545; }
.truncate { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
