<template>
  <div class="create-report">
    <h2>Create Report</h2>

    <div class="card">
      <h3>1. Patient</h3>
      <div v-if="!selectedPatient" class="patient-search">
        <input
          v-model="patientQ"
          type="search"
          placeholder="Type name, phone or MR No…"
          @input="searchPatients"
        />
        <ul v-if="patientHits.length" class="hits">
          <li v-for="p in patientHits" :key="p.id" @click="selectPatient(p)">
            <strong>{{ p.name }}</strong> — {{ p.mr_number }} — {{ p.phone }}
          </li>
        </ul>
        <p v-else-if="patientQ.length > 1" class="muted">No matches. Register the patient first.</p>
      </div>
      <div v-else class="selected-patient">
        <strong>{{ selectedPatient.name }}</strong>
        ({{ selectedPatient.mr_number }}) — {{ selectedPatient.gender }},
        Age {{ selectedPatient.age_years ?? '—' }}
        <button class="btn small" @click="selectedPatient = null">Change</button>
      </div>
    </div>

    <div class="card" v-if="selectedPatient">
      <h3>2. Select Tests</h3>
      <input
        v-model="testQ"
        type="search"
        placeholder="CBC, LFT, TFT, Sugar, Vit D…"
        @input="searchTests"
      />
      <ul v-if="testHits.length" class="hits">
        <li v-for="t in testHits" :key="t.id" @click="addTest(t)">
          <strong>{{ t.short_name || t.code }}</strong> — {{ t.name }}
          <span class="muted">({{ t.department }})</span>
        </li>
      </ul>
      <div v-if="selectedTests.length" class="selected-tests">
        <span v-for="t in selectedTests" :key="t.code" class="chip">
          {{ t.short_name || t.code }}
          <button @click="removeTest(t.code)">×</button>
        </span>
      </div>
      <button
        class="btn primary"
        style="margin-top: 0.75rem"
        :disabled="!selectedTests.length || creating"
        @click="createReport"
      >
        {{ creating ? 'Loading parameters…' : 'Load Tests & Enter Results' }}
      </button>
    </div>

    <div class="card" v-if="currentReport">
      <h3>3. Results — {{ currentReport.report_number }}</h3>
      <p class="muted">Status: {{ currentReport.status }}</p>

      <div v-for="rt in currentReport.report_tests || []" :key="rt.id" class="test-block">
        <h4>{{ rt.test_name_snapshot }}</h4>
        <table>
          <thead>
            <tr>
              <th>Parameter</th>
              <th>Result</th>
              <th>Unit</th>
              <th>Reference</th>
              <th>Flag</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rt.results || []" :key="r.id" :class="{ abnormal: r.is_abnormal }">
              <td>{{ r.parameter_name_snapshot }}</td>
              <td>
                <input
                  v-model="r.result_value"
                  type="text"
                  class="result-input"
                  :disabled="currentReport.status !== 'draft'"
                  @change="markDirty"
                />
              </td>
              <td>{{ r.unit_snapshot || '—' }}</td>
              <td>{{ r.reference_range_text || '—' }}</td>
              <td>
                <span v-if="r.result_flag" class="flag" :class="r.result_flag">{{ r.result_flag }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="form-actions" v-if="currentReport.status === 'draft'">
        <button class="btn" :disabled="savingResults" @click="saveResults">
          {{ savingResults ? 'Saving…' : 'Save Results' }}
        </button>
        <button class="btn primary" :disabled="savingResults" @click="finalize">
          Finalize Report
        </button>
        <span v-if="msg" class="muted">{{ msg }}</span>
      </div>
      <p v-else class="success">Report finalized. Snapshot locked.</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { patientsApi, catalogApi, reportsApi } from '../services/api'

const route = useRoute()

const patientQ = ref('')
const patientHits = ref<any[]>([])
const selectedPatient = ref<any>(null)

const testQ = ref('')
const testHits = ref<any[]>([])
const selectedTests = ref<any[]>([])

const currentReport = ref<any>(null)
const creating = ref(false)
const savingResults = ref(false)
const dirty = ref(false)
const msg = ref('')

let patientTimer: number | undefined
let testTimer: number | undefined

function searchPatients() {
  clearTimeout(patientTimer)
  patientTimer = window.setTimeout(async () => {
    if (patientQ.value.length < 2) {
      patientHits.value = []
      return
    }
    try {
      const res = await patientsApi.search(patientQ.value, 10)
      patientHits.value = res.data || []
    } catch {
      patientHits.value = []
    }
  }, 200)
}

function selectPatient(p: any) {
  selectedPatient.value = p
  patientHits.value = []
  patientQ.value = ''
}

function searchTests() {
  clearTimeout(testTimer)
  testTimer = window.setTimeout(async () => {
    if (testQ.value.length < 1) {
      testHits.value = []
      return
    }
    try {
      const res = await catalogApi.search(testQ.value)
      testHits.value = res.data || []
    } catch {
      testHits.value = []
    }
  }, 200)
}

function addTest(t: any) {
  if (selectedTests.value.some((x) => x.code === t.code)) return
  selectedTests.value.push(t)
  testQ.value = ''
  testHits.value = []
}

function removeTest(code: string) {
  selectedTests.value = selectedTests.value.filter((t) => t.code !== code)
}

async function createReport() {
  if (!selectedPatient.value || !selectedTests.value.length) return
  creating.value = true
  msg.value = ''
  try {
    const res = await reportsApi.create({
      patient_id: selectedPatient.value.id,
      test_codes: selectedTests.value.map((t) => t.code),
      referred_by: 'Self / Walk-in',
    })
    currentReport.value = res.data
  } catch (e: any) {
    msg.value = e?.response?.data?.message || 'Could not create report.'
  } finally {
    creating.value = false
  }
}

function markDirty() {
  dirty.value = true
}

async function saveResults() {
  if (!currentReport.value) return
  savingResults.value = true
  msg.value = ''
  try {
    const allResults: any[] = []
    for (const rt of currentReport.value.report_tests || []) {
      for (const r of rt.results || []) {
        allResults.push({ id: r.id, result_value: r.result_value })
      }
    }
    const res = await reportsApi.updateResults(currentReport.value.id, allResults)
    currentReport.value = res.data
    dirty.value = false
    msg.value = 'Results saved.'
  } catch (e: any) {
    msg.value = e?.response?.data?.message || 'Save failed.'
  } finally {
    savingResults.value = false
  }
}

async function finalize() {
  if (!currentReport.value) return
  if (dirty.value) await saveResults()
  savingResults.value = true
  try {
    const res = await reportsApi.finalize(currentReport.value.id)
    currentReport.value = res.data
    msg.value = 'Report finalized.'
  } catch (e: any) {
    msg.value = e?.response?.data?.message || 'Finalize failed.'
  } finally {
    savingResults.value = false
  }
}

onMounted(async () => {
  const pid = route.query.patient as string
  if (pid) {
    try {
      const res = await patientsApi.get(pid)
      selectedPatient.value = res.data
    } catch {
      // ignore
    }
  }
})
</script>

<style scoped>
.card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
input[type="search"], .result-input {
  padding: 0.45rem 0.6rem;
  border: 1px solid #ced4da;
  border-radius: 4px;
  width: 100%;
  max-width: 400px;
}
.hits {
  list-style: none;
  padding: 0;
  margin: 0.5rem 0 0;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  max-height: 200px;
  overflow-y: auto;
}
.hits li {
  padding: 0.5rem 0.75rem;
  cursor: pointer;
  border-bottom: 1px solid #f1f3f5;
}
.hits li:hover { background: #f8f9fa; }
.selected-patient { display: flex; align-items: center; gap: 0.75rem; }
.selected-tests { margin-top: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.4rem; }
.chip {
  background: #e7f1ff;
  color: #0d6efd;
  padding: 0.25rem 0.5rem;
  border-radius: 999px;
  font-size: 0.85rem;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}
.chip button { border: none; background: transparent; cursor: pointer; font-size: 1rem; line-height: 1; }
.test-block { margin-top: 1.25rem; }
.test-block h4 { margin: 0 0 0.5rem; font-size: 1rem; }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
th, td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #eee; }
.result-input { max-width: 100px; }
.abnormal { background: #fff5f5; }
.flag { font-weight: 700; }
.flag.H, .flag.HH { color: #dc3545; }
.flag.L, .flag.LL { color: #0d6efd; }
.btn { border: none; border-radius: 4px; padding: 0.45rem 0.9rem; cursor: pointer; font-weight: 500; margin-right: 0.5rem; }
.btn.primary { background: #0d6efd; color: #fff; }
.btn.small { padding: 0.2rem 0.5rem; font-size: 0.8rem; background: #e9ecef; }
.form-actions { margin-top: 1rem; }
.muted { color: #6c757d; font-size: 0.85rem; }
.success { color: #198754; font-weight: 500; }
</style>
