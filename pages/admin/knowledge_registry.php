<?php

declare(strict_types=1);

/**
 * Knowledge registry — Phase 6 admin listing (books / lessons / research).
 * Presentation only: rows come from ContentRepository::adminList(), the
 * extension extras (author / sort order) are pre-joined by the route.
 *
 * @var string                     $sectionPath books|lessons|research
 * @var array<string,mixed>        $section
 * @var list<array<string,mixed>>  $rows
 * @var array<int,array<string,mixed>> $extra  content_id => extension row
 * @var int                        $total
 * @var int                        $page
 * @var int                        $pages
 * @var array{q:string,status:string} $filters
 */

$statusLabels = ['draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'archived' => 'بایگانی'];
$publicPath = ['books' => '/books', 'lessons' => '/lessons', 'research' => '/research'][$sectionPath] ?? '/';
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">دانش و محتوا</p>
        <h2><?= e((string) $section['label']) ?></h2>
        <p><?= e((string) $section['intro']) ?> — <?= e(fa_digits((string) $total)) ?> مورد</p>
    </div>
    <a class="admin-button" href="<?= e(url('/admin/' . $sectionPath . '/new')) ?>">+ <?= e((string) $section['singular']) ?> جدید</a>
</section>

<form class="admin-filters" method="get" action="<?= e(url('/admin/' . $sectionPath)) ?>">
    <label>جستجوی عنوان<input name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" maxlength="120"></label>
    <label>وضعیت<select name="status">
        <option value="">همه</option>
        <?php foreach ($statusLabels as $value => $statusLabel): ?>
            <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
        <?php endforeach; ?>
    </select></label>
    <button class="admin-button" type="submit">اعمال فیلتر</button>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <caption class="sr-only">فهرست <?= e((string) $section['label']) ?></caption>
        <thead>
            <tr>
                <?php if (!empty($section['hasOrder'])): ?><th>ترتیب</th><?php endif; ?>
                <th>عنوان</th>
                <?php if (!empty($section['hasAuthor'])): ?><th>نویسنده</th><?php endif; ?>
                <th>وضعیت</th>
                <th>آخرین تغییر</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): $extraRow = $extra[(int) $row['id']] ?? null; ?>
                <tr>
                    <?php if (!empty($section['hasOrder'])): ?><td><?= e(fa_digits((string) (int) ($extraRow['sort_order'] ?? 0))) ?></td><?php endif; ?>
                    <td><?= e((string) $row['title']) ?></td>
                    <?php if (!empty($section['hasAuthor'])): ?><td><?= e((string) ($extraRow['author'] ?? '—')) ?></td><?php endif; ?>
                    <td><span class="status status-<?= e((string) $row['status']) ?>"><?= e($statusLabels[(string) $row['status']] ?? (string) $row['status']) ?></span></td>
                    <td><?= e((string) $row['updated_at']) ?></td>
                    <td>
                        <a href="<?= e(url('/admin/' . $sectionPath . '/edit/' . (int) $row['id'])) ?>">ویرایش</a>
                        <a href="<?= e(url('/admin/' . $sectionPath . '/' . (int) $row['id'])) ?>">مشاهده</a>
                        <a href="<?= e(url($publicPath . '/' . rawurlencode((string) $row['slug']))) ?>">نمایش عمومی</a>
                        <form class="inline-form" method="post" action="<?= e(url('/admin/' . $sectionPath . '/' . (int) $row['id'] . '/delete')) ?>" data-confirm="حذف شود؟">
                            <?= csrf_field() ?>
                            <button class="admin-button-danger" type="submit">حذف</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty-state">موردی با این فیلتر پیدا نشد.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
<nav class="pagination" aria-label="صفحه‌بندی">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="<?= $i === $page ? 'current' : '' ?>" href="<?= e(url('/admin/' . $sectionPath . '?' . http_build_query(array_merge($filters, ['page' => $i])))) ?>"><?= e((string) $i) ?></a>
    <?php endfor; ?>
</nav>
<?php endif; ?>
