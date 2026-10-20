<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/** Everything known about one symbol for a request; parts that failed to load are empty. */
final class SymbolBundle
{
    /**
     * @param array<string, mixed> $info
     * @param list<array<string, mixed>> $flowRecords
     * @param array<string, mixed> $priceInfo
     * @param array<string, mixed> $clientType
     * @param list<array<string, mixed>> $bestLimits
     */
    public function __construct(
        public readonly Instrument $instrument,
        public readonly PriceHistory $raw,
        public readonly PriceHistory $history,      // adjusted for capital increases / dividends
        public readonly array $info = [],
        public readonly array $flowRecords = [],
        public readonly array $priceInfo = [],
        public readonly array $clientType = [],
        public readonly array $bestLimits = [],
    ) {
    }

    /** Latest traded price, else today's close, else the last daily close. */
    public function lastPrice(): ?float
    {
        return Field::float($this->priceInfo, 'pDrCotVal') ?: Field::float($this->priceInfo, 'pClosing')
            ?: $this->raw->last();
    }

    /** Official closing price (قیمت پایانی), used for valuation as brokers do. */
    public function closePrice(): ?float
    {
        return Field::float($this->priceInfo, 'pClosing') ?: $this->raw->last();
    }

    public function referencePrice(): ?float
    {
        return Field::float($this->priceInfo, 'priceYesterday') ?: $this->raw->previousClose();
    }

    public function status(): string
    {
        return trim((string) Field::get(Field::get($this->priceInfo, 'instrumentState', []), 'cEtavalTitle', ''));
    }

    public function sector(): string
    {
        return trim((string) Field::get(Field::get($this->info, 'sector', []), 'lSecVal', ''));
    }

    public function subSector(): string
    {
        return trim((string) Field::get(Field::get($this->info, 'subSector', []), 'lSoSecVal', ''));
    }

    /** Trading board, e.g. «بازار اول (تابلوی اصلی) بورس», when the endpoint provides it. */
    public function board(): string
    {
        return trim((string) Field::get($this->info, ['cgrValCotTitle'], ''));
    }

    /** Classification with the freshest data: the instrument registry plus this request's info. */
    public function assetClass(): AssetClass
    {
        $i = $this->instrument;
        $name = $i->name ?: trim((string) Field::get($this->info, 'lVal30', ''));
        $isin = $i->isin ?: (string) Field::get($this->info, 'cIsin', '');
        return AssetClass::classify($isin, $name, $i->symbol, $this->sector() ?: $i->sector, $this->subSector() ?: $i->subSector,
            $this->board() ?: ($i->board ?: $i->market));
    }

    /** 'buy' / 'sell' queue from the first order-book level, or ''. */
    public function queue(): string
    {
        $first = null;
        foreach ($this->bestLimits as $lv) {
            if ((int) Field::get($lv, 'number', 0) === 1) {
                $first = $lv;
            }
        }
        $first ??= $this->bestLimits[0] ?? null;
        if (!$first) {
            return '';
        }
        $bid = Field::float($first, 'pMeDem', 0.0);
        $ask = Field::float($first, 'pMeOf', 0.0);
        return $bid > 0 && $ask <= 0 ? 'buy' : ($ask > 0 && $bid <= 0 ? 'sell' : '');
    }
}
