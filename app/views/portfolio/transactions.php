<?php
/** @var \Sabadgardan\View\View $v
 *  @var array $portfolio
 *  @var list<\Sabadgardan\Portfolio\Transaction> $transactions  newest first, filtered
 *  @var list<string> $symbols  every symbol traded in the portfolio
 *  @var array<string, ?\Sabadgardan\Market\AssetClass> $classes  insCode => kind
 *  @var string $filterType
 *  @var string $filterSymbol
 *  @var array<string, float> $fees */
use Sabadgardan\Portfolio\Transaction;

$pid = (int) $portfolio['id'];
$sum = static fn (string $type, callable $f) => array_sum(array_map($f, array_filter($transactions, static fn ($t) => $t->type === $type)));
$bought = $sum(Transaction::BUY, static fn ($t) => $t->quantity * $t->price);
$sold = $sum(Transaction::SELL, static fn ($t) => $t->quantity * $t->price);
$feeTotal = array_sum(array_map(static fn ($t) => $t->fee, $transactions));
$dividends = $sum(Transaction::DIVIDEND, static fn ($t) => $t->amount);
$filtered = $filterType !== '' || $filterSymbol !== '';
?>
<div class="page-head">
  <form class="filters" method="get" action="<?= $v->url("portfolio/$pid/transactions") ?>">
    <select class="input" name="type" onchange="this.form.submit()" aria-label="نوع تراکنش">
      <option value="">همه‌ی انواع</option>
      <?php foreach (Transaction::LABELS as $k => $label): ?>
        <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="symbol" onchange="this.form.submit()" aria-label="نماد">
      <option value="">همه‌ی نمادها</option>
      <?php foreach ($symbols as $s): ?>
        <option value="<?= $v->e($s) ?>" <?= $filterSymbol === $s ? 'selected' : '' ?>><?= $v->e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($filtered): ?><a class="btn ghost sm" href="<?= $v->url("portfolio/$pid/transactions") ?>">حذف فیلتر</a><?php endif; ?>
  </form>
  <div class="head-actions">
    <a class="btn secondary" href="<?= $v->url("portfolio/$pid/export") ?>"><?= $v->icon('download', 16) ?>خروجی CSV</a>
    <button class="btn" type="button" data-open-drawer><?= $v->icon('plus', 16) ?>ثبت تراکنش</button>
  </div>
</div>

<section class="kpis small" style="--cols:4">
  <div class="kpi"><div class="label">تعداد تراکنش<?= $filtered ? ' (فیلترشده)' : '' ?></div><div class="value"><?= $v->num(count($transactions)) ?></div></div>
  <div class="kpi"><div class="label">جمع خرید / فروش</div><div class="value" style="font-size:17px"><?= $v->money($bought) ?></div><div class="sub">فروش <?= $v->money($sold) ?></div></div>
  <div class="kpi"><div class="label">کارمزد پرداختی</div><div class="value" style="font-size:17px"><?= $v->money($feeTotal) ?></div><div class="sub"><?= $bought + $sold > 0 ? $v->pct($feeTotal / ($bought + $sold) * 100, 2, false, false) . ' از گردش معاملات' : '' ?></div></div>
  <div class="kpi"><div class="label">سود نقدی دریافتی <?= $v->help('dividends') ?></div><div class="value" style="font-size:17px"><?= $v->money($dividends) ?></div></div>
</section>

<?php if (!$transactions): ?>
  <section class="empty-hero">
    <div class="art"><?= $v->icon('list', 34) ?></div>
    <h2><?= $filtered ? 'تراکنشی با این فیلتر نیست' : 'هنوز تراکنشی ثبت نشده' ?></h2>
    <p>خرید، فروش، سود نقدی، سهام جایزه و واریز/برداشت وجه را ثبت کنید تا سود و زیان دقیق و میانگین قیمت هر نماد محاسبه شود.</p>
    <div class="actions"><button class="btn" type="button" data-open-drawer><?= $v->icon('plus', 16) ?>ثبت تراکنش</button></div>
  </section>
