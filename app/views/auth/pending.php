<?php /** @var \Sabadgardan\View\View $v */ ?>
<div class="auth-card">
  <div class="brand"><span class="logo"><?= $v->icon('chart', 18) ?></span><?= $v->e($siteName) ?></div>
  <div class="notice" style="margin-top:12px">حساب شما ساخته شد و پس از تأیید مدیر سامانه فعال می‌شود. بعد از تأیید با همین نام کاربری وارد شوید.</div>
  <div class="alt"><a href="<?= $v->url('login') ?>">رفتن به صفحه‌ی ورود</a></div>
</div>
