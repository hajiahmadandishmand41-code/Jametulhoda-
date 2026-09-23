<?php

declare(strict_types=1);

/**
 * Lessons listing — Phase 6.
 *
 * A course page: lessons render as an ordered curriculum (the number comes
 * from the editor-controlled sort_order), not as a news feed. Topic filter
 * + pagination + empty state follow the Phase 5 design system.
 *
 * @var string                    $heading
 * @var string                    $intro
 * @var string                    $sectionPath (/lessons)
 * @var list<array<string,mixed>> $items
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $total
 * @var list<array<string,mixed>> $topics
 * @var string                    $activeTopicSlug
 */

$intro = $intro ?? '';
$total = (int) ($total ?? count($items));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$activeTopicSlug = (string) ($activeTopicSlug ?? '');

$path = $sectionPath;
if ($activeTopicSlug !== '') {
    $path .= '?topic=' . rawurlencode($activeTopicSlug);
}
?>
<header class="page-head">
    <h1 class="page-title"><?= e($heading) ?></h1>
    <?php if ($intro !== ''): ?><p class="page-intro"><?= e($intro) ?></p><?php endif; ?>
    <?php if ($total > 0): ?><p class="result-count"><?= e(fa_digits((string) $total)) ?> درس</p><?php endif; ?>
</header>

<?php if ($topics !== []): ?>
<nav class="topic-chips" aria-label="فیلتر موضوع">
    <a class="topic-chip<?= $activeTopicSlug === '' ? ' is-active' : '' ?>" href="<?= e(url($sectionPath)) ?>">همه</a>
    <?php foreach ($topics as $topicChip): ?>
        <a class="topic-chip<?= (string) $topicChip['slug'] === $activeTopicSlug ? ' is-active' : '' ?>" href="<?= e(url($sectionPath . '?topic=' . rawurlencode((string) $topicChip['slug']))) ?>"><?= e((string) $topicChip['title']) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($items === []): ?>
    <p class="empty-state">هنوز درسی منتشر نشده است.</p>
<?php else: ?>
    <ol class="lesson-list">
        <?php $position = ($page - 1) * 12; ?>
        <?php foreach ($items as $item): ?>
            <?php $position++; ?>
            <li class="lesson-item">
                <span class="lesson-number" aria-hidden="true"><?= e(fa_digits((string) ((int) ($item['sort_order'] ?? $position)))) ?></span>
                <div class="lesson-body">
                    <h2 class="lesson-title">
                        <a href="<?= e(content_url('lesson', (string) $item['slug'])) ?>"><?= e((string) $item['title']) ?></a>
                    </h2>
                    <?php if (!empty($item['summary'])): ?>
                        <p class="lesson-summary"><?= e(excerpt((string) $item['summary'], 140)) ?></p>
                    <?php endif; ?>
                    <p class="lesson-meta">
                        <?php if (!empty($item['topic_title'])): ?>
                            <a class="topic-link" href="<?= e(url('/topics/' . rawurlencode((string) ($item['topic_slug'] ?? '')))) ?>"><?= e((string) $item['topic_title']) ?></a>
                        <?php endif; ?>
                        <?php if (!empty($item['published_at'])): ?>
                            <time datetime="<?= e((string) $item['published_at']) ?>"><?= e(format_date_fa((string) $item['published_at'])) ?></time>
                        <?php endif; ?>
                        <?php if (!empty($item['requires_login'])): ?>
                            <span class="lock-badge" title="ورود برای مشاهدهٔ متن درس لازم است">نیازمند ورود</span>
                        <?php endif; ?>
                    </p>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php require __DIR__ . '/../views/partials/pagination.php'; ?>
<?php endif; ?>
