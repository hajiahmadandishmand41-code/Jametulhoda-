<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $media */
$typeLabels = ['image' => 'تصویر', 'audio' => 'صوت', 'video' => 'ویدیو', 'document' => 'فایل'];
?>
<section class="admin-welcome"><div><p class="admin-kicker">رسانه</p><h2>کتابخانه رسانه</h2><p>آپلود امن تصویر، صوت، ویدیو و فایل کتاب</p></div></section>
<form class="admin-form" method="post" enctype="multipart/form-data" action="<?= e(url('/admin/media')) ?>">
    <?= csrf_field() ?>
    <label>فایل<input type="file" name="media" required accept="image/jpeg,image/png,image/gif,image/webp,audio/mpeg,audio/ogg,video/mp4,application/pdf"></label>
    <label>عنوان<input name="title" maxlength="250"></label>
    <label>متن جایگزین / توضیح<input name="alt_text" maxlength="250"></label>
    <button class="admin-button" type="submit">بارگذاری</button>
</form>
<div class="admin-table-wrap">
    <table class="admin-table">
        <caption class="sr-only">فهرست رسانه‌ها</caption>
        <thead><tr><th>پیش‌نمایش</th><th>عنوان</th><th>نوع</th><th>MIME</th><th>حجم</th><th>مسیر</th></tr></thead>
        <tbody>
        <?php foreach ($media as $m): $src = media_url((string) $m['disk_path']); ?>
            <tr>
                <td class="media-preview-cell">
                    <?php if ($src !== '' && $m['media_type'] === 'image'): ?><img loading="lazy" src="<?= e($src) ?>" alt="<?= e((string) ($m['alt_text'] ?? '')) ?>" width="96" height="64">
                    <?php elseif ($src !== '' && $m['media_type'] === 'audio'): ?><audio controls preload="metadata" src="<?= e($src) ?>"></audio>
                    <?php elseif ($src !== '' && $m['media_type'] === 'video'): ?><video controls preload="metadata" src="<?= e($src) ?>" width="180"></video>
                    <?php elseif ($src !== ''): ?><a href="<?= e($src) ?>">دانلود</a><?php else: ?>—<?php endif; ?>
                </td>
                <td><?= e((string) (($m['title'] ?? '') !== '' ? $m['title'] : ($m['original_name'] ?? 'رسانه'))) ?></td>
                <td><?= e($typeLabels[(string) $m['media_type']] ?? (string) $m['media_type']) ?></td>
                <td dir="ltr"><?= e((string) ($m['mime_type'] ?? '')) ?></td>
                <td><?= e(!empty($m['file_size']) ? fa_digits((string) round(((int) $m['file_size']) / 1024)) . ' KB' : '—') ?></td>
                <td dir="ltr"><code><?= e((string) $m['disk_path']) ?></code></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$media): ?><tr><td colspan="6" class="empty-state">هنوز رسانه‌ای ثبت نشده است.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
