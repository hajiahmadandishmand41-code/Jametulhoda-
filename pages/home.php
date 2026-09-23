<?php

declare(strict_types=1);

/**
 * Home page — Phase 5.
 *
 * A modern religious news portal home: a lead story, a column of important
 * stories, the latest news, then themed sections. All data comes from the
 * route handler; empty database => professional empty states (no fake data).
 *
 * @var array<string,mixed>|null      $featured
 * @var list<array<string,mixed>>     $secondary
 * @var list<array<string,mixed>>     $latest
 * @var list<array<string,mixed>>     $articles
 * @var list<array<string,mixed>>     $reports
 * @var list<array<string,mixed>>     $events
 * @var list<array<string,mixed>>     $topics
 */

$featured = $featured ?? null;
$secondary = $secondary ?? [];
$latest = $latest ?? [];
$hasAny = $featured || $secondary || $latest || !empty($articles) || !empty($reports) || !empty($events);

/** Render one content card. */
$card = static function (array $item, string $type, bool $withImage = true): void {
    $cover = media_url((string) ($item['cover_path'] ?? ''));
    ?>
    <article class="content-card">
        <?php if ($withImage && $cover !== ''): ?>
            <a class="card-media" href="<?= e(content_url($type, (string) $item['slug'])) ?>" tabindex="-1" aria-hidden="true">
                <img src="<?= e($cover) ?>" alt="<?= e((string) ($item['cover_alt'] ?? $item['title'])) ?>" loading="lazy" width="400" height="225">
            </a>
        <?php endif; ?>
        <div class="card-body">
            <?php if (!empty($item['topic_title'])): ?>
                <p class="card-meta">
                    <a href="<?= e(url('/topics/' . rawurlencode((string) ($item['topic_slug'] ?? '')))) ?>"><?= e((string) $item['topic_title']) ?></a>
                </p>
            <?php endif; ?>
            <h3 class="card-title"><a href="<?= e(content_url($type, (string) $item['slug'])) ?>"><?= e((string) $item['title']) ?></a></h3>
            <?php $sum = excerpt((string) ($item['summary'] ?? $item['body'] ?? ''), 120); ?>
            <?php if ($sum !== ''): ?><p class="card-excerpt"><?= e($sum) ?></p><?php endif; ?>
            <?php if (!empty($item['published_at'])): ?>
                <time class="card-date" datetime="<?= e((string) $item['published_at']) ?>"><?= e(format_date_fa((string) $item['published_at'])) ?></time>
            <?php endif; ?>
        </div>
    </article>
    <?php
};
?>

<?php if (!$hasAny): ?>
    <section class="hero">
        <h1>به <?= e((string) Config::get('app.name')) ?> خوش آمدید</h1>
        <p class="hero-lead"><?= e((string) Config::get('app.description')) ?></p>
        <p class="empty-state">هنوز محتوایی منتشر نشده است. به‌زودی مطالب تازه در دسترس قرار می‌گیرد.</p>
    </section>
<?php else: ?>

    <?php if ($featured): ?>
    <section class="lead-block" aria-label="خبر اصلی">
        <article class="lead-story">
            <?php $leadCover = media_url((string) ($featured['cover_path'] ?? '')); ?>
            <?php if ($leadCover !== ''): ?>
                <a class="lead-media" href="<?= e(content_url('news', (string) $featured['slug'])) ?>">
                    <img src="<?= e($leadCover) ?>" alt="<?= e((string) ($featured['cover_alt'] ?? $featured['title'])) ?>" width="800" height="450">
                </a>
            <?php endif; ?>
            <div class="lead-body">
                <p class="eyebrow">خبر اصلی</p>
                <?php if (!empty($featured['topic_title'])): ?>
                    <p class="card-meta"><a href="<?= e(url('/topics/' . rawurlencode((string) ($featured['topic_slug'] ?? '')))) ?>"><?= e((string) $featured['topic_title']) ?></a></p>
                <?php endif; ?>
                <h1 class="lead-title"><a href="<?= e(content_url('news', (string) $featured['slug'])) ?>"><?= e((string) $featured['title']) ?></a></h1>
                <?php $leadSum = excerpt((string) ($featured['summary'] ?? $featured['body'] ?? ''), 220); ?>
                <?php if ($leadSum !== ''): ?><p class="lead-excerpt"><?= e($leadSum) ?></p><?php endif; ?>
                <?php if (!empty($featured['published_at'])): ?>
                    <time class="card-date" datetime="<?= e((string) $featured['published_at']) ?>"><?= e(format_date_fa((string) $featured['published_at'])) ?></time>
                <?php endif; ?>
            </div>
        </article>

        <?php if ($secondary !== []): ?>
        <div class="lead-secondary">
            <h2 class="section-title">خبرهای مهم</h2>
            <ul class="story-list">
                <?php foreach ($secondary as $item): ?>
                    <li>
                        <a href="<?= e(content_url('news', (string) $item['slug'])) ?>"><?= e((string) $item['title']) ?></a>
                        <?php if (!empty($item['published_at'])): ?>
                            <time datetime="<?= e((string) $item['published_at']) ?>"><?= e(format_date_fa((string) $item['published_at'])) ?></time>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
    $sectionData = [
        ['items' => $latest,             'type' => 'news',    'label' => 'آخرین خبرها', 'href' => '/news'],
        ['items' => $articles ?? [],     'type' => 'article', 'label' => 'مقالات',     'href' => '/articles'],
        ['items' => $reports ?? [],      'type' => 'report',  'label' => 'گزارش‌ها',   'href' => '/reports'],
        ['items' => $events ?? [],       'type' => 'event',   'label' => 'رویدادها',   'href' => '/events'],
    ];
    foreach ($sectionData as $section):
        $items = $section['items'];
        if ($items === []) {
            continue;
        }
        ?>
        <section class="portal-section" aria-label="<?= e($section['label']) ?>">
            <div class="section-heading">
                <h2 class="section-title"><?= e($section['label']) ?></h2>
                <a class="section-more" href="<?= e(url($section['href'])) ?>">مشاهدهٔ همه</a>
            </div>
            <div class="content-grid">
                <?php foreach ($items as $item) {
                    $card($item, $section['type']);
                } ?>
            </div>
        </section>
    <?php endforeach; ?>

<?php endif; ?>

<?php if (!empty($topics)): ?>
<section class="portal-section" aria-label="موضوعات">
    <div class="section-heading">
        <h2 class="section-title">موضوعات</h2>
    </div>
    <div class="topic-pills">
        <?php foreach ($topics as $topic): ?>
            <a href="<?= e(url('/topics/' . rawurlencode((string) $topic['slug']))) ?>"><?= e((string) $topic['title']) ?></a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
