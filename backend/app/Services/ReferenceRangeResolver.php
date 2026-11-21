<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\ReferenceRange;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Chooses the ONE stored reference range that applies to a patient.
 *
 * Safety rule: if no stored range matches the patient's sex AND age, return null so the
 * report shows "no reference range" — never a range for another population.
 */
final class ReferenceRangeResolver
{
    /** "Adult" ranges start at 18 years (assumption; the pathologist can set exact bounds per range). */
    public const ADULT_MIN_DAYS = 6574;

    /** Patient age in whole days on the given date; null when no age information exists. */
    public static function ageInDays(Patient $patient, ?CarbonInterface $asOf = null): ?int
    {
        $asOf ??= now('Asia/Karachi');

        if ($patient->date_of_birth !== null) {
            $dob = Carbon::parse($patient->date_of_birth->format('Y-m-d'), 'UTC')->startOfDay();
            $ref = Carbon::parse($asOf->format('Y-m-d'), 'UTC')->startOfDay();

            return max(0, intdiv($ref->getTimestamp() - $dob->getTimestamp(), 86400));
        }

        if ($patient->age_years === null && $patient->age_months === null && $patient->age_days === null) {
            return null;
        }

        return (int) round(
            ((int) $patient->age_years) * 365.25
            + ((int) $patient->age_months) * 30.4375
            + ((int) $patient->age_days)
        );
    }

    /**
     * @param iterable<ReferenceRange> $ranges candidate ranges of one parameter
     */
    public function resolve(iterable $ranges, ?string $gender, ?int $ageDays): ?ReferenceRange
    {
        $best = null;
        $bestKey = null;

        foreach ($ranges as $range) {
            $rangeGender = $range->gender ?: 'both';
            if ($rangeGender !== 'both' && $rangeGender !== $gender) {
                continue;
            }

            $min = $range->age_min_days;
            $max = $range->age_max_days;
            $bounded = $min !== null || $max !== null;

            if ($bounded) {
                if ($ageDays === null) {
                    continue;
                }
                if ($min !== null && $ageDays < (int) $min) {
                    continue;
                }
                if ($max !== null && $ageDays > (int) $max) {
                    continue;
                }
            }

            // Better = sex-specific over "both", then the narrowest age band, then stable id order.
            $span = $bounded ? ((int) ($max ?? 36525)) - ((int) ($min ?? 0)) : PHP_INT_MAX;
            $key = [$rangeGender === 'both' ? 0 : 1, -$span];

            $cmp = $bestKey === null ? 1 : ($key <=> $bestKey);
            if ($cmp > 0 || ($cmp === 0 && strcmp((string) $range->id, (string) $best->id) < 0)) {
                $best = $range;
                $bestKey = $key;
            }
        }

        return $best;
    }
}
