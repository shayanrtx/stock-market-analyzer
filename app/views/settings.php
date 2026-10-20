<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array<string, mixed>> $channels linked messenger chats
 *  @var array<string, array{label: string, url: ?string}> $links deep links per messenger
 *  @var list<string> $enabled messengers configured by the admin
 *  @var string $linkCode
 *  @var array<string, float> $values
 *  @var array<string, array> $fields SettingsController::ANALYSIS_FIELDS
 *  @var array{buy: bool, sell: bool, min: float} $prefs */
$linked = array_column($channels, null, 'channel');
?>
<section class="card s6" id="notify">
  <header><div class="card-title"><h2>اعلان سیگنال در بله و تلگرام</h2></div></header>
  <?php if (!$enabled): ?>
    <div class="notice">ربات پیام‌رسان هنوز توسط مدیر سامانه تنظیم نشده است.<?= $isAdmin ? ' <a href="' . $v->url('admin/settings') . '">تنظیم ربات</a>' : '' ?></div>
  <?php else: ?>
    <p class="muted" style="font-size:13px">سیگنال‌های خرید و هشدارهای فروش نمادهای سبد و دیده‌بان شما همراه با نمودار و تحلیل به پیام‌رسان ارسال می‌شود.</p>
    <div class="channels">
      <?php foreach ($links as $ch => $l): if (!in_array($ch, $enabled, true)) continue; ?>
        <div class="channel">
          <span class="ch-logo <?= $ch ?>"><?= $ch === 'bale' ? 'ب' : 'T' ?></span>
          <div class="grow"><b><?= $l['label'] ?></b>
            <?php if (isset($linked[$ch])): ?><span class="sub up">متصل از <?= $v->e($v->jdate(substr((string) $linked[$ch]['linked_at'], 0, 10))) ?></span>
            <?php else: ?><span class="sub">متصل نیست</span><?php endif; ?></div>
          <?php if (isset($linked[$ch])): ?>
            <form method="post" action="<?= $v->url("settings/notifications/$ch/unlink") ?>" data-confirm="اتصال <?= $l['label'] ?> قطع شود؟"><?= $v->csrf() ?><button class="btn secondary sm">قطع اتصال</button></form>
          <?php elseif ($l['url']): ?>
            <a class="btn sm" href="<?= $v->e($l['url']) ?>" target="_blank" rel="noopener">اتصال به <?= $l['label'] ?></a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <details class="howto"><summary>راهنمای اتصال</summary>
      <ol>
        <li>روی «اتصال» بزنید؛ ربات در پیام‌رسان باز می‌شود.</li>
        <li>دکمه‌ی «شروع» را بزنید. اگر دکمه نبود، این پیام را برای ربات بفرستید: <code dir="ltr">/start <?= $v->e($linkCode) ?></code></li>
        <li>به همین صفحه برگردید و «بررسی اتصال» را بزنید.</li>
      </ol>
      <p class="faint" style="font-size:12px">این کد مخصوص حساب شماست؛ آن را با کسی به اشتراک نگذارید.</p>
    </details>
    <div class="btn-row">
      <form method="post" action="<?= $v->url('settings/notifications/check') ?>"><?= $v->csrf() ?><button class="btn secondary sm">بررسی اتصال</button></form>
      <?php if ($channels): ?><form method="post" action="<?= $v->url('settings/notifications/test') ?>"><?= $v->csrf() ?><button class="btn secondary sm">ارسال پیام آزمایشی</button></form><?php endif; ?>
    </div>
  <?php endif; ?>

  <hr>
  <form method="post" action="<?= $v->url('settings/notifications') ?>">
    <?= $v->csrf() ?>
    <label class="checkbox"><input type="checkbox" name="buy" value="1" <?= $prefs['buy'] ? 'checked' : '' ?>> سیگنال‌های خرید (نمادهای سبد و دیده‌بان)</label>
    <label class="checkbox"><input type="checkbox" name="sell" value="1" <?= $prefs['sell'] ? 'checked' : '' ?>> هشدارهای فروش (فقط نمادهایی که دارید)</label>
    <div class="field" style="margin-top:10px"><label for="minp">حداقل احتمال موفقیت برای ارسال سیگنال خرید</label>
      <div class="input-unit"><input id="minp" name="min_probability" value="<?= $v->e($prefs['min']) ?>" inputmode="decimal" class="num-input"><span>٪</span></div>
      <span class="hint">سیگنال‌هایی با احتمال تاریخی کمتر از این عدد ارسال نمی‌شوند (پیشنهاد: ۵۵ تا ۶۵).</span></div>
    <button class="btn">ذخیره‌ی تنظیمات اعلان</button>
  </form>
