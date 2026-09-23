<?php
/**
 * Admin: Users management.
 *
 * @var list<array<string,mixed>> $users
 */
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">مدیریت کاربران</p>
        <h2>کاربران سیستم</h2>
        <p>فهرست حساب‌های کاربری فعال و غیرفعال.</p>
    </div>
</section>

<div class="admin-table-wrap">
    <table class="admin-table">
        <caption class="sr-only">فهرست کاربران</caption>
        <thead>
            <tr>
                <th>نام</th>
                <th>ایمیل</th>
                <th>نقش</th>
                <th>وضعیت</th>
                <th>آخرین ورود</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?php
                        $roleLabels = ['admin' => 'مدیر', 'editor' => 'ویرایشگر', 'user' => 'کاربر'];
                        echo e($roleLabels[$u['role']] ?? $u['role']);
                    ?></td>
                    <td><?= !empty($u['is_active']) ? '<span class="status status-published">فعال</span>' : '<span class="status status-archived">غیرفعال</span>' ?></td>
                    <td><?= e($u['last_login_at'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
                <tr><td colspan="5" class="empty-state">هنوز کاربری ثبت نشده است.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
