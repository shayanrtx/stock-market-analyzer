<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array<string, mixed>> $users
 *  @var string $term
 *  @var string $status */
use Sabadgardan\Auth\Auth;

$pill = [Auth::ACTIVE => 'active', Auth::PENDING => 'pending', Auth::SUSPENDED => 'suspended'];
?>
<?= $v->partial('admin/_tabs', ['tab' => 'users']) ?>
<div class="page-head">
  <form class="filters" method="get" action="<?= $v->url('admin/users') ?>">
    <div class="search-box"><?= $v->icon('search', 16) ?><input type="search" name="q" value="<?= $v->e($term) ?>" placeholder="نام کاربری، نام یا ایمیل"></div>
    <select class="input" name="status" onchange="this.form.submit()" aria-label="وضعیت">
      <option value="">همه‌ی وضعیت‌ها</option>
      <?php foreach (Auth::STATUS_LABELS as $k => $label): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
    </select>
  </form>
  <span class="sub"><?= count($users) ?> کاربر</span>
</div>
<?php ob_start(); ?>
<div class="table-wrap menu-host"><table class="sortable">
  <thead><tr><th data-sort="text">کاربر</th><th data-sort="text">وضعیت</th><th data-sort="num">سبد / تراکنش</th><th data-sort="num">پیام‌رسان</th>
    <th data-sort="text">ثبت‌نام</th><th data-sort="text">آخرین ورود</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): $self = (int) $u['id'] === (int) $user['id']; ?>
    <tr>
      <td><div class="symbol-cell"><span class="avatar"><?= $v->e(mb_substr((string) ($u['display_name'] ?: $u['username']), 0, 1)) ?></span>
        <div><b><?= $v->e($u['display_name']) ?></b><?= $u['is_admin'] ? ' <span class="pill accent">مدیر</span>' : '' ?><?= $self ? ' <span class="faint">(شما)</span>' : '' ?>
        <span class="sub" dir="ltr" style="text-align:right"><?= $v->e($u['username']) ?><?= $u['email'] ? ' · ' . $v->e($u['email']) : '' ?></span></div></div></td>
      <td><span class="pill <?= $pill[$u['status']] ?? '' ?>"><?= Auth::STATUS_LABELS[$u['status']] ?? $v->e($u['status']) ?></span></td>
      <td data-v="<?= (int) $u['transactions'] ?>"><?= (int) $u['portfolios'] ?> / <?= $v->num((float) $u['transactions']) ?></td>
      <td data-v="<?= (int) $u['channels'] ?>"><?= (int) $u['channels'] ? '<span class="up">متصل</span>' : '<span class="faint">-</span>' ?></td>
      <td data-v="<?= $v->e($u['created_at']) ?>"><span class="num"><?= $v->e($v->jdatetime((string) $u['created_at'])) ?></span></td>
      <td data-v="<?= $v->e($u['last_login_at'] ?? '') ?>"><span class="num"><?= $u['last_login_at'] ? $v->e($v->jdatetime((string) $u['last_login_at'])) : '<span class="faint">هرگز</span>' ?></span></td>
      <td><?php if (!$self): ?>
        <details class="menu">
          <summary class="btn ghost sm">مدیریت</summary>
          <div class="menu-pop">
            <?php if ($u['status'] === Auth::PENDING): ?>
              <form method="post" action="<?= $v->url("admin/users/{$u['id']}/status") ?>"><?= $v->csrf() ?><input type="hidden" name="status" value="active"><button>تأیید حساب</button></form>
            <?php endif; ?>
            <?php if ($u['status'] !== Auth::SUSPENDED): ?>
              <form method="post" action="<?= $v->url("admin/users/{$u['id']}/status") ?>" data-confirm="حساب «<?= $v->e($u['username']) ?>» مسدود شود؟"><?= $v->csrf() ?><input type="hidden" name="status" value="suspended"><button>مسدود کردن</button></form>
            <?php else: ?>
              <form method="post" action="<?= $v->url("admin/users/{$u['id']}/status") ?>"><?= $v->csrf() ?><input type="hidden" name="status" value="active"><button>رفع مسدودی</button></form>
            <?php endif; ?>
            <form method="post" action="<?= $v->url("admin/users/{$u['id']}/role") ?>"><?= $v->csrf() ?><input type="hidden" name="admin" value="<?= $u['is_admin'] ? '0' : '1' ?>"><button><?= $u['is_admin'] ? 'برداشتن دسترسی مدیر' : 'مدیر کردن' ?></button></form>
            <form method="post" action="<?= $v->url("admin/users/{$u['id']}/password") ?>" class="inline-form"><?= $v->csrf() ?>
              <input class="input" type="text" name="password" placeholder="رمز جدید (۸+ نویسه)" minlength="8" required autocomplete="off" dir="ltr"><button class="btn sm secondary">بازنشانی رمز</button></form>
            <form method="post" action="<?= $v->url("admin/users/{$u['id']}/delete") ?>" data-confirm="کاربر «<?= $v->e($u['username']) ?>» و همه‌ی سبدها و تراکنش‌هایش برای همیشه حذف شود؟"><?= $v->csrf() ?><button class="danger-text">حذف کاربر</button></form>
          </div>
        </details>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= $v->card('کاربران', (string) ob_get_clean(), 12) ?>
