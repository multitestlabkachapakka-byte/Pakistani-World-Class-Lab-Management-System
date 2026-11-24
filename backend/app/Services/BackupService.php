<?php

namespace App\Services;

use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted backup, restore and factory reset of the whole database.
 *
 * File = "LIMSBK1\n" + salt(16) + iv(12) + GCM tag(16) + AES-256-GCM(gzip(JSON)). The key comes from a
 * passphrase (PBKDF2-SHA256, 200 000 rounds). Without the passphrase the file cannot be read, and any
 * change to the file is detected. Login tokens, sessions, caches and queues are not backed up.
 *
 * Restore replaces ALL data in one transaction (all or nothing) and first saves an encrypted safety
 * copy of the current data on the server. It accepts backups from older versions (extra new columns
 * take their defaults) but refuses backups from a newer version.
 */
final class BackupService
{
    public const FORMAT = 'LIMSBK1';

    private const EXCLUDED = [
        'migrations', 'personal_access_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];
    private const PBKDF2_ROUNDS = 200000;
    private const MAX_PLAIN_BYTES = 536870912; // 512 MB after decompression

    /** The tables that are backed up, parents before children (so rows can be inserted in this order). */
    public function tables(): array
    {
        $names = collect(Schema::getTables())->pluck('name')
            ->reject(fn ($n) => in_array($n, self::EXCLUDED, true))
            ->sort()->values()->all();

        $parents = [];
        foreach ($names as $table) {
            $parents[$table] = collect(Schema::getForeignKeys($table))->pluck('foreign_table')
                ->unique()
                ->filter(fn ($p) => $p !== $table && in_array($p, $names, true))
                ->values()->all();
        }

        $ordered = [];
        $remaining = $names;
        while ($remaining !== []) {
            $progressed = false;
            foreach ($remaining as $i => $table) {
                if (array_diff($parents[$table], $ordered) === []) {
                    $ordered[] = $table;
                    unset($remaining[$i]);
                    $progressed = true;
                }
            }
            if (!$progressed) { // a cycle: keep the rest in a fixed order
                array_push($ordered, ...array_values($remaining));
                break;
            }
            $remaining = array_values($remaining);
        }

        return $ordered;
    }

    public function snapshot(): array
    {
        $tables = [];
        foreach ($this->tables() as $table) {
            $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return [
            'format' => self::FORMAT,
            'created_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
            'tables' => $tables,
        ];
    }

    /** The encrypted backup file contents. */
    public function create(string $passphrase): string
    {
        $json = json_encode($this->snapshot(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        return $this->encrypt(gzencode($json, 6), $passphrase);
    }

    public function encrypt(string $plain, string $passphrase): string
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ROUNDS, 32, true);
        $tag = '';

        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::FORMAT, 16);
        if ($cipher === false) {
            throw new BackupException('The backup could not be encrypted.');
        }

        return self::FORMAT."\n".$salt.$iv.$tag.$cipher;
    }

    public function decrypt(string $blob, string $passphrase): string
    {
        $head = self::FORMAT."\n";
        $offset = strlen($head);

        if (!str_starts_with($blob, $head) || strlen($blob) < $offset + 16 + 12 + 16 + 1) {
            throw new BackupException('This is not a backup file of this software.');
        }

        $salt = substr($blob, $offset, 16);
        $iv = substr($blob, $offset + 16, 12);
        $tag = substr($blob, $offset + 28, 16);
        $cipher = substr($blob, $offset + 44);

        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ROUNDS, 32, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::FORMAT);

        if ($plain === false) {
            throw new BackupException('Wrong passphrase, or the backup file is damaged.');
        }

        return $plain;
    }

    /** Decrypts and checks a backup file; returns its data. */
    public function read(string $blob, string $passphrase): array
    {
        $compressed = $this->decrypt($blob, $passphrase);
        $json = @gzdecode($compressed, self::MAX_PLAIN_BYTES);
        $payload = $json === false ? null : json_decode($json, true);

        if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || !is_array($payload['tables'] ?? null)) {
            throw new BackupException('The backup file is damaged.');
        }

        $current = DB::table('migrations')->pluck('migration')->all();
        if (array_diff($payload['migrations'] ?? [], $current) !== []) {
            throw new BackupException('This backup was made by a newer version of the software. Update the software first.');
        }

        $known = $this->tables();
        foreach ($payload['tables'] as $table => $rows) {
            if (!in_array($table, $known, true) || !is_array($rows)) {
                throw new BackupException("The backup contains a table this version does not know ({$table}).");
            }
            $columns = Schema::getColumnListing($table);
            foreach ($rows as $row) {
                if (!is_array($row) || array_diff(array_keys($row), $columns) !== []) {
                    throw new BackupException("The backup contains data this version cannot store ({$table}). Update the software first.");
                }
            }
        }

        return $payload;
    }

    /**
     * Replaces everything with the contents of the backup (all or nothing).
     *
     * @return array{tables: int, rows: int, safety_copy: string}
     */
    public function restore(string $blob, string $passphrase): array
    {
        $payload = $this->read($blob, $passphrase);
        $safety = $this->saveSafetyCopy('pre-restore', $passphrase);
        $rows = 0;

        DB::transaction(function () use ($payload, &$rows) {
            $this->wipe();
            foreach ($this->tables() as $table) {
                $data = $payload['tables'][$table] ?? [];
                foreach (array_chunk($data, 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
                $rows += count($data);
            }
        });

        return ['tables' => count($payload['tables']), 'rows' => $rows, 'safety_copy' => $safety];
    }

    /** Wipes ALL data, puts the catalog back to its shipped state and returns the name of the safety copy. */
    public function factoryReset(string $passphrase): string
    {
        $safety = $this->saveSafetyCopy('pre-reset', $passphrase);

        DB::transaction(function () {
            $this->wipe();
            app(CatalogSeeder::class)->run();
        });

        return $safety;
    }

    /** Deletes every row of every backed-up table (children first) and all login tokens. */
    public function wipe(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            DB::table($table)->delete();
        }
        DB::table('personal_access_tokens')->delete();
    }

    // ------------------------------------------------------------------ safety copies on the server

    public function directory(): string
    {
        $dir = config('lims.backup_dir');
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    public function saveSafetyCopy(string $kind, string $passphrase): string
    {
        $name = $kind.'-'.now('Asia/Karachi')->format('Ymd-His').'-'.bin2hex(random_bytes(2)).'.limsbk';
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        file_put_contents($path, $this->create($passphrase));
        @chmod($path, 0600);

        return $name;
    }

    /** @return list<array{name: string, bytes: int, created_at: string}> newest first */
    public function listCopies(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.'*.limsbk') ?: [];

        $out = array_map(fn ($f) => [
            'name' => basename($f),
            'bytes' => (int) filesize($f),
            'created_at' => date('c', (int) filemtime($f)),
        ], $files);

        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['name'], $a['name']));

        return $out;
    }

    /** Path of a stored copy, or null when the name is not a plain backup file name that exists. */
    public function copyPath(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+\.limsbk$/', $name)) {
            return null;
        }
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }
}
