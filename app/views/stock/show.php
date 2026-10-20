<?php
/** @var \Sabadgardan\View\View $v
 *  @var \Sabadgardan\Analysis\StockReport $r
 *  @var bool $watched
 *  @var array|null $plan      trade plan with historical odds
 *  @var list<array> $flowDaily
 *  @var list<array> $holdings the user's open positions in this symbol
 *  @var array $portfolio      default portfolio (for the transaction drawer)
 *  @var array<string, float> $fees
 *  @var float $riskFree  annual risk-free rate (fraction) */
use Sabadgardan\Analysis\Potential\Factor;
use Sabadgardan\Market\Field;
use Sabadgardan\Signals\SignalEngine;
use Sabadgardan\View\Charts;

$b = $r->bundle;
$inst = $b->instrument;
$p = $r->potential;
$lv = $p->levels;
$h = $p->history;
$val = $p->valuation;
$t = $r->technical;
$price = $b->closePrice() ?? (float) $lv['price'];
$kv = static function (array $rows) use ($v): string {
    $html = '<table class="kv"><tbody>';
    foreach ($rows as [$label, $value]) {
        $html .= '<tr><td>' . $label . '</td><td>' . $value . '</td></tr>';
    }
    return $html . '</tbody></table>';
};
$status = $b->status();
$class = $b->assetClass();
$meta = array_unique(array_filter([$b->subSector() ?: ($p->sector ?: $b->sector()), $b->board() ?: $inst->market]));
?>
<div class="page-head stock-head">
  <div class="title-block">
    <span class="symbol-badge lg"><?= $v->e(mb_substr($inst->symbol, 0, 2)) ?></span>
    <div>
      <h2 class="stock-title"><?= $v->e($inst->symbol) ?> <span class="muted"><?= $v->e($inst->name) ?></span></h2>
      <div class="sub"><span data-help="asset_class"><?= $v->assetTag($class->label(), $class->tone()) ?></span> <?= $v->e(implode(' · ', $meta)) ?>
        <?php if ($status && $status !== 'مجاز'): ?><span class="pill halted"><?= $v->e($status) ?></span><?php endif; ?>
        <?php if ($b->queue()): ?><span class="pill <?= $b->queue() === 'buy' ? 'up' : 'down' ?>"><?= $b->queue() === 'buy' ? 'صف خرید' : 'صف فروش' ?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="price-block">
    <div class="price"><?= $v->num($price) ?> <small>ریال</small></div>
    <div><?= $v->pct($r->closeChangePct(), 2) ?> <span class="faint">پایانی</span>
      <?php if ($b->lastPrice() && abs($b->lastPrice() - $price) > 0.5): ?> · آخرین <?= $v->num($b->lastPrice()) ?> <?= $v->pct($r->changePct(), 2) ?><?php endif; ?></div>
  </div>
  <div class="head-actions">
    <form method="post" action="<?= $v->url($watched ? 'watchlist/' . $inst->insCode . '/delete' : 'watchlist') ?>">
      <?= $v->csrf() ?><input type="hidden" name="ins_code" value="<?= $v->e($inst->insCode) ?>"><input type="hidden" name="back" value="/stock?ins=<?= $v->e(rawurlencode($inst->insCode)) ?>">
      <button class="btn secondary"><?= $v->icon($watched ? 'eye' : 'star', 16) ?><?= $watched ? 'در دیده‌بان' : 'افزودن به دیده‌بان' ?></button>
    </form>
    <button class="btn" type="button" data-open-drawer data-ins="<?= $v->e($inst->insCode) ?>" data-symbol="<?= $v->e($inst->symbol) ?>" data-type="buy"><?= $v->icon('plus', 16) ?>ثبت معامله</button>
  </div>
</div>

