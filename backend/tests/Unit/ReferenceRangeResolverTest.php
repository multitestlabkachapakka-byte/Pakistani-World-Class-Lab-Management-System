<?php

namespace Tests\Unit;

use App\Models\Patient;
use App\Models\ReferenceRange;
use App\Services\ReferenceRangeResolver;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferenceRangeResolverTest extends TestCase
{
    private const ADULT = ReferenceRangeResolver::ADULT_MIN_DAYS;

    private ReferenceRangeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ReferenceRangeResolver();
    }

    private function range(array $attrs = []): ReferenceRange
    {
        return new ReferenceRange($attrs + ['id' => (string) Str::uuid(), 'gender' => 'both']);
    }

    public function test_sex_specific_range_wins_over_both(): void
    {
        $both = $this->range(['age_min_days' => self::ADULT]);
        $male = $this->range(['gender' => 'male', 'age_min_days' => self::ADULT]);

        $this->assertSame($male, $this->resolver->resolve([$both, $male], 'male', 10000));
        $this->assertSame($both, $this->resolver->resolve([$both, $male], 'female', 10000));
    }

    public function test_an_adult_range_is_never_returned_for_a_child(): void
    {
        $adultOnly = $this->range(['age_label' => 'Adult', 'age_min_days' => self::ADULT]);

        $this->assertNull($this->resolver->resolve([$adultOnly], 'male', 1800));
        $this->assertSame($adultOnly, $this->resolver->resolve([$adultOnly], 'male', self::ADULT));
    }

    public function test_age_bounds_are_inclusive(): void
    {
        $band = $this->range(['age_min_days' => 100, 'age_max_days' => 200]);

        $this->assertNull($this->resolver->resolve([$band], 'male', 99));
        $this->assertSame($band, $this->resolver->resolve([$band], 'male', 100));
        $this->assertSame($band, $this->resolver->resolve([$band], 'male', 200));
        $this->assertNull($this->resolver->resolve([$band], 'male', 201));
    }

    public function test_the_narrowest_matching_age_band_wins(): void
    {
        $infant = $this->range(['age_min_days' => 0, 'age_max_days' => 365]);
        $child = $this->range(['age_min_days' => 0, 'age_max_days' => 6000]);

        $this->assertSame($infant, $this->resolver->resolve([$child, $infant], 'male', 100));
        $this->assertSame($child, $this->resolver->resolve([$infant, $child], 'male', 1000));
    }

    public function test_an_unbounded_range_applies_to_every_age_including_unknown(): void
    {
        $all = $this->range();

        $this->assertSame($all, $this->resolver->resolve([$all], 'male', 5));
        $this->assertSame($all, $this->resolver->resolve([$all], 'male', null));
        $this->assertSame($all, $this->resolver->resolve([$all], null, 5));
    }

    public function test_unknown_age_never_matches_an_age_bounded_range(): void
    {
        $adultOnly = $this->range(['age_min_days' => self::ADULT]);

        $this->assertNull($this->resolver->resolve([$adultOnly], 'male', null));
    }

    public function test_other_or_unknown_sex_only_matches_ranges_for_both(): void
    {
        $male = $this->range(['gender' => 'male']);
        $female = $this->range(['gender' => 'female']);
        $both = $this->range();

        $this->assertNull($this->resolver->resolve([$male, $female], 'other', 10000));
        $this->assertNull($this->resolver->resolve([$male, $female], null, 10000));
        $this->assertSame($both, $this->resolver->resolve([$male, $female, $both], 'other', 10000));
    }

    public function test_ties_are_broken_by_id_so_the_choice_never_depends_on_row_order(): void
    {
        $a = $this->range(['id' => '00000000-0000-4000-8000-000000000001']);
        $b = $this->range(['id' => '00000000-0000-4000-8000-000000000002']);

        $this->assertSame($a, $this->resolver->resolve([$a, $b], 'male', 100));
        $this->assertSame($a, $this->resolver->resolve([$b, $a], 'male', 100));
    }

    public function test_age_in_days_from_date_of_birth(): void
    {
        $asOf = Carbon::parse('2026-11-21 10:00:00', 'Asia/Karachi');

        $this->assertSame(1, ReferenceRangeResolver::ageInDays((new Patient())->forceFill(['date_of_birth' => '2026-11-20']), $asOf));
        $this->assertSame(365, ReferenceRangeResolver::ageInDays((new Patient())->forceFill(['date_of_birth' => '2025-11-21']), $asOf));
        $this->assertSame(0, ReferenceRangeResolver::ageInDays((new Patient())->forceFill(['date_of_birth' => '2027-01-01']), $asOf));
    }

    public function test_age_in_days_from_age_fields_and_unknown_age(): void
    {
        $this->assertSame(365, ReferenceRangeResolver::ageInDays((new Patient())->forceFill(['age_years' => 1])));
        $this->assertSame(183, ReferenceRangeResolver::ageInDays((new Patient())->forceFill(['age_months' => 6])));
        $this->assertSame(10, ReferenceRangeResolver::ageInDays((new Patient())->forceFill(['age_days' => 10])));
        $this->assertNull(ReferenceRangeResolver::ageInDays(new Patient()));
    }
}
