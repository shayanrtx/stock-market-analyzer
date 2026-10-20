<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array<string, mixed>> $alerts  PriceAlerts::forUser rows
 *  @var string $back  page to return to after deleting
 *  @var bool $showSymbol */
?>
<?php if (!$alerts): ?>
  <div class="empty">هشداری ثبت نشده است.</div>
<?php else: ?>
  <ul class="alert-list">
    <?php foreach ($alerts as $a): $fired = $a['triggered_at'] !== null; ?>
      <li class="<?= $fired ? 'fired' : '' ?>">
        <span class="pill <?= $fired ? 'neutral' : ($a['direction'] === 'above' ? 'up' : 'down') ?>"><?= $a['direction'] === 'above' ? '▲ بالاتر از' : '▼ پایین‌تر از' ?></span>
        <?php if ($showSymbol): ?><a href="<?= $v->url('stock', ['ins' => $a['ins_code']]) ?>"><b><?= $v->e($a['symbol']) ?></b></a><?php endif; ?>
        <b class="num"><?= $v->num((float) $a['price']) ?></b>
        <?php if ($a['note'] !== ''): ?><span class="muted"><?= $v->e($a['note']) ?></span><?php endif; ?>
        <span class="grow"></span>
        <span class="faint"><?= $fired ? 'فعال شد ' . $v->e($v->jdatetime(date('Y-m-d H:i', (int) $a['triggered_at']))) . ' با ' . $v->num((float) $a['triggered_price'])
            : 'در انتظار' ?></span>
        <form method="post" action="<?= $v->url('alerts/' . (int) $a['id'] . '/delete') ?>"><?= $v->csrf() ?>
          <input type="hidden" name="back" value="<?= $v->e($back) ?>">
          <button class="icon-btn" title="حذف هشدار" aria-label="حذف"><?= $v->icon('trash', 15) ?></button></form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
