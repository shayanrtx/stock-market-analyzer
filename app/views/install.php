<?php /** @var \Sabadgardan\View\View $v */ ?>
<div class="auth-card guest-wide">
  <div class="brand"><span class="logo"><?= $v->icon('chart', 18) ?></span>نصب دستیار سبدگردان</div>
  <div class="tag">این صفحه فقط یک بار، پیش از ساخته شدن فایل تنظیمات، نمایش داده می‌شود.</div>
  <h3 style="margin-bottom:8px">پیش‌نیازها</h3>
  <ul class="req">
    <?php foreach ($requirements as [$label, $ok, $hint]): ?>
      <li><span><?= $v->e($label) ?> <span class="faint"><?= $ok ? '' : '— ' . $v->e($hint) ?></span></span>
          <span class="pill <?= $ok ? 'up' : 'down' ?>"><?= $ok ? 'برقرار' : 'برقرار نیست' ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php foreach ($errors as $e): ?><div class="form-error"><?= $v->e($e) ?></div><?php endforeach; ?>
  <form method="post" novalidate>
    <h3 style="margin:8px 0">پایگاه داده</h3>
    <div class="segmented" style="margin-bottom:14px">
      <label><input type="radio" name="driver" value="sqlite" <?= $old['driver'] !== 'mysql' ? 'checked' : '' ?>><span>SQLite (بدون تنظیم، پیشنهادی)</span></label>
      <label><input type="radio" name="driver" value="mysql" <?= $old['driver'] === 'mysql' ? 'checked' : '' ?>><span>MySQL / MariaDB</span></label>
    </div>
    <div class="row2">
      <div class="field"><label>میزبان MySQL</label><input name="host" value="<?= $v->e($old['host'] ?? 'localhost') ?>" dir="ltr"></div>
      <div class="field"><label>پورت</label><input name="port" value="<?= $v->e($old['port'] ?? '3306') ?>" dir="ltr"></div>
      <div class="field"><label>نام پایگاه داده</label><input name="database" value="<?= $v->e($old['database'] ?? '') ?>" dir="ltr"></div>
      <div class="field"><label>کاربر پایگاه داده</label><input name="db_user" value="<?= $v->e($old['db_user'] ?? '') ?>" dir="ltr"></div>
      <div class="field"><label>رمز پایگاه داده</label><input type="password" name="db_password" dir="ltr"></div>
    </div>
    <p class="hint faint">با انتخاب SQLite، فیلدهای MySQL نادیده گرفته می‌شوند.</p>
    <h3 style="margin:8px 0">حساب مدیر</h3>
    <div class="row2">
      <div class="field"><label>نام کاربری مدیر</label><input name="username" value="<?= $v->e($old['username'] ?? '') ?>" dir="ltr" required></div>
      <div class="field"><label>نام نمایشی</label><input name="display_name" value="<?= $v->e($old['display_name'] ?? '') ?>"></div>
      <div class="field"><label>رمز عبور (دست‌کم ۸ کاراکتر)</label><input type="password" name="password" dir="ltr" required></div>
    </div>
    <button class="btn" type="submit">نصب و ساخت حساب مدیر</button>
  </form>
</div>
