<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The device code is part of every MR / report number (e.g. 261121-A1-0007), so offline
 * devices never produce the same number. Client input is reduced to A-Z0-9 (max 6 chars);
 * a missing header means the central server ("P").
 */
final class DeviceCode
{
    public static function fromRequest(Request $request): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $request->header('X-Device-Code', '')));

        return $clean === '' ? 'P' : substr($clean, 0, 6);
    }
}
