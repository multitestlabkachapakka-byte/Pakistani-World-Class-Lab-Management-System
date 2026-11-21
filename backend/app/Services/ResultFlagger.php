<?php

namespace App\Services;

/**
 * Turns a typed result into H/L (abnormal) or HH/LL (critical).
 *
 * Works only on plain numeric entries and only against limits that are actually stored;
 * it never invents a limit. Entries like "<5" or "Positive" get no automatic flag.
 */
final class ResultFlagger
{
    /**
     * @return array{flag: ?string, is_abnormal: bool, is_critical: bool}
     */
    public static function evaluate(?string $value, $low, $high, $criticalLow = null, $criticalHigh = null): array
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '' || !is_numeric($value)) {
            return self::result(null);
        }

        $v = (float) $value;

        if ($criticalLow !== null && $v < (float) $criticalLow) {
            return self::result('LL');
        }
        if ($criticalHigh !== null && $v > (float) $criticalHigh) {
            return self::result('HH');
        }
        if ($low !== null && $v < (float) $low) {
            return self::result('L');
        }
        if ($high !== null && $v > (float) $high) {
            return self::result('H');
        }

        return self::result(null);
    }

    private static function result(?string $flag): array
    {
        return [
            'flag' => $flag,
            'is_abnormal' => $flag !== null,
            'is_critical' => $flag === 'LL' || $flag === 'HH',
        ];
    }
}
