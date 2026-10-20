<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array<string, mixed>> $signals  newest first, with outcome and decoded reasons
 *  @var array<string, ?float> $summary SignalTracker::summary() */
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Market\Jalali;
use Sabadgardan\Signals\Signal;
use Sabadgardan\Signals\SignalTracker;

if (!$signals): ?>
  <section class="empty-hero">
    <div class="art"><?= $v->icon('bell', 34) ?></div>
    <h2>هنوز سیگنالی برای نمادهای شما صادر نشده</h2>
    <p>موتور رصد در ساعات بازار نمادهای سبد و دیده‌بان شما را بررسی می‌کند و فقط وقتی همه‌ی شرایط هم‌سو باشد سیگنال خرید یا هشدار فروش صادر می‌کند؛ پس روزهای بدون سیگنال طبیعی است.</p>
    <div class="actions"><a class="btn" href="<?= $v->url('live') ?>"><?= $v->icon('pulse', 16) ?>رصد زنده</a>
      <a class="btn secondary" href="<?= $v->url('settings') ?>#notify"><?= $v->icon('bell', 16) ?>اتصال بله / تلگرام</a></div>
  </section>
<?php return; endif; ?>

<section class="kpis small" style="--cols:4" data-help="signal_record">
  <div class="kpi"><div class="label">سیگنال‌های خرید</div><div class="value"><?= $v->num($summary['buys']) ?></div><div class="sub"><?= $v->num($summary['closed_buys']) ?> بسته‌شده</div></div>
  <div class="kpi"><div class="label">نرخ موفقیت خرید <?= $v->help('signal_record') ?></div><div class="value"><?= $summary['win_rate'] !== null ? $v->pct($summary['win_rate'] * 100, 0, false, false) : '<span class="faint">-</span>' ?></div><div class="sub">از سیگنال‌های بسته‌شده</div></div>
  <div class="kpi"><div class="label">میانگین نتیجه‌ی خرید</div><div class="value"><?= $v->pct($summary['avg_buy_return_pct'], 1) ?></div><div class="sub">هدف، حد ضرر یا پایان افق</div></div>
  <div class="kpi"><div class="label">هشدارهای فروش</div><div class="value"><?= $v->num($summary['sells']) ?></div><div class="sub">تغییر قیمت پس از هشدار <?= $v->pct($summary['avg_sell_move_pct'], 1) ?></div></div>
</section>

<?php ob_start(); ?>
<div class="table-wrap"><table class="sortable">
  <thead><tr><th data-sort="text">زمان</th><th data-sort="text">نماد</th><th data-sort="text">نوع</th><th data-sort="num">قیمت ورود</th>
    <th data-sort="num">هدف / حد ضرر</th><th data-sort="num">احتمال</th><th data-sort="text">نتیجه</th><th data-sort="num">بازده</th><th>دلایل</th></tr></thead>
  <tbody>
  <?php foreach ($signals as $s):
      $o = $s['outcome'];
      $buy = $s['side'] === Signal::BUY;
      $cls = !$o ? '' : match ($o['status']) { SignalTracker::TARGET => 'up', SignalTracker::STOP => 'down', default => 'neutral' };
      $dateKey = $s['day'] . date('His', (int) $s['created_at']); ?>
    <tr>
      <td data-v="<?= $v->e($dateKey) ?>"><span class="num"><?= $v->e(Jalali::fromGregorianString(date('Y-m-d', (int) $s['created_at']))) ?></span><span class="sub"><?= MarketClock::local((int) $s['created_at'], 'H:i') ?></span></td>
      <td><a href="<?= $v->url('stock', ['ins' => $s['ins_code']]) ?>"><b><?= $v->e($s['symbol']) ?></b></a>
        <?= $s['class'] ? $v->assetTag($s['class']->label(), $s['class']->tone()) : '' ?></td>
      <td><span class="pill <?= $buy ? 'buy' : 'sell' ?>"><?= $buy ? 'خرید' : 'هشدار فروش' ?></span></td>
      <td data-v="<?= (float) $s['entry'] ?>"><?= $v->num((float) $s['entry']) ?></td>
      <td data-v="<?= (float) $s['target'] ?>"><?php if ($s['target'] || $s['stop']): ?><span class="up"><?= $v->num($s['target'] !== null ? (float) $s['target'] : null) ?></span> / <span class="down"><?= $v->num($s['stop'] !== null ? (float) $s['stop'] : null) ?></span><?php else: ?><span class="faint">-</span><?php endif; ?></td>
      <td data-v="<?= (float) $s['probability'] ?>"><?= $s['probability'] !== null ? $v->pct((float) $s['probability'] * 100, 0, false, false) . '<span class="sub">' . (int) $s['samples'] . ' نمونه</span>' : '<span class="faint">-</span>' ?></td>
      <td><?php if ($o): ?><span class="pill <?= $cls ?>"><?= $buy || $o['status'] === SignalTracker::NEW ? $o['label'] : ($o['status'] === SignalTracker::EXPIRED ? 'پایان افق' : 'در جریان') ?></span>
        <?php if ($o['days']): ?><span class="sub"><?= $o['days'] ?> جلسه</span><?php endif; ?><?php else: ?><span class="faint">-</span><?php endif; ?></td>
      <td data-v="<?= $o['return_pct'] ?? 0 ?>"><?= $o ? $v->pct($o['return_pct'], 1) : '<span class="faint">-</span>' ?></td>
      <td class="wrap"><?= $v->e(implode('؛ ', array_slice($s['reasons'], 0, 3))) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= $v->card('تاریخچه‌ی سیگنال‌ها', (string) ob_get_clean(), 12, '', 'نمادهای سبد و دیده‌بان شما · ۲۰۰ مورد اخیر') ?>
