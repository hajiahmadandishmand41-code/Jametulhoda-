<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $users */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $query */

$roleLabels = ['admin' => 'مدیر', 'editor' => 'ویرایشگر', 'user' => 'کاربر'];
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">دسترسی‌ها</p>
        <h2>کاربران</h2>
        <p><?= e(fa_digits((string) $total)) ?> حساب کاربری ثبت شده است.</p>
    </div>
    <a class="admin-button" href="<?= e(url('/admin/users/new')) ?>">+ کاربر جدید</a>
</section>

<form class="admin-filters" method="get" action="<?= e(url('/admin/users')) ?>">
    <label>جستجوی نام یا ایمیل<input name="q" value="<?= e($query) ?>" maxlength="120"></label>
    <button class="admin-button" type="submit">جستجو</button>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <caption class="sr-only">فهرست کاربران</caption>
        <thead><tr><th>نام</th><th>ایمیل</th><th>نقش</th><th>وضعیت</th><th>آخرین ورود</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($users as $row): ?>
            <tr>
                <td><?= e((string) $row['name']) ?></td>
                <td dir="ltr"><?= e((string) $row['email']) ?></td>
                <td><?= e($roleLabels[(string) $row['role']] ?? (string) $row['role']) ?></td>
                <td><span class="status <?= (int) $row['is_active'] === 1 ? 'status-published' : 'status-archived' ?>"><?= (int) $row['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?></span></td>
                <td><?= e(!empty($row['last_login_at']) ? format_date_fa((string) $row['last_login_at']) : '—') ?></td>
                <td>
                    <a href="<?= e(url('/admin/users/edit/' . (int) $row['id'])) ?>">ویرایش</a>
                    <form class="inline-form" method="post" action="<?= e(url('/admin/users/' . (int) $row['id'] . '/delete')) ?>" data-confirm="این کاربر حذف شود؟">
                        <?= csrf_field() ?>
                        <button class="admin-button-danger" type="submit">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="6" class="empty-state">کاربری پیدا نشد.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
<nav class="pagination" aria-label="صفحه‌بندی">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="<?= $i === $page ? 'current' : '' ?>" href="<?= e(url('/admin/users?' . http_build_query(['q' => $query, 'page' => $i]))) ?>"><?= e(fa_digits((string) $i)) ?></a>
    <?php endfor; ?>
</nav>
<?php endif; ?>
