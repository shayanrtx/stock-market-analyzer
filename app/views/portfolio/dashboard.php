<?php
/** @var \Sabadgardan\View\View $v
 *  @var array $portfolio
 *  @var array $val       valuation: positions, closed, transactions, totals, errors
 *  @var array|null $performance */
use Sabadgardan\View\Charts;
use Sabadgardan\View\View;

$t = $val['totals'];
$pid = (int) $portfolio['id'];
$hasTx = (bool) $val['transactions'];
$fees = [];
foreach (array_keys(\Sabadgardan\Portfolio\FeeSchedule::DEFAULTS) as $k) {
    $fees[$k] = (float) $GLOBALS['__fees'][$k] ?? 0;
}
?>
<div class="page-head">
  <div>
    <?php if (count($portfolioList) > 1): ?>
      <select class="input" style="width:auto" onchange="location.href=this.value" aria-label="انتخاب سبد">
        <?php foreach ($portfolioList as $p): ?>
          <option value="<?= $v->url('portfolio/' . $p['id']) ?>" <?= (int) $p['id'] === $pid ? 'selected' : '' ?>><?= $v->e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <div class="sub">به‌روزرسانی <?= $v->now() ?> · ارزش‌گذاری با قیمت پایانی</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn secondary" href="<?= $v->url("portfolio/$pid/transactions") ?>"><?= $v->icon('list', 16) ?>تراکنش‌ها</a>
    <button class="btn secondary" type="button" onclick="document.getElementById('import-card').hidden=false;document.getElementById('import-card').scrollIntoView({behavior:'smooth'})"><?= $v->icon('upload', 16) ?>ورود گروهی</button>
    <button class="btn" type="button" data-open-drawer><?= $v->icon('plus', 16) ?>ثبت معامله</button>
  </div>
</div>

<?php if (!$hasTx): ?>
  <section class="empty-hero">
    <div class="art"><?= $v->icon('wallet', 34) ?></div>
    <h2>سبد شما هنوز خالی است</h2>
    <p>معاملات خود را ثبت کنید تا ارزش روز، سود و زیان، ترکیب دارایی‌ها، ریسک سبد و پیشنهادهای مدیریتی را ببینید. می‌توانید یکی‌یکی ثبت کنید یا جدول دارایی‌های کارگزاری را یک‌جا بچسبانید.</p>
    <div class="actions">
      <button class="btn" type="button" data-open-drawer><?= $v->icon('plus', 16) ?>ثبت اولین معامله</button>
      <button class="btn secondary" type="button" onclick="document.getElementById('import-card').hidden=false;document.getElementById('import-card').scrollIntoView({behavior:'smooth'})"><?= $v->icon('upload', 16) ?>ورود گروهی از کارگزاری</button>
    </div>
  </section>
<?php else: ?>

<?php
$pnlPct = $t['unrealized_pct'];
$tiles = [
    '<div class="kpi hero" data-help="nav"><div class="label">ارزش روز سبد ' . $v->help('nav') . '</div><div class="value">'
        . View::moneyParts($t['nav'])[0] . '<small>' . View::moneyParts($t['nav'])[1] . '</small></div><div class="sub">'
        . count($val['positions']) . ' نماد' . ($t['tracks_cash'] ? ' · نقد ' . $v->money($t['cash']) : '') . '</div></div>',
    '<div class="kpi" data-help="total_pnl"><div class="label">سود و زیان کل ' . $v->help('total_pnl') . '</div><div class="value">'
        . $v->money($t['total_pnl'], true, true) . '</div><div class="sub">تحقق‌نیافته + تحقق‌یافته + سود نقدی</div></div>',
    '<div class="kpi" data-help="unrealized"><div class="label">سود و زیان تحقق‌نیافته ' . $v->help('unrealized') . '</div><div class="value">'
        . $v->money($t['unrealized'], true, true) . '</div><div class="sub">' . $v->pct($pnlPct) . ' روی بهای تمام‌شده ' . $v->money($t['cost_basis']) . '</div></div>',
    '<div class="kpi" data-help="day_pnl"><div class="label">سود و زیان امروز ' . $v->help('day_pnl') . '</div><div class="value">'
        . $v->money($t['day_pnl'], true, true) . '</div><div class="sub">' . $v->pct($t['day_pct'], 2) . '</div></div>',
    '<div class="kpi" data-help="realized"><div class="label">سود و زیان تحقق‌یافته ' . $v->help('realized') . '</div><div class="value">'
        . $v->money($t['realized'], true, true) . '</div><div class="sub">سود نقدی ' . $v->money($t['dividends']) . ' · کارمزدها ' . $v->money($t['fees']) . '</div></div>',
];
?>
<section class="kpis" style="--cols:<?= count($tiles) ?>"><?= implode('', $tiles) ?></section>

