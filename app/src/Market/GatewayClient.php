<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/**
 * Official tse.ir gateway (https://webgw.tse.ir). Keyed by ISIN; answers Iranian IPs only.
 * Numeric cells are ``{"value": x, "state": y}``; read them with Field.
 */
final class GatewayClient extends TsetmcClient
{
    public const BASE_URL = 'https://webgw.tse.ir';

    protected string $baseUrl = self::BASE_URL;

    /** @return bool|null whether the market is open today (holidays), null when unknown */
    public function marketOpen(): ?bool
    {
        try {
            $rows = $this->get('api/v1/PublicData/MarketDate/fa', self::TTL_INTRADAY);
        } catch (MarketException) {
            return null;
        }
        $flags = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $flag = Field::get($r, 'isOpen');
            if ($flag !== null) {
                $flags[] = (bool) $flag;
            }
        }
        return $flags ? in_array(true, $flags, true) : null;
    }

    /** @return array<string, mixed> */
    public function marketSummary(): array
    {
        $data = $this->get('InstrumentProvider/api/v1/Instrument/MarketSummary/fa', self::TTL_LIVE);
        return is_array($data) ? $data : [];
    }

    /**
     * @param string $kind Cash | Etf | Future | Option | Debt | TradeOption | TALMarket
     * @return list<array<string, mixed>>
     */
    public function marketWatch(string $kind = 'Cash'): array
    {
        return Field::list($this->get("InstrumentProvider/api/v1/MarketWatch/MarketWatch$kind/fa", self::TTL_LIVE),
            'Items');
    }
}
