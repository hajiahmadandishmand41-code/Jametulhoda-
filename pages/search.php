<?php

declare(strict_types=1);

/**
 * Search page — Phase 5.
 *
 * The query is always escaped on output (XSS) and matched with prepared
 * statements at the repository layer (SQL injection). A minimum length keeps
 * the query meaningful and the index efficient.
 *
 * @var string                    $query
 * @var bool                      $tooShort
 * @var int                       $minLen
 * @var list<array<string,mixed>> $items
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $total
 */

$query = (string) ($query ?? '');
$tooShort = (bool) ($tooShort ?? false);
$minLen = (int) ($minLen ?? 2);
$total = (int) ($total ?? count($items));
// Utility page: never index search result URLs.
$noindex = true;
?>
<header class="page-head">
    <h1 class="page-title">جستجو</h1>
    <form class="search-form" action="<?= e(url('/search')) ?>" method="get" role="search">
        <label class="visually-hidden" for="search-page-input">عبارت جستجو</label>
        <input id="search-page-input" name="q" type="search" value="<?= e($query) ?>" placeholder="عبارت مورد نظر را وارد کنید…" maxlength="120" autocomplete="off" autofocus>
        <button type="submit">جستجو</button>
    </form>
</header>

<?php if ($query === ''): ?>
    <p class="empty-state">برای جستجو، عبارتی را وارد کنید.</p>
<?php elseif ($tooShort): ?>
    <p class="empty-state">عبارت جستجو باید حداقل <?= e(fa_digits((string) $minLen)) ?> نویسه باشد.</p>
<?php elseif ($items === []): ?>
    <p class="empty-state">نتیجه‌ای برای «<?= e($query) ?>» یافت نشد.</p>
<?php else: ?>
    <p class="result-count"><?= e(fa_digits((string) $total)) ?> نتیجه برای «<?= e($query) ?>»</p>
    <div class="content-grid">
        <?php foreach ($items as $cardItem):
            $cardType = (string) ($cardItem['content_type'] ?? 'news');
            require __DIR__ . '/../views/partials/content_card.php';
        endforeach; ?>
    </div>
    <?php
    $path = '/search?q=' . rawurlencode($query);
    require __DIR__ . '/../views/partials/pagination.php';
    ?>
<?php endif; ?>
