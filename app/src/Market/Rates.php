<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

use Sabadgardan\Core\Cache;
use Sabadgardan\Core\Settings;

/**
 * The risk-free rate the analysis uses. By default it follows the market: the median yield of
 * treasury bills (TreasuryYield), refreshed every six hours. Users who prefer a fixed figure
 * switch "risk_free_auto" off and enter it in Settings; the setting is also the fallback when
 * no bill has traded recently.
 */
final class Rates
{
    public const TTL = 21600;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private MarketData $market, private Settings $settings, private Cache $cache,
                                ?callable $clock = null)
    {
        $this->clock = $clock ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /** @return array{rate: float, bills: list<array<string, mixed>>}|null */
    public function treasury(): ?array
    {
        $today = date('Y-m-d', ($this->clock)());
        $key = "treasury:$today";
        $hit = $this->cache->get($key);
        if (is_array($hit)) {
            return $hit['rate'] === null ? null : $hit;
        }
        try {
            $result = TreasuryYield::fromMarket($this->market, $today);
        } catch (MarketException) {
            $result = null;
        }
        $this->cache->set($key, $result ?? ['rate' => null], $result ? self::TTL : 1800);
        return $result;
    }

    public function riskFree(int $userId): float
    {
        if ($this->auto($userId) && ($t = $this->treasury())) {
            return $t['rate'];
        }
        return $this->settings->float($userId, 'risk_free_annual');
    }

    /** «بازده اسناد خزانه» or «تنظیمات», for labels next to the rate. */
    public function source(int $userId): string
    {
        return $this->auto($userId) && $this->treasury() ? 'بازده اسناد خزانه' : 'تنظیمات';
    }

    private function auto(int $userId): bool
    {
        return $this->settings->forUser($userId, 'risk_free_auto') !== '0';
    }
}
