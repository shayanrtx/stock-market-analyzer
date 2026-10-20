<?php
/** Async fragment of the watchlist page.
 *  @var \Sabadgardan\View\View $v
 *  @var list<array{bundle: \Sabadgardan\Market\SymbolBundle, report: \Sabadgardan\Analysis\StockReport}> $rows  best score first */
if (!$rows) {
    echo '<div class="notice warn" style="grid-column:1/-1">داده‌ی کافی برای تحلیل نمادهای دیده‌بان دریافت نشد.</div>';
    return;
}
ob_start(); ?>
<div class="table-wrap"><table class="sortable">
  <thead><tr><th>#</th><th data-sort="text">نماد</th><th data-sort="num">پتانسیل رشد <?= $v->help('potential_score') ?></th>
    <th data-sort="num">احتمال رشد ۳ ماهه <?= $v->help('probability_up') ?></th><th data-sort="num">تا هدف</th><th data-sort="num">حد ضرر</th>
    <th data-sort="num">پاداش/ریسک <?= $v->help('reward_risk') ?></th><th data-sort="num">بازده ۱ ساله</th><th>مهم‌ترین دلایل</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $k => $row):
      $r = $row['report'];
      $p = $r->potential;
      $lv = $p->levels;
      $top = array_map(static fn ($f) => $f->name, array_slice($p->strengths(), 0, 2));
      $weak = array_map(static fn ($f) => $f->name, array_slice($p->weaknesses(), 0, 1)); ?>
    <tr>
      <td class="faint"><?= $k + 1 ?></td>
      <?php $c = $row['bundle']->assetClass(); ?>
      <td><a href="<?= $v->url('stock', ['ins' => $row['bundle']->instrument->insCode]) ?>"><b><?= $v->e($row['bundle']->instrument->symbol) ?></b></a>
        <?= $v->assetTag($c->label(), $c->tone()) ?><span class="sub"><?= $v->e($p->sector) ?></span></td>
      <td data-v="<?= round($p->score, 1) ?>"><span class="score <?= $v->tone($p->score) ?>"><?= round($p->score) ?></span><span class="sub"><?= $v->e($p->label) ?></span></td>
      <td data-v="<?= $p->probabilityUp ?? -1 ?>"><?= $p->probabilityUp !== null ? $v->pct($p->probabilityUp * 100, 0, false, false) : '<span class="faint">-</span>' ?></td>
      <td data-v="<?= $lv['upside_pct'] ?? 0 ?>"><?= $v->pct($lv['upside_pct']) ?></td>
      <td data-v="<?= $lv['stop_pct'] ?? 0 ?>"><?= $v->num($lv['stop']) ?><span class="sub"><?= $v->pct($lv['stop_pct']) ?></span></td>
      <td data-v="<?= $lv['reward_risk'] ?? 0 ?>"><?= $v->num($lv['reward_risk'], 1) ?></td>
      <td data-v="<?= $r->returns['1y'] ?? 0 ?>"><?= $v->pct($r->returns['1y'] ?? null) ?></td>
      <td class="wrap"><?= $top ? '<span class="up">+ ' . $v->e(implode('، ', $top)) . '</span>' : '' ?><?= $weak ? ' <span class="down">− ' . $v->e($weak[0]) . '</span>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= $v->card('رتبه‌بندی پتانسیل رشد', (string) ob_get_clean(), 12, 'gauge', 'از بیشترین به کمترین') ?>
