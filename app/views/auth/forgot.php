<?php /** @var \Sabadgardan\View\View $v
 *  @var string $username
 *  @var string $error */ ?>
<div class="auth-card">
  <div class="brand"><span class="logo"><?= $v->icon('chart', 18) ?></span><?= $v->e($siteName) ?></div>
  <div class="tag">بازیابی رمز عبور</div>
  <?php if ($error): ?><div class="form-error" role="alert"><?= $v->e($error) ?></div><?php endif; ?>
  <p class="muted" style="font-size:13px">اگر حساب شما به بله یا تلگرام متصل باشد، یک کد ۸ رقمی به همان‌جا فرستاده می‌شود.</p>
  <form method="post" action="<?= $v->url('forgot') ?>" novalidate>
    <?= $v->csrf() ?>
    <div class="field"><label for="username">نام کاربری</label>
      <input id="username" name="username" value="<?= $v->e($username) ?>" autocomplete="username" dir="ltr" required autofocus></div>
    <button class="btn" type="submit">ارسال کد بازیابی</button>
  </form>
  <div class="alt">پیام‌رسان متصل نکرده‌اید؟ از مدیر سامانه بخواهید رمز را تغییر دهد. · <a href="<?= $v->url('login') ?>">بازگشت به ورود</a></div>
</div>