<?php
$probText = $p->probabilityUp !== null && $p->analogs ? 'بر پایه‌ی ' . $p->analogs['samples'] . ' موقعیت مشابه' : 'موقعیت مشابه کافی نیست';
$tiles = [
    ['gauge', 'پتانسیل رشد', '<span class="' . $v->tone($p->score) . '">' . round($p->score) . '</span><small>/۱۰۰</small>', $v->e($p->label) . ' · اطمینان ' . $v->e($p->confidence)],
    ['probability_up', 'احتمال رشد ۳ ماهه', $p->probabilityUp !== null ? $v->pct($p->probabilityUp * 100, 0, false, false) : '<span class="faint">-</span>', $probText],
    ['target', 'تا هدف (مقاومت)', $v->pct($lv['upside_pct']), $lv['target'] ? $v->num($lv['target']) : 'نزدیک سقف تاریخی'],
    ['stop', 'حد ضرر', $v->num($lv['stop']), $v->pct($lv['stop_pct']) . ' · زیر حمایت، حداکثر ۲.۵ ATR'],
    ['reward_risk', 'پاداش به ریسک', $v->num($lv['reward_risk'], 1), 'تا هدف ÷ تا حد ضرر · ۲ به بالا مطلوب'],
    ['levels', 'P/E', $v->num($val['pe'] ?? null, 1), 'صنعت ' . $v->num($val['sector_pe'] ?? null, 1)],
    ['rsi', 'RSI ۱۴', $v->num($t['rsi14'], 0), 'قدرت خریدار ' . $v->num($r->flow['buyer_power'] ?? null, 2)],
    ['annual_returns', 'بازده ۱ ساله', $v->pct($r->returns['1y'] ?? null), 'رشد مرکب سالانه ' . $v->pct($h['cagr_pct'] ?? null)],
];
$cashLike = $class->isCashLike();
if ($cashLike) {
    // A fixed-income fund or bond is judged by its yield against the risk-free rate, not by growth signals.
    $ret1y = $r->returns['1y'] ?? null;
    $tiles = [
        ['annual_returns', 'بازده ۱ ساله', $v->pct($ret1y), 'رشد مرکب سالانه از ابتدا ' . $v->pct($h['cagr_pct'] ?? null)],
        ['sharpe', 'در برابر نرخ بدون ریسک', $ret1y !== null ? $v->pct($ret1y - $riskFree * 100, 1) : '<span class="faint">-</span>',
            'نرخ بدون ریسک تنظیمات: ' . $v->pct($riskFree * 100, 0, false, false)],
        ['volatility', 'نوسان سالانه', $v->pct($h['volatility_annual_pct'] ?? null, 1, false, false), 'هرچه کمتر، به سپرده نزدیک‌تر'],
        ['max_drawdown', 'بیشترین افت تاریخی', $v->pct($h['max_drawdown_pct'] ?? null, 1), 'از ' . $v->e($h['first_date'] ?? '')],
    ];
}
?>
<section class="kpis" style="--cols:<?= \Sabadgardan\View\View::kpiColumns(count($tiles)) ?>">
  <?php foreach ($tiles as [$key, $label, $value, $sub]): ?>
    <div class="kpi" data-help="<?= $key ?>"><div class="label"><?= $label ?> <?= $v->help($key) ?></div><div class="value"><?= $value ?></div><div class="sub"><?= $sub ?></div></div>
  <?php endforeach; ?>
</section>

<?php if ($class->kind !== \Sabadgardan\Market\AssetClass::STOCK && $class->role() !== ''): ?>
  <div class="notice <?= $class->isCashLike() || $class->isOption() ? 'warn' : '' ?>" style="grid-column:1/-1"><b><?= $v->e($class->label()) ?>:</b> <?= $v->e($class->role()) ?>
    <?php if ($class->isCashLike()): ?> امتیاز پتانسیل رشد و سیگنال‌های فنی برای این نوع ابزار معنای محدودی دارند؛ معیار اصلی آن، بازده سالانه در برابر نرخ سود بدون ریسک است.<?php endif; ?></div>
<?php endif; ?>

