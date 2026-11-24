<template>
  <div class="catalog">
    <h2>Test Catalog</h2>
    <p v-if="meta" class="muted">
      {{ meta.tests }} tests · {{ meta.ranges_verified }} of
      {{ meta.ranges_verified + meta.ranges_pending }} reference ranges verified by a pathologist ·
      {{ meta.ranges_pending }} pending review. "Pending review" means no pathologist has verified the
      range yet.
    </p>

    <div class="toolbar">
      <input v-model="q" type="search" placeholder="Search by name, code or department…" />
      <select v-model="dept">
        <option value="">All departments</option>
        <option v-for="d in departments" :key="d" :value="d">{{ d }}</option>
      </select>
    </div>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>

    <div class="layout">
      <table class="list">
        <thead>
          <tr><th>Code</th><th>Test</th><th>Ranges verified</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr
            v-for="t in filtered"
            :key="t.id"
            :class="{ active: t.id === selectedId }"
            @click="open(t.id)"
          >
            <td>{{ t.code }}</td>
            <td>
              {{ t.name }}
              <div class="muted">{{ t.department }}</div>
            </td>
            <td>{{ t.ranges_verified }} / {{ t.ranges_total }}</td>
            <td>
              <span class="badge" :class="statusInfo(t.verification_status).tone">
                {{ statusInfo(t.verification_status).label }}
              </span>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="4" class="muted">No tests match.</td>
          </tr>
        </tbody>
      </table>

      <section v-if="detail" class="detail">
        <h3>
          {{ detail.name }} <small class="muted">({{ detail.code }})</small>
        </h3>
        <p class="muted">
          {{ detail.department }}
          <span v-if="detail.sample_type"> · {{ detail.sample_type }}</span>
          <span v-if="detail.methodology"> · {{ detail.methodology }}</span>
        </p>
        <p v-if="detail.notes" class="note">{{ detail.notes }}</p>

        <div v-if="canVerify" class="actions">
          <button class="btn" :disabled="busy" @click="verifyAll">Verify all ranges of this test</button>
        </div>

        <div v-for="p in detail.parameters" :key="p.id" class="param">
          <h4>
            {{ p.name }} <small class="muted">{{ p.unit }}</small>
            <small v-if="p.calculation_formula" class="muted" title="Calculated from the other results of this test">
              calculated: {{ p.calculation_formula }}
            </small>
            <button v-if="canManage" class="btn small" @click="startAdd(p)">+ Add range</button>
          </h4>
          <div class="scroll">
            <table>
              <thead>
                <tr>
                  <th>Sex</th><th>Age</th><th>Reference</th><th>Unit</th><th>Source</th><th>Status</th><th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in p.reference_ranges || []" :key="r.id">
                  <td>{{ sexLabel(r.gender) }}</td>
                  <td>{{ ageBandLabel(r.age_min_days, r.age_max_days) }}</td>
                  <td>{{ r.range_text || limitsLabel(r.low, r.high) }}</td>
                  <td>{{ r.unit || p.unit || '—' }}</td>
                  <td class="src">{{ r.source || 'no source recorded' }}</td>
                  <td>
                    <span class="badge" :class="statusInfo(r.verification_status).tone">
                      {{ statusInfo(r.verification_status).label }}
                    </span>
                    <div v-if="r.verified_by" class="muted">
                      {{ r.verified_by }}, {{ (r.verified_at || '').slice(0, 10) }}
                    </div>
                  </td>
                  <td class="row-actions">
                    <button v-if="canVerify && r.verification_status !== 'VERIFIED'" class="btn small" :disabled="busy" @click="verify(r)">Verify</button>
                    <button v-if="canVerify && r.verification_status === 'VERIFIED'" class="btn small" :disabled="busy" @click="unverify(r)">Withdraw</button>
                    <button v-if="canManage" class="btn small" @click="startEdit(p, r)">Edit</button>
                    <button v-if="canManage" class="btn small danger" :disabled="busy" @click="remove(r)">Remove</button>
                  </td>
                </tr>
                <tr v-if="!(p.reference_ranges || []).length">
                  <td colspan="7" class="muted">
                    No reference range stored: reports show none for this parameter.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <form v-if="editing && editing.parameterId === p.id" class="range-form" @submit.prevent="saveRange">
            <h5>{{ editing.range ? 'Edit range' : 'New range' }}</h5>
            <label>Sex
              <select v-model="form.gender">
                <option value="both">Both</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
            </label>
            <label>Age band
              <select v-model="form.band" @change="applyBand">
                <option v-for="a in AGE_PRESETS" :key="a.key" :value="a.key">{{ a.text }}</option>
                <option value="custom">Custom (days)</option>
              </select>
            </label>
            <template v-if="form.band === 'custom'">
              <label>From (days) <input v-model="form.ageMinDays" inputmode="numeric" /></label>
              <label>To (days) <input v-model="form.ageMaxDays" inputmode="numeric" /></label>
            </template>
            <label>Low limit <input v-model="form.low" inputmode="decimal" /></label>
            <label>High limit <input v-model="form.high" inputmode="decimal" /></label>
            <label>Critical low <input v-model="form.criticalLow" inputmode="decimal" /></label>
            <label>Critical high <input v-model="form.criticalHigh" inputmode="decimal" /></label>
            <label class="wide">Reference text
              <input v-model="form.rangeText" placeholder="Leave empty to generate it from the limits" />
              <small class="muted">If you change a limit and leave this text as it is, the text is regenerated from the limits.</small>
            </label>
            <label>Unit <input v-model="form.unit" /></label>
            <label class="wide">Source (required)
              <input v-model="form.source" required placeholder="e.g. analyzer kit insert, lab SOP, textbook and page" />
            </label>
            <label>Population <input v-model="form.population" placeholder="optional" /></label>
            <label>Methodology <input v-model="form.methodology" placeholder="optional" /></label>
            <p class="muted wide">
              Saving a change marks this range "Pending review" until a pathologist verifies it again.
            </p>
            <p v-if="formError" class="error wide">{{ formError }}</p>
            <div class="wide">
              <button class="btn primary" type="submit" :disabled="busy">Save</button>
              <button class="btn" type="button" @click="editing = null">Cancel</button>
            </div>
          </form>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { catalogApi } from '../services/api'
