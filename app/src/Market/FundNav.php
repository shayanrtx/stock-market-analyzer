<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/**
 * Published NAV of a traded fund and its premium or discount («حباب»): price / redemption NAV − 1.
 * Source: the fund's ETF record (pRedTran / pSubTran, checked against the live API); if that lacks a NAV,
 * the fund registry of its type (navRed / navSub / navStat), matched by name.
 * A large premium means paying more than the fund's assets are worth (common in leveraged and gold
 * funds after rallies); a discount is the opposite.
 */
final class FundNav
{
    /** AssetClass kind => Fund/GetFunds type id */
    public const REGISTRY_TYPES = [
        AssetClass::FUND_FIXED_INCOME => 4, AssetClass::FUND_GOLD => 5, AssetClass::FUND_COMMODITY => 5,
        AssetClass::FUND_EQUITY => 6, AssetClass::FUND_MIXED => 7, AssetClass::FUND_REAL_ESTATE => 18,
        AssetClass::FUND_LEVERAGED => 22, AssetClass::FUND_OTHER => 6,
    ];

    /** @return array{redemption: float, subscription: ?float, statistical: ?float, source: string}|null */
    public static function fetch(TsetmcClient $cdn, Instrument $inst, AssetClass $class): ?array
    {
        try {
            $nav = self::fromRecord($cdn->etf($inst->insCode));
            if ($nav) {
                return $nav + ['source' => 'etf'];
            }
        } catch (MarketException) {
            // fall through to the registry
        }
        $type = self::REGISTRY_TYPES[$class->kind] ?? null;
        if ($type === null || $inst->name === '') {
            return null;
        }
        try {
            foreach ($cdn->funds($type) as $f) {
                if (self::sameFund($inst->name, (string) Field::get($f, 'mfName', ''))) {
                    $nav = self::fromRecord($f);
                    return $nav ? $nav + ['source' => 'registry'] : null;
                }
            }
        } catch (MarketException) {
        }
        return null;
    }

    /** @return array{redemption: float, subscription: ?float, statistical: ?float}|null */
    public static function fromRecord(array $r): ?array
    {
        $red = Field::float($r, ['pRedTran', 'navRed', 'redemptionNav', 'navRedemption']);
        if (!$red || $red <= 0) {
            return null;
        }
        return ['redemption' => $red, 'subscription' => Field::float($r, ['pSubTran', 'navSub', 'subscriptionNav']) ?: null,
                'statistical' => Field::float($r, ['navStat', 'statisticalNav']) ?: null];
    }

    public static function premium(?float $price, ?array $nav): ?float
    {
        return $price && $nav ? ($price / $nav['redemption'] - 1) * 100 : null;
    }

    /**
     * Names differ in boilerplate («صندوق س.» vs «صندوق سرمایه گذاری»): every distinctive word of the
     * instrument's name must appear in the registry name.
     */
    public static function sameFund(string $instrumentName, string $registryName): bool
    {
        $words = static function (string $s): array {
            $s = strtr($s, ['ي' => 'ی', 'ك' => 'ک', 'آ' => 'ا', "\u{200C}" => ' ', '.' => ' ', '-' => ' ']);
            $stop = ['صندوق', 'س', 'سرمایه', 'گذاری', 'سرمایهگذاری', 'قابل', 'معامله', 'در', 'و', 'با', 'ثابت', 'درامد'];
            return array_values(array_diff(preg_split('/\s+/u', trim($s)) ?: [], $stop, ['']));
        };
        $mine = $words($instrumentName);
        $theirs = $words($registryName);
        return $mine && !array_diff($mine, $theirs);
    }
}
