<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis\Potential;

final class PotentialResult
{
    public string $summary = '';

    /**
     * @param list<Factor> $factors
     * @param array<string, ?float> $levels price, target, upside_pct, support, downside_pct, stop, stop_pct, atr, reward_risk
     * @param array<string, mixed> $history HistoryStats
     * @param array<string, mixed>|null $analogs
     * @param array<string, ?float> $valuation
     * @param array<string, float|int> $flow
     * @param array<string, float|string>|null $scenarios bear/base/bull prices, source
     * @param list<array{0: string, 1: float}> $adjustments
     */
    public function __construct(
        public readonly float $score,
        public readonly string $label,
        public readonly string $confidence,
        public readonly array $factors,
        public readonly array $levels,
        public readonly array $history,
        public readonly ?array $analogs,
        public readonly array $valuation,
        public readonly array $flow,
        public readonly ?array $scenarios,
        public readonly ?float $probabilityUp,
        public readonly array $adjustments,
        public readonly string $sector,
    ) {
    }

    /** @return list<Factor> */
    public function strengths(): array
    {
        $f = array_values(array_filter($this->factors, static fn (Factor $x) => $x->score > 0.1));
        usort($f, static fn (Factor $a, Factor $b) => $b->contribution <=> $a->contribution);
        return $f;
    }

    /** @return list<Factor> */
    public function weaknesses(): array
    {
        $f = array_values(array_filter($this->factors, static fn (Factor $x) => $x->score < -0.1));
        usort($f, static fn (Factor $a, Factor $b) => $a->contribution <=> $b->contribution);
        return $f;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $analogs = $this->analogs;
        unset($analogs['forward']);
        return [
            'score' => $this->score, 'label' => $this->label, 'confidence' => $this->confidence,
            'summary' => $this->summary, 'probability_up' => $this->probabilityUp, 'levels' => $this->levels,
            'scenarios' => $this->scenarios, 'valuation' => $this->valuation, 'flow' => $this->flow,
            'history' => $this->history, 'analogs' => $analogs, 'sector' => $this->sector,
            'factors' => array_map(static fn (Factor $f) => $f->toArray(), $this->factors),
        ];
    }
}
