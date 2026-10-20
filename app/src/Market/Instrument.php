<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

final class Instrument
{
    public function __construct(
        public readonly string $insCode,
        public readonly string $symbol,
        public readonly string $name = '',
        public readonly string $isin = '',
        public readonly string $market = '',
    ) {
    }

    /** @param array<string, mixed> $r a GetInstrumentSearch record */
    public static function fromSearch(array $r): self
    {
        return new self(
            (string) Field::get($r, 'insCode', ''),
            trim((string) Field::get($r, 'lVal18AFC', '')),
            trim((string) Field::get($r, 'lVal30', '')),
            (string) Field::get($r, 'cIsin', ''),
            trim((string) Field::get($r, 'flowTitle', '')),
        );
    }

    /** Broad asset class from the ISIN prefix (ENDPOINTS.md, «تشخیص نوع ابزار مالی»). */
    public static function typeOf(string $isin): string
    {
        $isin = strtoupper($isin);
        return match (true) {
            str_starts_with($isin, 'IRT') => 'صندوق',
            str_starts_with($isin, 'IRB') => 'اوراق',
            str_starts_with($isin, 'IRO9'), str_starts_with($isin, 'IRS'), str_starts_with($isin, 'IROF') => 'اختیار معامله',
            str_starts_with($isin, 'IRO') => 'سهام',
            default => 'نامشخص',
        };
    }

    public function type(): string
    {
        return self::typeOf($this->isin);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['ins_code' => $this->insCode, 'symbol' => $this->symbol, 'name' => $this->name,
                'isin' => $this->isin, 'market' => $this->market, 'type' => $this->type()];
    }
}
