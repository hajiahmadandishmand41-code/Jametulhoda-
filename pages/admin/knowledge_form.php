<?php

declare(strict_types=1);

/**
 * Knowledge form — Phase 6 admin create/edit/view for books, lessons and
 * research. Field visibility is driven by $section flags so the three
 * sections share one reviewed, escaped form.
 *
 * @var string                       $action
 * @var string                       $title
 * @var string                       $sectionPath books|lessons|research
 * @var array<string,mixed>          $section
 * @var array<string,mixed>          $item
 * @var list<array<string,mixed>>    $topics
 * @var array<string,list<array<string,mixed>>> $mediaByRole  role => library rows
 * @var list<array<string,mixed>>    $coverMedia
 * @var list<array<string,mixed>>    $attachedMedia existing attachments (edit)
 * @var array<string,list<int>>      $postedMedia  redisplay after a failed POST
 * @var list<string>                 $errors
 */

$isEdit = isset($item['id']) && (int) $item['id'] > 0;
$errors = $errors ?? [];
$attachedMedia = $attachedMedia ?? [];
$postedMedia = $postedMedia ?? null;
$item = is_array($item) ? $item : [];

/** role => currently selected media ids; submitted values win after validation errors. */
$currentMedia = ['video' => [], 'audio' => [], 'document' => []];
if (is_array($postedMedia)) {
    foreach ($postedMedia as $role => $ids) {
        if (isset($currentMedia[$role]) && is_array($ids)) {
            $currentMedia[$role] = array_map('intval', $ids);
        }
    }
} else {
    foreach ($attachedMedia as $m) {
        $role = (string) ($m['role'] ?? '');
        if (isset($currentMedia[$role])) {
            $currentMedia[$role][] = (int) $m['id'];
        }
    }
}

