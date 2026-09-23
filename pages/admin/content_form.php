<?php

declare(strict_types=1);

$isEdit = isset($item['id']) && (int) $item['id'] > 0;
$currentType = (string) ($item['content_type'] ?? 'news');
$typeLabels = ['news' => 'خبر', 'article' => 'مقاله', 'event' => 'رویداد', 'report' => 'گزارش'];
$statusOptions = ['draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'archived' => 'بایگانی'];
$errors = $errors ?? [];
$topics = $topics ?? [];
$coverMedia = $coverMedia ?? [];
$mediaByRole = $mediaByRole ?? ['video' => [], 'audio' => [], 'document' => []];
$attachedMedia = $attachedMedia ?? [];
$relatedItems = $relatedItems ?? [];
$relationOptions = $relationOptions ?? [];
$gallery = $gallery ?? [];
$postedMedia = $postedMedia ?? null;
$postedRelations = $postedRelations ?? null;
$postedGallery = $postedGallery ?? null;

$currentMedia = ['video' => [], 'audio' => [], 'document' => []];
if (is_array($postedMedia)) {
    foreach ($postedMedia as $role => $ids) {
        if (isset($currentMedia[$role]) && is_array($ids)) {
            $currentMedia[$role] = array_map('intval', $ids);
        }
    }
} else {
    foreach ($attachedMedia as $media) {
        $role = (string) ($media['role'] ?? '');
        if (isset($currentMedia[$role])) {
            $currentMedia[$role][] = (int) $media['id'];
        }
    }
}
$currentRelations = is_array($postedRelations) ? array_map('intval', $postedRelations) : array_map(static fn (array $row): int => (int) $row['id'], $relatedItems);
$currentGallery = is_array($postedGallery) ? array_map(static fn (array $row): int => (int) ($row['media_id'] ?? 0), $postedGallery) : array_map(static fn (array $row): int => (int) ($row['media_id'] ?? 0), $gallery);
?>
<section class="admin-welcome">
    <div><p class="admin-kicker">تحریریه</p><h2><?= $isEdit ? 'ویرایش محتوا' : 'محتوای جدید' ?></h2></div>
    <a class="admin-button" href="<?= e(url('/admin/content')) ?>">بازگشت به فهرست</a>
</section>

<?php if ($errors): ?><div id="content-form-errors" class="form-errors" role="alert"><p><strong>فرم ذخیره نشد.</strong> موارد زیر را اصلاح کنید:</p><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form class="admin-form" method="post" action="<?= e($action) ?>"<?= $errors ? ' aria-describedby="content-form-errors"' : '' ?>>
    <?= csrf_field() ?>
    <label>نوع محتوا
        <select name="content_type" required <?= $isEdit ? 'disabled' : '' ?>>
            <?php foreach ($typeLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= (string) ($item['content_type'] ?? 'news') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </label>
    <?php if ($isEdit): ?><input type="hidden" name="content_type" value="<?= e((string) $item['content_type']) ?>"><?php endif; ?>

    <label>عنوان <span class="required-mark" aria-hidden="true">*</span><input name="title" value="<?= e((string) ($item['title'] ?? '')) ?>" maxlength="250" required></label>
    <label>Slug امن (خالی بگذارید تا از عنوان ساخته شود)<input name="slug" value="<?= e((string) ($item['slug'] ?? '')) ?>" maxlength="190" dir="ltr"></label>
    <label>خلاصه<textarea name="summary" maxlength="500" rows="3"><?= e((string) ($item['summary'] ?? '')) ?></textarea></label>
    <label>متن اصلی <span class="field-help-inline">برای انتشار تکمیل شود</span><textarea name="body" rows="12" placeholder="متن محتوا را اینجا بنویسید…"><?= e((string) ($item['body'] ?? '')) ?></textarea></label>

    <label>موضوع
        <select name="topic_id"><option value="">بدون موضوع</option><?php foreach ($topics as $topic): ?><option value="<?= e((string) $topic['id']) ?>" <?= (string) ($item['topic_id'] ?? '') === (string) $topic['id'] ? 'selected' : '' ?>><?= e((string) $topic['title']) ?></option><?php endforeach; ?></select>
    </label>

    <label>تصویر شاخص
        <select name="cover_media_id"><option value="">بدون تصویر</option><?php foreach ($coverMedia as $media): ?><option value="<?= e((string) $media['id']) ?>" <?= (string) ($item['cover_media_id'] ?? '') === (string) $media['id'] ? 'selected' : '' ?>><?= e((string) (($media['title'] ?? '') !== '' ? $media['title'] : ($media['original_name'] ?? ('رسانه ' . $media['id'])))) ?></option><?php endforeach; ?></select>
    </label>

    <div class="content-extra-fields content-extra-event"<?= $currentType === 'event' ? '' : ' hidden aria-hidden="true"' ?>>
        <label>زمان شروع رویداد<input type="datetime-local" name="starts_at" value="<?= e(!empty($item['starts_at']) ? str_replace(' ', 'T', substr((string) $item['starts_at'], 0, 16)) : '') ?>"></label>
        <label>زمان پایان رویداد<input type="datetime-local" name="ends_at" value="<?= e(!empty($item['ends_at']) ? str_replace(' ', 'T', substr((string) $item['ends_at'], 0, 16)) : '') ?>"></label>
        <label>مکان رویداد<input name="event_location" value="<?= e((string) ($item['location'] ?? '')) ?>" maxlength="250"></label>
    </div>

    <div class="content-extra-fields content-extra-report"<?= $currentType === 'report' ? '' : ' hidden aria-hidden="true"' ?>>
        <label>تاریخ گزارش<input type="date" name="event_date" value="<?= e((string) ($item['event_date'] ?? '')) ?>"></label>
        <label>مکان گزارش<input name="report_location" value="<?= e((string) ($item['location'] ?? '')) ?>" maxlength="250"></label>
        <label>گالری تصاویر گزارش
            <select name="gallery_media_id[]" multiple size="5">
                <?php foreach ($coverMedia as $media): ?><option value="<?= e((string) $media['id']) ?>" <?= in_array((int) $media['id'], $currentGallery, true) ? 'selected' : '' ?>><?= e((string) (($media['title'] ?? '') !== '' ? $media['title'] : ($media['original_name'] ?? ('تصویر ' . $media['id'])))) ?></option><?php endforeach; ?>
            </select>
        </label>
    </div>

    <?php foreach (['video' => 'ویدیوهای مرتبط', 'audio' => 'فایل‌های صوتی مرتبط', 'document' => 'پیوست‌ها / فایل کتاب'] as $role => $label): ?>
        <label><?= e($label) ?>
            <select name="media_<?= e($role) ?>[]" multiple size="5">
                <?php foreach ($mediaByRole[$role] ?? [] as $media): ?><option value="<?= e((string) $media['id']) ?>" <?= in_array((int) $media['id'], $currentMedia[$role], true) ? 'selected' : '' ?>><?= e((string) (($media['title'] ?? '') !== '' ? $media['title'] : ($media['original_name'] ?? ('رسانه ' . $media['id'])))) ?></option><?php endforeach; ?>
            </select>
        </label>
    <?php endforeach; ?>

    <label>محتوای مرتبط
        <select name="related_content_id[]" multiple size="6">
            <?php foreach ($relationOptions as $option): if ($isEdit && (int) $option['id'] === (int) $item['id']) { continue; } ?><option value="<?= e((string) $option['id']) ?>" <?= in_array((int) $option['id'], $currentRelations, true) ? 'selected' : '' ?>><?= e(content_type_label((string) $option['content_type']) . ' — ' . (string) $option['title']) ?></option><?php endforeach; ?>
        </select>
    </label>

    <label>وضعیت<select name="status"><?php foreach ($statusOptions as $value => $label): ?><option value="<?= e($value) ?>" <?= (string) ($item['status'] ?? 'draft') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label>تاریخ انتشار (اختیاری)<input type="datetime-local" name="published_at" value="<?= e(isset($item['published_at']) && $item['published_at'] ? str_replace(' ', 'T', substr((string) $item['published_at'], 0, 16)) : '') ?>"></label>
    <div><button class="admin-button" type="submit">ذخیره</button> <a href="<?= e(url('/admin/content')) ?>">انصراف</a></div>
</form>

<?php if ($isEdit): ?>
<div class="admin-actions-row">
    <?php if (($item['status'] ?? '') !== 'published'): ?>
    <form class="inline-form" method="post" action="<?= e(url('/admin/content/' . (int) $item['id'] . '/publish')) ?>"><?= csrf_field() ?><button class="admin-button" type="submit">انتشار</button></form>
    <?php else: ?>
    <form class="inline-form" method="post" action="<?= e(url('/admin/content/' . (int) $item['id'] . '/unpublish')) ?>"><?= csrf_field() ?><button class="admin-button" type="submit">پیش‌نویس کردن</button></form>
    <?php endif; ?>
    <form class="inline-form" method="post" action="<?= e(url('/admin/content/' . (int) $item['id'] . '/archive')) ?>"><?= csrf_field() ?><button class="admin-button" type="submit">بایگانی</button></form>
    <form class="inline-form" method="post" action="<?= e(url('/admin/content/' . (int) $item['id'] . '/delete')) ?>" data-confirm="حذف قطعی این محتوا؟"><?= csrf_field() ?><button class="admin-button-danger" type="submit">حذف</button></form>
</div>
<?php endif; ?>
