<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array{0: string, 1: string, 2: bool}> $env
 *  @var list<array{0: string, 1: string, 2: bool, 3: float}> $checks  API probes: name, result, ok, ms
 *  @var int $migration
 *  @var array<string, mixed> $cron  AdminController::cronDiagnosis()
 *  @var list<array{name: string, size: int, time: int}> $backups newest first
 *  @var string $dbDriver sqlite | mysql */
$failed = count(array_filter($checks, static fn ($c) => !$c[2]));
$blocked = (bool) array_filter($checks, static fn ($c) => !$c[2] && str_contains($c[1], 'ایران'));
?>
<?= $v->partial('admin/_tabs', ['tab' => 'health']) ?>
<?php
$cronMessages = [
    'ok' => ['', 'Cron درست کار می‌کند.'],
    'job_errors' => ['warn', 'Cron اجرا می‌شود ولی بعضی کارها در آخرین اجرا خطا دادند: ' . $cron['error']],
    'old_php' => ['error', 'Cron به اسکریپت می‌رسد ولی آن را با PHP ' . $cron['beat_php'] . ' اجرا می‌کند که قدیمی‌تر از ۸٫۱ است. در cPanel، Command را با دستور زیر عوض کنید (مسیر PHP نسخه‌ی خود سایت).'],
    'crashes' => ['error', 'Cron به اسکریپت می‌رسد (آخرین بار ' . $v->jdatetime($cron['beat_at']) . ' با PHP ' . $cron['beat_php'] . ') ولی اجرا تمام نمی‌شود. علت در گزارش پایین همین کارت ثبت شده است؛ دکمه‌ی «اجرای آزمایشی» هم خطا را نشان می‌دهد.'],
    'stopped' => ['warn', 'آخرین تماس cron در ' . $v->jdatetime($cron['beat_at']) . ' بوده و از آن زمان اجرا نشده است. بررسی کنید کار cron حذف یا غیرفعال نشده باشد.'],
    'never' => ['error', 'Cron هنوز حتی یک بار به اسکریپت نرسیده است.'],
];
[$tone, $message] = $cronMessages[$cron['status']];
?>
<section class="card s12" id="cron">
  <header><div class="card-title"><h2>زمان‌بند (Cron)</h2></div>
    <form method="post" action="<?= $v->url('admin/health/cron') ?>"><?= $v->csrf() ?><button class="btn secondary sm">اجرای آزمایشی همین حالا</button></form></header>
  <div class="notice <?= $tone ?>"><span class="status-dot <?= $cron['status'] === 'ok' ? 'ok' : 'bad' ?>"></span><?= $v->e($message) ?></div>
  <table class="kv" style="margin-top:10px"><tbody>
    <tr><td>آخرین اجرای کامل</td><td><?= $cron['last'] !== '' ? $v->e($v->jdatetime($cron['last']) . ' · ' . $cron['runner']) : '<span class="faint">هرگز</span>' ?></td></tr>
    <tr><td>آخرین تماس cron با اسکریپت</td><td><?= $cron['beat_at'] !== '' ? $v->e($v->jdatetime($cron['beat_at']) . ' · PHP ' . $cron['beat_php']) : '<span class="faint">هرگز</span>' ?></td></tr>
    <tr><td>دستور پیشنهادی برای Command</td><td><input readonly dir="ltr" class="input" value="<?= $v->e($cron['command']) ?>" onclick="this.select()" style="font-size:12.5px"></td></tr>
  </tbody></table>
  <?php if ($cron['status'] !== 'ok'): ?>
    <ul class="faint" style="font-size:12.5px;line-height:2.1;padding-inline-start:18px;margin:10px 0 0">
      <li>در cPanel ← Cron Jobs، هر پنج فیلد Minute، Hour، Day، Month و Weekday باید <code dir="ltr">*</code> باشد (یا اگر هاست اجازه نداد، Minute برابر <code dir="ltr">*/5</code>).</li>
      <li>Command دقیقاً دستور بالا باشد؛ چیزی مثل <code dir="ltr">&gt;/dev/null</code> به آن اضافه نکنید.</li>
      <li>نسخه‌ی جدید پوشه‌های <code dir="ltr">bin</code>، <code dir="ltr">src</code> و <code dir="ltr">views</code> آپلود شده باشد؛ نسخه‌های قبلی این ردپا را ثبت نمی‌کردند.</li>
      <li>بعد از ذخیره، یکی دو دقیقه صبر کنید و این صفحه را دوباره باز کنید.</li>
    </ul>
  <?php endif; ?>
  <?php if ($cron['log']): ?>
    <details class="howto" <?= in_array($cron['status'], ['crashes', 'job_errors'], true) ? 'open' : '' ?>><summary>گزارش خطاهای cron (storage/logs/cron.log)</summary>
      <pre class="log" dir="ltr"><?= $v->e(implode("\n", $cron['log'])) ?></pre></details>
  <?php endif; ?>
