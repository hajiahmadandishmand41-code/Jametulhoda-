<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $media */
$typeLabels = ['image' => 'تصویر', 'audio' => 'صوت', 'video' => 'ویدیو', 'document' => 'فایل'];
$activeType = (string) ($activeType ?? '');
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">کتابخانه</p>
        <h2>رسانه‌ها</h2>
        <p>فایل‌های قابل استفاده در جلد، متن و پیوست محتوا را از یکجا مدیریت کنید.</p>
    </div>
</section>

<div class="admin-media-toolbar">
    <nav class="topic-chips" aria-label="فیلتر نوع رسانه">
        <a class="topic-chip<?= $activeType === '' ? ' is-active' : '' ?>" href="<?= e(url('/admin/media')) ?>">همه</a>
        <?php foreach ($typeLabels as $value => $label): ?>
            <a class="topic-chip<?= $activeType === $value ? ' is-active' : '' ?>" href="<?= e(url('/admin/media?type=' . rawurlencode($value))) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <p class="admin-media-note">فرمت‌های مجاز: JPG، PNG، GIF، WEBP، MP3، OGG، MP4 و PDF</p>
</div>

<form class="admin-form media-upload-form" method="post" enctype="multipart/form-data" action="<?= e(url('/admin/media')) ?>">
    <?= csrf_field() ?>
    <label for="media-file">فایل
        <input id="media-file" type="file" name="media" required accept="image/jpeg,image/png,image/gif,image/webp,audio/mpeg,audio/ogg,video/mp4,application/pdf" aria-describedby="media-file-help">
    </label>
    <p id="media-file-help" class="field-help">فایل باید از نوع مجاز باشد؛ حجم تصویر حداکثر ۵ مگابایت است.</p>
    <label for="media-title">عنوان اختیاری
        <input id="media-title" name="title" maxlength="250" autocomplete="off">
    </label>
    <label for="media-alt">متن جایگزین / توضیح
        <input id="media-alt" name="alt_text" maxlength="250" autocomplete="off">
    </label>
    <button class="admin-button" type="submit">بارگذاری رسانه</button>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <caption class="sr-only">فهرست رسانه‌ها</caption>
        <thead><tr><th>پیش‌نمایش</th><th>عنوان</th><th>نوع</th><th>مشخصات</th><th>استفاده</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($media as $m):
            $src = media_file_exists((string) ($m['disk_path'] ?? '')) ? media_url((string) $m['disk_path']) : '';
            $mediaId = (int) ($m['id'] ?? 0);
            $usage = (int) ($m['usage_count'] ?? 0);
        ?>
            <tr>
                <td class="media-preview-cell">
                    <?php if ($src !== '' && ($m['media_type'] ?? '') === 'image'): ?><img loading="lazy" src="<?= e($src) ?>" alt="<?= e((string) ($m['alt_text'] ?? $m['title'] ?? '')) ?>" width="120" height="76">
                    <?php elseif ($src !== '' && ($m['media_type'] ?? '') === 'audio'): ?><audio controls preload="metadata" src="<?= e($src) ?>">پخش صوت ممکن نیست.</audio>
                    <?php elseif ($src !== '' && ($m['media_type'] ?? '') === 'video'): ?><video controls preload="metadata" src="<?= e($src) ?>" width="180">پخش ویدیو ممکن نیست.</video>
                    <?php elseif ($src !== ''): ?><a href="<?= e($src) ?>" target="_blank" rel="noopener">باز کردن فایل</a>
                    <?php else: ?><span class="muted">فایل در دیسک پیدا نشد</span><?php endif; ?>
                </td>
                <td>
                    <strong><?= e((string) (($m['title'] ?? '') !== '' ? $m['title'] : ($m['original_name'] ?? 'رسانه'))) ?></strong>
                    <?php if (!empty($m['original_name'])): ?><small class="table-secondary" dir="ltr"><?= e((string) $m['original_name']) ?></small><?php endif; ?>
                </td>
                <td><span class="status status-<?= e((string) ($m['media_type'] ?? '')) ?>"><?= e($typeLabels[(string) ($m['media_type'] ?? '')] ?? (string) ($m['media_type'] ?? '')) ?></span></td>
                <td><span dir="ltr"><?= e((string) ($m['mime_type'] ?? '—')) ?></span><small class="table-secondary"><?= e(!empty($m['file_size']) ? fa_digits((string) max(1, round(((int) $m['file_size']) / 1024))) . ' KB' : '—') ?></small></td>
                <td><?= e(fa_digits((string) $usage)) ?> مورد</td>
                <td>
                    <form class="inline-form" method="post" action="<?= e(url('/admin/media/' . $mediaId . '/delete')) ?>" data-confirm="این فایل از کتابخانه حذف شود؟ اگر در محتوا استفاده شده باشد، پیوست آن هم حذف می‌شود.">
                        <?= csrf_field() ?>
                        <button class="admin-button-danger" type="submit">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$media): ?><tr><td colspan="6" class="empty-state">رسانه‌ای با این فیلتر پیدا نشد.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
