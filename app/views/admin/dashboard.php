<?php
/** @var \Sabadgardan\View\View $v
 *  @var array<string, int> $stats
 *  @var list<array{d: string, n: int}> $signups last 30 days
 *  @var string $lastCron
 *  @var list<array<string, mixed>> $recent audit rows */
use Sabadgardan\Core\AuditLog;
use Sabadgardan\View\Charts;

$cronAge = $lastCron !== '' ? time() - strtotime($lastCron) : null;
?>
<?= $v->partial('admin/_tabs', ['tab' => 'admin']) ?>
<?php if ($stats['pending']): ?>
  <div class="notice warn" style="grid-column:1/-1"><?= $stats['pending'] ?> حساب در انتظار تأیید است. <a href="<?= $v->url('admin/users', ['status' => 'pending']) ?>">بررسی</a></div>
<?php endif; ?>
<?php if ($cronAge === null || $cronAge > 600): ?>
  <div class="notice warn" style="grid-column:1/-1">Cron <?= $cronAge === null ? 'هرگز اجرا نشده' : 'از ' . $v->e($v->jdatetime($lastCron)) . ' اجرا نشده' ?> است؛ بدون آن رصد خودکار و ارسال سیگنال در غیاب کاربران انجام نمی‌شود. <a href="<?= $v->url('admin/settings') ?>#cron">راهنمای تنظیم</a></div>
<?php endif; ?>
<section class="kpis small" style="--cols:5">
  <div class="kpi"><div class="label">کاربران</div><div class="value"><?= $v->num($stats['users']) ?></div><div class="sub"><?= $v->num($stats['active']) ?> فعال · <?= $v->num($stats['new_week']) ?> جدید در هفته</div></div>
  <div class="kpi"><div class="label">کاربران فعال هفته</div><div class="value"><?= $v->num($stats['logins_week']) ?></div><div class="sub">ورود در ۷ روز اخیر</div></div>
  <div class="kpi"><div class="label">سبدها / تراکنش‌ها</div><div class="value"><?= $v->num($stats['portfolios']) ?></div><div class="sub"><?= $v->num($stats['transactions']) ?> تراکنش</div></div>
  <div class="kpi"><div class="label">سیگنال‌های هفته</div><div class="value"><?= $v->num($stats['signals_week']) ?></div><div class="sub"><?= $v->num($stats['notifications_week']) ?> پیام ارسال‌شده</div></div>
  <div class="kpi"><div class="label">متصل به پیام‌رسان</div><div class="value"><?= $v->num($stats['linked']) ?></div><div class="sub">کاربر</div></div>
</section>
<?php
$series = [];
for ($i = 29; $i >= 0; $i--) {
    $series[date('Y-m-d', time() - $i * 86400)] = 0;
}
foreach ($signups as $s) {
    if (isset($series[$s['d']])) {
        $series[$s['d']] = (int) $s['n'];
    }
}
$labels = [];
foreach ($series as $d => $n) {
    $labels[substr($v->jdate($d), 5)] = $n;
}
echo $v->card('ثبت‌نام ۳۰ روز اخیر', $v->chart(Charts::counts($labels, 'ثبت‌نام'), 'short'), 7);
?>
<?php ob_start(); ?>
<ul class="timeline">
  <?php foreach ($recent as $a): ?>
    <li><b><?= $v->e(AuditLog::LABELS[$a['action']] ?? $a['action']) ?></b> <span class="muted"><?= $v->e($a['username'] ?? '—') ?></span>
      <?php if ($a['detail'] !== ''): ?><span class="faint"> · <?= $v->e($a['detail']) ?></span><?php endif; ?>
      <span class="sub"><?= $v->e($v->jdatetime((string) $a['created_at'])) ?> · <span dir="ltr"><?= $v->e($a['ip']) ?></span></span></li>
  <?php endforeach; ?>
</ul>
<?= $v->card('رویدادهای اخیر', (string) ob_get_clean() ?: '<div class="empty">رویدادی ثبت نشده.</div>', 5, '', '', '<a class="btn ghost sm" href="' . $v->url('admin/audit') . '">همه</a>') ?>
