<?php

namespace Tests\Feature;

use App\Models\ReferenceRange;
use App\Models\TestDefinition;
use App\Models\TestParameter;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: int, 1: int, 2: int} tests, parameters, ranges found in the JSON files */
    private function jsonCounts(): array
    {
        $tests = $params = $ranges = 0;

        foreach (glob(database_path('seeders/catalog/*.json')) as $file) {
            foreach (json_decode(file_get_contents($file), true) as $test) {
                $tests++;
                foreach ($test['parameters'] ?? [] as $param) {
                    $params++;
                    $ranges += count($param['ranges'] ?? []);
                }
            }
        }

        return [$tests, $params, $ranges];
    }

    public function test_every_catalog_entry_is_seeded_exactly_once(): void
    {
        $this->seed(CatalogSeeder::class);

        [$tests, $params, $ranges] = $this->jsonCounts();

        $this->assertGreaterThan(0, $tests);
        // A lower database count would mean two entries collapsed onto one deterministic id.
        $this->assertSame($tests, TestDefinition::count());
        $this->assertSame($params, TestParameter::count());
        $this->assertSame($ranges, ReferenceRange::count());
    }

    public function test_seeding_twice_changes_nothing_and_keeps_ids_stable(): void
    {
        $this->seed(CatalogSeeder::class);
        $before = [
            TestDefinition::orderBy('code')->pluck('id', 'code')->all(),
            TestParameter::count(),
            ReferenceRange::count(),
        ];

        $this->seed(CatalogSeeder::class);
        $after = [
            TestDefinition::orderBy('code')->pluck('id', 'code')->all(),
            TestParameter::count(),
            ReferenceRange::count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_nothing_is_verified_without_a_named_verifier(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertSame(0, ReferenceRange::where('verification_status', ReferenceRange::STATUS_VERIFIED)
            ->where(fn ($q) => $q->whereNull('verified_by')->orWhereNull('verified_at'))
            ->count());
        $this->assertSame(0, TestDefinition::where('verification_status', ReferenceRange::STATUS_VERIFIED)
            ->whereNull('verified_by')
            ->count());
    }

    public function test_shipped_seed_files_never_claim_verification_without_a_verifier(): void
    {
        foreach (glob(database_path('seeders/catalog/*.json')) as $file) {
            foreach (json_decode(file_get_contents($file), true) as $test) {
                $this->assertVerifiedClaimHasVerifier($test, $file);
                foreach ($test['parameters'] ?? [] as $param) {
                    foreach ($param['ranges'] ?? [] as $range) {
                        $this->assertVerifiedClaimHasVerifier($range, $file);
                    }
                }
            }
        }
    }

    private function assertVerifiedClaimHasVerifier(array $entry, string $file): void
    {
        if (($entry['verification_status'] ?? null) === 'VERIFIED') {
            $this->assertNotEmpty($entry['verified_by'] ?? null, basename($file).': VERIFIED without verified_by');
            $this->assertNotEmpty($entry['verified_at'] ?? null, basename($file).': VERIFIED without verified_at');
        }
        $this->addToAssertionCount(1);
    }

    public function test_adult_ranges_always_carry_a_lower_age_bound(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertSame(0, ReferenceRange::where('age_label', 'Adult')->whereNull('age_min_days')->count());
    }

    public function test_limits_are_consistent_and_no_range_is_empty(): void
    {
        $this->seed(CatalogSeeder::class);

        foreach (ReferenceRange::all() as $range) {
            if ($range->low !== null && $range->high !== null) {
                $this->assertLessThanOrEqual((float) $range->high, (float) $range->low, "low > high for range {$range->id}");
            }

            $this->assertTrue(
                $range->low !== null || $range->high !== null || filled($range->text_normal) || filled($range->range_text),
                "range {$range->id} has no limit and no text"
            );
        }
    }

    public function test_every_nonzero_limit_appears_in_its_range_text(): void
    {
        // A limit that drives flags must be visible in the printed text. Zero is a placeholder.
        // Documented exception: HbA1c "< 5.7%" is stored as 5.6 because results are reported to one decimal.
        $allowed = ['HBA1C|HbA1c|both|high'];
        $violations = [];

        foreach (glob(database_path('seeders/catalog/*.json')) as $file) {
            foreach (json_decode(file_get_contents($file), true) as $test) {
                foreach ($test['parameters'] ?? [] as $param) {
                    foreach ($param['ranges'] ?? [] as $range) {
                        preg_match_all('/\d+(?:\.\d+)?/', (string) ($range['range_text'] ?? ''), $m);
                        $numbers = array_map('floatval', $m[0]);

                        foreach (['low', 'high'] as $limit) {
                            $value = $range[$limit] ?? null;
                            if ($value === null || (float) $value === 0.0) {
                                continue;
                            }
                            if (!in_array((float) $value, $numbers, true)) {
                                $violations[] = "{$test['code']}|{$param['name']}|".($range['gender'] ?? 'both')."|{$limit}";
                            }
                        }
                    }
                }
            }
        }

        $this->assertEqualsCanonicalizing($allowed, $violations, 'invented limits found in the seed data');
    }

    public function test_every_range_records_its_source(): void
    {
        $this->seed(CatalogSeeder::class);

        $missing = ReferenceRange::whereNull('source')->orWhere('source', '')->count();

        $this->assertSame(0, $missing, 'ranges without a recorded source');
    }
}
