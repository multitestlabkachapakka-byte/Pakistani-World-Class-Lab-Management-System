<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BackupException;
use App\Services\BackupService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Backup, restore and factory reset. Administrators only. Restore and reset also ask for the
 * administrator's password and an exact confirmation phrase, and they sign everyone out.
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups)
    {
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'passphrase' => ['required', 'string', 'min:10', 'max:200', 'confirmed'],
        ]);

        $blob = $this->backups->create($data['passphrase']);
        $name = 'lims-backup-'.now('Asia/Karachi')->format('Ymd-His').'.limsbk';

        AuditLogger::record($request, 'backup.created', 'backup', null, null, ['file' => $name, 'bytes' => strlen($blob)]);

        return response($blob, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function restore(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:204800',
            'passphrase' => 'required|string|max:200',
            'password' => 'required|string',
            'confirm' => ['required', Rule::in(['RESTORE'])],
        ]);
        $this->assertOwnPassword($request);

        try {
            $result = $this->backups->restore(
                (string) file_get_contents($request->file('file')->getRealPath()),
                $request->input('passphrase')
            );
        } catch (BackupException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLogger::record($request, 'backup.restored', 'backup', null, null, $result);

        return response()->json(['data' => $result]);
    }

    public function factoryReset(Request $request)
    {
        $request->validate([
            'passphrase' => ['required', 'string', 'min:10', 'max:200'],
            'password' => 'required|string',
            'confirm' => ['required', Rule::in(['RESET ALL DATA'])],
        ]);
        $this->assertOwnPassword($request);

        try {
            $safety = $this->backups->factoryReset($request->input('passphrase'));
        } catch (BackupException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // The audit trail was wiped too: this is its first entry.
        AuditLogger::record($request, 'factory_reset', 'system', null, null, ['safety_copy' => $safety]);

        return response()->json(['data' => ['safety_copy' => $safety]]);
    }

    public function index()
    {
        return response()->json(['data' => $this->backups->listCopies()]);
    }

    public function download(string $name)
    {
        $path = $this->backups->copyPath($name);
        abort_if($path === null, 404);

        return response()->download($path, $name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function assertOwnPassword(Request $request): void
    {
        if (!Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is not correct.']);
        }
    }
}
