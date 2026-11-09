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

    /** Проверка контрольной суммы 12-значного БИН или ИИН Республики Казахстан (модуль 11, два прохода). */
    public static function isValidBinIin(string $value): bool
    {
        if (strlen($value) !== 12 || !ctype_digit($value)) {
            return false;
        }
        $w1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
        $sum1 = 0;
        for ($i = 0; $i < 11; $i++) {
            $sum1 += ((int) $value[$i]) * $w1[$i];
        }
        $control = $sum1 % 11;
        if ($control === 10) {
            $w2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];
            $sum2 = 0;
            for ($i = 0; $i < 11; $i++) {
                $sum2 += ((int) $value[$i]) * $w2[$i];
            }
            $control = $sum2 % 11;
        }
        return $control < 10 && $control === ((int) $value[11]);
    }
}
