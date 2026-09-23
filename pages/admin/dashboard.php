<?php
/** @var array<string,int> $stats */
/** @var array<string,string> $typeLabels */
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">نمای کلی تحریریه</p>
        <h2>خلاصه وضعیت اتاق خبر</h2>
        <p>آمار زیر مستقیماً از داده‌های فعلی سامانه خوانده می‌شود.</p>
    </div>
    <span class="admin-live-indicator"><i aria-hidden="true"></i> داده زنده</span>
</section>

<section class="stat-grid" aria-label="آمار محتوا">
    <article class="stat-card stat-card-primary"><span class="stat-label">مجموع محتوا</span><strong><?= e($stats['total']) ?></strong><span class="stat-caption">همه انواع محتوا</span></article>
    <article class="stat-card"><span class="stat-label">منتشرشده</span><strong><?= e($stats['published']) ?></strong><span class="stat-caption">در دسترس مخاطبان</span></article>
    <article class="stat-card"><span class="stat-label">پیش‌نویس</span><strong><?= e($stats['draft']) ?></strong><span class="stat-caption">در انتظار تکمیل</span></article>
    <article class="stat-card"><span class="stat-label">رسانه‌ها</span><strong><?= e($stats['media']) ?></strong><span class="stat-caption">در کتابخانه رسانه</span></article>
</section>

<section class="admin-panels">
    <article class="admin-panel">
        <div class="panel-heading"><div><p class="admin-kicker">تفکیک محتوا</p><h3>محتوای ثبت‌شده</h3></div><span class="panel-icon">▥</span></div>
        <div class="type-list">
            <?php foreach ($typeLabels as $type => $label): ?>
                <div class="type-row"><span><?= e($label) ?></span><strong><?= e($stats[$type]) ?></strong></div>
            <?php endforeach; ?>
        </div>
    </article>
    <article class="admin-panel admin-panel-muted">
        <div class="panel-heading"><div><p class="admin-kicker">ساختار محتوا</p><h3>موضوعات فعال</h3></div><span class="panel-icon">◆</span></div>
        <div class="topic-total"><strong><?= e($stats['topics']) ?></strong><span>موضوع ثبت‌شده در سامانه</span></div>
        <p class="panel-note">بخش‌های تحریریه به‌صورت مرحله‌ای فعال خواهند شد. در این مرحله تنها نمای کلی داشبورد در دسترس است.</p>
    </article>
</section>

<section class="architecture-note" aria-label="راهنمای توسعه آینده">
    <span class="architecture-note-icon" aria-hidden="true">✦</span>
    <div><h3>آماده برای توسعه newsroom</h3><p>ساختار داشبورد برای افزودن ویجت‌های آخرین خبرها، خبرهای برتر، داستان ویژه و چندرسانه‌ای آماده شده است؛ منطق تحریریه این بخش‌ها هنوز فعال نیست.</p></div>
</section>

<section class="admin-panel latest-panel"><div class="panel-heading"><div><p class="admin-kicker">به‌روزرسانی</p><h3>آخرین محتوا</h3></div><a href="<?=e(url('/admin/content'))?>">مشاهده همه</a></div><div class="latest-list"><?php foreach(($latest??[]) as $item): ?><a href="<?=e(url('/admin/content/edit/'.$item['id']))?>"><strong><?=e($item['title'])?></strong><span><?=e($item['updated_at'])?></span></a><?php endforeach; ?><?php if(empty($latest)): ?><p class="empty-state">هنوز محتوایی ثبت نشده است.</p><?php endif; ?></div></section>
