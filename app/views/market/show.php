<?php
/** @var \Sabadgardan\View\View $v
 *  @var string|null $error
 *  @var list<array> $bourse  selected indices of the bourse
 *  @var list<array> $fara    selected indices of farabourse
 *  @var array<string, float> $index  TEDPIX history
 *  @var list<array> $effects index effect of top symbols
 *  @var list<array{symbol: string, industry: string, change: float, value: float}> $watch
 *  @var array{rate: float, bills: list<array>}|null $treasury treasury bill yields
 *  @var array<string, float> $usd free-market dollar (rial) by date
 *  @var \Sabadgardan\Analysis\MarketRegime|null $regime primary trend of the market */
use Sabadgardan\Market\Field;
use Sabadgardan\View\Charts;
use Sabadgardan\View\View;

if ($error) {
    echo '<section class="empty-hero"><div class="art">' . $v->icon('globe', 34) . '</div><h2>داده‌ی بازار دریافت نشد</h2><p>'
        . $v->e($error) . '</p>' . ($isAdmin ? '<div class="actions"><a class="btn secondary" href="' . $v->url('admin/health') . '">بررسی سلامت سامانه</a></div>' : '') . '</section>';
    return;
}
// [label, value html, sub html, help key]
$tiles = [];
foreach (array_slice($bourse, 0, 4) as $k => $i) {
    $tiles[] = [$v->e(trim((string) Field::get($i, 'lVal30', ''))), $v->num(Field::float($i, 'xDrNivJIdx004')), $v->pct(Field::float($i, 'xVarIdxJRfV'), 2),
                $k === 0 ? 'indices' : ''];   // the tour explains the indices once
}
foreach (array_slice($fara, 0, 1) as $i) {
    $name = trim((string) Field::get($i, 'lVal30', ''));
    $tiles[] = [$v->e(str_contains($name, 'فرابورس') ? $name : 'فرابورس — ' . $name), $v->num(Field::float($i, 'xDrNivJIdx004')),
                $v->pct(Field::float($i, 'xVarIdxJRfV'), 2), ''];
}
if (count($usd) >= 2) {
    $last = array_slice($usd, -2, 2, true);
    [$prev, $now] = array_values($last);
    $tiles[] = ['دلار آزاد', $v->num($now / 10) . '<small>تومان</small>',
                $v->pct(($now / $prev - 1) * 100, 2) . ' <span class="faint">' . $v->e($v->jdate((string) array_key_last($last))) . '</span>', 'usd_rate'];
}
if ($treasury) {
    $tiles[] = ['بازده اسناد خزانه', $v->pct($treasury['rate'] * 100, 1, false, false),
                'سالانه · میانه‌ی ' . $v->num(count($treasury['bills'])) . ' اخزا', 'treasury_yield'];
}
?>
<div class="page-head"><div class="sub"><?= $v->now() ?> · شاخص‌ها و دیده‌بان هر چند دقیقه به‌روز می‌شوند</div></div>
<section class="kpis" style="--cols:<?= View::kpiColumns(count($tiles)) ?>">
  <?php foreach ($tiles as [$label, $value, $sub, $help]): ?>
    <div class="kpi"<?= $help ? ' data-help="' . $help . '"' : '' ?>><div class="label"><?= $label ?> <?= $help && $help !== 'indices' ? $v->help($help) : '' ?></div><div class="value"><?= $value ?></div><div class="sub"><?= $sub ?></div></div>
  <?php endforeach; ?>
</section>

<?php if ($regime): ?>
  <section class="card s12 regime <?= $regime->tone() ?>" data-help="market_regime">
    <div class="regime-row">
      <div><span class="faint">روند اولیه‌ی بازار <?= $v->help('market_regime') ?></span><br><span class="pill <?= $regime->tone() ?> lg"><?= $v->e($regime->label()) ?></span></div>
      <div class="grow"><p><?= $v->e($regime->summary()) ?></p>
        <p class="faint"><?= $v->e(implode(' · ', $regime->reasons)) ?></p></div>
    </div>
  </section>
<?php endif; ?>

<?php
echo $v->card('شاخص کل بورس', $index ? $v->chart(Charts::indexLine($index), 'mid') : '<div class="empty">تاریخچه‌ی شاخص در دسترس نیست.</div>',
    $watch ? 8 : 12, 'index_chart', 'با میانگین ۲۰۰ روزه · روی نمودار اسکرول کنید');
if ($watch) {
    $up = count(array_filter($watch, static fn ($r) => $r['change'] > 0));
    $down = count(array_filter($watch, static fn ($r) => $r['change'] < 0));
    echo $v->card('پهنای بازار', $v->chart(Charts::breadth($up, $down, count($watch) - $up - $down), 'mid'), 4, 'breadth', count($watch) . ' نماد');

    $groups = [];
    foreach ($watch as $r) {
        $g = &$groups[$r['industry']];
        $g ??= ['name' => $r['industry'], 'value' => 0.0, 'w' => 0.0];
        $g['value'] += $r['value'];
        $g['w'] += $r['change'] * $r['value'];
        unset($g);
    }
    $sectors = array_map(static fn ($g) => ['name' => $g['name'], 'value' => $g['value'], 'change' => $g['value'] ? $g['w'] / $g['value'] : 0.0], $groups);
    usort($sectors, static fn ($a, $b) => $b['value'] <=> $a['value']);
    echo $v->card('نقشه‌ی صنایع', $v->chart(Charts::treemap($sectors), null, 440), $effects ? 8 : 12, 'treemap',
        'اندازه = ارزش معاملات · رنگ = تغییر قیمت پایانی (وزنی)');
}
if ($effects) {
    $items = array_map(static fn ($x) => [(string) Field::get(Field::get($x, 'instrument', []), 'lVal18AFC', ''), (float) Field::get($x, 'instEffectValue', 0)], $effects);
    echo $v->card('بیشترین اثر بر شاخص کل', $v->chart(Charts::diverging($items), null, 440), $watch ? 4 : 12, 'index_effect');
}
if ($watch) {
    $movers = static function (array $rows, string $title) use ($v): string {
        $body = '<div class="table-wrap"><table><thead><tr><th>نماد</th><th>تغییر</th><th>ارزش معاملات</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 12) as $r) {
            $body .= '<tr><td><a href="' . $v->e($v->stockUrl($r['symbol'])) . '"><b>' . $v->e($r['symbol']) . '</b></a><span class="sub ellipsis">'
                . $v->e($r['industry']) . '</span></td><td>' . $v->pct($r['change'], 2) . '</td><td>' . $v->money($r['value']) . '</td></tr>';
        }
        return $v->card($title, $body . '</tbody></table></div>', 4);
    };
    $byChange = $watch;
    usort($byChange, static fn ($a, $b) => $b['change'] <=> $a['change']);
    echo $movers($byChange, 'بیشترین رشد');
    echo $movers(array_reverse($byChange), 'بیشترین افت');
    $byValue = $watch;
    usort($byValue, static fn ($a, $b) => $b['value'] <=> $a['value']);
    echo $movers($byValue, 'بیشترین ارزش معاملات');
} else {
    echo '<div class="notice" style="grid-column:1/-1">دیده‌بان رسمی بازار (tse.ir) فقط از IP ایران پاسخ می‌دهد؛ پهنای بازار و نقشه‌ی صنایع روی سرور داخل ایران نمایش داده می‌شود.</div>';
}
