<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

/** The active rule set. To add a rule: implement Rule and list it here. */
final class RuleRegistry
{
    /** @return list<Rule> */
    public static function defaults(): array
    {
        return [
            new QueueRule(),
            new QueueChangeRule(),
            new ImbalanceRule(),
            new PreOpenRule(),
            new SmartMoneyRule(),
            new VolumePaceRule(),
            new LevelsRule(),
            new FastMoveRule(),
        ];
    }
}
