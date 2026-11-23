import { describe, expect, it } from 'vitest'
import {
  brandingFromSettings, escapeHtml, formatPatientAge, renderReportHtml,
  type ReportData, type ReportResultData,
} from '../reportHtml'

// All names and numbers below are synthetic test data.
const branding = brandingFromSettings({ lab_name: 'Test Lab', lab_address: 'Test Street', footer_note: 'Footer text' })

function result(over: Partial<ReportResultData> = {}): ReportResultData {
  return {
    parameter_name_snapshot: 'Param A', unit_snapshot: 'u', result_value: '5', result_flag: null,
    is_abnormal: false, is_critical: false, print_selected: true, reference_range_text: '1 – 10',
    reference_range_id: 'r1', reference_verification_status: 'VERIFIED', ...over,
  }
}

function report(over: Partial<ReportData> = {}, results: ReportResultData[] = [result()]): ReportData {
  return {
    report_number: '261121-P-R0001', status: 'final', collected_at: '2026-11-21T05:00:00Z',
    reported_at: '2026-11-21T07:00:00Z',
    patient: { name: 'Live Name', mr_number: 'MR-1', gender: 'male', date_of_birth: '1996-11-21' },
    report_tests: [{ test_name_snapshot: 'Test One', department_snapshot: 'Dept', results }],
    ...over,
  }
}

describe('escaping and safety', () => {
  it('escapes every dynamic value', () => {
    expect(escapeHtml(`<b>"x" & 'y'</b>`)).toBe('&lt;b&gt;&quot;x&quot; &amp; &#39;y&#39;&lt;/b&gt;')

    const html = renderReportHtml(
      report({ patient: { name: '<script>alert(1)</script>', mr_number: 'MR' } },
        [result({ parameter_name_snapshot: '<img src=x onerror=alert(1)>', result_value: '<b>9</b>' })]),
      branding,
    )
    expect(html).not.toContain('<script>alert(1)</script>')
    expect(html).not.toContain('<img src=x')
    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
    expect(html).toContain('&lt;b&gt;9&lt;/b&gt;')
  })

  it('accepts the logo only as an embedded image', () => {
    const png = 'data:image/png;base64,iVBORw0KGgo='
    expect(renderReportHtml(report(), brandingFromSettings({ logo_data_uri: png }))).toContain(`<img src="${png}"`)
    expect(renderReportHtml(report(), brandingFromSettings({ logo_data_uri: 'https://evil.example/logo.png' }))).not.toContain('<img')
    expect(renderReportHtml(report(), brandingFromSettings({ logo_data_uri: 'data:text/html;base64,PHNjcmlwdD4=' }))).not.toContain('<img')
  })

  it('contains no script unless the interactive print button is requested', () => {
    expect(renderReportHtml(report(), branding)).not.toContain('<script')
    expect(renderReportHtml(report(), branding)).not.toContain('onclick')
    expect(renderReportHtml(report(), branding, { interactive: true })).toContain('window.print()')
  })
})

describe('final versus draft', () => {
  it('renders a final report from its frozen snapshot, not from live data', () => {
    const final = report({
      patient: { name: 'Edited Later', mr_number: 'MR-1' },
      snapshot: {
        finalized_at: '2026-11-21T07:00:00Z',
        patient: { name: 'Name At Finalization', mr_number: 'MR-1', gender: 'male' },
        tests: [{ test_name_snapshot: 'Frozen Test', results: [result({ result_value: '7.7' })] }],
      },
    })
    const html = renderReportHtml(final, branding)
    expect(html).toContain('Name At Finalization')
    expect(html).toContain('Frozen Test')
    expect(html).toContain('7.7')
    expect(html).not.toContain('Edited Later')
    expect(html).not.toContain('Test One')
  })

  it('marks a draft as DRAFT and a final report not', () => {
    const draft = renderReportHtml(report({ status: 'draft' }), branding)
    expect(draft).toContain('DRAFT: results are not finalized')
    expect(draft).toContain('class="wm">DRAFT')

    const final = renderReportHtml(report(), branding)
    expect(final).not.toContain('DRAFT')
  })

  it('prints a configured watermark on final reports', () => {
    const html = renderReportHtml(report(), brandingFromSettings({ watermark_text: 'ORIGINAL' }))
    expect(html).toContain('class="wm">ORIGINAL')
  })
})

