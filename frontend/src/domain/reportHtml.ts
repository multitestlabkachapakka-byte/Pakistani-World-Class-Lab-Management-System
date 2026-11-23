/**
 * THE report renderer: one engine for preview, print, "Save as PDF" and (later) sharing.
 * It is a pure function (data in, HTML string out), so it works offline on every platform.
 *
 *  - A FINAL report is rendered from its frozen snapshot, never from live data.
 *  - A draft is clearly marked DRAFT (banner + watermark).
 *  - Every dynamic value is HTML-escaped; the logo is accepted only as an embedded image.
 *  - Reference ranges that no pathologist has verified are marked with a dagger and a footnote.
 */
export interface Signatory {
  name: string
  qualification?: string | null
  designation?: string | null
  pmdc_number?: string | null
}

export interface LabSettings {
  lab_name: string
  lab_address: string
  lab_phone: string
  lab_email: string
  header_note: string
  footer_note: string
  watermark_text: string
  show_unverified_notice: boolean
  logo_data_uri: string
  signatories: Signatory[]
}

export interface Branding {
  labName: string
  address: string
  phone: string
  email: string
  headerNote: string
  footerNote: string
  watermarkText: string
  logoDataUri: string
  signatories: Signatory[]
  showUnverifiedNotice: boolean
}

export interface PatientData {
  name: string
  mr_number: string
  gender?: string | null
  date_of_birth?: string | null
  age_years?: number | null
  age_months?: number | null
  age_days?: number | null
  phone?: string | null
  guardian_name?: string | null
  guardian_relation?: string | null
}

export interface ReportResultData {
  parameter_name_snapshot: string
  unit_snapshot?: string | null
  result_value?: string | null
  result_flag?: string | null
  is_abnormal?: boolean
  is_critical?: boolean
  print_selected?: boolean | null
  reference_range_text?: string | null
  reference_range_id?: string | null
  reference_verification_status?: string | null
}

export interface ReportTestData {
  test_name_snapshot: string
  department_snapshot?: string | null
  results?: ReportResultData[]
}

export interface ReportData {
  report_number: string
  status: string
  referred_by?: string | null
  collected_at?: string | null
  reported_at?: string | null
  patient?: PatientData | null
  doctor?: { name: string } | null
  report_tests?: ReportTestData[]
  snapshot?: {
    finalized_at?: string
    patient?: PatientData | null
    tests?: ReportTestData[]
    incomplete?: boolean
  } | null
}

export interface RenderOptions {
  /** Adds a "Print / Save as PDF" button (hidden when printing). */
  interactive?: boolean
  /** Used for the patient's age when the report has no collection date. */
  now?: Date
}

export function escapeHtml(value: unknown): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

const LOGO = /^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$/

export function brandingFromSettings(s: Partial<LabSettings> | null | undefined): Branding {
  return {
    labName: s?.lab_name ?? '',
    address: s?.lab_address ?? '',
    phone: s?.lab_phone ?? '',
    email: s?.lab_email ?? '',
    headerNote: s?.header_note ?? '',
    footerNote: s?.footer_note ?? '',
    watermarkText: s?.watermark_text ?? '',
    logoDataUri: s?.logo_data_uri ?? '',
    signatories: s?.signatories ?? [],
    showUnverifiedNotice: s?.show_unverified_notice ?? true,
  }
}

function dateOnly(iso: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null
}

/** "30 Y", "1 Y 4 M", "6 M 12 D", "10 D" - from the date of birth when known, else from the age fields. */
export function formatPatientAge(p: PatientData, asOf: Date): string {
  const dob = p.date_of_birth ? dateOnly(p.date_of_birth) : null
  if (dob) {
    let y = asOf.getUTCFullYear() - dob.getUTCFullYear()
    let m = asOf.getUTCMonth() - dob.getUTCMonth()
    let d = asOf.getUTCDate() - dob.getUTCDate()
    if (d < 0) {
      m -= 1
      d += new Date(Date.UTC(asOf.getUTCFullYear(), asOf.getUTCMonth(), 0)).getUTCDate()
    }
    if (m < 0) {
      y -= 1
      m += 12
    }
    if (y < 0) return '—'
    if (y >= 2) return `${y} Y`
    if (y >= 1) return m ? `${y} Y ${m} M` : `${y} Y`
    if (m >= 1) return d ? `${m} M ${d} D` : `${m} M`
    return `${d} D`
  }
  const parts: string[] = []
  if (p.age_years) parts.push(`${p.age_years} Y`)
  if (p.age_months) parts.push(`${p.age_months} M`)
  if (p.age_days) parts.push(`${p.age_days} D`)
  return parts.length ? parts.join(' ') : '—'
}

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Karachi',
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
  }).format(d)
}