<?php if ($holdings): ?>
  <?php ob_start(); ?>
  <div class="position-strip">
    <?php foreach ($holdings as $hd):
        $value = $hd['quantity'] * $price;
        $sellFee = $fees['fee_' . $class->feeClass() . '_sell'] * $value;
        $pnl = $value - $sellFee - $hd['cost']; ?>
      <div class="pos">
        <a class="faint" href="<?= $v->url('portfolio/' . $hd['portfolio']['id']) ?>"><?= $v->e($hd['portfolio']['name']) ?></a>
        <div><b><?= $v->num($hd['quantity']) ?></b> سهم · میانگین خرید <b><?= $v->num($hd['avg_cost']) ?></b></div>
        <div>ارزش روز <?= $v->money($value) ?> · سود/زیان <?= $v->money($pnl, true, true) ?> <?= $v->pct($hd['cost'] > 0 ? $pnl / $hd['cost'] * 100 : null) ?></div>
        <?php if ($lv['stop'] && $price < $lv['stop']): ?><div class="down">قیمت زیر حد ضرر تحلیلی است.</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?= $v->card('موقعیت شما در این نماد', (string) ob_get_clean(), 12, 'my_position') ?>
<?php endif; ?>

<?= $v->card('نمودار قیمت (تعدیل‌شده)', $v->chart(Charts::pricePanels($b->history), 'tall'), 12, 'price_chart',
    'شمعی + میانگین‌های ۲۰/۵۰/۲۰۰ و بولینگر · حجم · RSI · MACD — با اسکرول یا لغزنده بزرگ‌نمایی کنید') ?>

<?php if (!$cashLike): ?>
<?= $v->card('پتانسیل رشد', $v->chart(Charts::gauge($p->score), 'short'), 4, 'gauge') ?>
<?php ob_start(); ?>
<div class="summary"><?= $v->e($p->summary) ?></div>
<?php if ($p->scenarios): $s = $p->scenarios; ?>
  <div class="scen">
    <?php foreach ([['bear', 'بدبینانه', 'down'], ['base', 'پایه', ''], ['bull', 'خوش‌بینانه', 'up']] as [$k, $label, $cls]): ?>
      <div><div class="t"><?= $label ?></div><div class="v <?= $cls ?>"><?= $v->num((float) $s[$k]) ?></div><div class="t"><?= $v->pct(((float) $s[$k] / $lv['price'] - 1) * 100) ?></div></div>
    <?php endforeach; ?>
  </div>
  <p class="faint" style="margin:6px 0 0;font-size:12px">سناریوهای ۳ ماهه از <?= ($s['source'] ?? '') === 'analogs' ? 'توزیع موقعیت‌های مشابه تاریخی' : 'نوسان تاریخی (±۱ انحراف معیار)' ?></p>
<?php endif; ?>
<?= $v->card('جمع‌بندی تحلیلی', (string) ob_get_clean(), 8, 'summary') ?>

<?= $v->card('سهم هر عامل در امتیاز', $v->chart(Charts::factorContributions($p), null, max(260, 34 * count($p->factors) + 40)), 6, 'factors', 'امتیاز = ۵۰ + مجموع اثرها') ?>
<?php ob_start(); ?>
<ul class="reasons">
  <?php foreach (Factor::CATEGORIES as $cat):
      $items = array_values(array_filter($p->factors, static fn ($f) => $f->category === $cat));
      usort($items, static fn ($a, $b) => abs($b->contribution) <=> abs($a->contribution));
      foreach ($items as $f):
          $cls = $f->contribution > 0.3 ? 'up' : ($f->contribution < -0.3 ? 'down' : 'flat'); ?>
    <li><span class="pts <?= $cls ?> num"><?= ($f->contribution >= 0 ? '+' : '−') . number_format(abs($f->contribution), 1) ?></span>
      <div><span class="name"><?= $v->e($f->name) ?></span><span class="cat"><?= $v->e($cat) ?></span><div class="detail"><?= $v->e($f->detail) ?></div></div></li>
  <?php endforeach; endforeach; ?>
