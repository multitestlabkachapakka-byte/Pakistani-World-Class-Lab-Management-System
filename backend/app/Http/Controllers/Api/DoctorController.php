<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Support\AuditLogger;
use App\Support\DeviceCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Referring doctors. Everyone signed in may read the list; admin and receptionist keep it up to date.
 * A doctor is never deleted (old reports refer to the name): they are switched off instead.
 */
class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        $query = Doctor::query()->orderBy('name');
        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(fn ($w) => $w
                ->where('name', 'like', $like)
                ->orWhere('specialization', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('clinic', 'like', $like));
        }

        return response()->json(['data' => $query->limit(300)->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $this->assertNotDuplicate($data['name'], $data['phone'] ?? null);

        $doctor = Doctor::create($data + [
            'id' => (string) Str::uuid(),
            'is_active' => $data['is_active'] ?? true,
            'updated_by_device' => DeviceCode::fromRequest($request),
            'rev' => 1,
        ]);

        AuditLogger::record($request, 'doctor.created', 'doctor', $doctor->id, null, ['name' => $doctor->name]);

        return response()->json(['data' => $doctor->fresh()], 201);
    }

    public function update(Request $request, string $id)
    {
        $doctor = Doctor::findOrFail($id);
        $data = $request->validate($this->rules(false));

        if (array_key_exists('name', $data) || array_key_exists('phone', $data)) {
            $this->assertNotDuplicate(
                $data['name'] ?? $doctor->name,
                array_key_exists('phone', $data) ? $data['phone'] : $doctor->phone,
                $doctor->id
            );
        }

        $doctor->fill($data);
        $changed = array_keys($doctor->getDirty());

        if ($changed !== []) {
            $doctor->forceFill([
                'updated_by_device' => DeviceCode::fromRequest($request),
                'rev' => $doctor->rev + 1,
            ])->save();

            AuditLogger::record($request, 'doctor.updated', 'doctor', $doctor->id, null, [
                'name' => $doctor->name,
                'fields' => $changed,
            ]);
        }

        return response()->json(['data' => $doctor->fresh()]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'qualification' => 'nullable|string|max:120',
            'specialization' => 'nullable|string|max:120',
            'pmdc_number' => 'nullable|string|max:40',
            'clinic' => 'nullable|string|max:160',
            'city' => 'nullable|string|max:80',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'is_active' => 'sometimes|boolean',
        ];
    }

    /** The same name with the same phone number is the same doctor. */
    private function assertNotDuplicate(string $name, ?string $phone, ?string $ignoreId = null): void
    {
        $query = Doctor::whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        $phone === null ? $query->whereNull('phone') : $query->where('phone', $phone);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'This doctor is already in the list.']);
        }
    }
}
