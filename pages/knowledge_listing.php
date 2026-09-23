<?php

declare(strict_types=1);

/**
 * Knowledge listing page — Phase 6 (books and research).
 *
 * Same visual grammar as the Phase 5 listings (list_body partial) plus the
 * two things a library needs: a title/author search and a topic filter.
 *
 * @var string                    $heading
 * @var string                    $intro
 * @var string                    $sectionPath  e.g. /books
 * @var string                    $sectionLabel e.g. کتاب
 * @var list<array<string,mixed>> $items
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $total
 * @var string                    $query
 * @var list<array<string,mixed>> $topics
 * @var string                    $activeTopicSlug
 * @var bool                      $showAuthor
 */

$intro = $intro ?? '';
$total = (int) ($total ?? count($items));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$showAuthor = (bool) ($showAuthor ?? false);
$activeTopicSlug = (string) ($activeTopicSlug ?? '');
$query = (string) ($query ?? '');

/** Base path that preserves the active filters for pagination. */
$path = $sectionPath;
if ($query !== '') {
    $path .= '?q=' . rawurlencode($query);
}
if ($activeTopicSlug !== '') {
    $path .= (str_contains($path, '?') ? '&' : '?') . 'topic=' . rawurlencode($activeTopicSlug);
}
?>
<header class="page-head">
    <h1 class="page-title"><?= e($heading) ?></h1>
    <?php if ($intro !== ''): ?><p class="page-intro"><?= e($intro) ?></p><?php endif; ?>
    <?php if ($total > 0): ?><p class="result-count"><?= e(fa_digits((string) $total)) ?> مورد</p><?php endif; ?>
</header>

<form class="filter-form" method="get" action="<?= e(url($sectionPath)) ?>" role="search">
    <label class="visually-hidden" for="<?= e($sectionPath) ?>-q">جستجو</label>
    <input id="<?= e($sectionPath) ?>-q" name="q" type="search" value="<?= e($query) ?>" placeholder="جستجوی عنوان<?= $showAuthor ? ' یا نویسنده' : '' ?>…" maxlength="120" autocomplete="off">
    <label class="visually-hidden" for="<?= e($sectionPath) ?>-topic">موضوع</label>
    <select id="<?= e($sectionPath) ?>-topic" name="topic">
        <option value="">همهٔ موضوعات</option>
        <?php foreach ($topics as $topicOption): ?>
            <option value="<?= e((string) $topicOption['slug']) ?>" <?= (string) $topicOption['slug'] === $activeTopicSlug ? 'selected' : '' ?>><?= e((string) $topicOption['title']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">اعمال</button>
</form>

<?php if ($items === []): ?>
    <p class="empty-state">محتوای منتشرشده‌ای در این بخش وجود ندارد.</p>
<?php else: ?>
    <div class="content-grid">
        <?php foreach ($items as $cardItem):
            $cardType = (string) ($cardItem['content_type'] ?? '');
            require __DIR__ . '/../views/partials/content_card.php';
        endforeach; ?>
    </div>
    <?php require __DIR__ . '/../views/partials/pagination.php'; ?>
<?php endif; ?>
