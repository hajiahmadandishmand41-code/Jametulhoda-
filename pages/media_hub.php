<?php

declare(strict_types=1);

/**
 * Multimedia hub (/media) — Phase 6.
 *
 * One organised surface for published video/audio. Every card owns its
 * mobile-friendly player plus the published content it belongs to. The
 * related-content map is resolved in ONE batched query by the route.
 *
 * @var list<array<string,mixed>>                       $items
 * @var array<int, list<array<string,mixed>>>           $relatedByMedia
 * @var string|null                                     $activeType 'video'|'audio'|null
 * @var int                                             $page
 * @var int                                             $pages
 * @var int                                             $total
 */

$total = (int) ($total ?? count($items));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$activeType = (string) ($activeType ?? '');

$path = '/media' . ($activeType !== '' ? '?type=' . rawurlencode($activeType) : '');
$typeLabels = ['video' => 'ویدیو', 'audio' => 'صوت'];
?>
<header class="page-head">
    <h1 class="page-title">مرکز رسانه</h1>
    <p class="page-intro">ویدیوها و فایل‌های صوتی منتشرشده در یک نگاه.</p>
    <?php if ($total > 0): ?><p class="result-count"><?= e(fa_digits((string) $total)) ?> فایل</p><?php endif; ?>
</header>

<nav class="topic-chips" aria-label="فیلتر نوع رسانه">
    <a class="topic-chip<?= $activeType === '' ? ' is-active' : '' ?>" href="<?= e(url('/media')) ?>">همه</a>
    <a class="topic-chip<?= $activeType === 'video' ? ' is-active' : '' ?>" href="<?= e(url('/media?type=video')) ?>">ویدیو</a>
    <a class="topic-chip<?= $activeType === 'audio' ? ' is-active' : '' ?>" href="<?= e(url('/media?type=audio')) ?>">صوت</a>
</nav>

<?php if ($items === []): ?>
    <p class="empty-state">هنوز رسانه‌ای منتشر نشده است.</p>
<?php else: ?>
    <div class="media-grid">
        <?php foreach ($items as $m):
            $src = media_url((string) ($m['disk_path'] ?? ''));
            if ($src === '') { continue; }
            $mediaType = (string) ($m['media_type'] ?? '');
            $mediaId = (int) $m['id'];
            $relatedItems = $relatedByMedia[$mediaId] ?? [];
        ?>
            <article class="media-card">
                <div class="media-player">
                    <?php if ($mediaType === 'video'): ?>
                        <video controls preload="none" playsinline>
                            <source src="<?= e($src) ?>"<?= !empty($m['mime_type']) ? ' type="' . e((string) $m['mime_type']) . '"' : '' ?>>
                            مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                        </video>
                    <?php else: ?>
                        <audio controls preload="none">
                            <source src="<?= e($src) ?>"<?= !empty($m['mime_type']) ? ' type="' . e((string) $m['mime_type']) . '"' : '' ?>>
                            مرورگر شما از پخش صوت پشتیبانی نمی‌کند.
                        </audio>
                    <?php endif; ?>
                </div>
                <div class="media-card-body">
                    <p class="card-meta"><span class="card-type"><?= e($typeLabels[$mediaType] ?? $mediaType) ?></span></p>
                    <h2 class="media-title"><?= e((string) ($m['title'] !== null && $m['title'] !== '' ? $m['title'] : ($m['original_name'] ?? 'رسانه'))) ?></h2>
                    <?php if (!empty($m['duration_seconds'])): ?>
                        <p class="media-duration">مدت: <?= e(fa_digits((string) (int) $m['duration_seconds'])) ?> ثانیه</p>
                    <?php endif; ?>
                    <?php if ($relatedItems !== []): ?>
                        <div class="media-related">
                            <h3>محتوای مرتبط</h3>
                            <ul>
                                <?php foreach ($relatedItems as $rel): ?>
                                    <li><a href="<?= e(content_url((string) $rel['content_type'], (string) $rel['slug'])) ?>"><?= e((string) $rel['title']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php require __DIR__ . '/../views/partials/pagination.php'; ?>
<?php endif; ?>
