<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array{0: string, 1: string, 2: bool}> $env
 *  @var list<array{0: string, 1: string, 2: bool, 3: float}> $checks  API probes: name, result, ok, ms
 *  @var int $migration */
$failed = count(array_filter($checks, static fn ($c) => !$c[2]));
$blocked = (bool) array_filter($checks, static fn ($c) => !$c[2] && str_contains($c[1], 'ایران'));
?>
<?= $v->partial('admin/_tabs', ['tab' => 'health']) ?>
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
