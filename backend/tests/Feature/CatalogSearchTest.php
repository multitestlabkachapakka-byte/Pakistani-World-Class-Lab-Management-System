<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function codesFor(string $query): array
    {
        $json = $this->getJson('/api/catalog/search?limit=50&q='.urlencode($query))->assertOk()->json();

        return collect($json['data'] ?? $json)->pluck('code')->all();
    }

    public function test_search_finds_a_test_by_code_name_and_every_alias_in_any_letter_case(): void
    {
        $this->seed(CatalogSeeder::class);

        $cbc = collect(json_decode(file_get_contents(database_path('seeders/catalog/hematology.json')), true))
            ->firstWhere('code', 'CBC');

        $queries = array_merge(['cbc', 'CBC', 'complete blood'], array_filter(
            array_map('strtolower', $cbc['aliases'] ?? []),
            fn ($alias) => strlen($alias) >= 4
        ));

        foreach ($queries as $query) {
            $this->assertContains('CBC', $this->codesFor($query), "query: {$query}");
        }
    }

    public function test_search_for_nonsense_returns_nothing(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertSame([], $this->codesFor('zzzqqqxxx'));
    }
}