const isPrinted = (r: ReportResultData): boolean => r.print_selected === undefined || r.print_selected === null || Boolean(r.print_selected)
const hasValue = (r: ReportResultData): boolean => (r.result_value ?? '').toString().trim() !== ''
const isUnverified = (r: ReportResultData): boolean =>
  Boolean(r.reference_range_id) && r.reference_verification_status !== 'VERIFIED'

const CSS = `
@page { size: A4; margin: 12mm; }
* { box-sizing: border-box; }
body { font-family: "Segoe UI", Arial, Helvetica, sans-serif; font-size: 10.5pt; color: #1b1b1b; margin: 0; }
.bar { text-align: right; padding: 8px; background: #f1f3f5; }
.bar button { padding: 6px 14px; font-size: 11pt; cursor: pointer; }
.page { padding: 6px 10px; }
.hdr { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #1f4e79; padding-bottom: 8px; margin-bottom: 10px; }
.hdr img { max-height: 72px; max-width: 170px; }
.lab { font-size: 17pt; font-weight: 700; color: #1f4e79; }
.meta { font-size: 9pt; color: #555; }
.note { font-size: 9pt; color: #333; margin-top: 2px; }
.draft { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; padding: 6px 10px; font-weight: 700; margin-bottom: 10px; }
h1 { font-size: 12pt; letter-spacing: 1px; text-align: center; margin: 4px 0 8px; }
.patient { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 18px; font-size: 10pt; border: 1px solid #ccd; padding: 8px 10px; margin-bottom: 10px; }
.patient b { display: inline-block; min-width: 88px; }
h3 { font-size: 10.5pt; margin: 14px 0 4px; text-transform: uppercase; border-bottom: 1px solid #999; padding-bottom: 2px; }
h3 small { font-weight: 400; color: #666; text-transform: none; margin-left: 6px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 10pt; page-break-inside: avoid; }
th, td { border: 1px solid #d5d9de; padding: 4px 6px; text-align: left; vertical-align: top; }
th { background: #eef2f7; }
td.val { font-weight: 600; }
tr.abn td.val, tr.abn td.flag { color: #b02a37; }
tr.crit td { background: #fdecee; }
td.flag { font-weight: 700; white-space: nowrap; }
.critlabel { font-size: 8pt; background: #b02a37; color: #fff; padding: 0 4px; border-radius: 3px; margin-left: 3px; }
.box { border: 1px solid #b02a37; background: #fdecee; color: #842029; padding: 6px 10px; margin: 10px 0; font-weight: 600; }
.foot { font-size: 8.5pt; color: #444; margin-top: 8px; }
.sigs { display: flex; gap: 30px; margin-top: 34px; flex-wrap: wrap; }
.sig { min-width: 170px; border-top: 1px solid #333; padding-top: 4px; font-size: 9pt; }
.end { text-align: center; font-size: 9pt; color: #555; margin-top: 14px; border-top: 1px solid #ccc; padding-top: 6px; }
.wm { position: fixed; top: 42%; left: 0; right: 0; text-align: center; font-size: 80pt; font-weight: 700; color: rgba(0,0,0,0.06); transform: rotate(-28deg); z-index: -1; pointer-events: none; }
@media print { .bar { display: none; } }
`

