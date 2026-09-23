<?php

declare(strict_types=1);

/**
 * Reusable content card partial — Phase 5.
 *
 * Expects two variables in scope:
 *   $cardItem  array<string,mixed>  the content row (with topic_title/slug, cover_path/alt)
 *   $cardType  string               internal content type used to build the URL
 *
 * Every dynamic value is escaped. Images lazy-load and reserve their box.
 */

/** @var array<string,mixed> $cardItem */
/** @var string $cardType */
$cardType = $cardType ?? (string) ($cardItem['content_type'] ?? 'news');
$coverPath = (string) ($cardItem['cover_path'] ?? '');
$cover = media_file_exists($coverPath) ? media_url($coverPath) : '';
$excerptText = excerpt((string) ($cardItem['summary'] ?? $cardItem['body'] ?? ''), 140);
$cardAuthor = trim((string) ($cardItem['author'] ?? ''));
?>
<article class="content-card">
    <?php if ($cover !== ''): ?>
        <a class="card-media" href="<?= e(content_url($cardType, (string) $cardItem['slug'])) ?>" tabindex="-1" aria-hidden="true">
            <img src="<?= e($cover) ?>" alt="<?= e((string) ($cardItem['cover_alt'] ?? $cardItem['title'])) ?>" loading="lazy" width="400" height="225">
        </a>
    <?php endif; ?>
    <div class="card-body">
        <p class="card-meta">
            <span class="card-type"><?= e(content_type_label($cardType)) ?></span>
            <?php if (!empty($cardItem['topic_title'])): ?>
                <a href="<?= e(url('/topics/' . rawurlencode((string) ($cardItem['topic_slug'] ?? '')))) ?>"><?= e((string) $cardItem['topic_title']) ?></a>
            <?php endif; ?>
        </p>
        <h2 class="card-title"><a href="<?= e(content_url($cardType, (string) $cardItem['slug'])) ?>"><?= e((string) $cardItem['title']) ?></a></h2>
        <?php if ($cardAuthor !== ''): ?><p class="card-author"><?= e($cardAuthor) ?></p><?php endif; ?>
        <?php if ($excerptText !== ''): ?><p class="card-excerpt"><?= e($excerptText) ?></p><?php endif; ?>
        <?php if (!empty($cardItem['published_at'])): ?>
            <time class="card-date" datetime="<?= e((string) $cardItem['published_at']) ?>"><?= e(format_date_fa((string) $cardItem['published_at'])) ?></time>
        <?php endif; ?>
    </div>
</article>
