<?php

declare(strict_types=1);

namespace Sabadgardan\Signals;

use Sabadgardan\Analysis\Potential\HistoricalStudy;
use Sabadgardan\Live\Insight;
use Sabadgardan\Live\Snapshot;
use Sabadgardan\Live\SymbolContext;
use Sabadgardan\Market\PriceHistory;

/**
 * Turns live insights into trade signals only when independent evidence lines up:
 *
 * BUY  = live strength trigger (severity ≥ 2) + growth potential ≥ 60 + price above SMA200
 *        + reward/risk ≥ 1.5 + positive historical expectancy of the exact plan (entry/stop/target)
 * SELL = live weakness trigger (or stop crossed) on a symbol the user holds
 *        + weak structure (potential ≤ 45, below SMA200 or below the stop)
 *
 * The stated probability is the share of comparable past setups that reached the target before
 * the stop within the horizon (HistoricalStudy::tradeOdds), not a guess.
 */
final class SignalEngine
{
    public const BUY_MIN_SCORE = 60.0;
    public const SELL_MAX_SCORE = 45.0;
    public const MIN_REWARD_RISK = 1.5;
    public const MIN_PROBABILITY = 0.45;  // target before stop in comparable past setups
    public const MIN_TRIGGER_SEVERITY = 2;
    public const HORIZON = 42;  // ~2 months of sessions

    public function __construct(private float $inflationAnnual = 0.35)
    {
    }

    /** @param list<Insight> $recent insights of this symbol from the last minutes */
    public function evaluate(SymbolContext $ctx, Snapshot $s, array $recent, PriceHistory $history, bool $held): ?Signal
    {
        $price = $s->price();
        if (!$price || $history->count() < 120 || $ctx->isQuiet()) {
            return null;
        }
        $strong = array_values(array_filter($recent, static fn (Insight $i) => $i->direction > 0 && $i->severity >= self::MIN_TRIGGER_SEVERITY));
        $weak = array_values(array_filter($recent, static fn (Insight $i) => $i->direction < 0 && $i->severity >= self::MIN_TRIGGER_SEVERITY));

        if ($strong && !array_filter($weak, static fn (Insight $i) => $i->severity >= 3)) {
            $buy = $this->buy($ctx, $price, $strong, $history, $s->at);
            if ($buy) {
                return $buy;
            }
        }
        if ($held && $weak) {
            return $this->sell($ctx, $price, $weak, $history, $s->at);
        }
        return null;
    }

    /** @param list<Insight> $triggers */
    private function buy(SymbolContext $ctx, float $price, array $triggers, PriceHistory $h, int $at): ?Signal
    {
        if (($ctx->potentialScore ?? 0) < self::BUY_MIN_SCORE || $ctx->aboveSma200 !== true) {
            return null;
        }
        $stop = $ctx->stop && $ctx->stop < $price ? $ctx->stop : ($ctx->atr ? $price - 2.5 * $ctx->atr : null);
        if (!$stop || $stop <= 0) {
            return null;
        }
        $risk = $price - $stop;
        // Next resistance as target; near all-time highs use a 2R objective.
        $target = $ctx->target && $ctx->target > $price * 1.03 ? $ctx->target : $price + 2 * $risk;
        $rr = ($target - $price) / $risk;
        if ($rr < self::MIN_REWARD_RISK) {
            return null;
        }
        $odds = (new HistoricalStudy($h, $this->inflationAnnual))
            ->tradeOdds(($target / $price - 1) * 100, ($risk / $price) * 100, self::HORIZON, 1);
        if (!$odds || $odds['expectancy_pct'] <= 0 || $odds['win_rate'] < self::MIN_PROBABILITY
            || $odds['win_rate'] <= $odds['loss_rate']) {
            return null;
        }
        $reasons = array_map(static fn (Insight $i) => $i->title . ': ' . $i->detail, array_slice($triggers, 0, 3));
        $reasons[] = sprintf('امتیاز پتانسیل رشد %d و قیمت بالای میانگین ۲۰۰ روزه (روند اولیه صعودی)', round($ctx->potentialScore));
        $reasons[] = sprintf('بازده مورد انتظار این طرح معاملاتی در گذشته %s٪ بوده است', number_format($odds['expectancy_pct'], 1));
        return new Signal($ctx->insCode, $ctx->symbol, Signal::BUY, $at, $price, $stop, $target, $odds['win_rate'], $odds,
            $ctx->potentialScore, $reasons);
    }

    /** @param list<Insight> $triggers */
    private function sell(SymbolContext $ctx, float $price, array $triggers, PriceHistory $h, int $at): ?Signal
    {
        $belowStop = $ctx->stop && $price < $ctx->stop;
        $weakStructure = ($ctx->potentialScore !== null && $ctx->potentialScore <= self::SELL_MAX_SCORE)
            || $ctx->aboveSma200 === false;
        if (!$belowStop && !$weakStructure) {
            return null;
        }
        // "Further decline" odds: a fall of X before a rise of X, X = max(3%, 2 ATR).
        $x = max(3.0, $ctx->atr ? 2 * $ctx->atr / $price * 100 : 3.0);
        $odds = (new HistoricalStudy($h, $this->inflationAnnual))->tradeOdds($x, $x, self::HORIZON, -1) ?? [];
        $reasons = array_map(static fn (Insight $i) => $i->title . ': ' . $i->detail, array_slice($triggers, 0, 3));
        if ($belowStop) {
            $reasons[] = sprintf('قیمت زیر حد ضرر (%s) است', number_format($ctx->stop));
        }
        if ($ctx->aboveSma200 === false) {
            $reasons[] = 'قیمت زیر میانگین ۲۰۰ روزه است (روند اولیه نزولی)';
        }
        if ($ctx->potentialScore !== null && $ctx->potentialScore <= self::SELL_MAX_SCORE) {
            $reasons[] = sprintf('امتیاز پتانسیل رشد فقط %d است', round($ctx->potentialScore));
        }
        return new Signal($ctx->insCode, $ctx->symbol, Signal::SELL, $at, $price, $ctx->stop, null,
            $odds['win_rate'] ?? null, $odds, $ctx->potentialScore, $reasons);
    }
}
