<?php

declare(strict_types=1);

namespace Tungo\Sdk;

final class TungoMoney
{
    public static function toTiyn(float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function toTenge(int $tiyn): float
    {
        return $tiyn / 100.0;
    }

    public static function formatTiyn(int $tiyn, string $currency = 'KZT'): string
    {
        $symbol = match (strtoupper(trim($currency))) {
            '', 'KZT' => '₸',
            'USD' => '$',
            'EUR' => '€',
            'RUB' => '₽',
            default => strtoupper(trim($currency)),
        };
        $neg = $tiyn < 0;
        $abs = abs($tiyn);
        $whole = intdiv($abs, 100);
        $frac = $abs % 100;
        $formattedWhole = number_format($whole, 0, ',', ' ');
        $sign = $neg ? '-' : '';
        return sprintf('%s%s,%02d %s', $sign, $formattedWhole, $frac, $symbol);
    }
}
