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
        public readonly string $market = '',      // بورس / فرابورس (flowTitle)
        public readonly string $sector = '',
        public readonly string $subSector = '',
        public readonly string $board = '',       // trading board (cgrValCotTitle), when known
    ) {
    }

    /** @param array<string, mixed> $row a row of the instruments table */
    public static function fromRow(array $row): self
    {
        return new self((string) $row['ins_code'], (string) $row['symbol'], (string) $row['name'], (string) $row['isin'],
            (string) $row['market'], (string) ($row['sector'] ?? ''), (string) ($row['sub_sector'] ?? ''),
            (string) ($row['board'] ?? ''));
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

    public function assetClass(): AssetClass
    {
        return AssetClass::classify($this->isin, $this->name, $this->symbol, $this->sector, $this->subSector,
            $this->board ?: $this->market);
    }

    /** Broad family (سهام، صندوق، اوراق، …); see assetClass() for the precise kind. */
    public function type(): string
    {
        return $this->assetClass()->group();
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $class = $this->assetClass();
        return ['ins_code' => $this->insCode, 'symbol' => $this->symbol, 'name' => $this->name,
                'isin' => $this->isin, 'market' => $this->market, 'type' => $class->group(), 'class' => $class->label(),
                'class_kind' => $class->kind, 'class_tone' => $class->tone(), 'fee_class' => $class->feeClass()];
    }
}