import { useAuthStore } from '../stores/auth'
import {
  AGE_PRESETS, ageBandLabel, createPayload, emptyForm, formFromRange, limitsLabel, sexLabel,
  statusInfo, updatePayload, type RangeLike,
} from '../domain/catalogReview'

interface QueueRow {
  id: string
  code: string
  name: string
  department: string
  verification_status: string
  ranges_total: number
  ranges_verified: number
  ranges_pending: number
}

const auth = useAuthStore()
const canManage = computed(() => auth.can('admin', 'pathologist'))
const canVerify = computed(() => auth.can('pathologist'))

const queue = ref<QueueRow[]>([])
const meta = ref<{ tests: number; ranges_verified: number; ranges_pending: number } | null>(null)
const q = ref('')
const dept = ref('')
const selectedId = ref<string | null>(null)
const detail = ref<any>(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')

const editing = ref<{ parameterId: string; range: any | null } | null>(null)
const form = reactive(emptyForm())
const formError = ref('')

const departments = computed(() => [...new Set(queue.value.map((t) => t.department))].sort())
const filtered = computed(() => {
  const term = q.value.trim().toLowerCase()
  return queue.value.filter(
    (t) =>
      (!dept.value || t.department === dept.value) &&
      (!term || `${t.code} ${t.name} ${t.department}`.toLowerCase().includes(term)),
  )
})

function message(e: any, fallback: string): string {
  const data = e?.response?.data
  const first = data?.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined
  return first || data?.message || fallback
}

async function loadQueue() {
  try {
    const res = await catalogApi.reviewQueue()
    queue.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = message(e, 'Could not load the catalog.')
  }
}

async function open(id: string) {
  selectedId.value = id
  editing.value = null
  error.value = ''
  notice.value = ''
  try {
    detail.value = (await catalogApi.get(id)).data
  } catch (e) {
    error.value = message(e, 'Could not load this test.')
  }
}

async function refreshAll() {
  if (selectedId.value) detail.value = (await catalogApi.get(selectedId.value)).data
  await loadQueue()
}

async function run(action: () => Promise<unknown>, done: string, failed: string) {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await action()
    await refreshAll()
    notice.value = done
  } catch (e) {
    error.value = message(e, failed)
  } finally {
    busy.value = false
  }
}

const verify = (r: any) => run(() => catalogApi.verifyRange(r.id), 'Range verified.', 'Could not verify.')
const unverify = (r: any) =>
  run(() => catalogApi.unverifyRange(r.id), 'Verification withdrawn.', 'Could not withdraw the verification.')

