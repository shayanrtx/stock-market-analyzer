<?php /** @var \Sabadgardan\View\View $v */ ?>
<div class="auth-card">
  <div class="brand"><span class="logo"><?= $v->icon('chart', 18) ?></span><?= $v->e($siteName) ?></div>
  <div class="tag">مدیریت حرفه‌ای سبد سهام بورس تهران</div>
  <?php if ($error): ?><div class="form-error" role="alert"><?= $v->e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= $v->url('login') ?>" novalidate>
    <?= $v->csrf() ?>
    <div class="field"><label for="username">نام کاربری</label>
      <input id="username" name="username" value="<?= $v->e($username) ?>" autocomplete="username" dir="ltr" required autofocus></div>
    <div class="field"><label for="password">رمز عبور</label>
      <input id="password" type="password" name="password" autocomplete="current-password" dir="ltr" required></div>
    <button class="btn" type="submit">ورود</button>
  </form>
  <?php if ($registrationOpen): ?>
    <div class="alt">حساب ندارید؟ <a href="<?= $v->url('register') ?>">ثبت‌نام کنید</a></div>
  <?php endif; ?>
</div>
