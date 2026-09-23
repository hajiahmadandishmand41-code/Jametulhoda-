<?php

declare(strict_types=1);

/**
 * Pagination partial — Phase 5.
 *
 * Expects in scope:
 *   $page   int    current page (1-based)
 *   $pages  int    total pages
 *   $path   string base path; may already contain a query string (e.g. /search?q=x)
 *
 * Builds accessible previous/next + numbered links, preserving any existing
 * query string. Windows the numbers around the current page for long lists.
 */

/** @var int $page */
/** @var int $pages */
/** @var string $path */
if (($pages ?? 1) <= 1) {
    return;
}

$pageUrl = static function (int $n) use ($path): string {
    $sep = str_contains($path, '?') ? '&' : '?';
    return url($path . $sep . 'page=' . $n);
};

$window = 2;
$start = max(1, $page - $window);
$end = min($pages, $page + $window);
?>
<nav class="pagination" aria-label="صفحه‌بندی">
    <?php if ($page > 1): ?>
        <a class="pagination-nav" rel="prev" href="<?= e($pageUrl($page - 1)) ?>">« قبلی</a>
    <?php endif; ?>

    <?php if ($start > 1): ?>
        <a href="<?= e($pageUrl(1)) ?>"><?= e(fa_digits('1')) ?></a>
        <?php if ($start > 2): ?><span class="pagination-gap" aria-hidden="true">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="current" aria-current="page"><?= e(fa_digits((string) $i)) ?></span>
        <?php else: ?>
            <a href="<?= e($pageUrl($i)) ?>"><?= e(fa_digits((string) $i)) ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $pages): ?>
        <?php if ($end < $pages - 1): ?><span class="pagination-gap" aria-hidden="true">…</span><?php endif; ?>
        <a href="<?= e($pageUrl($pages)) ?>"><?= e(fa_digits((string) $pages)) ?></a>
    <?php endif; ?>

    <?php if ($page < $pages): ?>
        <a class="pagination-nav" rel="next" href="<?= e($pageUrl($page + 1)) ?>">بعدی »</a>
    <?php endif; ?>
</nav>
