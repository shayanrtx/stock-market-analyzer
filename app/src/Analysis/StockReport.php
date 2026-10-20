<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis;

use Sabadgardan\Analysis\Potential\PotentialAnalyzer;
use Sabadgardan\Analysis\Potential\PotentialResult;
use Sabadgardan\Market\SymbolBundle;

/** Full single-symbol analysis assembled from a bundle: technicals, money flow and growth potential. */
final class StockReport
{
    /**
     * @param array<string, ?float> $technical
     * @param list<array{score: int, text: string}> $signals
     * @param array<string, ?float> $returns
     * @param array<string, ?float> $flow
     */
    public function __construct(
        public readonly SymbolBundle $bundle,
        public readonly array $technical,
        public readonly array $signals,
        public readonly string $verdict,
        public readonly array $returns,
        public readonly array $flow,
        public readonly PotentialResult $potential,
    ) {
    }

    /** @param array<string, float> $benchmark */
    public static function build(SymbolBundle $b, PotentialAnalyzer $analyzer, array $benchmark = []): self
    {
        $h = $b->history;
        $price = $b->closePrice() ?? (float) $h->last();
        $technical = Technical::snapshot($h);
        $flow = MoneyFlow::fromRecord($b->clientType, $price);
        $signals = Technical::signals($price, $technical, $flow);
        return new self($b, $technical, $signals, Technical::verdict($signals), Technical::returns($h), $flow,
            $analyzer->assess($h, $b->info, $flow, $b->flowRecords, $benchmark));
    }

    public function changePct(): ?float
    {
        $ref = $this->bundle->referencePrice();
        $last = $this->bundle->lastPrice();
        return $ref && $last ? ($last / $ref - 1) * 100 : null;
    }

    public function closeChangePct(): ?float
    {
        $ref = $this->bundle->referencePrice();
        $close = $this->bundle->closePrice();
        return $ref && $close ? ($close / $ref - 1) * 100 : null;
    }

    public function signalScore(): int
    {
        return array_sum(array_column($this->signals, 'score'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'instrument' => $this->bundle->instrument->toArray(),
            'price' => ['last' => $this->bundle->lastPrice(), 'close' => $this->bundle->closePrice(),
                        'change_pct' => $this->changePct(), 'status' => $this->bundle->status()],
            'technical' => $this->technical, 'signals' => $this->signals, 'verdict' => $this->verdict,
            'returns' => $this->returns, 'flow' => $this->flow, 'potential' => $this->potential->toArray(),
        ];
    }
}
