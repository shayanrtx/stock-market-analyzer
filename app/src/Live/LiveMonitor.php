<?php

declare(strict_types=1);

namespace Sabadgardan\Live;

use Sabadgardan\Analysis\Indicators;
use Sabadgardan\Analysis\MoneyFlow;
use Sabadgardan\Analysis\Potential\PotentialAnalyzer;
use Sabadgardan\Analysis\Stats;
use Sabadgardan\Core\Cache;
use Sabadgardan\Core\Database;
use Sabadgardan\Core\Settings;
use Sabadgardan\Live\Rules\Rule;
use Sabadgardan\Live\Rules\RuleRegistry;
use Sabadgardan\Market\Field;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\TsetmcClient;
use Sabadgardan\Notify\NotificationService;
use Sabadgardan\Portfolio\PortfolioRepository;
use Sabadgardan\Signals\Signal;
use Sabadgardan\Signals\SignalEngine;

/**
 * Request-driven market monitor for shared hosting (no background threads):
 * each tick() — from the live page's polling or from cron — fetches snapshots of every monitored
 * symbol, stores them, runs the rules on today's history, records new insights (de-duplicated),
 * turns confluent insights into signals and notifies the users concerned.
 * A file lock prevents overlapping ticks; ticks closer than the interval are skipped.
 */
final class LiveMonitor
{
    public const COOLDOWN = 900;           // same symbol/rule/direction not repeated sooner, unless more severe
    public const FLOW_COOLDOWN = 3600;
    public const SIGNAL_OPEN_DAYS = 60;    // ~ the signal horizon (42 sessions) in calendar days     // money-flow readings change slowly; at most hourly
    /** States (a level crossed, a queue formed): reported once a day, again only when they escalate. */
    public const ONCE_A_DAY = ['breakout', 'stop', 'target', 'queue', 'pre_open'];
    public const FLOW = ['smart_money', 'volume_pace'];
    public const HISTORY_LIMIT = 120;      // snapshots per symbol used by the rules
    public const CONTEXTS_PER_TICK = 10;   // bound the heavy first-of-day work per request
    public const SIGNAL_WINDOW = 900;      // insights considered as signal triggers

    /** @var callable(): int */
    private $clock;

    /** @param list<Rule>|null $rules */
    public function __construct(
        private Database $db,
        private MarketData $market,
        private PortfolioRepository $repo,
        private Settings $settings,
        private Cache $cache,
        private ?NotificationService $notifier,
        private string $lockFile,
        private int $interval = 45,
        ?callable $clock = null,
        private ?array $rules = null,
    ) {
        $this->clock = $clock ?? 'time';
        $this->rules ??= RuleRegistry::defaults();
    }

