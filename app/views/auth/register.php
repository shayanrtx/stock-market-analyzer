<?php /** @var \Sabadgardan\View\View $v */
$err = static fn (string $k) => isset($errors[$k]) ? '<div class="err">' . $v->e($errors[$k]) . '</div>' : '';
$cls = static fn (string $k) => isset($errors[$k]) ? 'field invalid' : 'field'; ?>
<div class="auth-card">
  <div class="brand"><span class="logo"><?= $v->icon('chart', 18) ?></span><?= $v->e($siteName) ?></div>
  <div class="tag">حساب بسازید و سبد، سود و زیان و تحلیل‌های خود را یک‌جا داشته باشید.</div>
  <form method="post" action="<?= $v->url('register') ?>" novalidate>
    <?= $v->csrf() ?>
    <div class="hp" aria-hidden="true"><label>وب‌سایت<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="field"><label for="display_name">نام نمایشی</label>
      <input id="display_name" name="display_name" value="<?= $v->e($old['display_name'] ?? '') ?>" placeholder="مثلاً علی رضایی" autocomplete="name"></div>
    <div class="<?= $cls('username') ?>"><label for="username">نام کاربری</label>
      <input id="username" name="username" value="<?= $v->e($old['username'] ?? '') ?>" dir="ltr" autocomplete="username" required>
      <span class="hint">۳ تا ۳۰ حرف انگلیسی، عدد، نقطه، خط تیره یا زیرخط</span><?= $err('username') ?></div>
    <div class="<?= $cls('email') ?>"><label for="email">ایمیل <span class="faint">(اختیاری)</span></label>
      <input id="email" type="email" name="email" value="<?= $v->e($old['email'] ?? '') ?>" dir="ltr" autocomplete="email"><?= $err('email') ?></div>
    <div class="row2">
      <div class="<?= $cls('password') ?>"><label for="password">رمز عبور</label>
        <input id="password" type="password" name="password" dir="ltr" autocomplete="new-password" required><?= $err('password') ?></div>
      <div class="<?= $cls('password_confirm') ?>"><label for="password_confirm">تکرار رمز</label>
        <input id="password_confirm" type="password" name="password_confirm" dir="ltr" autocomplete="new-password" required><?= $err('password_confirm') ?></div>
    </div>
    <button class="btn" type="submit">ساخت حساب</button>
  </form>
  <div class="alt">حساب دارید؟ <a href="<?= $v->url('login') ?>">وارد شوید</a></div>
</div>