function verifyAll() {
  if (!detail.value) return
  const ok = window.confirm(
    `Verify ALL reference ranges of "${detail.value.name}" as correct? This is recorded under your name.`,
  )
  if (ok) run(() => catalogApi.verifyTest(detail.value.id), 'All ranges of this test verified.', 'Could not verify.')
}

function remove(r: any) {
  if (window.confirm('Remove this range? New reports will no longer use it.')) {
    run(() => catalogApi.deleteRange(r.id), 'Range removed.', 'Could not remove the range.')
  }
}

function applyBand() {
  const preset = AGE_PRESETS.find((p) => p.key === form.band)
  if (preset) {
    form.ageMinDays = preset.min === null ? '' : String(preset.min)
    form.ageMaxDays = preset.max === null ? '' : String(preset.max)
  }
}

function startAdd(p: any) {
  Object.assign(form, emptyForm(), { unit: p.unit || '' })
  formError.value = ''
  editing.value = { parameterId: p.id, range: null }
}

function startEdit(p: any, r: RangeLike & { id: string }) {
  Object.assign(form, formFromRange(r))
  formError.value = ''
  editing.value = { parameterId: p.id, range: r }
}

async function saveRange() {
  if (!editing.value) return
  formError.value = ''
  busy.value = true
  try {
    if (editing.value.range) {
      const payload = updatePayload(editing.value.range, form)
      if (Object.keys(payload).length) await catalogApi.updateRange(editing.value.range.id, payload)
    } else {
      await catalogApi.createRange(editing.value.parameterId, createPayload(form))
    }
    editing.value = null
    await refreshAll()
    notice.value = 'Saved. The range is pending review until a pathologist verifies it.'
  } catch (e) {
    formError.value = message(e, 'Could not save the range.')
  } finally {
    busy.value = false
  }
}

onMounted(loadQueue)
</script>

<style scoped>
.toolbar { display: flex; gap: 0.5rem; margin: 0.75rem 0; flex-wrap: wrap; }
.toolbar input, .toolbar select, .range-form input, .range-form select {
  padding: 0.45rem 0.6rem; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.95rem;
}
.toolbar input { flex: 1; min-width: 12rem; max-width: 26rem; }
.layout { display: grid; grid-template-columns: minmax(18rem, 26rem) 1fr; gap: 1rem; align-items: start; }
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; background: #fff; }
th, td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #eee; vertical-align: top; }
.list tbody tr { cursor: pointer; }
.list tbody tr:hover, .list tbody tr.active { background: #f1f6ff; }
.detail { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 1rem 1.25rem; }
.detail h3 { margin: 0 0 0.25rem; }
.param { margin-top: 1.25rem; }
.param h4 { margin: 0 0 0.4rem; display: flex; align-items: center; gap: 0.5rem; }
.scroll { overflow-x: auto; }
.src { max-width: 14rem; color: #495057; font-size: 0.8rem; }
.note { background: #f8f9fa; border-left: 3px solid #adb5bd; padding: 0.4rem 0.6rem; font-size: 0.85rem; }
.badge { display: inline-block; padding: 0.05rem 0.5rem; border-radius: 999px; font-size: 0.75rem; white-space: nowrap; }
.badge.ok { background: #d1e7dd; color: #0f5132; }
.badge.warn { background: #fff3cd; color: #664d03; }
.row-actions { white-space: nowrap; }
.btn { border: none; border-radius: 4px; padding: 0.4rem 0.8rem; cursor: pointer; font-weight: 500; margin-right: 0.4rem; background: #e9ecef; }
.btn.primary { background: #0d6efd; color: #fff; }
.btn.small { padding: 0.15rem 0.5rem; font-size: 0.8rem; }
.btn.danger { background: #f8d7da; color: #842029; }
.btn:disabled { opacity: 0.55; cursor: default; }
.actions { margin: 0.5rem 0; }
.range-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.6rem; margin-top: 0.75rem; padding: 0.75rem; background: #f8f9fa; border-radius: 6px; }
.range-form label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.85rem; font-weight: 500; }
.range-form .wide { grid-column: 1 / -1; }
.range-form h5 { margin: 0; grid-column: 1 / -1; }
.muted { color: #6c757d; font-size: 0.85rem; }
.error { color: #b02a37; }
.notice { color: #0f5132; }
</style>
