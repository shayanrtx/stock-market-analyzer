<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array<string, mixed>> $portfolioList  shared: the user's portfolios with tx_count */
?>
<div class="page-head">
  <div class="sub">برای حساب‌های کارگزاری مختلف یا استراتژی‌های جدا (بلندمدت، نوسان‌گیری، صندوق‌ها) سبد جداگانه بسازید. سود و زیان و تحلیل هر سبد مستقل محاسبه می‌شود.</div>
</div>

<section class="card s8">
  <header><div class="card-title"><h2>سبدهای من</h2></div><span class="hint"><?= count($portfolioList) ?> سبد</span></header>
  <div class="list">
    <?php foreach ($portfolioList as $p): ?>
      <div class="list-row">
        <span class="symbol-badge"><?= $v->icon('folder', 16) ?></span>
        <div class="grow">
          <a href="<?= $v->url('portfolio/' . $p['id']) ?>"><b><?= $v->e($p['name']) ?></b></a>
          <span class="sub"><?= $v->num((float) $p['tx_count']) ?> تراکنش · ساخته‌شده در <?= $v->e($v->jdate(substr((string) $p['created_at'], 0, 10))) ?></span>
        </div>
        <details class="inline-edit">
          <summary class="btn ghost sm"><?= $v->icon('edit', 14) ?>تغییر نام</summary>
          <form method="post" action="<?= $v->url('portfolios/' . $p['id'] . '/rename') ?>" class="inline-form">
            <?= $v->csrf() ?>
            <input class="input" name="name" value="<?= $v->e($p['name']) ?>" maxlength="60" required aria-label="نام جدید">
            <button class="btn sm">ذخیره</button>
          </form>
        </details>
        <?php if (count($portfolioList) > 1): ?>
          <form method="post" action="<?= $v->url('portfolios/' . $p['id'] . '/delete') ?>"
                data-confirm="سبد «<?= $v->e($p['name']) ?>» و همه‌ی <?= (int) $p['tx_count'] ?> تراکنش آن برای همیشه حذف شود؟">
            <?= $v->csrf() ?><button class="btn danger sm"><?= $v->icon('trash', 14) ?>حذف</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card s4">
  <header><div class="card-title"><h2>سبد جدید</h2></div></header>
  <form method="post" action="<?= $v->url('portfolios') ?>">
    <?= $v->csrf() ?>
    <div class="field"><label for="pf-name">نام سبد</label><input id="pf-name" name="name" maxlength="60" required placeholder="مثلاً حساب کارگزاری مفید"></div>
    <button class="btn" type="submit"><?= $v->icon('plus', 16) ?>ساخت سبد</button>
  </form>
</section>
