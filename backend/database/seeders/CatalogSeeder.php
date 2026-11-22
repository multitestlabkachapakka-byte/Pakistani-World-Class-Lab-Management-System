<?php

namespace Database\Seeders;

use App\Models\ReferenceRange;
use App\Models\TestDefinition;
use App\Models\TestParameter;
use App\Services\ReferenceRangeResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Ramsey\Uuid\Uuid;

/**
 * Loads the catalog JSON files from database/seeders/catalog/.
 *
 * Clinical-safety rules:
 *  - Only values present in the JSON are stored; nothing is invented.
 *  - Everything is "VERIFY REQUIRED" unless the JSON names a verifier (verified_by + verified_at).
 *    A bare "VERIFIED" without a verifier is downgraded.
 *  - IDs are deterministic (UUIDv5): every device that seeds the catalog gets identical IDs,
 *    so catalog records stay mergeable by the Google Drive sync and re-seeding never re-keys rows.
 */
class CatalogSeeder extends Seeder
{
    private const ID_NAMESPACE = 'lims.catalog';

    public function run(): void
    {
        $dir = database_path('seeders/catalog');

        if (!File::isDirectory($dir)) {
            $this->command?->warn('No catalog directory found.');
            return;
        }

        $tests = 0;
        $params = 0;

        DB::transaction(function () use ($dir, &$tests, &$params) {
            foreach (File::files($dir) as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }

                $data = json_decode(File::get($file->getPathname()), true);
                if (!is_array($data)) {
                    throw new \RuntimeException("Invalid catalog JSON: {$file->getFilename()}");
                }

                foreach ($data as $testData) {
                    $this->seedTest($testData);
                    $tests++;
                    $params += count($testData['parameters'] ?? []);
                }

                $this->command?->info("Loaded: {$file->getFilename()}");
            }
        });

        $this->command?->info("Catalog seed complete. Tests: {$tests}, Parameters: {$params}");
    }

    protected function seedTest(array $data): void
    {
        $code = $data['code'];

        $test = TestDefinition::withTrashed()->firstOrNew(['id' => $this->uuid("test:{$code}")]);
        $isNew = !$test->exists;

        // A test that staff have reviewed (rev > 1) or removed is left exactly as they left it.
        if (!$isNew && ($test->rev > 1 || $test->trashed())) {
            $this->seedParameters($data, $test);

            return;
        }

        $test->fill([
            'code' => $code,
            'name' => $data['name'],
            'short_name' => $data['short_name'] ?? null,
            'department' => $data['department'],
            'aliases' => $data['aliases'] ?? [],
            'sample_type' => $data['sample_type'] ?? null,
            'methodology' => $data['methodology'] ?? null,
            'is_profile' => $data['is_profile'] ?? false,
            'verification_status' => $this->status($data),
            'verified_by' => $this->isVerified($data) ? $data['verified_by'] : null,
            'last_verified' => $this->isVerified($data) ? $data['verified_at'] : null,
            'source' => $data['source'] ?? null,
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'rev' => 1,
        ]);
        if ($isNew) {
            $test->is_active = true; // never re-enable a test an administrator switched off
        }
        $test->save();

        $this->seedParameters($data, $test);
    }

    protected function seedParameters(array $data, TestDefinition $test): void
    {
        $code = $data['code'];

        foreach ($data['parameters'] ?? [] as $paramData) {
            $param = TestParameter::updateOrCreate(
                ['id' => $this->uuid("param:{$code}:{$paramData['name']}")],
                [
                    'test_definition_id' => $test->id,
                    'name' => $paramData['name'],
                    'short_name' => $paramData['short_name'] ?? null,
                    'unit' => $paramData['unit'] ?? null,
                    'data_type' => $paramData['data_type'] ?? 'numeric',
                    'is_calculated' => $paramData['is_calculated'] ?? false,
                    'calculation_formula' => $paramData['calculation_formula'] ?? null,
                    'sort_order' => $paramData['sort_order'] ?? 0,
                    'print_by_default' => $paramData['print_by_default'] ?? true,
                ]
            );

            foreach ($paramData['ranges'] ?? [] as $rangeData) {
                $this->seedRange($data, $paramData, $param, $rangeData);
            }
        }
    }

    protected function seedRange(array $testData, array $paramData, TestParameter $param, array $rangeData): void
    {
        $gender = $rangeData['gender'] ?? 'both';
        $label = $rangeData['age_label'] ?? 'All';
        $min = $rangeData['age_min_days'] ?? null;
        $max = $rangeData['age_max_days'] ?? null;

        // An "Adult" range must never be shown for a child.
        if ($min === null && $max === null && $label === 'Adult') {
            $min = ReferenceRangeResolver::ADULT_MIN_DAYS;
        }

        $id = $this->uuid(sprintf(
            'range:%s:%s:%s:%s:%s:%s',
            $testData['code'], $paramData['name'], $gender, $label, $min ?? '', $max ?? ''
        ));

        // A range that staff have edited (rev > 1), verified or removed is never overwritten by re-seeding.
        $existing = ReferenceRange::withTrashed()->find($id);
        if ($existing && ($existing->rev > 1 || $existing->verified_by !== null || $existing->trashed())) {
            return;
        }

        ReferenceRange::withTrashed()->updateOrCreate(
            ['id' => $id],
            [
                'test_parameter_id' => $param->id,
                'gender' => $gender,
                'age_min_days' => $min,
                'age_max_days' => $max,
                'age_label' => $label,
                'low' => $rangeData['low'] ?? null,
                'high' => $rangeData['high'] ?? null,
                'critical_low' => $rangeData['critical_low'] ?? null,
                'critical_high' => $rangeData['critical_high'] ?? null,
                'text_normal' => $rangeData['text_normal'] ?? null,
                'range_text' => $rangeData['range_text'] ?? null,
                'unit' => $rangeData['unit'] ?? $paramData['unit'] ?? null,
                'population' => $rangeData['population'] ?? null,
                'methodology' => $rangeData['methodology'] ?? $testData['methodology'] ?? null,
                'source' => $rangeData['source'] ?? $testData['source'] ?? null,
                'verification_status' => $this->status($rangeData),
                'verified_by' => $this->isVerified($rangeData) ? $rangeData['verified_by'] : null,
                'verified_at' => $this->isVerified($rangeData) ? $rangeData['verified_at'] : null,
                'rev' => 1,
            ]
        );
    }

    private function isVerified(array $src): bool
    {
        return ($src['verification_status'] ?? null) === ReferenceRange::STATUS_VERIFIED
            && !empty($src['verified_by'])
            && !empty($src['verified_at']);
    }

    private function status(array $src): string
    {
        return $this->isVerified($src) ? ReferenceRange::STATUS_VERIFIED : ReferenceRange::STATUS_PENDING;
    }

    private function uuid(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::ID_NAMESPACE.':'.$key)->toString();
    }
}
