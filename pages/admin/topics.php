<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $topics */
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">ساختار محتوا</p>
        <h2>موضوعات</h2>
        <p>موضوع‌ها برای دسته‌بندی خبر، مقاله، کتاب، درس و پژوهش استفاده می‌شوند. حذف موضوع، محتوای وابسته را حذف نمی‌کند.</p>
    </div>
</section>

<form class="admin-form topic-create-form" method="post" action="<?= e(url('/admin/topics')) ?>">
    <?= csrf_field() ?>
    <fieldset>
        <legend>افزودن موضوع</legend>
        <div class="admin-form-grid">
            <label for="topic-title">عنوان<span class="required-mark" aria-hidden="true">*</span>
                <input id="topic-title" name="title" required maxlength="160" autocomplete="off">
            </label>
            <label for="topic-slug">نامک (اختیاری)
                <input id="topic-slug" name="slug" maxlength="160" dir="ltr" autocomplete="off">
            </label>
            <label for="topic-order">ترتیب
                <input id="topic-order" type="number" name="sort_order" value="0" min="0" step="1" inputmode="numeric">
            </label>
            <label for="topic-description">توضیح
                <textarea id="topic-description" name="description" maxlength="500" rows="2"></textarea>
            </label>
        </div>
        <label class="checkbox-label" for="topic-active"><input id="topic-active" type="checkbox" name="is_active" value="1" checked> فعال باشد</label>
        <button class="admin-button" type="submit">افزودن موضوع</button>
    </fieldset>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <caption class="sr-only">موضوعات</caption>
        <thead><tr><th>عنوان و توضیح</th><th>نامک</th><th>استفاده</th><th>ترتیب</th><th>وضعیت</th><th>ویرایش / حذف</th></tr></thead>
        <tbody>
        <?php foreach ($topics as $topic): ?>
            <tr>
                <td>
                    <strong><?= e((string) $topic['title']) ?></strong>
                    <?php if (!empty($topic['description'])): ?><small class="table-secondary"><?= e((string) $topic['description']) ?></small><?php endif; ?>
                </td>
                <td dir="ltr"><?= e((string) $topic['slug']) ?></td>
                <td><?= e(fa_digits((string) (int) ($topic['usage_count'] ?? 0))) ?> محتوا</td>
                <td><?= e(fa_digits((string) (int) $topic['sort_order'])) ?></td>
                <td><span class="status <?= !empty($topic['is_active']) ? 'status-published' : 'status-archived' ?>"><?= !empty($topic['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                <td>
                    <form class="topic-inline-form" method="post" action="<?= e(url('/admin/topics/' . (int) $topic['id'] . '/edit')) ?>">
                        <?= csrf_field() ?>
                        <label class="sr-only" for="topic-title-<?= (int) $topic['id'] ?>">عنوان</label>
                        <input id="topic-title-<?= (int) $topic['id'] ?>" name="title" value="<?= e((string) $topic['title']) ?>" maxlength="160" required>
                        <label class="sr-only" for="topic-slug-<?= (int) $topic['id'] ?>">نامک</label>
                        <input id="topic-slug-<?= (int) $topic['id'] ?>" name="slug" value="<?= e((string) $topic['slug']) ?>" maxlength="160" dir="ltr" required>
                        <label class="sr-only" for="topic-order-<?= (int) $topic['id'] ?>">ترتیب</label>
                        <input id="topic-order-<?= (int) $topic['id'] ?>" type="number" name="sort_order" value="<?= e((string) (int) $topic['sort_order']) ?>" min="0" step="1">
                        <label class="sr-only" for="topic-description-<?= (int) $topic['id'] ?>">توضیح</label>
                        <input id="topic-description-<?= (int) $topic['id'] ?>" name="description" value="<?= e((string) ($topic['description'] ?? '')) ?>" maxlength="500">
                        <label class="checkbox-label" for="topic-active-<?= (int) $topic['id'] ?>"><input id="topic-active-<?= (int) $topic['id'] ?>" type="checkbox" name="is_active" value="1" <?= !empty($topic['is_active']) ? 'checked' : '' ?>> فعال</label>
                        <button class="admin-button" type="submit">ذخیره</button>
                    </form>
                    <form class="inline-form" method="post" action="<?= e(url('/admin/topics/' . (int) $topic['id'] . '/delete')) ?>" data-confirm="موضوع حذف شود؟ محتوای مرتبط حفظ می‌شود اما بدون موضوع خواهد شد.">
                        <?= csrf_field() ?>
                        <button class="admin-button-danger" type="submit">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$topics): ?><tr><td colspan="6" class="empty-state">موضوعی ثبت نشده است.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
