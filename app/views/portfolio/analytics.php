<?php
/** Asynchronously loaded part of the dashboard; replaces the ``.loading-block`` placeholder.
 *  @var \Sabadgardan\View\View $v
 *  @var array $a    PortfolioService::analytics()
 *  @var array $val  valuation */
use Sabadgardan\Portfolio\Advisor;
use Sabadgardan\View\Charts;

if (!$a['positions']) {
    return;
}
$risk = $a['risk'];
$m = $risk['metrics'] ?? [];
$nav = (float) $val['totals']['nav'];

$rows = $a['positions'];
usort($rows, static fn ($x, $y) => [array_search($x['advice']['action'], Advisor::ORDER, true), -$x['weight']]
    <=> [array_search($y['advice']['action'], Advisor::ORDER, true), -$y['weight']]);
$counts = array_count_values(array_map(static fn ($r) => $r['advice']['action'], $rows));
?>
<?php ob_start(); ?>
<div class="action-summary">
  <?php foreach (Advisor::ORDER as $act): if (empty($counts[$act])) continue; ?>
    <span class="pill <?= $act ?>"><?= Advisor::ACTIONS[$act] ?> · <?= $counts[$act] ?></span>
  <?php endforeach; ?>
</div>
<div class="table-wrap"><table class="advice">
  <thead><tr>
    <th>نماد</th>
    <th>پیشنهاد</th>
    <th>وزن فعلی ← هدف <?= $v->help('target_weight') ?></th>
    <th>پتانسیل رشد <?= $v->help('potential_score') ?></th>
    <th>احتمال رشد <?= $v->help('probability_up') ?></th>
    <th>حد ضرر / هدف <?= $v->help('stop') ?></th>
    <th>پاداش/ریسک <?= $v->help('reward_risk') ?></th>
    <th>سهم ریسک <?= $v->help('risk_share') ?></th>
    <th>روز تا خروج <?= $v->help('days_to_exit') ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $pot = $r['potential'];
      $adv = $r['advice'];
      $lv = $pot?->levels ?? [];
      $delta = $adv['target_weight'] - $r['weight'];
      $deltaValue = $nav * $delta / 100; ?>
    <tr>
      <td><a href="<?= $v->url('stock', ['ins' => $r['ins_code']]) ?>"><b><?= $v->e($r['symbol']) ?></b></a>
        <?= $v->assetTag($r['class'], $r['class_tone']) ?>
        <span class="sub"><?= $v->e($r['sector']) ?><?php if ($r['premium'] !== null): ?> · حباب <span class="<?= $r['premium'] > \Sabadgardan\Portfolio\Advisor::PREMIUM_HIGH ? 'down' : '' ?>"><?= $v->pct($r['premium'], 1, false) ?></span><?php endif; ?></span>
        <?php if ($r['queue']): ?><span class="pill <?= $r['queue'] === 'buy' ? 'up' : 'down' ?>" style="margin-top:4px"><?= $r['queue'] === 'buy' ? 'صف خرید' : 'صف فروش' ?></span><?php endif; ?></td>
      <td><span class="pill <?= $adv['action'] ?>"><?= $v->e($adv['label']) ?></span></td>
      <td><?= $v->pct($r['weight'], 1, false, false) ?> ← <b><?= $v->pct($adv['target_weight'], 1, false, false) ?></b>
        <?php if (abs($delta) >= 0.5): ?><span class="sub"><?= $delta > 0 ? 'خرید' : 'فروش' ?> حدود <?= $v->money(abs($deltaValue)) ?></span><?php endif; ?></td>
      <td><?php if ($pot): ?><span class="score <?= $v->tone($pot->score) ?>"><?= round($pot->score) ?></span>
        <span class="sub"><?= $v->e($pot->label) ?></span><?php else: ?><span class="faint">-</span><?php endif; ?></td>
      <td><?= $pot && $pot->probabilityUp !== null ? $v->pct($pot->probabilityUp * 100, 0, false, false) : '<span class="faint">-</span>' ?></td>
      <td><?php if (!empty($lv['stop'])): ?>
          <span class="down"><?= $v->num($lv['stop']) ?></span> / <span class="up"><?= $v->num($lv['target'] ?? null) ?></span>
          <span class="sub">فاصله تا حد ضرر <?= $v->pct(($lv['stop'] / max($r['close'], 1) - 1) * 100, 1, false) ?></span>
        <?php else: ?><span class="faint">-</span><?php endif; ?></td>
      <td><?= isset($lv['reward_risk']) ? '<span class="num ' . ($lv['reward_risk'] >= 2 ? 'up' : ($lv['reward_risk'] < 1 ? 'down' : '')) . '">' . number_format($lv['reward_risk'], 1) . '</span>' : '<span class="faint">-</span>' ?></td>
      <td><?= $r['risk_share'] !== null ? $v->pct($r['risk_share'], 1, false, false) : '<span class="faint">-</span>' ?>
        <?php if ($r['risk_share'] !== null && $r['weight'] > 0 && $r['risk_share'] / $r['weight'] > Advisor::RISK_OVERWEIGHT): ?><span class="sub warn-text">بیش از وزن</span><?php endif; ?></td>
      <td><?= $r['days_to_exit'] !== null ? '<span class="num' . ($r['days_to_exit'] > Advisor::MAX_DAYS_TO_EXIT ? ' down' : '') . '">' . ($r['days_to_exit'] < 0.1 ? '&lt;0.1' : number_format($r['days_to_exit'], 1)) . '</span>' : '<span class="faint">-</span>' ?></td>
    </tr>
    <tr class="reason-row"><td colspan="9"><?= $v->e(implode('؛ ', $adv['reasons'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="faint" style="font-size:12px;margin:10px 0 0">پیشنهادها حاصل قواعد مدیریت سبد (امتیاز پتانسیل، روند، احتمال تاریخی، پاداش به ریسک، سهم از ریسک، سقف وزن و نقدشوندگی) هستند و جایگزین قضاوت شما نیستند.</p>
<?= $v->card('پیشنهادهای مدیریت سبد', (string) ob_get_clean(), 12, 'actions', 'مرتب‌شده بر اساس فوریت اقدام') ?>

<?php if ($a['warnings']): ?>
  <?= $v->card('هشدارهای سبد', '<ul class="alerts">' . implode('', array_map(static fn ($w) => '<li>' . $v->e($w) . '</li>', $a['warnings'])) . '</ul>', 12, 'warnings') ?>
<?php endif; ?>

<?php if ($risk): ?>
<?php
$var = isset($m['var95_1d_pct']) ? $m['var95_1d_pct'] / 100 * $nav : null;
$conc = $a['concentration'];
$tiles = [
    ['volatility', 'نوسان سالانه', $v->pct($m['volatility_annual_pct'], 1, false, false), 'بیشترین افت ' . $v->pct($m['max_drawdown_pct'], 1)],
    ['max_drawdown', 'افت فعلی از سقف', $v->pct($m['current_drawdown_pct'], 1), 'بیشترین افت دوره ' . $v->pct($m['max_drawdown_pct'], 1)],
    ['sharpe', 'نسبت شارپ', $v->num($m['sharpe'], 2), 'سورتینو ' . $v->num($m['sortino'], 2) . ' · نرخ بدون ریسک ' . $v->pct($m['risk_free_pct'], 1, false, false)],
    ['beta', 'بتا نسبت به شاخص', $v->num($m['beta'] ?? null, 2), isset($m['excess_vs_benchmark_pct']) ? 'بازده مازاد بر شاخص ' . $v->pct($m['excess_vs_benchmark_pct'], 1) : 'داده‌ی شاخص در دسترس نیست'],
    ['var', 'VaR روزانه ۹۵٪', $v->money($var), $v->pct(-$m['var95_1d_pct'], 2) . ' · CVaR ' . $v->pct(isset($m['cvar95_1d_pct']) ? -$m['cvar95_1d_pct'] : null, 2)],
    ['effective_positions', 'تعداد مؤثر نمادها', $v->num($conc['effective_positions'] ?? null, 1), 'از ' . count($a['positions']) . ' نماد · HHI ' . $v->num(($conc['hhi'] ?? 0) * 10000, 0)],
];
?>
<section class="kpis small" style="--cols:<?= count($tiles) ?>" data-help="risk_kpis">
  <?php foreach ($tiles as [$key, $label, $value, $sub]): ?>
    <div class="kpi"><div class="label"><?= $label ?> <?= $v->help($key) ?></div><div class="value"><?= $value ?></div><div class="sub"><?= $sub ?></div></div>
  <?php endforeach; ?>
</section>

<?= $v->card('ترکیب فعلی در برابر شاخص کل', $v->chart(Charts::equityVsBenchmark($risk['equity'], $risk['benchmark'], $risk['drawdown']), 'mid'), 8, 'backtest',
    sprintf('%d جلسه · بازده %s', $risk['days'], strip_tags($v->pct($m['return_period_pct'])))) ?>
<?php
$types = $a['types'];
echo $v->card('ترکیب بر اساس نوع ابزار', $v->chart(Charts::donut($types), 'mid'), 4, 'asset_types');
?>
<?php endif; ?>

<?php
$sectors = array_slice($a['sectors'], 0, 12, true);
echo $v->card('ترکیب صنعت', $v->chart(Charts::hbar($sectors, Charts::ACCENT), null, max(220, 30 * count($sectors) + 40)), 6, 'sectors');

if ($risk && $risk['risk_contribution']) {
    $rw = [];
    // Risk-parity weights split the invested part of the portfolio, so they are scaled to it.
    $invested = array_sum(array_map(static fn ($r) => isset($risk['risk_parity'][$r['symbol']]) ? $r['weight'] : 0, $a['positions']));
    foreach ($a['positions'] as $r) {
        if (isset($risk['risk_contribution'][$r['symbol']])) {
            $parity = $risk['risk_parity'][$r['symbol']] ?? null;
            $rw[] = [$r['symbol'], $r['weight'], $risk['risk_contribution'][$r['symbol']], $parity !== null ? $parity * $invested / 100 : null];
        }
    }
    echo $v->card('وزن در برابر سهم از ریسک', $v->chart(Charts::riskVsWeight($rw), null, max(240, 34 * count($rw) + 60)), 6, 'risk_vs_weight',
        $risk['risk_parity'] ? 'لوزی: وزنی که سهم ریسک همه را برابر می‌کند' : '');
}
if ($risk) {
    $points = array_map(static fn ($r) => ['symbol' => $r['symbol'], 'vol' => $r['volatility_pct'], 'ret' => $r['return_1y_pct'],
        'weight' => $r['weight'], 'score' => $r['potential']?->score], $a['positions']);
    echo $v->card('نقشه‌ی ریسک و بازده', $v->chart(Charts::riskReturn($points), 'mid'), 6, 'risk_return', 'یک سال اخیر، قیمت تعدیل‌شده');
    if (count($risk['correlation']) > 1) {
        echo $v->card('همبستگی بازده نمادها', $v->chart(Charts::correlation($risk['correlation']), null, max(320, 30 * count($risk['correlation']) + 110)), 6, 'correlation');
    }
}
if ($a['errors']) {
    echo '<div class="notice warn" style="grid-column:1/-1">داده‌ی کامل ' . count($a['errors']) . ' نماد دریافت نشد و در تحلیل ریسک لحاظ نشده است.</div>';
}
