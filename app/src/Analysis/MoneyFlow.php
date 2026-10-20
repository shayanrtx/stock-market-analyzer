<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis;

use Sabadgardan\Market\Field;

/**
 * Individual (حقیقی) vs institutional (حقوقی) flow from a ``clientType`` record.
 * Buyer power (قدرت خریدار) = per-capita individual buy volume / per-capita sell volume;
 * above 1 individual buyers are bigger than sellers ("smart money" entering).
 *
 * Today's record (GetClientType) names the counts ``buy_CountI``; the daily history
 * (GetClientTypeHistory) names them ``buy_I_Count``. Both are accepted.
 */
final class MoneyFlow
{
    /** @return array<string, ?float> empty when the record has no volumes */
    public static function fromRecord(array $record, ?float $price): array
    {
        $buyI = Field::float($record, 'buy_I_Volume', 0.0);
        $sellI = Field::float($record, 'sell_I_Volume', 0.0);
        $buyN = Field::float($record, 'buy_N_Volume', 0.0);
        $sellN = Field::float($record, 'sell_N_Volume', 0.0);
        $buyCount = Field::float($record, ['buy_CountI', 'buy_I_Count'], 0.0);
        $sellCount = Field::float($record, ['sell_CountI', 'sell_I_Count'], 0.0);
        if (!$buyI && !$sellI && !$buyN && !$sellN) {
            return [];
        }
        $perBuy = $buyCount ? $buyI / $buyCount : null;
        $perSell = $sellCount ? $sellI / $sellCount : null;
        $total = $buyI + $buyN;
        return [
            'per_capita_buy' => $perBuy && $price ? $perBuy * $price : null,
            'per_capita_sell' => $perSell && $price ? $perSell * $price : null,
            'buyer_power' => $perBuy && $perSell ? $perBuy / $perSell : null,
            'individual_net_volume' => $buyI - $sellI,
            'individual_net_value' => $price ? ($buyI - $sellI) * $price : null,
            'institutional_buy_share' => $total ? $buyN / $total * 100 : null,
        ];
    }

    /**
     * Average buyer power and net individual share of volume over the latest sessions.
     *
     * @param list<array<string, mixed>> $records daily client-type rows
     * @return array<string, float|int>
     */
    public static function trend(array $records, int $days = 20): array
    {
        usort($records, static fn ($a, $b) => (int) Field::get($b, ['recDate', 'dEven'], 0)
            <=> (int) Field::get($a, ['recDate', 'dEven'], 0));
        $recent = array_slice($records, 0, $days);
        $powers = [];
        $net = $total = 0.0;
        foreach ($recent as $r) {
            $f = self::fromRecord($r, null);
            if (!empty($f['buyer_power'])) {
                $powers[] = $f['buyer_power'];
            }
            $net += $f['individual_net_volume'] ?? 0.0;
            $total += Field::float($r, 'buy_I_Volume', 0.0) + Field::float($r, 'buy_N_Volume', 0.0);
        }
        if (!$powers) {
            return [];
        }
        return [
            'days' => count($recent),
            'avg_buyer_power' => array_sum($powers) / count($powers),
            'individual_net_share' => $total ? $net / $total : 0.0,
        ];
    }

    /**
     * Per-day series for charts, oldest first.
     *
     * @return list<array{date: string, buyer_power: ?float, net_volume: float}>
     */
    public static function daily(array $records, int $days = 60): array
    {
        $out = [];
        foreach ($records as $r) {
            $d = (string) Field::get($r, ['recDate', 'dEven'], '');
            if (strlen($d) !== 8) {
                continue;
            }
            $f = self::fromRecord($r, null);
            if ($f) {
                $out[substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2)] = [
                    'buyer_power' => $f['buyer_power'], 'net_volume' => (float) $f['individual_net_volume']];
            }
        }
        ksort($out);
        $rows = [];
        foreach (array_slice($out, -$days, null, true) as $date => $row) {
            $rows[] = ['date' => $date] + $row;
        }
        return $rows;
    }
}
