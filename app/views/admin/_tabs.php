<?php
/** @var \Sabadgardan\View\View $v
 *  @var string $tab */
$tabs = ['admin' => ['admin', 'نمای کلی'], 'users' => ['admin/users', 'کاربران'], 'settings' => ['admin/settings', 'تنظیمات سامانه'],
         'audit' => ['admin/audit', 'گزارش رویدادها'], 'health' => ['admin/health', 'سلامت سامانه']];
?>
<nav class="tabs" aria-label="بخش‌های مدیریت">
  <?php foreach ($tabs as $key => [$href, $label]): ?>
    <a class="<?= $tab === $key ? 'on' : '' ?>" href="<?= $v->url($href) ?>"><?= $label ?></a>
  <?php endforeach; ?>
</nav>
