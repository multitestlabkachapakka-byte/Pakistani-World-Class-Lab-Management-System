<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Support\AuditLogger;
use App\Support\DeviceCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    /**
     * List / search patients.
     * Supports: q (name, phone, MR, CNIC, address), gender, limit
     */
    public function index(Request $request)
    {
        $q = $request->string('q')->trim();
        $limit = min((int) $request->get('limit', 50), 200);

        $query = Patient::query()->orderByDesc('created_at');

        if ($q->isNotEmpty()) {
            $term = $q->toString();
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('mr_number', 'like', "%{$term}%")
                    ->orWhere('cnic', 'like', "%{$term}%")
                    ->orWhere('address', 'like', "%{$term}%")
                    ->orWhere('guardian_name', 'like', "%{$term}%");
            });
        }

        if ($request->filled('gender')) {
            $query->where('gender', $request->get('gender'));
        }

        $patients = $query->limit($limit)->get();

        return response()->json([
            'data' => $patients,
            'meta' => ['count' => $patients->count()],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'guardian_name' => 'nullable|string|max:255',
            'guardian_relation' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
            'age_years' => 'nullable|integer|min:0|max:150',
            'age_months' => 'nullable|integer|min:0|max:11',
            'age_days' => 'nullable|integer|min:0|max:30',
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'phone' => 'required|string|max:20',
            'cnic' => 'nullable|string|max:15',
            'whatsapp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'required|string',
            'city' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'branch_id' => 'nullable|uuid',
        ]);

        $data['id'] = (string) Str::uuid();
        $data['phone_raw'] = $data['phone'];
        $data['phone'] = $this->normalizePhone($data['phone']);
        $data['updated_by_device'] = DeviceCode::fromRequest($request);
        $data['rev'] = 1;

        $patient = $this->createWithUniqueMr($data, $request);

        AuditLogger::record($request, 'patient.created', 'patient', $patient->id, null, ['mr_number' => $patient->mr_number]);

        return response()->json(['data' => $patient], 201);
    }

    public function show(string $id)
    {
        $patient = Patient::with([])->findOrFail($id);
        return response()->json(['data' => $patient]);
    }

    public function update(Request $request, string $id)
    {
        $patient = Patient::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'guardian_name' => 'nullable|string|max:255',
            'guardian_relation' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
            'age_years' => 'nullable|integer|min:0|max:150',
            'gender' => ['sometimes', Rule::in(['male', 'female', 'other'])],
            'phone' => 'sometimes|string|max:20',
            'cnic' => 'nullable|string|max:15',
            'whatsapp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'sometimes|string',
            'city' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        if (isset($data['phone'])) {
            $data['phone_raw'] = $data['phone'];
            $data['phone'] = $this->normalizePhone($data['phone']);
        }

        $data['updated_by_device'] = DeviceCode::fromRequest($request);
        $data['rev'] = $patient->rev + 1;

        $patient->update($data);

        AuditLogger::record($request, 'patient.updated', 'patient', $patient->id, null, [
            'mr_number' => $patient->mr_number,
            'fields' => array_values(array_diff(array_keys($data), ['updated_by_device', 'rev', 'phone_raw'])),
        ]);

        return response()->json(['data' => $patient->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $patient = Patient::findOrFail($id);
        $patient->delete(); // soft delete

        AuditLogger::record($request, 'patient.deleted', 'patient', $patient->id, null, ['mr_number' => $patient->mr_number]);
        return response()->json(['message' => 'Patient soft-deleted']);
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '92') && strlen($digits) === 12) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+92' . substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return '+92' . $digits;
        }
        return $phone;
    }

    /** Creates the patient, retrying with a fresh MR number if two requests raced for the same one. */
    protected function createWithUniqueMr(array $data, Request $request): Patient
    {
        for ($attempt = 1; ; $attempt++) {
            $data['mr_number'] = $this->generateMrNumber($request);

            try {
                return Patient::create($data);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    protected function generateMrNumber(Request $request): string
    {
        $device = DeviceCode::fromRequest($request);
        $date = now('Asia/Karachi')->format('ymd');
        $prefix = "{$date}-{$device}-";

        $last = Patient::withTrashed()
            ->where('mr_number', 'like', $prefix . '%')
            ->orderByDesc('mr_number')
            ->value('mr_number');

        $seq = 1;
        if ($last) {
            $parts = explode('-', $last);
            $seq = ((int) end($parts)) + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
