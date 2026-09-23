<?php

declare(strict_types=1);

/**
 * Topic page — Phase 5. Latest published content for one topic.
 *
 * @var array<string,mixed>       $topic
 * @var list<array<string,mixed>> $items
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $total
 * @var string                    $path
 */

$description = trim((string) ($topic['description'] ?? ''));
$total = (int) ($total ?? count($items));
?>
<header class="page-head topic-head">
    <p class="eyebrow">موضوع</p>
    <h1 class="page-title"><?= e((string) $topic['title']) ?></h1>
    <?php if ($description !== ''): ?><p class="page-intro"><?= e($description) ?></p><?php endif; ?>
    <?php if ($total > 0): ?><p class="result-count"><?= e(fa_digits((string) $total)) ?> مورد</p><?php endif; ?>
</header>

<?php if ($items === []): ?>
    <p class="empty-state">هنوز محتوایی برای این موضوع منتشر نشده است.</p>
<?php else: ?>
    <div class="content-grid">
        <?php foreach ($items as $cardItem):
            $cardType = (string) ($cardItem['content_type'] ?? 'news');
            require __DIR__ . '/../views/partials/content_card.php';
        endforeach; ?>
    </div>
    <?php require __DIR__ . '/../views/partials/pagination.php'; ?>
<?php endif; ?>
