<?php /** @var \Sabadgardan\View\View $v */ ?>
<div class="auth-card">
  <div class="brand"><span class="logo"><?= $v->icon('chart', 18) ?></span><?= $v->e($siteName) ?></div>
  <p class="muted" style="text-align:center;margin-top:12px">ثبت‌نام در حال حاضر بسته است. برای دریافت حساب با مدیر سامانه تماس بگیرید.</p>
  <div class="alt"><a href="<?= $v->url('login') ?>">بازگشت به ورود</a></div>
</div>
