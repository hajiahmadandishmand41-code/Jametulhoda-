<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $topics */
?>
<section class="admin-welcome"><div><p class="admin-kicker">موضوعات</p><h2>مدیریت موضوعات</h2><p>موضوع‌ها برای دسته‌بندی خبر، مقاله، کتاب، درس و پژوهش استفاده می‌شوند.</p></div></section>
<form class="admin-form" method="post" action="<?= e(url('/admin/topics')) ?>">
    <?= csrf_field() ?>
    <label>عنوان<input name="title" required maxlength="160"></label>
    <label>Slug<input name="slug" maxlength="160" dir="ltr"></label>
    <label>ترتیب<input type="number" name="sort_order" value="0" step="1"></label>
    <label>توضیح<textarea name="description" maxlength="500"></textarea></label>
    <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" checked> فعال باشد</label>
    <button class="admin-button" type="submit">افزودن موضوع</button>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <caption class="sr-only">موضوعات</caption>
        <thead><tr><th>عنوان</th><th>Slug</th><th>ترتیب</th><th>وضعیت</th><th>ویرایش</th></tr></thead>
        <tbody>
        <?php foreach ($topics as $topic): ?>
            <tr>
                <td><?= e((string) $topic['title']) ?></td>
                <td dir="ltr"><?= e((string) $topic['slug']) ?></td>
                <td><?= e(fa_digits((string) (int) $topic['sort_order'])) ?></td>
                <td><span class="status <?= !empty($topic['is_active']) ? 'status-published' : 'status-archived' ?>"><?= !empty($topic['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                <td>
                    <form class="topic-inline-form" method="post" action="<?= e(url('/admin/topics/' . (int) $topic['id'] . '/edit')) ?>">
                        <?= csrf_field() ?>
                        <input name="title" value="<?= e((string) $topic['title']) ?>" maxlength="160" required>
                        <input name="slug" value="<?= e((string) $topic['slug']) ?>" maxlength="160" dir="ltr" required>
                        <input type="number" name="sort_order" value="<?= e((string) (int) $topic['sort_order']) ?>" step="1">
                        <input name="description" value="<?= e((string) ($topic['description'] ?? '')) ?>" maxlength="500">
                        <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= !empty($topic['is_active']) ? 'checked' : '' ?>> فعال</label>
                        <button class="admin-button" type="submit">ذخیره</button>
                    </form>
                    <form class="inline-form" method="post" action="<?= e(url('/admin/topics/' . (int) $topic['id'] . '/delete')) ?>" data-confirm="موضوع حذف شود؟ محتوای مرتبط بدون موضوع می‌شود.">
                        <?= csrf_field() ?>
                        <button class="admin-button-danger" type="submit">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$topics): ?><tr><td colspan="5" class="empty-state">موضوعی ثبت نشده است.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