</ul>
<?= $v->card('دلایل تخصصی', (string) ob_get_clean(), 6, 'reasons') ?>

<?= $v->card('سناریوهای ۳ ماه آینده', $v->chart(Charts::scenarios($b->history, $p), 'mid'), 8, 'scenarios') ?>
<?php if ($p->analogs && !empty($p->analogs['forward'])): $a = $p->analogs; ?>
  <?= $v->card('توزیع بازده موقعیت‌های مشابه', $v->chart(Charts::analogHistogram($a), 'mid')
      . '<p class="faint" style="font-size:12px;margin:4px 0 0">میانه ' . strip_tags($v->pct($a['median_pct'])) . ' · نیمی از موارد بین '
      . strip_tags($v->pct($a['p20_pct'])) . ' و ' . strip_tags($v->pct($a['p80_pct'])) . ' (صدک ۲۰ تا ۸۰) · بالاتر از تورم '
      . strip_tags($v->pct($a['beat_inflation_rate'] * 100, 0, false, false)) . '</p>', 4, 'analogs', $a['samples'] . ' مورد · ۳ ماه بعد') ?>
<?php else: ?>
  <?= $v->card('موقعیت‌های مشابه', '<div class="empty">تاریخچه یا موقعیت مشابه کافی نیست.</div>', 4, 'analogs') ?>
<?php endif; ?>

<?php ob_start(); ?>
<?php if ($plan): $o = $plan['odds']; ?>
  <div class="plan">
    <div class="plan-levels">
      <div><span>ورود</span><b class="num"><?= number_format($plan['entry']) ?></b></div>
      <div class="up"><span>هدف</span><b class="num"><?= number_format($plan['target']) ?></b><small class="num">+<?= number_format($plan['target_pct'], 1) ?>٪</small></div>
      <div class="down"><span>حد ضرر</span><b class="num"><?= number_format($plan['stop']) ?></b><small class="num">−<?= number_format($plan['stop_pct'], 1) ?>٪</small></div>
      <div><span>پاداش/ریسک</span><b class="num"><?= $plan['reward_risk'] !== null ? number_format($plan['reward_risk'], 1) : '-' ?></b></div>
    </div>
    <?php if ($o): ?>
      <div class="odds-bar" role="img" aria-label="احتمال‌ها">
        <i class="win" style="width:<?= round($o['win_rate'] * 100, 1) ?>%"></i><i class="timeout" style="width:<?= round($o['timeout_rate'] * 100, 1) ?>%"></i><i class="loss" style="width:<?= round($o['loss_rate'] * 100, 1) ?>%"></i>
      </div>
      <div class="odds-legend">
        <span><i class="win"></i>رسیدن به هدف <b><?= round($o['win_rate'] * 100) ?>٪</b></span>
        <span><i class="timeout"></i>بی‌نتیجه در <?= $o['horizon_days'] ?> جلسه <b><?= round($o['timeout_rate'] * 100) ?>٪</b></span>
        <span><i class="loss"></i>خوردن حد ضرر <b><?= round($o['loss_rate'] * 100) ?>٪</b></span>
      </div>
      <?php
      $ok = $o['expectancy_pct'] > 0 && $o['win_rate'] >= SignalEngine::MIN_PROBABILITY && $o['win_rate'] > $o['loss_rate']
          && ($plan['reward_risk'] ?? 0) >= SignalEngine::MIN_REWARD_RISK;
      ?>
      <p style="margin:12px 0 0"><span class="pill <?= $ok ? 'up' : 'warn' ?>"><?= $ok ? 'طرح از نظر آماری قابل قبول است' : 'طرح معیارهای صدور سیگنال را ندارد' ?></span>
        <span class="faint" style="font-size:12px">معیار: احتمال ≥ <?= round(SignalEngine::MIN_PROBABILITY * 100) ?>٪، بیشتر از احتمال حد ضرر، پاداش/ریسک ≥ <?= SignalEngine::MIN_REWARD_RISK ?> و بازده مورد انتظار مثبت</span></p>
      <p class="muted" style="font-size:13px;margin:10px 0 0">بازده مورد انتظار هر بار اجرای این طرح در گذشته <?= $v->pct($o['expectancy_pct'], 1) ?> بوده است
        <?= $o['avg_days_to_target'] ? '· میانگین زمان رسیدن به هدف ' . round($o['avg_days_to_target']) . ' جلسه' : '' ?>.
        <span class="faint">(<?= $o['samples'] ?> نمونه، <?= $o['conditioned'] ? 'فقط موقعیت‌های مشابه امروز' : 'همه‌ی روزهای ۳ سال اخیر؛ موقعیت مشابه کافی نبود' ?>)</span></p>
    <?php else: ?>
      <div class="empty">تاریخچه‌ی کافی برای سنجش احتمال این طرح نیست.</div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="empty">قیمت نزدیک سقف تاریخی است یا حد ضرر قابل محاسبه نیست؛ طرح خرید معناداری تعریف نمی‌شود.</div>
