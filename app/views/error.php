<?php /** @var \Sabadgardan\View\View $v */ ?>
<div class="<?= isset($user) && $user ? 'empty-hero' : 'auth-card' ?>">
  <div class="art" style="margin:0 auto 16px;width:64px;height:64px;border-radius:18px;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-size:22px;font-weight:700"><?= (int) $status ?></div>
  <h2 style="text-align:center"><?= $v->e($title) ?></h2>
  <p class="muted" style="text-align:center"><?= $v->e($message) ?></p>
  <div style="text-align:center"><a class="btn" href="<?= $v->url('/') ?>">بازگشت به صفحه‌ی اصلی</a></div>
</div>