<?php else: ?>
<?php ob_start(); ?>
<div class="table-wrap"><table class="sortable">
  <thead><tr>
    <th data-sort="text">تاریخ</th><th data-sort="text">نوع</th><th data-sort="text">نماد</th><th data-sort="num">تعداد</th>
    <th data-sort="num">قیمت (ریال)</th><th data-sort="num">ارزش</th><th data-sort="num">کارمزد (ریال)</th><th data-sort="num">اثر بر نقدینگی</th>
    <th>یادداشت</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($transactions as $t):
      $jd = $v->jdate($t->date);
      $gross = $t->isTrade() ? $t->quantity * $t->price : $t->amount;
      $data = ['id' => $t->id, 'type' => $t->type, 'ins_code' => $t->insCode, 'symbol' => $t->symbol, 'date' => $jd,
               'quantity' => $t->quantity ? number_format($t->quantity) : '', 'price' => $t->price ? number_format($t->price) : '',
               'fee' => $t->type === Transaction::BUY || $t->type === Transaction::SELL ? (string) round($t->fee) : '',
               'amount' => $t->amount ? number_format($t->amount) : '', 'note' => $t->note];
      $pill = ['buy' => 'up', 'sell' => 'down', 'dividend' => 'accent', 'bonus' => 'accent', 'deposit' => 'neutral', 'withdraw' => 'warn'][$t->type]; ?>
    <tr>
      <td data-v="<?= $v->e($t->date) ?>"><span class="num"><?= $v->e($jd) ?></span></td>
      <td><span class="pill <?= $pill ?>"><?= Transaction::LABELS[$t->type] ?></span></td>
      <td><?php if ($t->symbol): $c = $classes[$t->insCode] ?? null; ?><a href="<?= $v->url('stock', ['ins' => $t->insCode]) ?>"><b><?= $v->e($t->symbol) ?></b></a>
        <?= $c ? $v->assetTag($c->label(), $c->tone()) : '' ?><?php else: ?><span class="faint">-</span><?php endif; ?></td>
      <td data-v="<?= $t->quantity ?>"><?= $t->quantity ? $v->num($t->quantity) : '<span class="faint">-</span>' ?></td>
      <td data-v="<?= $t->price ?>"><?= $t->price ? $v->num($t->price) : '<span class="faint">-</span>' ?></td>
      <td data-v="<?= $gross ?>"><?= $gross ? $v->money($gross) : '<span class="faint">-</span>' ?></td>
      <td data-v="<?= $t->fee ?>"><?= $t->fee ? $v->num($t->fee) : '<span class="faint">-</span>' ?></td>
      <td data-v="<?= $t->cashFlow() ?>"><?= $t->cashFlow() ? $v->money($t->cashFlow(), true, true) : '<span class="faint">-</span>' ?></td>
      <td class="note-cell"><?= $v->e($t->note) ?></td>
      <td><div class="row-actions">
        <button class="icon-btn" type="button" data-open-drawer data-tx="<?= $v->e(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>" title="ویرایش" aria-label="ویرایش"><?= $v->icon('edit', 16) ?></button>
        <form method="post" action="<?= $v->url("portfolio/$pid/transactions/{$t->id}/delete") ?>" data-confirm="این تراکنش حذف شود؟ سود و زیان و میانگین قیمت دوباره محاسبه می‌شود.">
          <?= $v->csrf() ?><button class="icon-btn danger-hover" title="حذف" aria-label="حذف"><?= $v->icon('trash', 16) ?></button></form>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= $v->card('دفتر معاملات', (string) ob_get_clean(), 12, '', 'قیمت‌ها به ریال · ارزش‌ها به تومان') ?>
<?php endif; ?>

<?= $v->partial('partials/tx_drawer', ['portfolio' => $portfolio, 'fees' => $fees]) ?>
