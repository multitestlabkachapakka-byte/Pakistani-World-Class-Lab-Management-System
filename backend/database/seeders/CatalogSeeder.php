<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\TestDefinition;
use App\Models\TestParameter;
use App\Models\ReferenceRange;

/**
 * Loads verified catalog JSON files from database/seeders/catalog/
 * Never invents ranges — only seeds what is present in the JSON files.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalogDir = database_path('seeders/catalog');

        if (!File::isDirectory($catalogDir)) {
            $this->command?->warn('No catalog directory found.');
            return;
        }

        $files = File::files($catalogDir);
        $totalTests = 0;
        $totalParams = 0;

        foreach ($files as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $data = json_decode(File::get($file->getPathname()), true);
            if (!is_array($data)) {
                $this->command?->error("Invalid JSON: {$file->getFilename()}");
                continue;
            }

            foreach ($data as $testData) {
                $this->seedTest($testData);
                $totalTests++;
                $totalParams += count($testData['parameters'] ?? []);
            }

            $this->command?->info("Loaded: {$file->getFilename()}");
        }

        $this->command?->info("Catalog seed complete. Tests: {$totalTests}, Parameters: {$totalParams}");
    }

    protected function seedTest(array $data): void
    {
        $test = TestDefinition::updateOrCreate(
            ['code' => $data['code']],
            [
                'id' => (string) Str::uuid(),
                'name' => $data['name'],
                'short_name' => $data['short_name'] ?? null,
                'department' => $data['department'],
                'aliases' => $data['aliases'] ?? [],
                'sample_type' => $data['sample_type'] ?? null,
                'methodology' => $data['methodology'] ?? null,
                'is_profile' => $data['is_profile'] ?? false,
                'is_active' => true,
                'verification_status' => $data['verification_status'] ?? 'VERIFY REQUIRED',
                'source' => $data['source'] ?? null,
                'notes' => $data['notes'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'rev' => 1,
            ]
        );

        // Clear existing parameters for clean re-seed (dev only)
        $test->parameters()->delete();

        foreach ($data['parameters'] ?? [] as $paramData) {
            $param = TestParameter::create([
                'id' => (string) Str::uuid(),
                'test_definition_id' => $test->id,
                'name' => $paramData['name'],
                'short_name' => $paramData['short_name'] ?? null,
                'unit' => $paramData['unit'] ?? null,
                'data_type' => $paramData['data_type'] ?? 'numeric',
                'is_calculated' => $paramData['is_calculated'] ?? false,
                'calculation_formula' => $paramData['calculation_formula'] ?? null,
                'sort_order' => $paramData['sort_order'] ?? 0,
                'print_by_default' => $paramData['print_by_default'] ?? true,
            ]);

            foreach ($paramData['ranges'] ?? [] as $rangeData) {
                ReferenceRange::create([
                    'id' => (string) Str::uuid(),
                    'test_parameter_id' => $param->id,
                    'gender' => $rangeData['gender'] ?? 'both',
                    'age_min_days' => $rangeData['age_min_days'] ?? null,
                    'age_max_days' => $rangeData['age_max_days'] ?? null,
                    'age_label' => $rangeData['age_label'] ?? 'Adult',
                    'low' => $rangeData['low'] ?? null,
                    'high' => $rangeData['high'] ?? null,
                    'critical_low' => $rangeData['critical_low'] ?? null,
                    'critical_high' => $rangeData['critical_high'] ?? null,
                    'text_normal' => $rangeData['text_normal'] ?? null,
                    'range_text' => $rangeData['range_text'] ?? null,
                    'source' => $rangeData['source'] ?? $data['source'] ?? null,
                    'verification_status' => $rangeData['verification_status'] ?? 'VERIFY REQUIRED',
                ]);
            }
        }
    }
}
