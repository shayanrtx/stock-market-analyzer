<?php
/** @var \Sabadgardan\View\View $v
 *  @var string $error
 *  @var string $term
 *  @var array<string, string> $quick ins_code => symbol (holdings and watchlist) */
?>
<section class="search-hero">
  <div class="art"><?= $v->icon('chart', 30) ?></div>
  <h2>تحلیل جامع یک نماد</h2>
  <p class="muted">روند، اندیکاتورها، جریان پول حقیقی، ارزش‌گذاری، کارنامه‌ی تاریخی، پتانسیل رشد و احتمال موفقیت طرح معاملاتی بر پایه‌ی رفتار گذشته‌ی همان نماد.</p>
  <form method="get" action="<?= $v->url('stock') ?>" class="ac big" data-symbol-search data-navigate>
    <input type="hidden" name="ins_code" value="">
    <div class="search-box"><?= $v->icon('search', 18) ?><input type="search" name="s" value="<?= $v->e($term) ?>" placeholder="نام یا نماد، مثلاً فملی یا ملی مس" autocomplete="off" autofocus></div>
    <div class="ac-list" role="listbox"></div>
  </form>
  <?php if ($error !== ''): ?><div class="notice error" style="margin-top:14px"><?= $v->e($error) ?></div><?php endif; ?>
  <?php if ($quick): ?>
    <div class="quick">
      <span class="faint">دسترسی سریع:</span>
      <?php foreach ($quick as $code => $symbol): ?>
        <a class="chip" href="<?= $v->url('stock', ['ins' => $code]) ?>"><?= $v->e($symbol) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
