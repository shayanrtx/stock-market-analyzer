<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

use Sabadgardan\Core\Database;

/**
 * Application-facing market data service: instrument registry (persisted), symbol search and
 * bulk loading of symbol bundles in one parallel batch.
 */
final class MarketData
{
    public const PARTS_PRICE = ['price'];
    public const PARTS_FULL = ['history', 'info', 'flow', 'price', 'client', 'book'];

    public function __construct(
        public readonly TsetmcClient $cdn,
        public readonly ?GatewayClient $gateway,
        private Database $db,
    ) {
    }

    /** @return list<array<string, string>> */
    public function search(string $query): array
    {
        $out = [];
        foreach (array_slice($this->cdn->search($query), 0, 15) as $row) {
            $inst = Instrument::fromSearch($row);
            if ($inst->insCode === '' || $inst->symbol === '') {
                continue;
            }
            $this->remember($inst);
            $out[] = $inst->toArray();
        }
        // Exact symbol matches first, then shorter symbols (main board before derivatives).
        $q = Text::normalizeSymbol($query);
        usort($out, static fn ($a, $b) => [Text::normalizeSymbol($a['symbol']) !== $q, mb_strlen($a['symbol'])]
            <=> [Text::normalizeSymbol($b['symbol']) !== $q, mb_strlen($b['symbol'])]);
        return $out;
    }

    public function instrument(string $insCode): ?Instrument
    {
        $row = $this->db->one('SELECT * FROM instruments WHERE ins_code = ?', [$insCode]);
        return $row ? new Instrument($row['ins_code'], $row['symbol'], $row['name'], $row['isin'], $row['market']) : null;
    }

    /** Resolves a typed symbol through the registry or a search, and remembers it. */
    public function resolve(string $symbol): Instrument
    {
        $row = $this->db->one('SELECT * FROM instruments WHERE symbol = ?', [Text::normalizeSymbol($symbol)]);
        if ($row) {
            return new Instrument($row['ins_code'], $row['symbol'], $row['name'], $row['isin'], $row['market']);
        }
        $inst = $this->cdn->resolve($symbol);
        $this->remember($inst);
        return $inst;
    }

    public function remember(Instrument $i, string $sector = ''): void
    {
        $row = ['symbol' => Text::normalizeSymbol($i->symbol), 'name' => $i->name, 'isin' => $i->isin,
                'market' => $i->market, 'updated_at' => date('Y-m-d H:i:s')];
        if ($sector !== '') {
            $row['sector'] = $sector;
        }
        if ($this->db->value('SELECT 1 FROM instruments WHERE ins_code = ?', [$i->insCode])) {
            $this->db->update('instruments', $row, ['ins_code' => $i->insCode]);
        } else {
            $this->db->insert('instruments', ['ins_code' => $i->insCode] + $row + ['sector' => $sector]);
        }
    }

    /**
     * Loads bundles for many symbols with one parallel batch. Failed parts stay empty; a symbol
     * whose price history can't be loaded (when requested) is reported in ``$errors``.
     *
     * @param list<string> $insCodes
     * @param list<string> $parts subset of PARTS_FULL
     * @param array<string, string> $errors insCode => message (out)
     * @return array<string, SymbolBundle>
     */
    public function bundles(array $insCodes, array $parts = self::PARTS_FULL, array &$errors = []): array
    {
        $insCodes = array_values(array_unique(array_filter($insCodes)));
        $paths = [];
        foreach ($insCodes as $c) {
            $paths += array_filter([
                "history|$c" => in_array('history', $parts, true) ? "ClosingPrice/GetClosingPriceDailyList/$c/0" : null,
                "info|$c" => in_array('info', $parts, true) ? "Instrument/GetInstrumentInfo/$c" : null,
                "flow|$c" => in_array('flow', $parts, true) ? "ClientType/GetClientTypeHistory/$c" : null,
                "price|$c" => in_array('price', $parts, true) ? "ClosingPrice/GetClosingPriceInfo/$c" : null,
                "client|$c" => in_array('client', $parts, true) ? "ClientType/GetClientType/$c/1/0" : null,
                "book|$c" => in_array('book', $parts, true) ? "BestLimits/$c" : null,
            ]);
        }
        $ttl = static fn (string $key) => match (explode('|', $key)[0]) {
            'history', 'flow' => TsetmcClient::TTL_DAILY,
            'info' => TsetmcClient::TTL_STATIC,
            default => TsetmcClient::TTL_LIVE,
        };
        $data = [];
        foreach ([TsetmcClient::TTL_DAILY, TsetmcClient::TTL_STATIC, TsetmcClient::TTL_LIVE] as $t) {
            $group = array_filter($paths, static fn ($k) => $ttl($k) === $t, ARRAY_FILTER_USE_KEY);
            if ($group) {
                $data += $this->cdn->tryMany($group, $t);
            }
        }

        $out = [];
        foreach ($insCodes as $c) {
            $inst = $this->instrument($c) ?? new Instrument($c, $c);
            $raw = PriceHistory::fromClosingPriceDaily(Field::list($data["history|$c"] ?? [], 'closingPriceDaily'));
            if (in_array('history', $parts, true) && $raw->isEmpty()) {
                $errors[$c] = $data["history|$c"] === null ? 'دریافت تاریخچه‌ی قیمت ممکن نشد' : 'تاریخچه‌ی قیمت خالی است';
            }
            $info = (array) Field::get($data["info|$c"] ?? [], 'instrumentInfo', []);
            $bundle = new SymbolBundle(
                $inst, $raw, $raw->adjusted(), $info,
                Field::list($data["flow|$c"] ?? [], 'clientType'),
                (array) Field::get($data["price|$c"] ?? [], 'closingPriceInfo', []),
                (array) Field::get($data["client|$c"] ?? [], 'clientType', []),
                Field::list($data["book|$c"] ?? [], 'bestLimits'),
            );
            if ($info && ($sector = $bundle->sector()) !== '') {
                $this->remember($inst, $sector);
            }
            $out[$c] = $bundle;
        }
        return $out;
    }

    /** @return array<string, float> TEDPIX closes by date, or [] when unavailable */
    public function benchmark(): array
    {
        try {
            return $this->cdn->indexHistory();
        } catch (MarketException) {
            return [];
        }
    }
}
