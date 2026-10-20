<?php
/** @var \Sabadgardan\View\View $v
 *  @var list<array<string, mixed>> $rows */
use Sabadgardan\Core\AuditLog;

$tone = ['login_failed' => 'warn', 'admin_user_delete' => 'down', 'admin_user_status' => 'warn', 'register' => 'up', 'install' => 'accent'];
?>
<?= $v->partial('admin/_tabs', ['tab' => 'audit']) ?>
<?php ob_start(); ?>
<div class="table-wrap"><table class="sortable">
  <thead><tr><th data-sort="text">زمان</th><th data-sort="text">رویداد</th><th data-sort="text">کاربر</th><th>جزئیات</th><th data-sort="text">IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $a): ?>
    <tr>
      <td data-v="<?= $v->e($a['created_at']) ?>"><span class="num"><?= $v->e($v->jdatetime((string) $a['created_at'])) ?></span></td>
      <td><span class="pill <?= $tone[$a['action']] ?? '' ?>"><?= $v->e(AuditLog::LABELS[$a['action']] ?? $a['action']) ?></span></td>
      <td dir="ltr" style="text-align:right"><?= $v->e($a['username'] ?? '—') ?></td>
      <td class="muted"><?= $v->e($a['detail']) ?></td>
      <td dir="ltr" style="text-align:right" class="faint"><?= $v->e($a['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php $table = (string) ob_get_clean(); ?>
<?= $v->card('گزارش رویدادهای امنیتی', $rows ? $table : '<div class="empty">رویدادی ثبت نشده است.</div>', 12, '', '۳۰۰ مورد اخیر · نگهداری ۱۸۰ روز') ?>
