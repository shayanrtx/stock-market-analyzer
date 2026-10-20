<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<\Sabadgardan\Live\Rules\Rule> $rules
 *  @var bool $cronOk  cron ran within the last 10 minutes */
use Sabadgardan\Live\MarketClock;
?>
<div id="live-root" style="display:contents">
<div class="page-head">
  <div class="live-status" data-help="live_phase">
    <span class="pulse-dot"></span><b id="live-phase">در حال اتصال…</b>
    <span class="sub" id="live-clock"></span>
  </div>
  <button class="btn secondary" id="notify-btn" type="button"><?= $v->icon('bell', 16) ?>فعال‌سازی اعلان مرورگر</button>
</div>

<?php if (!$cronOk): ?>
  <div class="notice warn" style="grid-column:1/-1">زمان‌بند (Cron) سرور فعال نیست؛ رصد فقط تا وقتی این صفحه باز است انجام می‌شود و سیگنال بله/تلگرام در غیاب شما ارسال نمی‌شود.
    <?= $isAdmin ? '<a href="' . $v->url('admin/settings') . '">راهنمای تنظیم Cron</a>' : 'به مدیر سامانه اطلاع دهید.' ?></div>
<?php endif; ?>

<section class="kpis small" style="--cols:3">
  <div class="kpi"><div class="label">نمادهای تحت رصد</div><div class="value" id="live-count">-</div><div class="sub">دارایی‌های سبد + دیده‌بان</div></div>
  <div class="kpi"><div class="label">رویدادهای مهم امروز</div><div class="value" id="live-high">-</div><div class="sub">شدت بالا (قابل اقدام)</div></div>
  <div class="kpi"><div class="label">سیگنال‌های امروز <?= $v->help('live_signals') ?></div><div class="value" id="live-sigcount">-</div><div class="sub"><a href="<?= $v->url('signals') ?>">تاریخچه‌ی سیگنال‌ها</a></div></div>
</section>

<section class="card s7" data-help="live_feed">
  <header><div class="card-title"><h2>جریان بینش‌ها</h2><?= $v->help('live_feed') ?></div>
    <div class="chips" id="live-chips">
      <span class="chip on" data-f="important">مهم</span><span class="chip" data-f="up">قدرت خرید</span>
      <span class="chip" data-f="down">فشار فروش</span><span class="chip" data-f="all">همه</span>
    </div></header>
  <ul class="reasons feed" id="live-feed"><li class="skeleton" style="height:120px"></li></ul>
</section>
<section class="card s5" data-help="live_signals">
  <header><div class="card-title"><h2>سیگنال‌های امروز</h2><?= $v->help('live_signals') ?></div></header>
  <ul class="reasons feed" id="live-signals"><li class="skeleton" style="height:80px"></li></ul>
  <p class="faint" style="font-size:12px;margin:12px 0 0">سیگنال فقط وقتی صادر می‌شود که رفتار لحظه‌ای تابلو، روند، پتانسیل رشد، نسبت پاداش به ریسک و احتمال تاریخی همگی هم‌سو باشند. ارسال به بله/تلگرام را در <a href="<?= $v->url('settings') ?>#notify">تنظیمات</a> فعال کنید.</p>
</section>

<section class="card s12" data-help="live_watch">
  <header><div class="card-title"><h2>دیده‌بان زنده</h2><?= $v->help('live_watch') ?></div><span class="hint">قدرت خریدار <?= $v->help('buyer_power') ?> · خرید/فروش تابلو <?= $v->help('imbalance') ?></span></header>
  <div class="table-wrap" id="live-table"><div class="skeleton" style="height:140px"></div></div>
</section>
</div>

<section class="card s12">
  <header><div class="card-title"><h2>موتور رصد چه چیزهایی را بررسی می‌کند؟</h2></div><span class="hint">هر <?= (int) $interval ?> ثانیه در ساعات بازار</span></header>
  <div class="rules">
    <?php foreach ($rules as $rule): ?>
      <div class="rule"><b><?= $v->e($rule->description()) ?></b>
        <span class="faint"><?= $v->e(implode('، ', array_map(static fn ($ph) => MarketClock::LABELS[$ph], $rule->phases()))) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>