export function renderReportHtml(report: ReportData, branding: Branding, options: RenderOptions = {}): string {
  const final = report.status === 'final'
  const frozen = final && report.snapshot?.tests ? report.snapshot : null

  const patient: PatientData | null = frozen?.patient ?? report.patient ?? null
  const tests: ReportTestData[] = frozen?.tests ?? report.report_tests ?? []

  const asOf = new Date(report.collected_at || report.reported_at || options.now || Date.now())
  const reported = frozen?.finalized_at ?? report.reported_at

  const rowsByTest = tests.map((t) => ({ test: t, rows: (t.results ?? []).filter(isPrinted) }))
  const allRows = rowsByTest.flatMap((x) => x.rows)
  const critical = allRows.filter((r) => r.is_critical && hasValue(r)).map((r) => r.parameter_name_snapshot)
  const showMarks = branding.showUnverifiedNotice
  const anyUnverified = showMarks && allRows.some((r) => hasValue(r) && isUnverified(r))
  const incomplete = frozen ? Boolean(frozen.incomplete) : allRows.some((r) => !hasValue(r))

  const logo = LOGO.test(branding.logoDataUri) ? branding.logoDataUri : ''
  const watermark = final ? branding.watermarkText : 'DRAFT'

  const out: string[] = []
  out.push('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">')
  out.push(`<title>Report ${escapeHtml(report.report_number)}</title>`)
  out.push(`<style>${CSS}</style></head><body>`)
  if (options.interactive) {
    out.push('<div class="bar"><button onclick="window.print()">Print / Save as PDF</button></div>')
  }
  if (watermark) out.push(`<div class="wm">${escapeHtml(watermark)}</div>`)
  out.push('<div class="page">')

  // Header
  out.push('<div class="hdr">')
  if (logo) out.push(`<img src="${logo}" alt="">`)
  out.push('<div>')
  out.push(`<div class="lab">${escapeHtml(branding.labName || 'Laboratory')}</div>`)
  const contact = [branding.address, branding.phone, branding.email].filter(Boolean).map(escapeHtml).join(' &nbsp;|&nbsp; ')
  if (contact) out.push(`<div class="meta">${contact}</div>`)
  if (branding.headerNote) out.push(`<div class="note">${escapeHtml(branding.headerNote)}</div>`)
  out.push('</div></div>')

  if (!final) {
    out.push('<div class="draft">DRAFT: results are not finalized. Not valid for clinical use.</div>')
  }
  out.push('<h1>LABORATORY REPORT</h1>')

  // Patient
  const sex = patient?.gender ? patient.gender.charAt(0).toUpperCase() + patient.gender.slice(1) : '—'
  const guardian = patient?.guardian_name
    ? `${escapeHtml(patient.guardian_name)}${patient.guardian_relation ? ` (${escapeHtml(patient.guardian_relation)})` : ''}`
    : ''
  out.push('<div class="patient">')
  out.push(`<div><b>Name:</b> ${escapeHtml(patient?.name)}</div>`)
  out.push(`<div><b>Report No:</b> ${escapeHtml(report.report_number)}</div>`)
  out.push(`<div><b>MR No:</b> ${escapeHtml(patient?.mr_number)}</div>`)
  out.push(`<div><b>Collected:</b> ${escapeHtml(formatDateTime(report.collected_at))}</div>`)
  out.push(`<div><b>Age / Sex:</b> ${patient ? escapeHtml(formatPatientAge(patient, asOf)) : '—'} / ${escapeHtml(sex)}</div>`)
  out.push(`<div><b>Reported:</b> ${escapeHtml(final ? formatDateTime(reported) : '—')}</div>`)
  if (guardian) out.push(`<div><b>Guardian:</b> ${guardian}</div>`)
  out.push(`<div><b>Referred by:</b> ${escapeHtml(report.doctor?.name || report.referred_by || 'Self / Walk-in')}</div>`)
  if (patient?.phone) out.push(`<div><b>Phone:</b> ${escapeHtml(patient.phone)}</div>`)
  out.push('</div>')

  if (critical.length) {
    out.push(`<div class="box">CRITICAL RESULT: ${critical.map(escapeHtml).join(', ')}</div>`)
  }

  // Results
  for (const { test, rows } of rowsByTest) {
    if (!rows.length) continue
    const dept = test.department_snapshot ? `<small>${escapeHtml(test.department_snapshot)}</small>` : ''
    out.push(`<h3>${escapeHtml(test.test_name_snapshot)}${dept}</h3>`)
    out.push('<table><thead><tr><th>Parameter</th><th>Result</th><th>Unit</th><th>Reference range</th><th>Flag</th></tr></thead><tbody>')
    for (const r of rows) {
      const cls = [r.is_abnormal ? 'abn' : '', r.is_critical ? 'crit' : ''].filter(Boolean).join(' ')
      const ref = r.reference_range_text ? escapeHtml(r.reference_range_text) : '—'
      const mark = showMarks && hasValue(r) && isUnverified(r) ? '<sup>†</sup>' : ''
      const crit = r.is_critical ? '<span class="critlabel">CRITICAL</span>' : ''
      out.push(
        `<tr class="${cls}"><td>${escapeHtml(r.parameter_name_snapshot)}</td>` +
          `<td class="val">${hasValue(r) ? escapeHtml(r.result_value) : '—'}</td>` +
          `<td>${escapeHtml(r.unit_snapshot)}</td><td>${ref}${mark}</td>` +
          `<td class="flag">${escapeHtml(r.result_flag)}${crit}</td></tr>`,
      )
    }
    out.push('</tbody></table>')
  }

  if (anyUnverified) {
    out.push('<div class="foot">† Reference range pending verification by the laboratory pathologist.</div>')
  }
  if (incomplete) {
    out.push('<div class="foot">Some requested results were not available when this report was issued.</div>')
  }
  if (branding.footerNote) out.push(`<div class="foot">${escapeHtml(branding.footerNote)}</div>`)

  if (branding.signatories.length) {
    out.push('<div class="sigs">')
    for (const s of branding.signatories) {
      const lines = [s.qualification, s.designation, s.pmdc_number ? `PMDC: ${s.pmdc_number}` : '']
        .filter(Boolean)
        .map((l) => `<div>${escapeHtml(l)}</div>`)
        .join('')
      out.push(`<div class="sig"><strong>${escapeHtml(s.name)}</strong>${lines}</div>`)
    }
    out.push('</div>')
  }

  out.push('<div class="end">*** End of Report ***</div>')
  out.push('</div></body></html>')
  return out.join('\n')
}