    /** @return array{ran: bool, phase: string, insights: int, signals: int, pending_contexts: int, reason?: string} */
    public function tick(bool $force = false): array
    {
        $now = ($this->clock)();
        $lock = @fopen($this->lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['ran' => false, 'phase' => '', 'insights' => 0, 'signals' => 0, 'pending_contexts' => 0, 'reason' => 'locked'];
        }
        try {
            $last = (int) $this->settings->system('live_last_tick');
            if (!$force && $now - $last < $this->interval) {
                return ['ran' => false, 'phase' => $this->phase($now), 'insights' => 0, 'signals' => 0,
                        'pending_contexts' => 0, 'reason' => 'fresh'];
            }
            $this->settings->set(Settings::SYSTEM, 'live_last_tick', (string) $now);
            return $this->run($now);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function phase(int $now): string
    {
        $open = $this->cache->get('market_open:' . MarketClock::day($now));
        if ($open === null && $this->market->gateway) {
            $flag = $this->market->gateway->marketOpen();
            $open = $flag === null ? 'unknown' : ($flag ? 'yes' : 'no');
            $this->cache->set('market_open:' . MarketClock::day($now), $open, 600);
        }
        return MarketClock::phase($now, $open === 'yes' ? true : ($open === 'no' ? false : null));
    }

    /** @return array<string, mixed> */
    private function run(int $now): array
    {
        $phase = $this->phase($now);
        $day = MarketClock::day($now);
        $monitored = $this->repo->monitoredSymbols();
        $contexts = $this->contexts(array_map('strval', array_keys($monitored)), $day);
        $pending = count($monitored) - count($contexts);

        $snapshots = $this->snapshots($contexts, $now, $day);
        $this->db->run('DELETE FROM live_snapshots WHERE day < ?', [$day]);

        $insights = $signals = 0;
        if (MarketClock::isLive($phase)) {
            foreach ($snapshots as $code => $snap) {
                $code = (string) $code;
                if ($contexts[$code]->isQuiet()) {
                    continue;  // money-market-like instruments: their ticks are noise, not events
                }
                $history = $this->todaysSnapshots($code, $day);
                $fresh = [];
                foreach ($this->rules as $rule) {
                    if (!in_array($phase, $rule->phases(), true)) {
                        continue;
                    }
                    foreach ($rule->evaluate($history, $contexts[$code], $now) as $ins) {
                        if ($this->shouldRecord($ins, $now, $day)) {
                            $this->record($ins, $day);
                            $fresh[] = $ins;
                        }
                    }
                }
                $insights += count($fresh);
                if ($fresh && $phase === MarketClock::CONTINUOUS) {
                    $signals += $this->signal($contexts[$code], $snap, $monitored[$code], $now, $day);
                }
            }
            $insights += $this->marketInsight($now, $day);
        }
        return ['ran' => true, 'phase' => $phase, 'insights' => $insights, 'signals' => $signals, 'pending_contexts' => $pending];
    }

    /**
     * Daily reference levels per symbol, cached for the day; at most CONTEXTS_PER_TICK new ones per tick.
     *
     * @param list<string> $codes
     * @return array<string, SymbolContext>
     */
    private function contexts(array $codes, string $day): array
    {
        $out = [];
        $missing = [];
        foreach ($codes as $code) {
            $cached = $this->cache->get("ctx:$day:$code");
            if (is_array($cached)) {
                $out[$code] = SymbolContext::fromArray($cached);
            } else {
                $missing[] = $code;
            }
        }
        $batch = array_slice($missing, 0, self::CONTEXTS_PER_TICK);
        if (!$batch) {
            return $out;
        }
        $analyzer = new PotentialAnalyzer((float) $this->settings->system('inflation_annual'),
            (float) $this->settings->system('risk_free_annual'));
        foreach ($this->market->bundles($batch, ['history', 'info', 'flow']) as $code => $b) {
            $h = $b->history;
            if ($h->count() < 30) {
                continue;
            }
            $pot = $analyzer->assess($h, $b->info, MoneyFlow::fromRecord([], null), $b->flowRecords);
            $sma200 = Stats::last(Indicators::sma($h->close, 200));
            $n = $h->count();
            $ctx = new SymbolContext(
                insCode: (string) $code,
                symbol: $b->instrument->symbol,
                avgVolume: Stats::mean(array_slice($b->raw->volume, -20)),
                avgValue: Stats::mean(array_slice($b->raw->value, -20)),
                prevHigh: $b->raw->high[$b->raw->count() - 1] ?? null,
                prevClose: $b->raw->last(),
                high52w: max(array_slice($h->close, -250)),
                stop: $pot->levels['stop'],
                target: $pot->levels['target'],
                potentialScore: $pot->score,
                aboveSma200: $sma200 !== null ? $h->close[$n - 1] > $sma200 : null,
                rewardRisk: $pot->levels['reward_risk'],
                atr: $pot->levels['atr'],
                assetClass: $b->assetClass()->kind,
                cashLike: $b->assetClass()->isCashLike(),
            );
            $this->cache->set("ctx:$day:$code", $ctx->toArray(), 86400);
            $out[$code] = $ctx;
        }
        return $out;
    }

    /**
     * @param array<string, SymbolContext> $contexts
     * @return array<string, Snapshot>
     */
    private function snapshots(array $contexts, int $now, string $day): array
    {
        if (!$contexts) {
            return [];
        }
        $paths = [];
        foreach (array_keys($contexts) as $c) {
            $paths["price|$c"] = "ClosingPrice/GetClosingPriceInfo/$c";
            $paths["book|$c"] = "BestLimits/$c";
            $paths["client|$c"] = "ClientType/GetClientType/$c/1/0";
        }
        $data = $this->market->cdn->tryMany($paths, TsetmcClient::TTL_LIVE);
        $gDay = substr($day, 0, 8);
        $out = [];
        foreach ($contexts as $code => $ctx) {
            $code = (string) $code;
            $price = (array) Field::get($data["price|$code"] ?? [], 'closingPriceInfo', []);
            if (!$price) {
                continue;
            }
            $limits = $this->cache->get("limits:$gDay:$code");
            if (!is_array($limits)) {
                try {
                    $limits = $this->market->cdn->thresholds($code, $gDay);
                } catch (\Throwable) {
                    $limits = [null, null];
                }
                $this->cache->set("limits:$gDay:$code", $limits, 86400);
            }
            $snap = Snapshot::fromApi($code, $ctx->symbol, $now, $price, Field::list($data["book|$code"] ?? [], 'bestLimits'),
                (array) Field::get($data["client|$code"] ?? [], 'clientType', []), $limits[0], $limits[1]);
            $this->db->insert('live_snapshots', ['day' => $day, 'ins_code' => $code, 'taken_at' => $now,
                                                 'payload' => json_encode($snap->toArray(), JSON_UNESCAPED_UNICODE)]);
            $out[$code] = $snap;
        }
        return $out;
    }

    /** @return list<Snapshot> oldest first */
    private function todaysSnapshots(string $code, string $day): array
    {
        $rows = $this->db->all('SELECT payload FROM live_snapshots WHERE day = ? AND ins_code = ? ORDER BY taken_at DESC LIMIT '
            . self::HISTORY_LIMIT, [$day, $code]);
        return array_map(static fn ($r) => Snapshot::fromArray(json_decode((string) $r['payload'], true)), array_reverse($rows));
    }

    private function shouldRecord(Insight $ins, int $now, string $day): bool
    {
        $last = $this->db->one('SELECT created_at, severity FROM live_insights WHERE day = ? AND ins_code = ? AND rule = ? AND direction = ?
                                ORDER BY id DESC LIMIT 1', [$day, $ins->insCode, $ins->rule, $ins->direction]);
        if (!$last || $ins->severity > (int) $last['severity']) {
            return true;
        }
        if (in_array($ins->rule, self::ONCE_A_DAY, true)) {
            return false;
        }
        $cooldown = in_array($ins->rule, self::FLOW, true) ? self::FLOW_COOLDOWN : self::COOLDOWN;
        return $now - (int) $last['created_at'] >= $cooldown;
    }

    private function record(Insight $ins, string $day): void
    {
        $this->db->insert('live_insights', [
            'day' => $day, 'created_at' => $ins->at, 'ins_code' => $ins->insCode, 'symbol' => $ins->symbol,
            'rule' => $ins->rule, 'direction' => $ins->direction, 'severity' => $ins->severity, 'title' => $ins->title,
            'detail' => $ins->detail, 'metrics' => json_encode($ins->metrics, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @param array{symbol: string, holders: list<int>, watchers: list<int>} $who */
    private function signal(SymbolContext $ctx, Snapshot $snap, array $who, int $now, string $day): int
    {
        $recent = array_map(static fn ($r) => new Insight((int) $r['created_at'], $r['ins_code'], $r['symbol'], $r['rule'],
            (int) $r['direction'], (int) $r['severity'], $r['title'], $r['detail']),
            $this->db->all('SELECT * FROM live_insights WHERE day = ? AND ins_code = ? AND created_at >= ? ORDER BY id',
                [$day, $ctx->insCode, $now - self::SIGNAL_WINDOW]));
        $bundle = $this->market->bundles([$ctx->insCode], ['history'])[$ctx->insCode] ?? null;
        if (!$bundle) {
            return 0;
        }
        $engine = new SignalEngine((float) $this->settings->system('inflation_annual'));
        $signal = $engine->evaluate($ctx, $snap, $recent, $bundle->history, (bool) $who['holders']);
        if (!$signal || $this->hasOpenSignal($signal, $now)) {
            return 0;
        }
        $signal->id = $this->db->insert('signals', [
            'day' => $day, 'created_at' => $now, 'ins_code' => $signal->insCode, 'symbol' => $signal->symbol,
            'side' => $signal->side, 'entry' => $signal->entry, 'stop' => $signal->stop, 'target' => $signal->target,
            'probability' => $signal->probability, 'samples' => (int) ($signal->odds['samples'] ?? 0),
            'score' => $signal->score, 'reasons' => json_encode($signal->reasons, JSON_UNESCAPED_UNICODE),
        ]);
        if ($this->notifier) {
            $sent = $this->notifier->dispatch($signal, $who['holders'], $who['watchers'], $bundle->raw);
            $this->db->update('signals', ['notified' => $sent], ['id' => $signal->id]);
        }
        return 1;
    }

    /**
     * A buy signal stays open until its stop or target is reached (at most SIGNAL_OPEN_DAYS); a sell
     * warning for a week. Re-issuing it every day while it is still running would only be noise.
     */
    private function hasOpenSignal(Signal $signal, int $now): bool
    {
        $prev = $this->db->one('SELECT * FROM signals WHERE ins_code = ? AND side = ? AND created_at >= ? ORDER BY id DESC LIMIT 1',
            [$signal->insCode, $signal->side, $now - self::SIGNAL_OPEN_DAYS * 86400]);
        if (!$prev) {
            return false;
        }
        if ($signal->side === Signal::SELL) {
            return (int) $prev['created_at'] >= $now - 7 * 86400;
        }
        $price = $signal->entry;
        return ($prev['stop'] === null || $price > (float) $prev['stop']) && ($prev['target'] === null || $price < (float) $prev['target']);
    }

    private function marketInsight(int $now, string $day): int
    {
        try {
            $indices = $this->market->cdn->indices(TsetmcClient::FLOW_BOURSE);
        } catch (\Throwable) {
            return 0;
        }
        $total = null;
        foreach ($indices as $i) {
            if ((string) Field::get($i, 'insCode', '') === TsetmcClient::TEDPIX) {
                $total = $i;
            }
        }
        $total ??= $indices[0] ?? null;
        $change = (float) Field::get($total, 'xVarIdxJRfV', 0);
        if (!$total || abs($change) < 1.0) {
            return 0;
        }
        $snap = new Snapshot('market', 'بازار', $now);
        $ins = Insight::from($snap, 'market', $change > 0 ? 1 : -1, abs($change) >= 2 ? 3 : 2,
            $change > 0 ? 'رشد قوی شاخص کل' : 'افت قابل توجه شاخص کل',
            sprintf('شاخص کل %s٪ تغییر کرده است؛ جهت کلی بازار را در تصمیم‌های امروز لحاظ کنید.',
                ($change > 0 ? '+' : '') . number_format($change, 2)), ['change' => $change]);
        if (!$this->shouldRecord($ins, $now, $day)) {
            return 0;
        }
        $this->record($ins, $day);
        return 1;
    }

    /**
     * Dashboard view for one user: phase, their symbols' latest snapshot and today's insights/signals.
     *
     * @return array<string, mixed>
     */
    public function state(int $userId): array
    {
        $now = ($this->clock)();
        $day = MarketClock::day($now);
        $mine = [];
        foreach ($this->repo->monitoredSymbols() as $code => $who) {
            if (in_array($userId, $who['holders'], true) || in_array($userId, $who['watchers'], true)) {
                $mine[$code] = ['symbol' => $who['symbol'], 'held' => in_array($userId, $who['holders'], true)];
            }
        }
        $rows = [];
        foreach ($mine as $code => $m) {
            $r = $this->db->one('SELECT payload FROM live_snapshots WHERE day = ? AND ins_code = ? ORDER BY taken_at DESC LIMIT 1',
                [$day, $code]);
            $s = $r ? Snapshot::fromArray(json_decode((string) $r['payload'], true)) : null;
            $class = $this->market->instrument((string) $code)?->assetClass();
            $rows[] = [
                'ins_code' => (string) $code, 'symbol' => $m['symbol'], 'held' => $m['held'],
                'class' => $class?->label(), 'class_tone' => $class?->tone(),
                'price' => $s?->price(), 'change' => $s?->changePct(), 'close_change' => $s?->closeChangePct(),
                'queue' => $s?->queue(), 'queue_value' => $s && $s->queue() ? $s->queueValue() : null,
                'imbalance' => $s && $s->askTotal() > 0 && !$s->queue() ? $s->bidTotal() / $s->askTotal() : null,
                'buyer_power' => $s?->buyerPower(), 'updated' => $s ? MarketClock::local($s->at, 'H:i:s') : null,
            ];
        }
        $codes = array_map('strval', array_keys($mine));
        $insights = [];
        $signals = [];
        if ($codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $insights = $this->db->all("SELECT * FROM live_insights WHERE day = ? AND (ins_code IN ($in) OR ins_code = 'market')
                                        ORDER BY id DESC LIMIT 200", [$day, ...$codes]);
            $signals = $this->db->all("SELECT * FROM signals WHERE day = ? AND ins_code IN ($in) ORDER BY id DESC", [$day, ...$codes]);
        }
        $phase = $this->phase($now);
        $last = (int) $this->settings->system('live_last_tick');
        return [
            'phase' => $phase,
            'phase_label' => MarketClock::LABELS[$phase],
            'clock' => MarketClock::local($now, 'H:i'),
            'updated' => $last ? MarketClock::local($last, 'H:i:s') : null,
            'interval' => $this->interval,
            'symbols' => $rows,
            'insights' => array_map(static fn ($i) => [
                'at' => MarketClock::local((int) $i['created_at'], 'H:i'), 'symbol' => $i['symbol'], 'rule' => $i['rule'],
                'direction' => (int) $i['direction'], 'severity' => (int) $i['severity'], 'title' => $i['title'],
                'detail' => $i['detail']], $insights),
            'signals' => array_map(static fn ($s) => [
                'id' => (int) $s['id'], 'at' => MarketClock::local((int) $s['created_at'], 'H:i'), 'symbol' => $s['symbol'],
                'side' => $s['side'], 'label' => $s['side'] === Signal::BUY ? 'خرید' : 'فروش', 'entry' => (float) $s['entry'],
                'stop' => $s['stop'] !== null ? (float) $s['stop'] : null, 'target' => $s['target'] !== null ? (float) $s['target'] : null,
                'probability' => $s['probability'] !== null ? (float) $s['probability'] : null, 'samples' => (int) $s['samples'],
                'reasons' => json_decode((string) $s['reasons'], true) ?: []], $signals),
        ];
    }
}
