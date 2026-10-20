<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array<string, mixed>> $items  watchlist rows (ins_code, symbol, name)
 *  @var array<string, \Sabadgardan\Market\SymbolBundle> $bundles  prices */
?>
<div class="page-head">
  <form method="post" action="<?= $v->url('watchlist') ?>" class="ac add-symbol" data-symbol-search data-submit>
    <?= $v->csrf() ?>
    <input type="hidden" name="ins_code" value="">
    <div class="search-box"><?= $v->icon('search', 16) ?><input type="search" placeholder="افزودن نماد به دیده‌بان…" autocomplete="off"></div>
    <div class="ac-list" role="listbox"></div>
  </form>
  <div class="sub">نمادهای دیده‌بان در رصد زنده بررسی می‌شوند و سیگنال خرید آن‌ها برایتان ارسال می‌شود.</div>
</div>

<?php if (!$items): ?>
  <section class="empty-hero">
    <div class="art"><?= $v->icon('eye', 34) ?></div>
    <h2>دیده‌بان خالی است</h2>
    <p>نمادهایی را که قصد خرید دارید یا می‌خواهید زیر نظر داشته باشید اضافه کنید تا رتبه‌بندی پتانسیل رشد، رصد لحظه‌ای و سیگنال‌های خرید آن‌ها را ببینید.</p>
  </section>
<?php return; endif; ?>

<?php ob_start(); ?>
<div class="table-wrap"><table class="sortable">
  <thead><tr><th data-sort="text">نماد</th><th data-sort="num">قیمت پایانی</th><th data-sort="num">تغییر پایانی</th><th data-sort="num">آخرین معامله</th><th data-sort="text">وضعیت</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $w):
      $b = $bundles[$w['ins_code']] ?? null;
      $close = $b?->closePrice();
      $ref = $b?->referencePrice();
      $last = $b?->lastPrice();
      $chg = $close && $ref ? ($close / $ref - 1) * 100 : null;
      $lastChg = $last && $ref ? ($last / $ref - 1) * 100 : null; ?>
    <tr>
      <td><div class="symbol-cell"><span class="symbol-badge"><?= $v->e(mb_substr((string) $w['symbol'], 0, 2)) ?></span>
        <div><a href="<?= $v->url('stock', ['ins' => $w['ins_code']]) ?>"><b><?= $v->e($w['symbol']) ?></b></a>
          <?php if ($b): $c = $b->assetClass(); ?><?= $v->assetTag($c->label(), $c->tone()) ?><?php endif; ?>
          <span class="sub"><?= $v->e($w['name'] ?? '') ?></span></div></div></td>
      <td data-v="<?= $close ?? 0 ?>"><?= $v->num($close) ?></td>
      <td data-v="<?= $chg ?? 0 ?>"><?= $v->pct($chg, 2) ?></td>
      <td data-v="<?= $last ?? 0 ?>"><?= $v->num($last) ?> <?= $v->pct($lastChg, 2) ?></td>
      <td><?php $st = $b?->status() ?? ''; ?><?= $st && $st !== 'مجاز' ? '<span class="pill halted">' . $v->e($st) . '</span>' : '' ?>
        <?php $qu = $b?->queue() ?? ''; ?><?= $qu ? '<span class="pill ' . ($qu === 'buy' ? 'up' : 'down') . '">' . ($qu === 'buy' ? 'صف خرید' : 'صف فروش') . '</span>' : '' ?></td>
      <td><div class="row-actions">
        <a class="btn ghost sm" href="<?= $v->url('stock', ['ins' => $w['ins_code']]) ?>">تحلیل</a>
        <form method="post" action="<?= $v->url('watchlist/' . rawurlencode((string) $w['ins_code']) . '/delete') ?>"><?= $v->csrf() ?>
          <button class="icon-btn" title="حذف از دیده‌بان" aria-label="حذف"><?= $v->icon('trash', 16) ?></button></form>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= $v->card('دیده‌بان', (string) ob_get_clean(), 12, '', count($items) . ' نماد') ?>

<div class="loading-block" data-async="<?= $v->url('watchlist/analysis') ?>" aria-busy="true">
  <div class="card s12" style="box-shadow:none"><h2>رتبه‌بندی پتانسیل رشد</h2><p class="muted" style="margin:6px 0 0">در حال تحلیل تاریخچه، روند و جریان پول نمادها…</p></div>
  <div class="skeleton wide"></div>
</div>
