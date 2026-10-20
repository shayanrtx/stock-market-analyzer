<?php
/** @var \Sabadgardan\View\View $v */
$nav = $nav ?? '';
$links = [
    ['portfolio', '/', 'pie', 'سبد من'],
    ['transactions', isset($portfolio) ? "/portfolio/{$portfolio['id']}/transactions" : '/portfolio/' . ($portfolioList[0]['id'] ?? 0) . '/transactions', 'list', 'تراکنش‌ها'],
    ['stock', '/stock', 'chart', 'تحلیل نماد'],
    ['market', '/market', 'globe', 'بازار'],
    ['live', '/live', 'pulse', 'رصد زنده'],
    ['signals', '/signals', 'bell', 'سیگنال‌ها'],
    ['watchlist', '/watchlist', 'eye', 'دیده‌بان'],
];
$initial = mb_substr((string) ($user['display_name'] ?? $user['username'] ?? '?'), 0, 1);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf" content="<?= $v->e(\Sabadgardan\Http\Session::csrfToken()) ?>">
<meta name="base" content="<?= $v->e(rtrim($v->url('/'), '/')) ?>">
<meta name="theme-color" content="#2563eb">
<title><?= $v->e($title ?? '') ?> · <?= $v->e($siteName) ?></title>
<link rel="preload" href="<?= $v->url('assets/fonts/Vazirmatn-FD-Regular.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Cpath d='M7 22l6-7 5 4 7-9' stroke='white' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<script>window.GLOSSARY = <?= json_encode($glossary, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= $v->asset('echarts.min.js') ?>" defer></script>
<script src="<?= $v->asset('app.js') ?>" defer></script>
</head>
<body>
<div class="shell">
  <aside class="sidebar" aria-label="منوی اصلی">
    <a class="brand" href="<?= $v->url('/') ?>"><span class="logo"><?= $v->icon('chart', 18) ?></span><?= $v->e($siteName) ?></a>
    <?php foreach ($links as [$key, $href, $icon, $label]): ?>
      <a class="side-link <?= $nav === $key ? 'active' : '' ?>" href="<?= $v->url($href) ?>"><?= $v->icon($icon) ?><?= $label ?></a>
    <?php endforeach; ?>
    <div class="side-group">حساب</div>
    <a class="side-link <?= $nav === 'portfolios' ? 'active' : '' ?>" href="<?= $v->url('portfolios') ?>"><?= $v->icon('folder') ?>سبدهای من</a>
    <a class="side-link <?= $nav === 'settings' ? 'active' : '' ?>" href="<?= $v->url('settings') ?>"><?= $v->icon('gear') ?>تنظیمات و اعلان‌ها</a>
    <?php if ($isAdmin): ?>
      <a class="side-link <?= $nav === 'admin' ? 'active' : '' ?>" href="<?= $v->url('admin') ?>"><?= $v->icon('shield') ?>مدیریت سامانه</a>
    <?php endif; ?>
    <div class="side-foot">
      <span class="avatar"><?= $v->e($initial) ?></span>
      <span class="who"><b><?= $v->e($user['display_name'] ?? '') ?></b><span><?= $v->e($user['username'] ?? '') ?></span></span>
      <form method="post" action="<?= $v->url('logout') ?>"><?= $v->csrf() ?><button class="icon-btn" title="خروج" aria-label="خروج"><?= $v->icon('logout') ?></button></form>
    </div>
  </aside>
  <div class="main">
    <div class="topbar"><div class="inner">
      <button class="icon-btn menu-btn" data-menu aria-label="منو"><?= $v->icon('menu', 22) ?></button>
      <h1><?= $v->e($heading ?? $title ?? '') ?></h1>
      <span class="spacer"></span>
      <?php if (!empty($tour)): ?>
        <button class="btn secondary sm" type="button" data-tour-start data-tour-auto><?= $v->icon('help', 16) ?><span class="hide-mobile">آموزش این صفحه</span></button>
      <?php endif; ?>
      <?= $topActions ?? '' ?>
    </div></div>
    <main class="content">
      <?php if (!empty($demo)): ?>
        <div class="demo-banner">حالت نمایشی: داده‌های بازار ساختگی‌اند و فقط برای آشنایی با برنامه تولید شده‌اند. برای داده‌ی واقعی، در config/config.php مقدار <code dir="ltr">market.transport</code> را <code dir="ltr">curl</code> بگذارید و برنامه را روی هاست داخل ایران اجرا کنید.</div>
      <?php endif; ?>
      <?= $content ?>
      <div class="foot">داده‌ها از tsetmc.com و tse.ir · تحلیل‌ها خودکار و بر پایه‌ی داده‌های گذشته‌اند و توصیه‌ی قطعی خرید یا فروش نیستند.</div>
    </main>
  </div>
</div>
<nav class="tabbar" aria-label="منوی پایین">
  <?php foreach ([['portfolio', '/', 'pie', 'سبد'], ['stock', '/stock', 'chart', 'تحلیل'], ['live', '/live', 'pulse', 'زنده'], ['market', '/market', 'globe', 'بازار']] as [$key, $href, $icon, $label]): ?>
    <a class="<?= $nav === $key ? 'active' : '' ?>" href="<?= $v->url($href) ?>"><?= $v->icon($icon, 22) ?><?= $label ?></a>
  <?php endforeach; ?>
  <a href="#" data-menu><?= $v->icon('menu', 22) ?>بیشتر</a>
</nav>
<div class="overlay"></div>
<div class="toasts" aria-live="polite"></div>
<?php foreach ($flashes as $f): ?><span hidden data-flash="<?= $v->e($f['message']) ?>" data-type="<?= $v->e($f['type']) ?>"></span><?php endforeach; ?>
<?= $after ?? '' ?>
</body>
</html>
