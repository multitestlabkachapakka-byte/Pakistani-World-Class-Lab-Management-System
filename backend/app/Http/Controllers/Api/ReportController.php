<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\Patient;
use App\Models\TestDefinition;
use App\Models\ReportTest;
use App\Models\ReportResult;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
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

        $device = $request->header('X-Device-Code', 'P');

        return DB::transaction(function () use ($data, $device, $request) {
            $report = Report::create([
                'id' => (string) Str::uuid(),
                'report_number' => $this->generateReportNumber($device),
                'patient_id' => $data['patient_id'],
                'doctor_id' => $data['doctor_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'status' => 'draft',
                'sample_type' => $data['sample_type'] ?? null,
                'collected_at' => $data['collected_at'] ?? now(),
                'referred_by' => $data['referred_by'] ?? 'Self / Walk-in',
                'clinical_notes' => $data['clinical_notes'] ?? null,
                'updated_by_device' => $device,
                'rev' => 1,
            ]);

            $sort = 0;
            foreach ($data['test_codes'] as $code) {
                $test = TestDefinition::where('code', $code)->where('is_active', true)->first();
                if (!$test) {
                    continue;
                }

                $reportTest = ReportTest::create([
                    'id' => (string) Str::uuid(),
                    'report_id' => $report->id,
                    'test_definition_id' => $test->id,
                    'test_name_snapshot' => $test->name,
                    'department_snapshot' => $test->department,
                    'sort_order' => $sort++,
                    'status' => 'pending',
                ]);

                // Load parameters with reference ranges matched to patient gender/age later
                $patient = Patient::find($data['patient_id']);
                $gender = $patient?->gender ?? 'both';

                foreach ($test->parameters()->orderBy('sort_order')->get() as $param) {
                    $range = $param->referenceRanges()
                        ->where(function ($q) use ($gender) {
                            $q->where('gender', $gender)->orWhere('gender', 'both');
                        })
                        ->first();

                    ReportResult::create([
                        'id' => (string) Str::uuid(),
                        'report_id' => $report->id,
                        'report_test_id' => $reportTest->id,
                        'test_parameter_id' => $param->id,
                        'parameter_name_snapshot' => $param->name,
                        'unit_snapshot' => $param->unit,
                        'result_value' => null,
                        'reference_range_text' => $range?->range_text,
                        'ref_low' => $range?->low,
                        'ref_high' => $range?->high,
                        'print_selected' => $param->print_by_default,
                        'sort_order' => $param->sort_order,
                    ]);
                }
            }

            $report->load(['patient', 'reportTests.results']);

            return response()->json(['data' => $report], 201);
        });
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
     * Update individual result values and recalculate flags.
     */
    public function updateResults(Request $request, string $id)
    {
        $report = Report::where('status', 'draft')->findOrFail($id);

        $data = $request->validate([
            'results' => 'required|array',
            'results.*.id' => 'required|uuid',
            'results.*.result_value' => 'nullable|string',
            'results.*.print_selected' => 'sometimes|boolean',
        ]);

        foreach ($data['results'] as $item) {
            $result = ReportResult::where('report_id', $report->id)
                ->where('id', $item['id'])
                ->first();

            if (!$result) {
                continue;
            }

            $value = $item['result_value'] ?? null;
            $flag = null;
            $isAbnormal = false;
            $isCritical = false;

            if ($value !== null && $value !== '' && is_numeric($value)) {
                $num = (float) $value;
                if ($result->ref_low !== null && $num < (float) $result->ref_low) {
                    $flag = 'L';
                    $isAbnormal = true;
                } elseif ($result->ref_high !== null && $num > (float) $result->ref_high) {
                    $flag = 'H';
                    $isAbnormal = true;
                }
            }

            $result->update([
                'result_value' => $value,
                'result_flag' => $flag,
                'is_abnormal' => $isAbnormal,
                'is_critical' => $isCritical,
                'was_manually_edited' => true,
                'print_selected' => $item['print_selected'] ?? $result->print_selected,
            ]);
        }

        $report->update([
            'updated_by_device' => $request->header('X-Device-Code', 'P'),
            'rev' => $report->rev + 1,
        ]);

        return response()->json([
            'data' => $report->fresh(['reportTests.results']),
        ]);
    }

    public function finalize(Request $request, string $id)
    {
        $report = Report::where('status', 'draft')->findOrFail($id);

        // Build snapshot so later catalog edits cannot change this report
        $snapshot = [
            'tests' => $report->reportTests()->with('results')->get()->toArray(),
            'finalized_at' => now()->toIso8601String(),
        ];

        $report->update([
            'status' => 'final',
            'reported_at' => now(),
            'finalized_by' => $request->header('X-User-Id'),
            'snapshot' => $snapshot,
            'updated_by_device' => $request->header('X-Device-Code', 'P'),
            'rev' => $report->rev + 1,
        ]);

        return response()->json(['data' => $report->fresh()]);
    }

    protected function generateReportNumber(string $device): string
    {
        $date = now('Asia/Karachi')->format('ymd');
        $prefix = "{$date}-{$device}-R";

        $last = Report::withTrashed()
            ->where('report_number', 'like', $prefix . '%')
            ->orderByDesc('report_number')
            ->value('report_number');

        $seq = 1;
        if ($last) {
            $seq = ((int) substr($last, -4)) + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