<?php if ($val['errors']): ?>
  <div class="notice warn s12" style="grid-column:1/-1">قیمت برخی نمادها دریافت نشد؛ ارزش آن‌ها در جمع‌ها لحاظ نشده است. اتصال سرور به سایت بورس را در «سلامت سامانه» بررسی کنید.</div>
<?php endif; ?>
<?php if ($performance && $performance['warnings']): ?>
  <section class="card s12" data-help="warnings"><ul class="alerts"><?php foreach ($performance['warnings'] as $w): ?><li><?= $v->e($w) ?></li><?php endforeach; ?></ul></section>
<?php endif; ?>

<?= $v->card('عملکرد سبد', $v->chart($performance ? Charts::performance($performance['series']) : [], 'mid'), 8, 'performance_chart',
    'ارزش سهم‌ها در برابر بهای تمام‌شده؛ پایین: سود و زیان تجمعی') ?>
<?php
$alloc = [];
foreach ($val['positions'] as $p) {
    if ($p['market_value']) {
        $alloc[$p['symbol']] = $p['weight'];
    }
}
if ($t['tracks_cash'] && $t['cash'] > 0 && $t['nav'] > 0) {
    $alloc['نقد'] = $t['cash'] / $t['nav'] * 100;
}
echo $v->card('ترکیب دارایی‌ها', $v->chart(Charts::donut($alloc), 'mid'), 4, 'allocation');
?>

<?php ob_start(); ?>
<div class="table-wrap"><table class="sortable">
  <thead><tr>
    <th data-sort="text">نماد</th><th data-sort="num">تعداد</th><th data-sort="num">میانگین خرید</th><th data-sort="num">قیمت پایانی</th>
    <th data-sort="num">امروز</th><th data-sort="num">ارزش روز</th><th data-sort="num">سود/زیان</th><th data-sort="num">بازده</th>
    <th data-sort="num">وزن</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($val['positions'] as $p): ?>
    <tr>
      <td><div class="symbol-cell"><span class="symbol-badge"><?= $v->e(mb_substr($p['symbol'], 0, 2)) ?></span>
        <div><a href="<?= $v->url('stock', ['ins' => $p['ins_code']]) ?>"><b><?= $v->e($p['symbol']) ?></b></a>
        <span class="sub"><?= $v->e($p['name'] ?: $p['type']) ?><?= $p['status'] && $p['status'] !== 'مجاز' ? ' · ' . $v->e($p['status']) : '' ?></span></div></div></td>
      <td data-v="<?= $p['quantity'] ?>"><?= $v->num($p['quantity']) ?></td>
      <td data-v="<?= $p['avg_cost'] ?>"><?= $v->num($p['avg_cost']) ?></td>
      <td data-v="<?= $p['close'] ?>"><?= $v->num($p['close']) ?><?php if ($p['last'] && $p['close'] && abs($p['last'] - $p['close']) > 0.5): ?><span class="sub">آخرین <?= $v->num($p['last']) ?></span><?php endif; ?></td>
      <td data-v="<?= $p['change_pct'] ?>"><?= $v->pct($p['change_pct'], 2) ?><span class="sub"><?= $v->money($p['day_pnl'], true) ?></span></td>
      <td data-v="<?= $p['market_value'] ?>"><?= $v->money($p['market_value']) ?></td>
      <td data-v="<?= $p['unrealized'] ?>"><?= $v->money($p['unrealized'], true, true) ?><?php if ($p['realized']): ?><span class="sub">تحقق‌یافته <?= $v->money($p['realized'], true) ?></span><?php endif; ?></td>
      <td data-v="<?= $p['unrealized_pct'] ?>"><?= $v->pct($p['unrealized_pct']) ?></td>
      <td data-v="<?= $p['weight'] ?>"><?= $v->weightBar($p['weight']) ?></td>
      <td><div class="row-actions">
        <button class="btn ghost sm" type="button" data-open-drawer data-ins="<?= $v->e($p['ins_code']) ?>" data-symbol="<?= $v->e($p['symbol']) ?>" data-type="buy">خرید</button>
        <button class="btn ghost sm" type="button" data-open-drawer data-ins="<?= $v->e($p['ins_code']) ?>" data-symbol="<?= $v->e($p['symbol']) ?>" data-type="sell">فروش</button>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr>
    <td>جمع</td><td></td><td><?= $v->money($t['cost_basis']) ?></td><td></td><td><?= $v->money($t['day_pnl'], true, true) ?></td>
    <td><?= $v->money($t['market_value']) ?></td><td><?= $v->money($t['unrealized'], true, true) ?></td><td><?= $v->pct($t['unrealized_pct']) ?></td><td></td><td></td>
  </tr></tfoot>
