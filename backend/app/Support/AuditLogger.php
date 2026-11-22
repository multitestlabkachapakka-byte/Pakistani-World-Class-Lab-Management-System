<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes one audit row. Never pass passwords, tokens or clinical result values here -
 * log ids, numbers and field names only.
 */
final class AuditLogger
{
    public static function record(
        Request $request,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?string $notes = null,
        ?string $userId = null,
    ): void {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old,
            'new_values' => $new,
            'user_id' => $userId ?? $request->user()?->id,
            'device_id' => DeviceCode::fromRequest($request),
            'ip_address' => $request->ip(),
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }
}
