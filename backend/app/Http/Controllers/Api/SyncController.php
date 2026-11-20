<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\GoogleDriveSyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(private GoogleDriveSyncService $sync)
    {
    }

    public function status()
    {
        return response()->json(['data' => $this->sync->status()]);
    }

    public function run(Request $request)
    {
        $device = $request->header('X-Device-Code', 'P');
        $result = $this->sync->syncNow($device);

        return response()->json([
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }
}
