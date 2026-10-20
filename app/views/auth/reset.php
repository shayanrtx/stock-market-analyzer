<?php /** @var \Sabadgardan\View\View $v
 *  @var string $username
 *  @var string $error
 *  @var bool $sent  a code was just requested */ ?>
<div class="auth-card">
  <div class="brand"><span class="logo"><?= $v->icon('chart', 18) ?></span><?= $v->e($siteName) ?></div>
  <div class="tag">تعیین رمز عبور جدید</div>
  <?php if ($error): ?><div class="form-error" role="alert"><?= $v->e($error) ?></div><?php endif; ?>
  <?php if ($sent): ?><div class="form-notice" role="status">اگر این حساب به بله یا تلگرام متصل باشد، کد بازیابی همین حالا برایش فرستاده شد. کد ۱۵ دقیقه اعتبار دارد.</div><?php endif; ?>
  <form method="post" action="<?= $v->url('reset') ?>" novalidate autocomplete="off">
    <?= $v->csrf() ?>
    <div class="field"><label for="username">نام کاربری</label>
      <input id="username" name="username" value="<?= $v->e($username) ?>" autocomplete="username" dir="ltr" required></div>
    <div class="field"><label for="code">کد ۸ رقمی</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required <?= $username !== '' ? 'autofocus' : '' ?>></div>
    <div class="field"><label for="password">رمز جدید</label>
      <input id="password" type="password" name="password" autocomplete="new-password" dir="ltr" required minlength="8"></div>
    <div class="field"><label for="password_confirm">تکرار رمز جدید</label>
      <input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password" dir="ltr" required minlength="8"></div>
    <button class="btn" type="submit">تغییر رمز</button>
  </form>
  <div class="alt"><a href="<?= $v->url('forgot') ?>">کد نرسید؟ دوباره بفرستید</a> · <a href="<?= $v->url('login') ?>">بازگشت به ورود</a></div>
</div>