</section>

<section class="card s6">
  <header><div class="card-title"><h2>پارامترهای تحلیل و کارمزد</h2></div></header>
  <form method="post" action="<?= $v->url('settings/analysis') ?>">
    <?= $v->csrf() ?>
    <p class="muted" style="font-size:13px">این اعداد در سنجش بازده واقعی (در برابر تورم)، نسبت شارپ، پیشنهادهای وزن و محاسبه‌ی کارمزد تراکنش‌ها استفاده می‌شوند.</p>
    <div class="row2">
      <?php foreach ($fields as $key => [$label, $unit, $min, $max]): ?>
        <div class="field"><label for="f-<?= $key ?>"><?= $label ?></label>
          <div class="input-unit"><input id="f-<?= $key ?>" name="<?= $key ?>" value="<?= $v->e(rtrim(rtrim(number_format($values[$key], 4, '.', ''), '0'), '.')) ?>" inputmode="decimal" class="num-input"><span><?= $unit ?></span></div></div>
      <?php endforeach; ?>
    </div>
    <p class="faint" style="font-size:12px">کارمزد فروش سهام شامل ۰٫۵٪ مالیات نقل‌وانتقال است. نرخ دقیق را از صورت‌حساب کارگزاری خود بردارید.</p>
    <button class="btn">ذخیره</button>
  </form>
</section>

<section class="card s6">
  <header><div class="card-title"><h2>مشخصات</h2></div></header>
  <form method="post" action="<?= $v->url('settings/profile') ?>">
    <?= $v->csrf() ?>
    <div class="field"><label>نام کاربری</label><input value="<?= $v->e($user['username']) ?>" disabled dir="ltr"></div>
    <div class="field"><label for="dn">نام نمایشی</label><input id="dn" name="display_name" value="<?= $v->e($user['display_name']) ?>" maxlength="100"></div>
    <div class="field"><label for="em">ایمیل <span class="faint">(اختیاری)</span></label><input id="em" name="email" type="email" value="<?= $v->e($user['email'] ?? '') ?>" dir="ltr"></div>
    <button class="btn">ذخیره</button>
  </form>
</section>

<section class="card s6">
  <header><div class="card-title"><h2>تغییر رمز عبور</h2></div></header>
  <form method="post" action="<?= $v->url('settings/password') ?>" autocomplete="off">
    <?= $v->csrf() ?>
    <div class="field"><label for="cp">رمز فعلی</label><input id="cp" name="current_password" type="password" required autocomplete="current-password"></div>
    <div class="row2">
      <div class="field"><label for="np">رمز جدید</label><input id="np" name="new_password" type="password" required minlength="8" autocomplete="new-password"></div>
      <div class="field"><label for="np2">تکرار رمز جدید</label><input id="np2" name="new_password_confirm" type="password" required minlength="8" autocomplete="new-password"></div>
    </div>
    <span class="hint faint" style="display:block;margin:-6px 0 12px;font-size:12px">حداقل ۸ نویسه؛ ترکیب حرف، عدد و نماد امن‌تر است.</span>
    <button class="btn">تغییر رمز</button>
  </form>
</section>