describe('results', () => {
  it('shows flags and a critical summary', () => {
    const html = renderReportHtml(
      report({}, [
        result({ parameter_name_snapshot: 'Low One', result_value: '0.5', result_flag: 'L', is_abnormal: true }),
        result({ parameter_name_snapshot: 'Crit One', result_value: '99', result_flag: 'HH', is_abnormal: true, is_critical: true }),
      ]),
      branding,
    )
    expect(html).toContain('<td class="flag">L</td>')
    expect(html).toContain('CRITICAL RESULT: Crit One')
    expect(html).toContain('class="critlabel"')
  })

  it('leaves out parameters that were not selected for printing', () => {
    const html = renderReportHtml(
      report({}, [result({ parameter_name_snapshot: 'Shown' }), result({ parameter_name_snapshot: 'Hidden', print_selected: false })]),
      branding,
    )
    expect(html).toContain('Shown')
    expect(html).not.toContain('Hidden')
  })

  it('shows a dash when there is no reference range', () => {
    const html = renderReportHtml(
      report({}, [result({ reference_range_text: null, reference_range_id: null, reference_verification_status: null })]),
      branding,
    )
    expect(html).toContain('<td>—</td>')
    expect(html).not.toContain('†')
  })

  it('flags reference ranges nobody has verified, unless switched off', () => {
    const pending = report({}, [result({ reference_verification_status: 'VERIFY REQUIRED' })])
    const html = renderReportHtml(pending, branding)
    expect(html).toContain('<sup>†</sup>')
    expect(html).toContain('pending verification by the laboratory pathologist')

    expect(renderReportHtml(pending, brandingFromSettings({ show_unverified_notice: false }))).not.toContain('†')
    expect(renderReportHtml(report(), branding)).not.toContain('†') // verified range: nothing to warn about
  })

  it('says so when results are missing', () => {
    const html = renderReportHtml(report({}, [result({ result_value: null })]), branding)
    expect(html).toContain('not available when this report was issued')
    expect(html).toContain('<td class="val">—</td>')
  })
})

describe('letterhead and signatories', () => {
  it('prints the lab details, footer note and signatories', () => {
    const html = renderReportHtml(
      report(),
      brandingFromSettings({
        lab_name: 'Test Lab', lab_phone: '0300', header_note: 'Header note', footer_note: 'Footer text',
        signatories: [{ name: 'Dr Example', qualification: 'MBBS', designation: 'Pathologist', pmdc_number: '12345' }],
      }),
    )
    for (const text of ['Test Lab', '0300', 'Header note', 'Footer text', 'Dr Example', 'MBBS', 'Pathologist', 'PMDC: 12345', 'End of Report']) {
      expect(html).toContain(text)
    }
  })

  it('falls back to a neutral title when no name is set', () => {
    expect(renderReportHtml(report(), brandingFromSettings({}))).toContain('>Laboratory<')
  })
})

describe('patient age', () => {
  const asOf = new Date(Date.UTC(2026, 10, 21))
  const age = (dob: string) => formatPatientAge({ name: 'x', mr_number: 'x', date_of_birth: dob }, asOf)

  it('is worked out from the date of birth', () => {
    expect(age('1996-11-21')).toBe('30 Y')
    expect(age('1996-11-22')).toBe('29 Y')
    expect(age('2025-07-09')).toBe('1 Y 4 M')
    expect(age('2026-05-09')).toBe('6 M 12 D')
    expect(age('2026-11-11')).toBe('10 D')
    expect(age('2026-11-21')).toBe('0 D')
  })

  it('falls back to the age fields and to a dash', () => {
    expect(formatPatientAge({ name: 'x', mr_number: 'x', age_years: 2, age_months: 3 }, asOf)).toBe('2 Y 3 M')
    expect(formatPatientAge({ name: 'x', mr_number: 'x', age_days: 12 }, asOf)).toBe('12 D')
    expect(formatPatientAge({ name: 'x', mr_number: 'x' }, asOf)).toBe('—')
  })
})
