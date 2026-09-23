<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var array<string,string> $filters */

$typeLabels = ['news' => 'خبر', 'article' => 'مقاله', 'event' => 'رویداد', 'report' => 'گزارش', 'book' => 'کتاب', 'lesson' => 'درس', 'research' => 'پژوهش'];
$statusLabels = ['draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'archived' => 'بایگانی'];
$heading = (string) ($heading ?? 'همه محتوا');
$registryPath = (string) ($registryPath ?? '/admin/content');
?>
<section class="admin-welcome">
    <div><p class="admin-kicker">مرکز محتوا</p><h2><?= e($heading) ?></h2><p><?= e(fa_digits((string) $total)) ?> مورد واقعی از پایگاه داده</p></div>
    <a class="admin-button" href="<?= e(url('/admin/content/new?type=' . rawurlencode((string) ($filters['type'] ?: 'news')))) ?>">+ محتوای جدید</a>
</section>
<form class="admin-filters" method="get" action="<?= e(url($registryPath)) ?>">
    <label>جستجوی عنوان<input name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" maxlength="120"></label>
    <label>نوع<select name="type"<?= $registryPath !== '/admin/content' ? ' disabled' : '' ?>><option value="">همه</option><?php foreach ($typeLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= (($filters['type'] ?? '') === $value ? 'selected' : '') ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label>وضعیت<select name="status"><option value="">همه</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= (($filters['status'] ?? '') === $value ? 'selected' : '') ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <button class="admin-button" type="submit">اعمال فیلتر</button>
</form>
<div class="admin-table-wrap">
    <table class="admin-table">
        <caption class="sr-only">فهرست محتوا</caption>
        <thead><tr><th>عنوان</th><th>نوع</th><th>وضعیت</th><th>موضوع</th><th>آخرین تغییر</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= e((string) $row['title']) ?></td>
                <td><?= e($typeLabels[(string) $row['content_type']] ?? (string) $row['content_type']) ?></td>
                <td><span class="status status-<?= e((string) $row['status']) ?>"><?= e($statusLabels[(string) $row['status']] ?? (string) $row['status']) ?></span></td>
                <td><?= e((string) ($row['topic_title'] ?? '—')) ?></td>
                <td><?= e((string) $row['updated_at']) ?></td>
                <td>
                    <a href="<?= e(url('/admin/content/edit/' . (int) $row['id'])) ?>">ویرایش</a>
                    <a href="<?= e(content_url((string) $row['content_type'], (string) $row['slug'])) ?>">عمومی</a>
                    <?php if (($row['status'] ?? '') !== 'published'): ?>
                    <form class="inline-form" method="post" action="<?= e(url('/admin/content/' . (int) $row['id'] . '/publish')) ?>"><?= csrf_field() ?><button class="link-button" type="submit">انتشار</button></form>
                    <?php else: ?>
                    <form class="inline-form" method="post" action="<?= e(url('/admin/content/' . (int) $row['id'] . '/unpublish')) ?>"><?= csrf_field() ?><button class="link-button" type="submit">پیش‌نویس</button></form>
                    <?php endif; ?>
                    <form class="inline-form" method="post" action="<?= e(url('/admin/content/' . (int) $row['id'] . '/archive')) ?>"><?= csrf_field() ?><button class="link-button" type="submit">بایگانی</button></form>
                    <form class="inline-form" method="post" action="<?= e(url('/admin/content/' . (int) $row['id'] . '/delete')) ?>" data-confirm="حذف قطعی شود؟"><?= csrf_field() ?><button class="admin-button-danger" type="submit">حذف</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty-state">محتوایی با این فیلتر پیدا نشد.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($pages > 1): ?><nav class="pagination" aria-label="صفحه‌بندی"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'current' : '' ?>" href="<?= e(url($registryPath . '?' . http_build_query(array_merge($filters, ['page' => $i])))) ?>"><?= e(fa_digits((string) $i)) ?></a><?php endfor; ?></nav><?php endif; ?>
