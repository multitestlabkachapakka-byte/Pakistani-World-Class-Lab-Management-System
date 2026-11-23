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
      <p>
        <button type="button" class="btn small" @click="openReport">Preview / Print</button>
      </p>
      <p v-if="criticalNames.length" class="alert critical">
        Critical result: {{ criticalNames.join(', ') }}. Inform the doctor / patient immediately.
      </p>
      <p v-if="pendingRangeCount" class="alert warn">
        {{ pendingRangeCount }} reference range(s) used here are pending pathologist review.
      </p>

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
                  :disabled="currentReport.status !== 'draft' || !canEnterResults"
                  @change="markDirty"
                />
              </td>
              <td>{{ r.unit_snapshot || '—' }}</td>
              <td>
                <span v-if="r.reference_range_text">{{ r.reference_range_text }}</span>
                <span v-else-if="!r.reference_range_id" class="muted">No reference range for this patient</span>
                <span v-else>—</span>
                <span
                  v-if="r.reference_range_id && r.reference_verification_status !== 'VERIFIED'"
                  class="badge warn"
                  title="This reference range has not been verified by a pathologist yet"
                >pending review</span>
              </td>
              <td>
                <span v-if="r.result_flag" class="flag" :class="r.result_flag">{{ r.result_flag }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="form-actions" v-if="currentReport.status === 'draft' && canEnterResults">
        <button class="btn" :disabled="savingResults" @click="saveResults">
          {{ savingResults ? 'Saving…' : 'Save Results' }}
        </button>
        <button class="btn primary" :disabled="savingResults" @click="finalize(false)">
          Finalize Report
        </button>
        <span v-if="msg" class="muted">{{ msg }}</span>
        <div v-if="missing.length" class="alert warn">
          <strong>These selected parameters have no result:</strong> {{ missing.join(', ') }}
          <div style="margin-top: 0.5rem">
            <button class="btn" :disabled="savingResults" @click="finalize(true)">Finalize anyway (incomplete)</button>
            <button class="btn" @click="missing = []">Go back and enter them</button>
          </div>
        </div>
      </div>
      <p v-else-if="currentReport.status === 'draft'" class="muted">
        Entering results and finalizing is done by a technician or pathologist.
      </p>
      <div v-else>
        <p class="success">Report finalized. Snapshot locked.</p>
        <p v-if="finalNotice.critical.length" class="alert critical">
          Critical result: {{ finalNotice.critical.join(', ') }}. Make sure the doctor / patient is informed.
        </p>
        <p v-if="finalNotice.unverified" class="alert warn">
          {{ finalNotice.unverified }} result(s) used a reference range that is pending pathologist review.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { patientsApi, catalogApi, reportsApi } from '../services/api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const auth = useAuthStore()
const canEnterResults = computed(() => auth.can('admin', 'pathologist', 'technician'))

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
const missing = ref<string[]>([])
const finalNotice = ref<{ critical: string[]; unverified: number }>({ critical: [], unverified: 0 })

const allResults = computed<any[]>(() =>
  (currentReport.value?.report_tests || []).flatMap((rt: any) => rt.results || []),
)
const criticalNames = computed(() =>
  allResults.value.filter((r) => r.is_critical).map((r) => r.parameter_name_snapshot),
)
const pendingRangeCount = computed(
  () =>
    allResults.value.filter((r) => r.reference_range_id && r.reference_verification_status !== 'VERIFIED')
      .length,
)

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

async function openReport() {
  if (!currentReport.value) return
  try {
    if (dirty.value) await saveResults()
    await reportsApi.openReport(currentReport.value.id)
  } catch {
    msg.value = 'Could not open the report. Please try again.'
  }
}

async function finalize(allowIncomplete: boolean) {
  if (!currentReport.value) return
  if (dirty.value) await saveResults()
  savingResults.value = true
  missing.value = []
  msg.value = ''
  try {
    const res = await reportsApi.finalize(currentReport.value.id, allowIncomplete === true)
    finalNotice.value = {
      critical: res.meta?.critical_results || [],
      unverified: res.meta?.unverified_reference_ranges || 0,
    }
    // The finalize response carries no tests: reload the full report so the results stay visible.
    currentReport.value = (await reportsApi.get(currentReport.value.id)).data
    msg.value = 'Report finalized.'
  } catch (e: any) {
    const data = e?.response?.data
    if (data?.missing_parameters?.length) {
      missing.value = data.missing_parameters
    } else {
      msg.value = data?.message || 'Finalize failed.'
    }
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
.alert { padding: 0.6rem 0.8rem; border-radius: 6px; margin: 0.75rem 0; font-size: 0.9rem; }
.alert.critical { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; font-weight: 600; }
.alert.warn { background: #fff3cd; color: #664d03; border: 1px solid #ffecb5; }
.badge { display: inline-block; margin-left: 0.4rem; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.7rem; }
.badge.warn { background: #fff3cd; color: #664d03; }
</style>
