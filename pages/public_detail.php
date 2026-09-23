<?php

declare(strict_types=1);

/**
 * Public detail page — Phase 5.
 *
 * Renders one published content item of any type (news, article, report,
 * event) with its cover image, body, event/report metadata, attached media
 * (image/audio/video/document), report gallery and related content.
 *
 * Structured data (JSON-LD) and Open Graph tags are set here as top-level
 * variables so the layout can emit them — view() requires the page and the
 * layout in the same scope.
 *
 * @var array<string,mixed>       $item
 * @var string                    $type
 * @var list<array<string,mixed>> $related
 * @var list<array<string,mixed>> $media
 * @var list<array<string,mixed>> $gallery
 */

$type = (string) ($type ?? ($item['content_type'] ?? 'news'));
$related = $related ?? [];
$media = $media ?? [];
$gallery = $gallery ?? [];
$locked = (bool) ($locked ?? false);
$detailAuthor = trim((string) ($item['author'] ?? ''));
$cover = media_url((string) ($item['cover_path'] ?? ''));

// ---- SEO: Open Graph image + JSON-LD structured data (consumed by layout) ----
if ($cover !== '') {
    $ogImage = url(ltrim(parse_url($cover, PHP_URL_PATH) ?? '', '/'));
}
$ogType = in_array($type, ['article', 'research'], true) ? 'article' : 'website';

$schemaType = match ($type) {
    'article', 'report' => 'Article',
    'news' => 'NewsArticle',
    'event' => 'Event',
    'book' => 'Book',
    'lesson' => 'LearningResource',
    'research' => 'ScholarlyArticle',
    default => 'Article',
};
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => $schemaType,
    'headline' => (string) $item['title'],
    'inLanguage' => 'fa',
    'mainEntityOfPage' => url(current_path()),
];
if ($detailAuthor !== '') {
    $jsonLd['author'] = ['@type' => 'Person', 'name' => $detailAuthor];
}
if (!empty($item['summary'])) {
    $jsonLd['description'] = excerpt((string) $item['summary'], 200);
}
if (!empty($item['published_at'])) {
    $jsonLd['datePublished'] = date('c', strtotime((string) $item['published_at']));
}
if (!empty($item['updated_at'])) {
    $jsonLd['dateModified'] = date('c', strtotime((string) $item['updated_at']));
}
if (isset($ogImage)) {
    $jsonLd['image'] = $ogImage;
}
$jsonLd['publisher'] = ['@type' => 'Organization', 'name' => (string) Config::get('app.name')];
if ($type === 'event') {
    if (!empty($item['starts_at'])) {
        $jsonLd['startDate'] = date('c', strtotime((string) $item['starts_at']));
    }
    if (!empty($item['ends_at'])) {
        $jsonLd['endDate'] = date('c', strtotime((string) $item['ends_at']));
    }
    if (!empty($item['location'])) {
        $jsonLd['location'] = ['@type' => 'Place', 'name' => (string) $item['location']];
    }
}

