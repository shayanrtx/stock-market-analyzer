<?php
/** @var \Sabadgardan\View\View $v
 *  @var array $portfolio
 *  @var array $fees */
use Sabadgardan\Portfolio\Transaction; ?>
<aside class="drawer" id="tx-drawer" aria-labelledby="tx-title" data-fees="<?= $v->e(json_encode($fees)) ?>">
  <header><h2 id="tx-title">ثبت تراکنش</h2><button class="icon-btn" type="button" data-close-drawer aria-label="بستن"><?= $v->icon('close') ?></button></header>
  <form method="post" action="<?= $v->url("portfolio/{$portfolio['id']}/transactions") ?>" novalidate style="display:contents">
    <div class="body">
      <?= $v->csrf() ?>
      <input type="hidden" name="id" value="">
      <div class="form-error" hidden role="alert"></div>
      <?php if (count($portfolioList ?? []) > 1): ?>
        <div class="field"><label for="tx-portfolio">سبد</label>
          <select id="tx-portfolio" onchange="this.form.action=this.value">
            <?php foreach ($portfolioList as $pf): ?>
              <option value="<?= $v->url("portfolio/{$pf['id']}/transactions") ?>" <?= (int) $pf['id'] === (int) $portfolio['id'] ? 'selected' : '' ?>><?= $v->e($pf['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
      <?php endif; ?>
      <div class="field"><label>نوع تراکنش</label>
        <div class="segmented">
          <?php foreach (Transaction::LABELS as $type => $label): ?>
            <label><input type="radio" name="type" value="<?= $type ?>" <?= $type === 'buy' ? 'checked' : '' ?>><span><?= $label ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="field" data-for="buy sell bonus dividend"><label>نماد</label>
        <div class="ac" data-symbol-search>
          <input type="hidden" name="ins_code" value="">
          <div class="search-box"><?= $v->icon('search', 16) ?><input type="search" placeholder="نام یا نماد را بنویسید، مثلاً فملی" autocomplete="off"></div>
          <div class="chosen" hidden><div><b></b> <span class="faint"></span></div><button type="button" class="btn ghost sm">تغییر</button></div>
          <div class="ac-list" role="listbox"></div>
        </div>
      </div>
      <div class="field"><label for="tx-date">تاریخ</label>
        <input id="tx-date" name="date" data-jdate inputmode="numeric" class="num-input" placeholder="۱۴۰۵/۰۷/۲۸">
        <div class="chips"><span class="chip" data-jdate-chip="0" data-target="tx-date">امروز</span><span class="chip" data-jdate-chip="1" data-target="tx-date">دیروز</span></div>
      </div>
      <div class="row2" data-for="buy sell bonus">
        <div class="field"><label for="tx-qty">تعداد سهم</label><input id="tx-qty" name="quantity" inputmode="numeric" class="num-input" placeholder="0"></div>
        <div class="field" data-for="buy sell"><label for="tx-price">قیمت هر سهم (ریال)</label><input id="tx-price" name="price" inputmode="decimal" class="num-input" placeholder="0">
          <span class="hint price-hint"></span></div>
      </div>
      <div class="field" data-for="buy sell"><label for="tx-fee">کارمزد (ریال)</label><input id="tx-fee" name="fee" inputmode="numeric" class="num-input">
        <span class="hint">خالی بگذارید تا بر اساس نرخ کارمزد تنظیمات محاسبه شود؛ یا عدد دقیق صورت‌حساب کارگزاری را وارد کنید.</span></div>
      <div class="field" data-for="deposit withdraw dividend"><label for="tx-amount">مبلغ (ریال)</label><input id="tx-amount" name="amount" inputmode="numeric" class="num-input"></div>
      <div class="notice" data-for="bonus" style="margin-bottom:14px">برای افزایش سرمایه از محل سود انباشته یا اندوخته، تعداد سهام جایزه‌ی دریافتی را وارد کنید. میانگین قیمت خرید خودکار کم می‌شود.</div>
      <div class="field"><label for="tx-note">یادداشت <span class="faint">(اختیاری)</span></label><input id="tx-note" name="note" maxlength="255"></div>
      <div class="calc" hidden></div>
    </div>
    <footer><button class="btn" type="submit">ثبت</button><button class="btn secondary" type="button" data-close-drawer>انصراف</button></footer>
  </form>
</aside>