<?php endif; ?>
<?= $v->card('طرح معاملاتی و احتمال موفقیت', (string) ob_get_clean(), 5, 'trade_plan', 'بر اساس رفتار تاریخی همین نماد') ?>
<?php endif; ?>
<?= $v->card('جریان پول حقیقی (۶۰ جلسه)', $flowDaily ? $v->chart(Charts::moneyFlow($flowDaily, $b->raw), 'mid')
    : '<div class="empty">داده‌ی تاریخی حقیقی/حقوقی در دسترس نیست.</div>', $cashLike ? 12 : 7, 'money_flow') ?>

<?php
$f = $r->flow;
$book = '';
if ($b->bestLimits) {
    $book .= '<table class="book"><thead><tr><th>تعداد</th><th>حجم خرید</th><th>قیمت خرید</th><th>قیمت فروش</th><th>حجم فروش</th><th>تعداد</th></tr></thead><tbody>';
    foreach (array_slice($b->bestLimits, 0, 5) as $row) {
        $book .= '<tr><td>' . $v->num((float) Field::get($row, 'zOrdMeDem', 0)) . '</td><td class="up">' . $v->num((float) Field::get($row, 'qTitMeDem', 0))
            . '</td><td class="up"><b>' . $v->num((float) Field::get($row, 'pMeDem', 0)) . '</b></td><td class="down"><b>' . $v->num((float) Field::get($row, 'pMeOf', 0))
            . '</b></td><td class="down">' . $v->num((float) Field::get($row, 'qTitMeOf', 0)) . '</td><td>' . $v->num((float) Field::get($row, 'zOrdMeOf', 0)) . '</td></tr>';
    }
    $book .= '</tbody></table>';
}
$todayRows = [
    ['قدرت خریدار حقیقی ' . $v->help('buyer_power'), $v->num($f['buyer_power'] ?? null, 2)],
    ['سرانه‌ی خرید / فروش', $v->money($f['per_capita_buy'] ?? null) . ' / ' . $v->money($f['per_capita_sell'] ?? null)],
    ['ورود پول حقیقی', $v->money($f['individual_net_value'] ?? null, true, true)],
    ['سهم حقوقی از خرید', $v->pct($f['institutional_buy_share'] ?? null, 0, false, false)],
];
echo $v->card('تابلوی امروز', ($book ?: '<div class="empty">عرضه و تقاضا در دسترس نیست.</div>') . $kv($todayRows), 6, 'imbalance', 'عرضه و تقاضا و حقیقی/حقوقی');
echo $v->card('سطوح قیمتی و ارزش‌گذاری', $kv([
    ['نوع ابزار ' . $v->help('asset_class'), $v->assetTag($class->label(), $class->tone()) . ($b->board() ? ' <span class="faint">' . $v->e($b->board()) . '</span>' : '')],
    ['قیمت', $v->num($lv['price'])],
    ['مقاومت / هدف', $v->num($lv['target']) . ' ' . $v->pct($lv['upside_pct'])],
    ['حمایت', $v->num($lv['support']) . ' ' . $v->pct($lv['downside_pct'])],
    ['حد ضرر', $v->num($lv['stop']) . ' ' . $v->pct($lv['stop_pct'])],
    ['ATR روزانه (میانگین دامنه‌ی نوسان)', $v->num($lv['atr'])],
    ['EPS', $v->num($val['eps'] ?? null)],
    ['P/E نماد / صنعت', $v->num($val['pe'] ?? null, 1) . ' / ' . $v->num($val['sector_pe'] ?? null, 1)],
    ['بازده سود (E/P)', $v->pct(!empty($val['pe']) ? 100 / $val['pe'] : null, 1, false, false)],
    ['ارزش بازار', $v->money($val['market_cap'] ?? null)],
    ['سقف ۵۲ هفته / سقف تاریخی', $v->num($h['high_52w'] ?? null) . ' / ' . $v->num($h['ath'] ?? null)],
]), 6, 'levels');