</section>
<?php if (!$failed): ?>
  <div class="notice" style="grid-column:1/-1">همه‌ی سرویس‌های داده سالم‌اند.</div>
<?php elseif ($blocked): ?>
  <div class="notice error" style="grid-column:1/-1">سرور به برخی سرویس‌ها دسترسی ندارد. سایت‌های بورس فقط به IP ایران پاسخ می‌دهند؛ برنامه را روی هاست داخل ایران اجرا کنید.</div>
<?php else: ?>
  <div class="notice warn" style="grid-column:1/-1"><?= $failed ?> بررسی ناموفق بود. اگر «فیلد ناموجود» دیده می‌شود، ساختار پاسخ سایت بورس تغییر کرده و آن بخش از تحلیل ممکن است ناقص باشد.</div>
<?php endif; ?>

<?php ob_start(); ?>
<table class="kv"><tbody>
  <?php foreach ($env as [$name, $value, $ok]): ?>
    <tr><td><?= $v->e($name) ?></td><td><span class="status-dot <?= $ok ? 'ok' : 'bad' ?>"></span><?= $v->e($value) ?></td></tr>
  <?php endforeach; ?>
  <tr><td>نسخه‌ی پایگاه داده</td><td><span class="num"><?= (int) $migration ?></span></td></tr>
</tbody></table>
<?= $v->card('محیط اجرا', (string) ob_get_clean(), 4) ?>

<?php ob_start(); ?>
<div class="table-wrap"><table>
  <thead><tr><th>سرویس</th><th>نتیجه</th><th>زمان پاسخ</th></tr></thead>
  <tbody>
  <?php foreach ($checks as [$name, $result, $ok, $ms]): ?>
    <tr><td><span class="status-dot <?= $ok ? 'ok' : 'bad' ?>"></span><?= $v->e($name) ?></td>
      <td class="wrap <?= $ok ? 'up' : 'down' ?>"><?= $v->e($result) ?></td><td><span class="num"><?= $v->num($ms) ?></span> <small class="faint">ms</small></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= $v->card('سرویس‌های داده‌ی بازار', (string) ob_get_clean(), 8, '', 'فراخوانی واقعی هر سرویس و بررسی فیلدهای مورد نیاز') ?>

<?php ob_start(); ?>
<p class="muted" style="font-size:13px;margin-top:0">هر روز یک نسخه به‌طور خودکار (با Cron) در <code dir="ltr">storage/backups</code> ساخته و <?= \Sabadgardan\Core\Backup::KEEP ?> نسخه‌ی آخر نگه داشته می‌شود.
  <?= $dbDriver === 'sqlite' ? 'برای بازگردانی، فایل را از حالت فشرده (gz) خارج کنید و به‌جای فایل پایگاه داده‌ی SQLite بگذارید.' : 'برای بازگردانی، فایل را در phpMyAdmin با Import وارد کنید.' ?>
  یک نسخه را بیرون از هاست هم نگه دارید.</p>
<?php if ($backups): ?>
  <div class="table-wrap"><table>
    <thead><tr><th>فایل</th><th>زمان</th><th>حجم</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
      <tr><td dir="ltr" style="text-align:right"><?= $v->e($b['name']) ?></td><td><?= $v->e($v->jdatetime(date('Y-m-d H:i', $b['time']))) ?></td>
        <td><span class="num"><?= $v->num($b['size'] / 1024, 0) ?></span> <small class="faint">KB</small></td>
        <td><a class="btn ghost sm" href="<?= $v->url('admin/backups/' . rawurlencode($b['name'])) ?>">دانلود</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php else: ?>
  <div class="empty">هنوز پشتیبانی ساخته نشده است.</div>
<?php endif; ?>
<form method="post" action="<?= $v->url('admin/backups') ?>" style="margin-top:12px"><?= $v->csrf() ?><button class="btn secondary sm">پشتیبان‌گیری الان</button></form>
<?= $v->card('پشتیبان پایگاه داده', (string) ob_get_clean(), 12, '', count($backups) . ' نسخه') ?>
