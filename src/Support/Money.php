<?php

namespace Nit\AgraniHr\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Exact money arithmetic for payroll (never floats). Intermediate results keep 10 decimals; round() gives
 * 2 decimals, half up. Works with every brick/math version Laravel 13 allows (HalfUp / HALF_UP).
 */
final class Money
{
    private const WORKING_SCALE = 10;

    public static function of(BigNumber|string|int|float|null $value): BigDecimal
    {
        if ($value instanceof BigNumber) {
            return $value->toBigDecimal();
        }

        return BigDecimal::of($value === null || $value === '' ? '0' : (string) $value);
    }

    public static function round(BigNumber|string|int|float|null $value, int $scale = 2): string
    {
        return (string) self::of($value)->toScale($scale, self::mode('HalfUp', 'HALF_UP'));
    }

    public static function add(BigNumber|string|int|float|null ...$values): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::of($value));
        }

        return self::round($total);
    }

    public static function sub(BigNumber|string|int|float|null $a, BigNumber|string|int|float|null $b): string
    {
        return self::round(self::of($a)->minus(self::of($b)));
    }

    /** $value × $numerator ÷ $denominator, exact to the working scale. */
    public static function ratio(BigNumber|string|int|float|null $value, BigNumber|string|int|float $numerator, BigNumber|string|int|float $denominator): BigDecimal
    {
        return self::of($value)->multipliedBy(self::of($numerator))->dividedBy(self::of($denominator), self::WORKING_SCALE, self::mode('HalfUp', 'HALF_UP'));
    }

    public static function compare(BigNumber|string|int|float|null $a, BigNumber|string|int|float|null $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function isPositive(BigNumber|string|int|float|null $value): bool
    {
        return self::of($value)->isPositive();
    }

    public static function min(BigNumber|string|int|float|null ...$values): string
    {
        $values = array_map(fn ($value): BigDecimal => self::of($value), $values);

        return self::round(BigDecimal::min(...$values));
    }

    private static function mode(string $new, string $old): RoundingMode
    {
        return constant(RoundingMode::class.'::'.(defined(RoundingMode::class.'::'.$new) ? $new : $old));
    }
}