// ---- Split attached media by kind for the right player ----
$audioItems = [];
$videoItems = [];
$documentItems = [];
foreach ($media as $m) {
    $mtype = (string) ($m['media_type'] ?? '');
    if ($mtype === 'audio') {
        $audioItems[] = $m;
    } elseif ($mtype === 'video') {
        $videoItems[] = $m;
    } elseif ($mtype === 'document') {
        $documentItems[] = $m;
    }
}
?>
<article class="public-detail<?= $type === 'research' ? ' is-reading' : '' ?>">
    <nav class="breadcrumbs" aria-label="مسیر">
        <a href="<?= e(url('/')) ?>">خانه</a>
        <span aria-hidden="true">›</span>
        <a href="<?= e(listing_url($type)) ?>"><?= e(content_type_plural_label($type)) ?></a>
    </nav>

    <header class="detail-head">
        <p class="eyebrow">
            <span><?= e(content_type_label($type)) ?></span>
            <?php if (!empty($item['topic_title'])): ?>
                · <a href="<?= e(url('/topics/' . rawurlencode((string) ($item['topic_slug'] ?? '')))) ?>"><?= e((string) $item['topic_title']) ?></a>
            <?php endif; ?>
        </p>
        <h1 class="detail-title"><?= e((string) $item['title']) ?></h1>

        <div class="detail-meta">
            <?php if ($detailAuthor !== ''): ?>
                <span class="detail-author"><?= e($detailAuthor) ?></span>
            <?php endif; ?>
            <?php if (!empty($item['published_at'])): ?>
                <time datetime="<?= e((string) $item['published_at']) ?>"><?= e(format_date_fa((string) $item['published_at'])) ?></time>
            <?php endif; ?>
        </div>

        <?php if ($type === 'event'): ?>
            <dl class="event-facts">
                <?php if (!empty($item['starts_at'])): ?>
                    <div><dt>زمان آغاز</dt><dd><time datetime="<?= e((string) $item['starts_at']) ?>"><?= e(format_date_fa((string) $item['starts_at'])) ?></time></dd></div>
                <?php endif; ?>
                <?php if (!empty($item['ends_at'])): ?>
                    <div><dt>زمان پایان</dt><dd><time datetime="<?= e((string) $item['ends_at']) ?>"><?= e(format_date_fa((string) $item['ends_at'])) ?></time></dd></div>
                <?php endif; ?>
                <?php if (!empty($item['location'])): ?>
                    <div><dt>مکان</dt><dd><?= e((string) $item['location']) ?></dd></div>
                <?php endif; ?>
            </dl>
        <?php elseif ($type === 'report'): ?>
            <?php if (!empty($item['event_date']) || !empty($item['location'])): ?>
            <dl class="event-facts">
                <?php if (!empty($item['event_date'])): ?>
                    <div><dt>تاریخ</dt><dd><time datetime="<?= e((string) $item['event_date']) ?>"><?= e(format_date_fa((string) $item['event_date'])) ?></time></dd></div>
                <?php endif; ?>
                <?php if (!empty($item['location'])): ?>
                    <div><dt>مکان</dt><dd><?= e((string) $item['location']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php endif; ?>
        <?php endif; ?>
    </header>

    <?php if ($cover !== ''): ?>
        <figure class="detail-cover">
            <img src="<?= e($cover) ?>" alt="<?= e((string) ($item['cover_alt'] ?? $item['title'])) ?>" width="1000" height="560">
        </figure>
    <?php endif; ?>

    <?php if (!empty($item['summary'])): ?>
        <p class="detail-lead"><?= e((string) $item['summary']) ?></p>
    <?php endif; ?>

    <?php if ($locked): ?>
        <section class="lock-panel" aria-label="دسترسی محدود">
            <h2>این درس برای اعضای واردشده باز است</h2>
            <p>مشاهدهٔ متن و رسانه‌های این درس نیازمند ورود به حساب کاربری است. خلاصهٔ درس بالا برای همه آزاد است.</p>
            <a class="lock-action" href="<?= e(url('/login?redirect=' . rawurlencode(current_path()))) ?>">ورود به حساب کاربری</a>
        </section>
    <?php else: ?>
    <div class="detail-body">
        <?= nl2br(e((string) ($item['body'] ?? ''))) ?>
    </div>
    <?php endif; ?>

    <?php if ($videoItems !== [] && !$locked): ?>
        <section class="media-block" aria-label="ویدیو">
            <h2 class="section-title">ویدیو</h2>
            <?php foreach ($videoItems as $m):
                $src = media_url((string) ($m['disk_path'] ?? ''));
                if ($src === '') { continue; } ?>
                <figure class="media-figure">
                    <video controls preload="none" playsinline<?= $cover !== '' ? ' poster="' . e($cover) . '"' : '' ?>>
                        <source src="<?= e($src) ?>"<?= !empty($m['mime_type']) ? ' type="' . e((string) $m['mime_type']) . '"' : '' ?>>
                        مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                    </video>
                    <?php if (!empty($m['title'])): ?><figcaption><?= e((string) $m['title']) ?></figcaption><?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($audioItems !== [] && !$locked): ?>
        <section class="media-block" aria-label="صوت">
            <h2 class="section-title">صوت</h2>
            <?php foreach ($audioItems as $m):
                $src = media_url((string) ($m['disk_path'] ?? ''));
                if ($src === '') { continue; } ?>
                <figure class="media-figure">
                    <?php if (!empty($m['title'])): ?><figcaption><?= e((string) $m['title']) ?></figcaption><?php endif; ?>
                    <audio controls preload="none">
                        <source src="<?= e($src) ?>"<?= !empty($m['mime_type']) ? ' type="' . e((string) $m['mime_type']) . '"' : '' ?>>
                        مرورگر شما از پخش صوت پشتیبانی نمی‌کند.
                    </audio>
                </figure>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($gallery !== []): ?>
        <section class="media-block" aria-label="گالری تصاویر">
            <h2 class="section-title">گالری تصاویر</h2>
            <div class="gallery-grid">
                <?php foreach ($gallery as $g):
                    $src = media_url((string) ($g['disk_path'] ?? ''));
                    if ($src === '') { continue; } ?>
                    <figure class="gallery-item">
                        <img src="<?= e($src) ?>" alt="<?= e((string) ($g['alt_text'] ?? $g['caption'] ?? $item['title'])) ?>" loading="lazy" width="400" height="300">
                        <?php if (!empty($g['caption'])): ?><figcaption><?= e((string) $g['caption']) ?></figcaption><?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($documentItems !== [] && !$locked): ?>
        <section class="media-block" aria-label="پیوست‌ها">
            <h2 class="section-title">پیوست‌ها</h2>
            <ul class="attachment-list">
                <?php foreach ($documentItems as $m):
                    $src = media_url((string) ($m['disk_path'] ?? ''));
                    if ($src === '') { continue; } ?>
                    <li><a href="<?= e($src) ?>" rel="nofollow noopener" target="_blank"><?= e((string) ($m['title'] ?? $m['original_name'] ?? 'پیوست')) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</article>

<?php if ($related !== []): ?>
    <section class="related-block" aria-label="مطالب مرتبط">
        <h2 class="section-title">مطالب مرتبط</h2>
        <div class="content-grid">
            <?php foreach ($related as $cardItem):
                $cardType = (string) ($cardItem['content_type'] ?? 'news');
                require __DIR__ . '/../views/partials/content_card.php';
            endforeach; ?>
        </div>
    </section>
<?php endif; ?>
