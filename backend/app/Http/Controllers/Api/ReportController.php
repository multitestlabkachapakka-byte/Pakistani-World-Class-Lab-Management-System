<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\ReferenceRange;
use App\Models\Report;
use App\Models\ReportResult;
use App\Models\ReportTest;
use App\Models\TestDefinition;
use App\Services\ReferenceRangeResolver;
use App\Services\ResultFlagger;
use App\Support\DeviceCode;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function __construct(private ReferenceRangeResolver $ranges)
    {
    }

    public function index(Request $request)
    {
        $limit = min((int) $request->get('limit', 50), 200);
        $status = $request->get('status');
        $patientId = $request->get('patient_id');
        $date = $request->get('date'); // YYYY-MM-DD

        $query = Report::with(['patient:id,name,mr_number,phone,gender'])
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }
        if ($patientId) {
            $query->where('patient_id', $patientId);
        }
        if ($date) {
            $query->whereDate('created_at', $date);
        }

        $reports = $query->limit($limit)->get();

        return response()->json([
            'data' => $reports,
            'meta' => ['count' => $reports->count()],
        ]);
    }

    /**
     * Create a draft report. Result fields start EMPTY — normal values are never typed in
     * for the technician. Each parameter only receives the reference range that matches the
     * patient's sex and age (or none, when the catalog has no matching range).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'patient_id' => 'required|uuid|exists:patients,id',
            'doctor_id' => 'nullable|uuid',
            'branch_id' => 'nullable|uuid',
            'sample_type' => 'nullable|string|max:100',
            'collected_at' => 'nullable|date',
            'referred_by' => 'nullable|string|max:255',
            'clinical_notes' => 'nullable|string',
            'test_codes' => 'required|array|min:1',
            'test_codes.*' => 'string',
        ]);

        $device = DeviceCode::fromRequest($request);
        $patient = Patient::findOrFail($data['patient_id']);

        $codes = array_values(array_unique($data['test_codes']));
        $tests = TestDefinition::where('is_active', true)
            ->whereIn('code', $codes)
            ->with(['parameters' => fn ($q) => $q->orderBy('sort_order'), 'parameters.referenceRanges'])
            ->get()
            ->keyBy('code');

        // A requested test must never be dropped silently.
        $unknown = array_values(array_diff($codes, $tests->keys()->all()));
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown or inactive test code(s): '.implode(', ', $unknown),
                'unknown_test_codes' => $unknown,
            ], 422);
        }

        $collectedAt = isset($data['collected_at']) ? Carbon::parse($data['collected_at']) : now();
        $ageDays = ReferenceRangeResolver::ageInDays($patient, $collectedAt);

        $report = DB::transaction(function () use ($data, $device, $patient, $codes, $tests, $collectedAt, $ageDays) {
            $report = $this->createReport([
                'patient_id' => $patient->id,
                'doctor_id' => $data['doctor_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'status' => 'draft',
                'sample_type' => $data['sample_type'] ?? null,
                'collected_at' => $collectedAt,
                'referred_by' => $data['referred_by'] ?? 'Self / Walk-in',
                'clinical_notes' => $data['clinical_notes'] ?? null,
                'updated_by_device' => $device,
                'rev' => 1,
            ], $device);

            $sort = 0;
            foreach ($codes as $code) {
                $test = $tests[$code];

                $reportTest = ReportTest::create([
                    'id' => (string) Str::uuid(),
                    'report_id' => $report->id,
                    'test_definition_id' => $test->id,
                    'test_name_snapshot' => $test->name,
                    'department_snapshot' => $test->department,
                    'sort_order' => $sort++,
                    'status' => 'pending',
                ]);

                foreach ($test->parameters as $param) {
                    $range = $this->ranges->resolve($param->referenceRanges, $patient->gender, $ageDays);

                    ReportResult::create([
                        'id' => (string) Str::uuid(),
                        'report_id' => $report->id,
                        'report_test_id' => $reportTest->id,
                        'test_parameter_id' => $param->id,
                        'parameter_name_snapshot' => $param->name,
                        'unit_snapshot' => $param->unit,
                        'result_value' => null,
                        'reference_range_id' => $range?->id,
                        'reference_range_text' => $range?->range_text,
                        'ref_low' => $range?->low,
                        'ref_high' => $range?->high,
                        'critical_low' => $range?->critical_low,
                        'critical_high' => $range?->critical_high,
                        'reference_source' => $range?->source,
                        'reference_verification_status' => $range?->verification_status,
                        'print_selected' => $param->print_by_default,
                        'was_auto_filled' => false,
                        'sort_order' => $param->sort_order,
                    ]);
                }
            }

            return $report;
        });

        $report->load(['patient', 'reportTests.results']);

        return response()->json(['data' => $report], 201);
    }

    public function show(string $id)
    {
        $report = Report::with([
            'patient',
            'doctor',
            'reportTests.results',
        ])->findOrFail($id);

        return response()->json(['data' => $report]);
    }

    /**
     * Save typed results and recompute H/L (and critical HH/LL) flags from the frozen limits.
     */
    public function updateResults(Request $request, string $id)
    {
        $report = Report::where('status', 'draft')->findOrFail($id);

        $data = $request->validate([
            'results' => 'required|array|min:1',
            'results.*.id' => 'required|uuid',
            'results.*.result_value' => 'nullable|string|max:255',
            'results.*.print_selected' => 'sometimes|boolean',
        ]);

        $ids = array_column($data['results'], 'id');
        $found = ReportResult::where('report_id', $report->id)->whereIn('id', $ids)->get()->keyBy('id');

        $unknown = array_values(array_diff($ids, $found->keys()->all()));
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Result id(s) do not belong to this report.',
                'unknown_result_ids' => $unknown,
            ], 422);
        }

        DB::transaction(function () use ($data, $found, $report, $request) {
            foreach ($data['results'] as $item) {
                $result = $found[$item['id']];

                $value = isset($item['result_value']) ? trim($item['result_value']) : null;
                $value = $value === '' ? null : $value;

                $flag = ResultFlagger::evaluate(
                    $value,
                    $result->ref_low,
                    $result->ref_high,
                    $result->critical_low,
                    $result->critical_high
                );

                $result->update([
                    'result_value' => $value,
                    'result_flag' => $flag['flag'],
                    'is_abnormal' => $flag['is_abnormal'],
                    'is_critical' => $flag['is_critical'],
                    'was_manually_edited' => true,
                    'print_selected' => $item['print_selected'] ?? $result->print_selected,
                ]);
            }

            $report->update([
                'updated_by_device' => DeviceCode::fromRequest($request),
                'rev' => $report->rev + 1,
            ]);
        });

        return response()->json([
            'data' => $report->fresh(['reportTests.results']),
        ]);
    }

    /**
     * Validate and finalize. The snapshot freezes patient details, results and the reference
     * ranges that were applied, so later catalog edits can never change an issued report.
     */
    public function finalize(Request $request, string $id)
    {
        $report = Report::where('status', 'draft')->with(['patient', 'reportTests.results'])->findOrFail($id);

        $printable = $report->reportTests->flatMap(fn ($t) => $t->results)->where('print_selected', true);
        $isEntered = fn (ReportResult $r) => $r->result_value !== null && trim((string) $r->result_value) !== '';

        $entered = $printable->filter($isEntered)->values();
        $missing = $printable->reject($isEntered)->values();

        if ($entered->isEmpty()) {
            return response()->json(['message' => 'No results have been entered yet.'], 422);
        }

        if ($missing->isNotEmpty() && !$request->boolean('allow_incomplete')) {
            return response()->json([
                'message' => 'Some selected parameters have no result. Enter them, or finalize with allow_incomplete=true.',
                'missing_parameters' => $missing->pluck('parameter_name_snapshot')->values(),
            ], 422);
        }

        $critical = $entered->where('is_critical', true)->pluck('parameter_name_snapshot')->values()->all();
        $unverified = $entered
            ->filter(fn ($r) => $r->reference_range_id !== null
                && $r->reference_verification_status !== ReferenceRange::STATUS_VERIFIED)
            ->count();

        $snapshot = [
            'finalized_at' => now()->toIso8601String(),
            'patient' => $report->patient?->only([
                'id', 'mr_number', 'name', 'guardian_name', 'guardian_relation', 'gender',
                'date_of_birth', 'age_years', 'age_months', 'age_days', 'phone',
            ]),
            'tests' => $report->reportTests->toArray(),
            'critical_results' => $critical,
            'unverified_reference_ranges' => $unverified,
            'incomplete' => $missing->isNotEmpty(),
        ];

        $report->update([
            'status' => 'final',
            'reported_at' => now(),
            'finalized_by' => $request->header('X-User-Id'),
            'snapshot' => $snapshot,
            'updated_by_device' => DeviceCode::fromRequest($request),
            'rev' => $report->rev + 1,
        ]);

        return response()->json([
            'data' => $report->fresh(),
            'meta' => [
                'critical_results' => $critical,
                'unverified_reference_ranges' => $unverified,
            ],
        ]);
    }

    /** Creates the report, retrying with a fresh number if two requests raced for the same one. */
    private function createReport(array $attributes, string $device): Report
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return Report::create($attributes + [
                    'id' => (string) Str::uuid(),
                    'report_number' => $this->generateReportNumber($device),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    protected function generateReportNumber(string $device): string
    {
        $date = now('Asia/Karachi')->format('ymd');
        $prefix = "{$date}-{$device}-R";

        $last = Report::withTrashed()
            ->where('report_number', 'like', $prefix.'%')
            ->orderByDesc('report_number')
            ->value('report_number');

        $seq = 1;
        if ($last) {
            $seq = ((int) substr($last, -4)) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
