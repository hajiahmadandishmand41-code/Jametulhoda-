<?php
/** @var list<array<string,mixed>> $rows */
/** @var int $total */
/** @var int $page @var int $pages */
$typeLabels = ['article'=>'مقاله','news'=>'خبر','event'=>'رویداد','report'=>'گزارش'];
$statusLabels = ['draft'=>'پیش‌نویس','published'=>'منتشرشده','archived'=>'بایگانی'];
?>
<section class="admin-welcome"><div><p class="admin-kicker">مرکز محتوا</p><h2>همه محتوا</h2><p><?= e((string)$total) ?> مورد واقعی از پایگاه داده</p></div><a class="admin-button" href="<?= e(url('/admin/content/new')) ?>">+ محتوای جدید</a></section>
<form class="admin-filters" method="get" action="<?= e(url('/admin/content')) ?>">
<label>جستجوی عنوان<input name="q" value="<?= e((string)($filters['q'] ?? '')) ?>" maxlength="250"></label>
<label>نوع<select name="type"><option value="">همه</option><?php foreach($typeLabels as $v=>$l): ?><option value="<?=e($v)?>" <?= (($filters['type']??'')===$v?'selected':'') ?>><?=e($l)?></option><?php endforeach; ?></select></label>
<label>وضعیت<select name="status"><option value="">همه</option><?php foreach($statusLabels as $v=>$l): ?><option value="<?=e($v)?>" <?= (($filters['status']??'')===$v?'selected':'') ?>><?=e($l)?></option><?php endforeach; ?></select></label>
<button class="admin-button" type="submit">اعمال فیلتر</button>
</form>
<div class="admin-table-wrap"><table class="admin-table"><caption class="sr-only">فهرست محتوا</caption><thead><tr><th>عنوان</th><th>نوع</th><th>وضعیت</th><th>موضوع</th><th>آخرین تغییر</th><th>عملیات</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?=e($row['title'])?></td><td><?=e($typeLabels[$row['content_type']]??$row['content_type'])?></td><td><span class="status status-<?=e($row['status'])?>"><?=e($statusLabels[$row['status']]??$row['status'])?></span></td><td><?=e($row['topic_title']??'—')?></td><td><?=e($row['updated_at'])?></td><td><a href="<?=e(url('/admin/content/edit/'.$row['id']))?>">ویرایش</a> <a href="<?=e(url('/admin/content/'.$row['id']))?>">مشاهده</a></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="6" class="empty-state">محتوایی با این فیلتر پیدا نشد.</td></tr><?php endif; ?></tbody></table></div>
<?php if($pages>1): ?><nav class="pagination" aria-label="صفحه‌بندی"><?php for($i=1;$i<=$pages;$i++): ?><a class="<?= $i===$page?'current':'' ?>" href="<?=e(url('/admin/content?'.http_build_query(array_merge($filters,['page'=>$i]))) )?>"><?=e((string)$i)?></a><?php endfor; ?></nav><?php endif; ?>
