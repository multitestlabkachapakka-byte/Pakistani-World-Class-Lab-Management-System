<template>
  <div class="dashboard">
    <div class="page-header">
      <h2>Dashboard</h2>
      <button class="btn" :disabled="loading" @click="load">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="cards">
      <div class="card">
        <h3>Total Patients</h3>
        <p class="big">{{ data?.patients?.total ?? '—' }}</p>
        <p class="sub">Today {{ data?.patients?.today ?? 0 }} · Week {{ data?.patients?.week ?? 0 }}</p>
      </div>
      <div class="card">
        <h3>Total Reports</h3>
        <p class="big">{{ data?.reports?.total ?? '—' }}</p>
        <p class="sub">Today {{ data?.reports?.today ?? 0 }} · Yesterday {{ data?.reports?.yesterday ?? 0 }}</p>
      </div>
      <div class="card">
        <h3>Pending</h3>
        <p class="big warn">{{ data?.pending ?? '—' }}</p>
        <p class="sub">Draft reports</p>
      </div>
      <div class="card">
        <h3>Completed</h3>
        <p class="big ok">{{ data?.completed ?? '—' }}</p>
        <p class="sub">Finalized</p>
      </div>
      <div class="card">
        <h3>Abnormal</h3>
        <p class="big warn">{{ data?.abnormal_reports ?? '—' }}</p>
        <p class="sub">Reports with abnormal results</p>
      </div>
      <div class="card">
        <h3>This Month</h3>
        <p class="big">{{ data?.reports?.month ?? '—' }}</p>
        <p class="sub">Reports · Patients {{ data?.patients?.month ?? 0 }}</p>
      </div>
    </div>

    <div class="grid-2" v-if="data">
      <div class="card">
        <h3>Departments today</h3>
        <table v-if="data.departments_today?.length">
          <thead><tr><th>Department</th><th>Tests</th></tr></thead>
          <tbody>
            <tr v-for="d in data.departments_today" :key="d.department">
              <td>{{ d.department || '—' }}</td>
              <td>{{ d.total }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">No tests recorded today.</p>
      </div>

      <div class="card">
        <h3>Recent reports</h3>
        <table v-if="data.recent_reports?.length">
          <thead>
            <tr><th>Report No</th><th>Patient</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="r in data.recent_reports" :key="r.id">
              <td>{{ r.report_number }}</td>
              <td>{{ r.patient?.name || '—' }}</td>
              <td><span class="status" :class="r.status">{{ r.status }}</span></td>
              <td>
                <button type="button" class="btn small" @click="openPdf(r.id)">PDF</button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">No reports yet.</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { dashboardApi, reportsApi } from '../services/api'

const data = ref<any>(null)
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await dashboardApi.summary()
    data.value = res.data
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Could not load dashboard. Is the API running?'
    data.value = null
  } finally {
    loading.value = false
  }
}

async function openPdf(id: string) {
  try {
    await reportsApi.openPdf(id)
  } catch {
    error.value = 'Could not open the report. Please try again.'
  }
}

onMounted(load)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 1rem;
  margin-bottom: 1.25rem;
}
.card {
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 8px;
  padding: 1.1rem;
}
.card h3 { margin: 0 0 0.4rem; font-size: 0.85rem; color: #6c757d; font-weight: 600; }
.big { font-size: 1.75rem; font-weight: 700; margin: 0; }
.big.warn { color: #c05600; }
.big.ok { color: #198754; }
.sub { margin: 0.25rem 0 0; font-size: 0.8rem; color: #868e96; }
.grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
@media (max-width: 800px) { .grid-2 { grid-template-columns: 1fr; } }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
th, td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #eee; }
th { color: #495057; font-weight: 600; }
.status { text-transform: uppercase; font-size: 0.75rem; font-weight: 600; }
.status.draft { color: #c05600; }
.status.final { color: #198754; }
.btn { border: 1px solid #ced4da; background: #fff; border-radius: 4px; padding: 0.4rem 0.8rem; cursor: pointer; }
.btn.small { padding: 0.2rem 0.45rem; font-size: 0.8rem; text-decoration: none; color: #212529; background: #e9ecef; border: none; }
.muted { color: #6c757d; font-size: 0.9rem; }
.error { color: #dc3545; margin-bottom: 1rem; }
</style>
