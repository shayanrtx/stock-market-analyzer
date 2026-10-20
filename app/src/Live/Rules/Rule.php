<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\Snapshot;
use Sabadgardan\Live\SymbolContext;

/**
 * A live detection rule. ``$history`` holds today's snapshots of one symbol, oldest first; the
 * last one is the current state. Rules are pure (no I/O) so they can be tested with hand-made
 * snapshots. Register new rules in RuleRegistry::defaults().
 */
interface Rule
{
    public function id(): string;

    /** @return list<string> MarketClock phases in which the rule runs */
    public function phases(): array;

    /** One line for the help pages. */
    public function description(): string;

    /**
     * @param list<Snapshot> $history
     * @return list<Insight>
     */
    public function evaluate(array $history, SymbolContext $ctx, int $now): array;
}