$roleLabels = ['video' => 'ویدیوهای مرتبط', 'audio' => 'فایل‌های صوتی مرتبط', 'document' => 'پیوست‌ها'];
$statusOptions = ['draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'archived' => 'بایگانی'];
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">دانش و محتوا — <?= e((string) $section['label']) ?></p>
        <h2><?= e($title) ?></h2>
    </div>
    <a class="admin-button" href="<?= e(url('/admin/' . $sectionPath)) ?>">بازگشت به فهرست</a>
</section>

<?php if ($errors): ?>
<div id="knowledge-form-errors" class="form-errors" role="alert">
    <p><strong>فرم ذخیره نشد.</strong> موارد زیر را اصلاح کنید:</p><ul><?php foreach ($errors as $error): ?><li><?= e((string) $error) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form class="admin-form" method="post" action="<?= e($action) ?>"<?= $errors ? ' aria-describedby="knowledge-form-errors"' : '' ?>>
    <?= csrf_field() ?>

    <label>عنوان <span class="required-mark" aria-hidden="true">*</span><input name="title" value="<?= e((string) ($item['title'] ?? '')) ?>" maxlength="250" required></label>

    <label>نامک (Slug) — خالی بگذارید تا از عنوان ساخته شود
        <input name="slug" value="<?= e((string) ($item['slug'] ?? '')) ?>" maxlength="190" dir="ltr">
    </label>

    <?php if (!empty($section['hasAuthor'])): ?>
    <label>نویسنده / پژوهشگر<input name="author" value="<?= e((string) ($item['author'] ?? '')) ?>" maxlength="250"></label>
    <?php endif; ?>

    <label>خلاصه<textarea name="summary" maxlength="500" rows="3"><?= e((string) ($item['summary'] ?? '')) ?></textarea></label>

    <label>متن / توضیحات<textarea name="body" rows="14"><?= e((string) ($item['body'] ?? '')) ?></textarea></label>

    <label>موضوع
        <select name="topic_id">
            <option value="">بدون موضوع</option>
            <?php foreach ($topics as $topic): ?>
                <option value="<?= e((string) $topic['id']) ?>" <?= (string) ($item['topic_id'] ?? '') === (string) $topic['id'] ? 'selected' : '' ?>><?= e((string) $topic['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>تصویر جلد
        <select name="cover_media_id">
            <option value="">بدون تصویر</option>
            <?php foreach ($coverMedia as $cover): ?>
                <option value="<?= e((string) $cover['id']) ?>" <?= (string) ($item['cover_media_id'] ?? '') === (string) $cover['id'] ? 'selected' : '' ?>><?= e((string) ($cover['title'] !== null && $cover['title'] !== '' ? $cover['title'] : ($cover['original_name'] ?? ('رسانهٔ ' . $cover['id'])))) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <?php if (!empty($section['hasOrder'])): ?>
    <label>ترتیب در دوره (عدد؛ کوچک‌تر زودتر نمایش داده می‌شود)
        <input type="number" name="sort_order" min="0" step="1" value="<?= e((string) (int) ($item['sort_order'] ?? 0)) ?>">
    </label>
    <?php endif; ?>

    <?php if (!empty($section['hasLock'])): ?>
    <label class="checkbox-label">
        <input type="checkbox" name="requires_login" value="1" <?= !empty($item['requires_login']) ? 'checked' : '' ?>>
        مشاهدهٔ متن و رسانه‌های این درس نیازمند ورود کاربر باشد
    </label>
    <?php endif; ?>

    <?php foreach ($roleLabels as $role => $roleLabel): ?>
    <label><?= e($roleLabel) ?>
        <select name="media_<?= e($role) ?>[]" multiple size="5">
            <?php foreach ($mediaByRole[$role] as $mediaRow): ?>
                <option value="<?= e((string) $mediaRow['id']) ?>" <?= in_array((int) $mediaRow['id'], $currentMedia[$role], true) ? 'selected' : '' ?>><?= e((string) ($mediaRow['title'] !== null && $mediaRow['title'] !== '' ? $mediaRow['title'] : ($mediaRow['original_name'] ?? ('رسانهٔ ' . $mediaRow['id'])))) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php endforeach; ?>

    <label>وضعیت
        <select name="status">
            <?php foreach ($statusOptions as $value => $statusLabel): ?>
                <option value="<?= e($value) ?>" <?= (string) ($item['status'] ?? 'draft') === $value ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>تاریخ انتشار (اختیاری)
        <input type="datetime-local" name="published_at" value="<?= e(isset($item['published_at']) && $item['published_at'] ? str_replace(' ', 'T', substr((string) $item['published_at'], 0, 16)) : '') ?>">
    </label>

    <div>
        <button class="admin-button" type="submit">ذخیره</button>
        <a href="<?= e(url('/admin/' . $sectionPath)) ?>">انصراف</a>
    </div>
</form>

<?php if ($isEdit): ?>
<div class="admin-actions-row" aria-label="عملیات وضعیت محتوا">
    <?php if (($item['status'] ?? '') !== 'published'): ?>
    <form class="inline-form" method="post" action="<?= e(url('/admin/' . $sectionPath . '/' . (int) $item['id'] . '/publish')) ?>">
        <?= csrf_field() ?><button class="admin-button" type="submit">انتشار</button>
    </form>
    <?php else: ?>
    <form class="inline-form" method="post" action="<?= e(url('/admin/' . $sectionPath . '/' . (int) $item['id'] . '/unpublish')) ?>">
        <?= csrf_field() ?><button class="admin-button admin-button-secondary" type="submit">بازگشت به پیش‌نویس</button>
    </form>
    <?php endif; ?>
    <form class="inline-form" method="post" action="<?= e(url('/admin/' . $sectionPath . '/' . (int) $item['id'] . '/archive')) ?>">
        <?= csrf_field() ?><button class="admin-button admin-button-secondary" type="submit">بایگانی</button>
    </form>
    <form class="inline-form" method="post" action="<?= e(url('/admin/' . $sectionPath . '/' . (int) $item['id'] . '/delete')) ?>" data-confirm="حذف قطعی این مورد؟">
        <?= csrf_field() ?><button class="admin-button-danger" type="submit">حذف این مورد</button>
    </form>
</div>
<?php endif; ?>
