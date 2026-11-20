<?php

namespace App\Services;

use App\Models\Report;
use App\Models\LabSetting;

/**
 * Single PDF engine for preview / download / print / share.
 * Phase 1: returns clean HTML that can be printed or converted by DomPDF / browser.
 * Later: swap render() body to DomPDF or a dedicated engine without changing callers.
 */
class ReportPdfService
{
    public function render(Report $report): string
    {
        $report->load(['patient', 'doctor', 'reportTests.results']);

        $labName = $this->setting('lab_name', 'Pakistani Diagnostic Laboratory');
        $labAddress = $this->setting('lab_address', '');
        $labPhone = $this->setting('lab_phone', '');
        $disclaimer = $this->setting(
            'disclaimer',
            'Computer-generated report. Results relate to the sample received. Clinical correlation is advised. Not valid for medicolegal purposes.'
        );

        $patient = $report->patient;
        $html = [];

        $html[] = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html[] = '<title>Report ' . e($report->report_number) . '</title>';
        $html[] = '<style>
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; color: #222; margin: 20px; }
            .header { border-bottom: 2px solid #0d6efd; padding-bottom: 8px; margin-bottom: 12px; }
            .lab-name { font-size: 16pt; font-weight: bold; color: #0d6efd; }
            .meta { font-size: 9pt; color: #555; }
            .patient-block { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 14px; font-size: 10pt; }
            .patient-block strong { display: inline-block; min-width: 90px; }
            h3 { font-size: 11pt; margin: 14px 0 6px; text-transform: uppercase; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10pt; }
            th, td { border: 1px solid #ddd; padding: 4px 6px; text-align: left; }
            th { background: #f5f7fa; }
            .abnormal { font-weight: bold; color: #c00; }
            .flag { font-weight: bold; }
            .footer { margin-top: 24px; font-size: 8pt; color: #666; border-top: 1px solid #ccc; padding-top: 8px; }
            .signatory { margin-top: 30px; }
        </style></head><body>';

        // Header
        $html[] = '<div class="header">';
        $html[] = '<div class="lab-name">' . e($labName) . '</div>';
        if ($labAddress || $labPhone) {
            $html[] = '<div class="meta">' . e($labAddress) . ($labPhone ? ' | ' . e($labPhone) : '') . '</div>';
        }
        $html[] = '</div>';

        // Patient / Report block
        $html[] = '<div class="patient-block">';
        $html[] = '<div><strong>Name:</strong> ' . e($patient?->name ?? '') . '</div>';
        $html[] = '<div><strong>Report No:</strong> ' . e($report->report_number) . '</div>';
        $html[] = '<div><strong>MR No:</strong> ' . e($patient?->mr_number ?? '') . '</div>';
        $html[] = '<div><strong>Gender / Age:</strong> ' . e(ucfirst($patient?->gender ?? '')) . ' / ' . e((string) ($patient?->age_years ?? '—')) . '</div>';
        $html[] = '<div><strong>Phone:</strong> ' . e($patient?->phone ?? '') . '</div>';
        $html[] = '<div><strong>Reported:</strong> ' . e(optional($report->reported_at)->timezone('Asia/Karachi')->format('d-M-Y h:i A') ?? '—') . '</div>';
        $html[] = '<div><strong>Referred By:</strong> ' . e($report->referred_by ?? 'Self / Walk-in') . '</div>';
        $html[] = '<div><strong>Status:</strong> ' . e(strtoupper($report->status)) . '</div>';
        $html[] = '</div>';

        // Results by test
        foreach ($report->reportTests as $rt) {
            $html[] = '<h3>' . e($rt->test_name_snapshot) . '</h3>';
            $html[] = '<table><thead><tr>';
            $html[] = '<th>Parameter</th><th>Result</th><th>Unit</th><th>Reference Range</th><th>Flag</th>';
            $html[] = '</tr></thead><tbody>';

            foreach ($rt->results as $r) {
                if (!$r->print_selected) {
                    continue;
                }
                $cls = $r->is_abnormal ? ' class="abnormal"' : '';
                $html[] = '<tr' . $cls . '>';
                $html[] = '<td>' . e($r->parameter_name_snapshot) . '</td>';
                $html[] = '<td>' . e($r->result_value ?? '') . '</td>';
                $html[] = '<td>' . e($r->unit_snapshot ?? '') . '</td>';
                $html[] = '<td>' . e($r->reference_range_text ?? '') . '</td>';
                $html[] = '<td class="flag">' . e($r->result_flag ?? '') . '</td>';
                $html[] = '</tr>';
            }
            $html[] = '</tbody></table>';
        }

        // Footer
        $html[] = '<div class="footer">' . e($disclaimer) . '<br>Page 1 of 1 &nbsp;|&nbsp; *** End of Report ***</div>';
        $html[] = '</body></html>';

        return implode("\n", $html);
    }

    protected function setting(string $key, string $default = ''): string
    {
        try {
            $row = LabSetting::where('key', $key)->first();
            if ($row && is_array($row->value) && isset($row->value['text'])) {
                return (string) $row->value['text'];
            }
            if ($row && is_string($row->value)) {
                return $row->value;
            }
        } catch (\Throwable $e) {
            // settings table may not be migrated yet
        }
        return $default;
    }
}
