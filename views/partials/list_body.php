<?php

declare(strict_types=1);

/**
 * Shared list body — Phase 5.
 *
 * Renders a heading, a grid of content cards and pagination. Used by the
 * listing and topic pages so the markup stays in one place.
 *
 * @var string                    $heading
 * @var string                    $intro
 * @var string                    $type
 * @var list<array<string,mixed>> $items
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $total
 * @var string                    $path
 */

$intro = $intro ?? '';
$total = (int) ($total ?? count($items));
?>
<header class="page-head">
    <h1 class="page-title"><?= e($heading) ?></h1>
    <?php if ($intro !== ''): ?><p class="page-intro"><?= e($intro) ?></p><?php endif; ?>
    <?php if ($total > 0): ?><p class="result-count"><?= e(fa_digits((string) $total)) ?> مورد</p><?php endif; ?>
</header>

<?php if ($items === []): ?>
    <p class="empty-state">محتوای منتشرشده‌ای در این بخش وجود ندارد.</p>
<?php else: ?>
    <div class="content-grid">
        <?php foreach ($items as $cardItem):
            $cardType = $type;
            require __DIR__ . '/content_card.php';
        endforeach; ?>
    </div>
    <?php require __DIR__ . '/pagination.php'; ?>
<?php endif; ?>
