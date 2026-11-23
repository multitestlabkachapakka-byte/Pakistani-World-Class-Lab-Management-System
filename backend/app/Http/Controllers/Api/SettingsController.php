<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabSetting;
use App\Support\AuditLogger;
use App\Support\DeviceCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Laboratory settings used by the report renderer (name, address, logo, notes, signatories).
 * Everyone signed in may read them (every user prints reports); only an admin may change them.
 */
class SettingsController extends Controller
{
    public const DEFAULTS = [
        'lab_name' => '',
        'lab_address' => '',
        'lab_phone' => '',
        'lab_email' => '',
        'header_note' => '',
        'footer_note' => 'Computer-generated report. Results relate to the sample received. Clinical correlation is advised.',
        'watermark_text' => '',
        'show_unverified_notice' => true,
        'logo_data_uri' => '',
        'signatories' => [],
    ];

    public function index()
    {
        return response()->json(['data' => $this->current()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'lab_name' => 'sometimes|nullable|string|max:120',
            'lab_address' => 'sometimes|nullable|string|max:255',
            'lab_phone' => 'sometimes|nullable|string|max:50',
            'lab_email' => 'sometimes|nullable|email|max:120',
            'header_note' => 'sometimes|nullable|string|max:255',
            'footer_note' => 'sometimes|nullable|string|max:500',
            'watermark_text' => 'sometimes|nullable|string|max:40',
            'show_unverified_notice' => 'sometimes|boolean',
            // Only an embedded image: never a link to another site.
            'logo_data_uri' => ['sometimes', 'nullable', 'string', 'max:400000',
                'regex:/^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+\/=]+$/'],
            'signatories' => 'sometimes|nullable|array|max:4',
            'signatories.*.name' => 'required|string|max:120',
            'signatories.*.qualification' => 'nullable|string|max:160',
            'signatories.*.designation' => 'nullable|string|max:120',
            'signatories.*.pmdc_number' => 'nullable|string|max:40',
        ]);

        DB::transaction(function () use ($request, $data) {
            foreach ($data as $key => $value) {
                $setting = LabSetting::firstOrNew(['key' => $key]);
                $setting->fill([
                    'id' => $setting->id ?? Uuid::uuid5(Uuid::NAMESPACE_URL, 'lims.setting:'.$key)->toString(),
                    'value' => ['value' => $value ?? self::DEFAULTS[$key]],
                    'group' => 'report',
                    'updated_by_device' => DeviceCode::fromRequest($request),
                    'rev' => $setting->exists ? $setting->rev + 1 : 1,
                ])->save();
            }
        });

        // Field names only: a logo or a long text must never be copied into the audit trail.
        AuditLogger::record($request, 'settings.updated', 'settings', null, null, ['keys' => array_keys($data)]);

        return response()->json(['data' => $this->current()]);
    }

    private function current(): array
    {
        $stored = LabSetting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value['value'] ?? null]);

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = $stored->has($key) && $stored[$key] !== null ? $stored[$key] : $default;
        }

        return $out;
    }
}