echo $v->card('بازده سال‌های شمسی', $v->chart(Charts::bars($h['annual_returns'] ?? [], 'بازده'), 'short'), 6, 'annual_returns');
echo $v->card('افت از سقف (Drawdown)', $v->chart(Charts::underwater($b->history->dates, $b->history->close), 'short'), 6, 'drawdown');
echo $v->card('کارنامه‌ی تاریخی', $kv([
    ['شروع تاریخچه', $v->e($h['first_date'] ?? '')],
    ['طول تاریخچه', $v->num($h['years'] ?? null, 1) . ' سال'],
    ['بازده کل', $v->pct($h['total_return_pct'] ?? null, 0)],
    ['رشد مرکب سالانه', $v->pct($h['cagr_pct'] ?? null)],
    ['در برابر تورم فرضی ' . $v->num($h['inflation_pct'] ?? null) . '٪', ($h['beats_inflation'] ?? null) === null ? '-' : (($h['beats_inflation']) ? '<span class="up">بالاتر</span>' : '<span class="down">پایین‌تر</span>')],
    ['بیشترین افت تاریخی', $v->pct($h['max_drawdown_pct'] ?? null, 0)],
    ['نوسان سالانه', $v->pct($h['volatility_annual_pct'] ?? null, 0, false, false)],
    ['تعدیل‌های افزایش سرمایه/سود', $v->num(count($p->adjustments))],
]), 4, 'record');
echo $v->card('اندیکاتورها', $kv([
    ['SMA20 / SMA50 ' . $v->help('sma'), $v->num($t['sma20']) . ' / ' . $v->num($t['sma50'])],
    ['SMA200', $v->num($t['sma200'])],
    ['RSI14 ' . $v->help('rsi'), $v->num($t['rsi14'], 1)],
    ['MACD هیستوگرام ' . $v->help('macd'), $v->num($t['macd_hist'], 1)],
    ['باند بولینگر ' . $v->help('bollinger'), $v->num($t['bb_lower']) . ' تا ' . $v->num($t['bb_upper'])],
    ['حجم / میانگین ۲۰', $v->num($t['volume_ratio'], 2)],
]), 4, 'sma');
?>
<?php ob_start(); ?>
<p class="verdict"><?= $v->e($r->verdict) ?></p>
<ul class="reasons compact">
  <?php foreach ($r->signals as $sg): ?>
    <li><span class="pts <?= $sg['score'] > 0 ? 'up' : ($sg['score'] < 0 ? 'down' : 'flat') ?>"><?= $sg['score'] > 0 ? '▲' : ($sg['score'] < 0 ? '▼' : '•') ?></span><span class="detail"><?= $v->e($sg['text']) ?></span></li>
  <?php endforeach; ?>
</ul>
<?= $v->card('سیگنال‌های کوتاه‌مدت', (string) ob_get_clean(), 4, 'signals_short') ?>

<?= $v->partial('partials/tx_drawer', ['portfolio' => $portfolio, 'fees' => $fees]) ?>
