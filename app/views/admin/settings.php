<?php
/** @var \Sabadgardan\View\View $v
 *  @var array<string, string> $values
 *  @var array<string, string> $fields   system text fields
 *  @var array<string, array> $analysisFields
 *  @var string $cronUrl  includes the secret token
 *  @var string $appRoot  absolute path of the application */
?>
<?= $v->partial('admin/_tabs', ['tab' => 'settings']) ?>
<form method="post" action="<?= $v->url('admin/settings') ?>" style="display:contents">
  <?= $v->csrf() ?>
  <section class="card s6">
    <header><div class="card-title"><h2>عمومی و ثبت‌نام</h2></div></header>
    <div class="field"><label for="site_name"><?= $fields['site_name'] ?></label><input id="site_name" name="site_name" value="<?= $v->e($values['site_name']) ?>"></div>
    <label class="checkbox"><input type="checkbox" name="registration_open" value="1" <?= $values['registration_open'] === '1' ? 'checked' : '' ?>> ثبت‌نام عمومی باز است</label>
    <label class="checkbox"><input type="checkbox" name="registration_approval" value="1" <?= $values['registration_approval'] === '1' ? 'checked' : '' ?>> حساب‌های جدید پس از تأیید مدیر فعال شوند</label>
    <hr>
    <h3 style="margin-bottom:10px">مقادیر پیش‌فرض تحلیل برای کاربران</h3>
    <p class="faint" style="font-size:12px">هر کاربر می‌تواند در تنظیمات خودش این مقادیر را تغییر دهد.</p>
    <div class="row2">
      <?php foreach ($analysisFields as $key => [$label, $unit, $min, $max, $scale]): ?>
        <div class="field"><label for="a-<?= $key ?>"><?= $label ?></label>
          <div class="input-unit"><input id="a-<?= $key ?>" name="<?= $key ?>" value="<?= $v->e(rtrim(rtrim(number_format((float) $values[$key] * $scale, 4, '.', ''), '0'), '.')) ?>" inputmode="decimal" class="num-input"><span><?= $unit ?></span></div></div>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="card s6">
    <header><div class="card-title"><h2>ربات‌های پیام‌رسان</h2></div></header>
    <p class="muted" style="font-size:13px">برای ارسال سیگنال، در هر پیام‌رسان یک ربات بسازید و توکن آن را اینجا وارد کنید: در بله از <b dir="ltr">@botfather</b> و در تلگرام از <b dir="ltr">@BotFather</b> (دستور <code dir="ltr">/newbot</code>). سرورهای داخل ایران معمولاً به تلگرام دسترسی ندارند؛ بله بدون مشکل کار می‌کند.</p>
    <?php foreach (['bale_token', 'bale_username', 'telegram_token', 'telegram_username'] as $k): ?>
      <div class="field"><label for="<?= $k ?>"><?= $fields[$k] ?></label>
        <input id="<?= $k ?>" name="<?= $k ?>" value="<?= $v->e($values[$k]) ?>" dir="ltr" autocomplete="off" <?= str_ends_with($k, 'token') ? 'type="password"' : '' ?>></div>
    <?php endforeach; ?>
  </section>
  <div class="form-actions"><button class="btn">ذخیره‌ی تنظیمات سامانه</button></div>
</form>

<section class="card s12" id="cron">
  <header><div class="card-title"><h2>زمان‌بند (Cron) برای رصد خودکار</h2></div></header>
  <p class="muted" style="font-size:13px">رصد زنده، صدور سیگنال و اتصال حساب‌ها به پیام‌رسان با اجرای منظم این کار انجام می‌شود. در cPanel یا DirectAdmin بخش «Cron Jobs» یکی از این دو را با زمان‌بندی «هر دقیقه» (<code dir="ltr">* * * * *</code>) اضافه کنید:</p>
  <div class="field"><label>روش ۱ — اجرای مستقیم PHP (پیشنهادی)</label>
    <input readonly dir="ltr" value="<?= $v->e('/usr/local/bin/php ' . $appRoot . '/bin/cron.php >/dev/null 2>&1') ?>" onclick="this.select()"></div>
  <div class="field"><label>روش ۲ — فراخوانی نشانی</label>
    <input readonly dir="ltr" value="<?= $v->e('wget -q -O /dev/null "' . $cronUrl . '"') ?>" onclick="this.select()"></div>
  <p class="faint" style="font-size:12px">مسیر PHP در هاست‌ها متفاوت است (مثلاً <code dir="ltr">/usr/bin/php</code> یا <code dir="ltr">/opt/cpanel/ea-php82/root/usr/bin/php</code>). نشانی روش ۲ شامل توکن محرمانه است؛ آن را منتشر نکنید.</p>
</section>