</table></div>
<p class="faint" style="font-size:12px;margin:10px 0 0">سود و زیان با کسر کارمزد فروش (ارزش خالص فروش) محاسبه شده است، همان‌طور که در صورت‌حساب کارگزاری می‌بینید.</p>
<?php echo $v->card('دارایی‌ها', (string) ob_get_clean(), 12, 'holdings'); ?>

<?php
if ($performance && count($performance['monthly']) > 1) {
    $monthly = array_slice(array_map(static fn ($x) => $x / 10, $performance['monthly']), -12, null, true);
    echo $v->card('سود و زیان ماهانه', $v->chart(Charts::bars($monthly, 'سود و زیان', 'fn:tomanAxis', true), 'short'), 6, 'monthly_pnl', 'ماه‌های شمسی');
}
$bySymbol = [];
foreach ($val['positions'] as $p) {
    $bySymbol[$p['symbol']] = ($p['unrealized'] ?? 0) + $p['realized'] + $p['dividends'];
}
foreach ($val['closed'] as $c) {
    $bySymbol[$c['symbol']] = ($bySymbol[$c['symbol']] ?? 0) + $c['realized'] + $c['dividends'];
}
arsort($bySymbol);
if ($bySymbol) {
    $bySymbolToman = array_map(static fn ($x) => $x / 10, array_slice($bySymbol, 0, 15, true));
    echo $v->card('سهم هر نماد از سود و زیان', $v->chart(Charts::hbar($bySymbolToman, Charts::ACCENT, true, true),
        null, max(240, 30 * count($bySymbolToman) + 50)), 6, 'pnl_by_symbol');
}
?>

<div class="loading-block" data-async="<?= $v->url("portfolio/$pid/analytics") ?>" aria-busy="true">
  <div class="card s12" style="box-shadow:none"><h2>تحلیل سبد</h2><p class="muted" style="margin:6px 0 0">در حال محاسبه‌ی پتانسیل رشد، ریسک و پیشنهادهای هر نماد…</p></div>
  <div class="skeleton wide"></div><div class="skeleton"></div><div class="skeleton"></div>
</div>

<?php if ($val['closed']): ?>
  <?php ob_start(); ?>
  <div class="table-wrap"><table class="sortable">
    <thead><tr><th data-sort="text">نماد</th><th data-sort="num">سود/زیان قطعی</th><th data-sort="num">سود نقدی</th><th data-sort="num">کارمزدها</th><th data-sort="text">آخرین معامله</th></tr></thead>
    <tbody><?php foreach ($val['closed'] as $c): ?>
      <tr><td><b><?= $v->e($c['symbol']) ?></b></td><td data-v="<?= $c['realized'] ?>"><?= $v->money($c['realized'], true, true) ?></td>
        <td><?= $v->money($c['dividends']) ?></td><td><?= $v->money($c['fees']) ?></td><td><?= $v->e($v->jdate($c['last_date'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php echo $v->card('معاملات بسته‌شده', (string) ob_get_clean(), 12, 'closed'); ?>
<?php endif; ?>
<?php endif; ?>

<section class="card s12" id="import-card" hidden>
  <header><div class="card-title"><h2>ورود گروهی از جدول کارگزاری</h2></div>
    <button class="icon-btn" type="button" onclick="this.closest('section').hidden=true" aria-label="بستن"><?= $v->icon('close') ?></button></header>
  <form method="post" action="<?= $v->url("portfolio/$pid/import") ?>">
    <?= $v->csrf() ?>
    <p class="muted">هر خط: <b>نماد تعداد [میانگین قیمت خرید به ریال]</b>. ستون‌ها می‌توانند با فاصله، تب یا کاما جدا باشند و جدول کارگزاری را مستقیم کپی کنید. اگر قیمت خرید را ننویسید، قیمت پایانی امروز به‌عنوان قیمت خرید ثبت می‌شود.</p>
    <div class="field"><textarea class="input" name="text" rows="8" placeholder="فملی	17673	9500&#10;خودران	10000&#10;آوند	30000	24000" style="min-height:180px"></textarea></div>
    <div class="row2">
      <div class="field"><label for="import-date">تاریخ خرید</label><input id="import-date" name="date" data-jdate class="num-input"></div>
      <div class="field" style="align-self:end"><button class="btn" type="submit"><?= $v->icon('upload', 16) ?>ثبت همه</button></div>
    </div>
  </form>
</section>

<?php
// The drawer is rendered after the main content (fixed position) by the layout's $after slot.
?>
