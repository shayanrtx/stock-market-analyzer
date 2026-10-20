<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis\Potential;

final class Factor
{
    public const TECHNICAL = 'فنی';
    public const FLOW = 'جریان پول';
    public const FUNDAMENTAL = 'بنیادی';
    public const STATISTICAL = 'آماری';
    public const CATEGORIES = [self::TECHNICAL, self::FLOW, self::FUNDAMENTAL, self::STATISTICAL];

    /** Points added to / removed from the 0-100 score; set by the analyzer. */
    public float $contribution = 0.0;

    public function __construct(
        public readonly string $name,
        public readonly float $score,      // -1 bearish .. +1 bullish
        public readonly float $weight,
        public readonly string $detail,
        public readonly string $category = self::TECHNICAL,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'score' => $this->score, 'weight' => $this->weight, 'detail' => $this->detail,
                'category' => $this->category, 'contribution' => $this->contribution];
    }
}
