<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferenceRange;
use App\Models\TestDefinition;
use App\Models\TestParameter;
use App\Support\AuditLogger;
use App\Support\DeviceCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reference range management and pathologist verification.
 *
 *  - Admin and pathologist may add / edit / delete ranges.
 *  - Only a pathologist may verify (or withdraw a verification) - an administrator cannot.
 *  - Any clinical change to a range voids its verification: it goes back to "VERIFY REQUIRED".
 *  - A range always needs a source; the displayed text must agree with the numeric limits.
 */
class RangeController extends Controller
{
    /** Changing any of these makes an earlier verification void. */
    private const CLINICAL_FIELDS = [
        'gender', 'age_min_days', 'age_max_days', 'age_label', 'low', 'high', 'critical_low',
        'critical_high', 'text_normal', 'range_text', 'unit', 'population', 'methodology', 'source',
    ];

    /** Review queue: every active test with how many of its ranges are verified. */
    public function reviewQueue()
    {
        $rows = TestDefinition::where('is_active', true)
            ->with('parameters.referenceRanges')
            ->orderBy('department')->orderBy('name')
            ->get()
            ->map(function (TestDefinition $test) {
                $ranges = $test->parameters->flatMap(fn ($p) => $p->referenceRanges);
                $verified = $ranges->where('verification_status', ReferenceRange::STATUS_VERIFIED)->count();

                return [
                    'id' => $test->id,
                    'code' => $test->code,
                    'name' => $test->name,
                    'department' => $test->department,
                    'verification_status' => $test->verification_status,
                    'verified_by' => $test->verified_by,
                    'last_verified' => $test->last_verified,
                    'ranges_total' => $ranges->count(),
                    'ranges_verified' => $verified,
                    'ranges_pending' => $ranges->count() - $verified,
                ];
            })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'tests' => $rows->count(),
                'ranges_verified' => $rows->sum('ranges_verified'),
                'ranges_pending' => $rows->sum('ranges_pending'),
            ],
        ]);
    }

    public function store(Request $request, string $parameterId)
    {
        $parameter = TestParameter::with('testDefinition')->findOrFail($parameterId);
        $data = $this->validated($request, true);
        $this->assertNoDuplicate($parameter->id, $data);

        $range = DB::transaction(function () use ($request, $parameter, $data) {
            $range = ReferenceRange::create($data + [
                'id' => (string) Str::uuid(),
                'test_parameter_id' => $parameter->id,
                'unit' => $data['unit'] ?? $parameter->unit,
                'verification_status' => ReferenceRange::STATUS_PENDING,
                'updated_by_device' => DeviceCode::fromRequest($request),
                'rev' => 1,
            ]);
            $this->refreshTestStatus($parameter->testDefinition);

            return $range;
        });

        AuditLogger::record($request, 'range.created', 'reference_range', $range->id, null, [
            'test' => $parameter->testDefinition->code,
            'parameter' => $parameter->name,
            'gender' => $range->gender,
            'age_label' => $range->age_label,
        ]);

        return response()->json(['data' => $range->fresh()], 201);
    }

    public function update(Request $request, string $id)
    {
        $range = ReferenceRange::with('parameter.testDefinition')->findOrFail($id);
        $data = $this->validated($request, false, $range);

        $range->fill($data);
        $changed = array_values(array_intersect(array_keys($range->getDirty()), self::CLINICAL_FIELDS));

        if ($changed === []) {
            return response()->json(['data' => $range->fresh()]); // nothing changed: verification stays
        }

        if (array_intersect($changed, ['gender', 'age_min_days', 'age_max_days'])) {
            $this->assertNoDuplicate($range->test_parameter_id, $range->only(['gender', 'age_min_days', 'age_max_days']), $range->id);
        }

        $wasVerified = $range->verification_status === ReferenceRange::STATUS_VERIFIED;

        DB::transaction(function () use ($request, $range) {
            $range->forceFill([
                'verification_status' => ReferenceRange::STATUS_PENDING,
                'verified_by' => null,
                'verified_at' => null,
                'updated_by_device' => DeviceCode::fromRequest($request),
                'rev' => $range->rev + 1,
            ])->save();
            $this->refreshTestStatus($range->parameter->testDefinition);
        });

        AuditLogger::record($request, 'range.updated', 'reference_range', $range->id, null, [
            'test' => $range->parameter->testDefinition->code,
            'fields' => $changed,
            'verification_cleared' => $wasVerified,
        ]);

        return response()->json(['data' => $range->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $range = ReferenceRange::with('parameter.testDefinition')->findOrFail($id);
        $test = $range->parameter->testDefinition;

        DB::transaction(function () use ($range, $test) {
            $range->delete(); // soft delete: kept for history, never applied to new reports
            $this->refreshTestStatus($test);
        });

        AuditLogger::record($request, 'range.deleted', 'reference_range', $range->id, null, [
            'test' => $test->code,
            'parameter' => $range->parameter->name,
            'gender' => $range->gender,
            'age_label' => $range->age_label,
        ]);

        return response()->json(['message' => 'Range removed']);
    }

    public function verify(Request $request, string $id)
    {
        $range = ReferenceRange::with('parameter.testDefinition')->findOrFail($id);

        if (!$this->isVerifiable($range)) {
            return response()->json(['message' => 'A range without a source or without any limit/text cannot be verified.'], 422);
        }

        if ($range->verification_status !== ReferenceRange::STATUS_VERIFIED) {
            DB::transaction(function () use ($request, $range) {
                $this->markVerified($range, $request);
                $this->refreshTestStatus($range->parameter->testDefinition);
            });

            AuditLogger::record($request, 'range.verified', 'reference_range', $range->id, null, [
                'test' => $range->parameter->testDefinition->code,
                'parameter' => $range->parameter->name,
            ]);
        }

        return response()->json(['data' => $range->fresh()]);
    }

    public function unverify(Request $request, string $id)
    {
        $range = ReferenceRange::with('parameter.testDefinition')->findOrFail($id);

        if ($range->verification_status === ReferenceRange::STATUS_VERIFIED) {
            DB::transaction(function () use ($request, $range) {
                $range->forceFill([
                    'verification_status' => ReferenceRange::STATUS_PENDING,
                    'verified_by' => null,
                    'verified_at' => null,
                    'updated_by_device' => DeviceCode::fromRequest($request),
                    'rev' => $range->rev + 1,
                ])->save();
                $this->refreshTestStatus($range->parameter->testDefinition);
            });

            AuditLogger::record($request, 'range.unverified', 'reference_range', $range->id, null, [
                'test' => $range->parameter->testDefinition->code,
                'parameter' => $range->parameter->name,
            ]);
        }

        return response()->json(['data' => $range->fresh()]);
    }

    /** Verify every pending range of one test in one deliberate step (confirm=true required). */
    public function verifyTest(Request $request, string $id)
    {
        $request->validate(['confirm' => 'accepted']);

        $test = TestDefinition::with('parameters')->findOrFail($id);
        $ranges = ReferenceRange::whereIn('test_parameter_id', $test->parameters->pluck('id'))->get();

        if ($ranges->isEmpty()) {
            return response()->json(['message' => 'This test has no reference ranges to verify.'], 422);
        }

        $verified = 0;
        $skipped = [];

        DB::transaction(function () use ($request, $ranges, $test, &$verified, &$skipped) {
            foreach ($ranges as $range) {
                if ($range->verification_status === ReferenceRange::STATUS_VERIFIED) {
                    continue;
                }
                if (!$this->isVerifiable($range)) {
                    $skipped[] = $range->id;
                    continue;
                }
                $this->markVerified($range, $request);
                $verified++;
            }
            $this->refreshTestStatus($test);
        });

        AuditLogger::record($request, 'catalog.test_verified', 'test_definition', $test->id, null, [
            'code' => $test->code,
            'verified' => $verified,
            'skipped' => count($skipped),
        ]);

        return response()->json(['data' => [
            'verified' => $verified,
            'skipped_range_ids' => $skipped,
            'test_status' => $test->fresh()->verification_status,
        ]]);
    }

    // ---------------------------------------------------------------- helpers

    private function validated(Request $request, bool $creating, ?ReferenceRange $existing = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'gender' => [$req, Rule::in(['male', 'female', 'both'])],
            'age_min_days' => 'nullable|integer|min:0|max:36500',
            'age_max_days' => 'nullable|integer|min:0|max:36500',
            'age_label' => 'nullable|string|max:50',
            'low' => 'nullable|numeric',
            'high' => 'nullable|numeric',
            'critical_low' => 'nullable|numeric',
            'critical_high' => 'nullable|numeric',
            'text_normal' => 'nullable|string|max:255',
            'range_text' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'population' => 'nullable|string|max:255',
            'methodology' => 'nullable|string|max:255',
            'source' => [$req, 'string', 'max:255'],
        ]);

        $merged = array_merge($existing?->only(self::CLINICAL_FIELDS) ?? [], $data);
        $limitsTouched = array_key_exists('low', $data) || array_key_exists('high', $data);

        // The displayed text must follow the limits: regenerate it when the limits change without new text.
        $needsText = $creating
            ? blank($merged['range_text'] ?? null)
            : ($limitsTouched && !array_key_exists('range_text', $data));

        if ($needsText) {
            $generated = $this->generatedText($merged['low'] ?? null, $merged['high'] ?? null);
            if ($generated !== null) {
                $data['range_text'] = $merged['range_text'] = $generated;
            }
        }

        $this->crossChecks($merged, $creating || $limitsTouched || array_key_exists('range_text', $data));

        if ($creating && blank($merged['age_label'] ?? null)) {
            $data['age_label'] = $this->defaultLabel($merged);
        }

        return $data;
    }

    private function crossChecks(array $r, bool $checkText): void
    {
        $errors = [];

        $min = $r['age_min_days'] ?? null;
        $max = $r['age_max_days'] ?? null;
        if ($min !== null && $max !== null && (int) $max < (int) $min) {
            $errors['age_max_days'] = 'The maximum age must not be below the minimum age.';
        }

        $low = $r['low'] ?? null;
        $high = $r['high'] ?? null;
        if ($low !== null && $high !== null && (float) $low > (float) $high) {
            $errors['low'] = 'The low limit must not be above the high limit.';
        }

        $criticalLow = $r['critical_low'] ?? null;
        $criticalHigh = $r['critical_high'] ?? null;
        if ($criticalLow !== null && $low !== null && (float) $criticalLow > (float) $low) {
            $errors['critical_low'] = 'The critical low limit must not be above the low limit.';
        }
        if ($criticalHigh !== null && $high !== null && (float) $criticalHigh < (float) $high) {
            $errors['critical_high'] = 'The critical high limit must not be below the high limit.';
        }

        $text = (string) ($r['range_text'] ?? '');
        if ($low === null && $high === null && blank($r['text_normal'] ?? null) && $text === '') {
            $errors['range_text'] = 'Give at least one limit or a reference text.';
        }

        // The text that is printed must agree with the numbers that drive the flags (zero is a placeholder).
        if ($checkText && $text !== '' && !isset($errors['low'], $errors['range_text'])) {
            preg_match_all('/\d+(?:\.\d+)?/', $text, $m);
            $numbers = array_map('floatval', $m[0]);
            foreach (['low' => $low, 'high' => $high] as $name => $value) {
                if ($value !== null && (float) $value !== 0.0 && !in_array((float) $value, $numbers, true)) {
                    $errors['range_text'] = "The reference text does not mention the {$name} limit ({$this->fmt($value)}).";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertNoDuplicate(string $parameterId, array $band, ?string $ignoreId = null): void
    {
        $query = ReferenceRange::where('test_parameter_id', $parameterId)->where('gender', $band['gender']);
        foreach (['age_min_days', 'age_max_days'] as $field) {
            $value = $band[$field] ?? null;
            $value === null ? $query->whereNull($field) : $query->where($field, $value);
        }
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'gender' => 'A range for this sex and age band already exists - edit that one instead.',
            ]);
        }
    }

    private function isVerifiable(ReferenceRange $range): bool
    {
        return filled($range->source)
            && ($range->low !== null || $range->high !== null
                || filled($range->text_normal) || filled($range->range_text));
    }

    private function markVerified(ReferenceRange $range, Request $request): void
    {
        $range->forceFill([
            'verification_status' => ReferenceRange::STATUS_VERIFIED,
            'verified_by' => $request->user()->name,
            'verified_at' => now(),
            'updated_by_device' => DeviceCode::fromRequest($request),
            'rev' => $range->rev + 1,
        ])->save();
    }

    /** A test counts as verified only while every one of its ranges is verified. */
    private function refreshTestStatus(TestDefinition $test): void
    {
        $ranges = ReferenceRange::whereIn('test_parameter_id', $test->parameters()->pluck('id'))->get();
        $all = $ranges->isNotEmpty()
            && $ranges->every(fn ($r) => $r->verification_status === ReferenceRange::STATUS_VERIFIED);

        $status = $all ? ReferenceRange::STATUS_VERIFIED : ReferenceRange::STATUS_PENDING;
        $by = $all ? $ranges->sortByDesc('verified_at')->first()->verified_by : null;

        if ($test->verification_status === $status && $test->verified_by === $by) {
            return;
        }

        $test->forceFill([
            'verification_status' => $status,
            'verified_by' => $by,
            'last_verified' => $all ? now()->toDateString() : null,
            'rev' => $test->rev + 1,
        ])->save();
    }

    private function generatedText($low, $high): ?string
    {
        if ($low !== null && $high !== null) {
            return $this->fmt($low).' – '.$this->fmt($high);
        }
        if ($high !== null) {
            return '≤ '.$this->fmt($high);
        }
        if ($low !== null) {
            return '≥ '.$this->fmt($low);
        }

        return null;
    }

    private function fmt($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function defaultLabel(array $r): string
    {
        $min = $r['age_min_days'] ?? null;
        $max = $r['age_max_days'] ?? null;

        return ($min === null && $max === null) ? 'All' : sprintf('%s-%s days', $min ?? 0, $max ?? '+');
    }
}
