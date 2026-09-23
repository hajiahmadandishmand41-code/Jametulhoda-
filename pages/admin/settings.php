<?php

declare(strict_types=1);

$labels = ['logo' => 'لوگوی اصلی', 'favicon' => 'نماد مرورگر (favicon)', 'og_image' => 'تصویر اشتراک‌گذاری (Open Graph)'];
?>
<section class="admin-welcome">
    <div><p class="admin-kicker">هویت سایت</p><h2>تنظیمات سایت</h2><p>نام و تصاویر سایت را بدون ویرایش فایل‌ها مدیریت کنید.</p></div>
    <a class="admin-button" href="<?= e(url('/')) ?>">مشاهده سایت</a>
</section>
<?php if ($saved): ?><p class="settings-notice" role="status">تنظیمات ذخیره شد و در سایت قابل مشاهده است.</p><?php endif; ?>
<?php if ($errors): ?>
<div id="settings-errors" class="form-errors" role="alert"><strong>تنظیمات ذخیره نشد.</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><p>در صورت انتخاب تصویر، فایل را دوباره انتخاب کنید.</p></div>
<?php endif; ?>
<form class="admin-form settings-form" method="post" enctype="multipart/form-data" action="<?= e(url('/admin/settings')) ?>"<?= $errors ? ' aria-describedby="settings-errors"' : '' ?>>
    <?= csrf_field() ?>
    <fieldset>
        <legend>اطلاعات پایه</legend>
        <label for="site-name">نام سایت</label>
        <input id="site-name" name="name" required maxlength="120" value="<?= e($settings['name']) ?>">
        <label for="site-description">توضیح کوتاه سایت</label>
        <textarea id="site-description" name="description" maxlength="500" rows="3"><?= e($settings['description']) ?></textarea>
    </fieldset>
    <p id="branding-help">فرمت‌های مجاز: PNG، JPG، JPEG و WEBP. هر فایل حداکثر ۲ مگابایت، هر ضلع حداکثر ۴۰۹۶ پیکسل و مجموع حداکثر ۱۲ میلیون پیکسل. SVG و فایل اجرایی پذیرفته نمی‌شوند. برای favicon تصویر مربع و برای اشتراک‌گذاری تصویر ۱۲۰۰ × ۶۳۰ پیشنهاد می‌شود.</p>
    <div class="settings-grid">
    <?php foreach ($labels as $key => $label): ?>
        <?php $isCustom = str_starts_with(SiteSettings::imagePath($key), '/uploads/site/'); ?>
        <fieldset class="branding-card">
            <legend><?= e($label) ?></legend>
            <div class="branding-preview <?= $key === 'og_image' ? 'branding-preview-wide' : '' ?>">
                <img id="preview-<?= e($key) ?>" src="<?= e(site_image($key)) ?>" alt="پیش‌نمایش <?= e($label) ?>" width="240" height="140">
            </div>
            <p class="branding-status" id="status-<?= e($key) ?>" aria-live="polite"><?= $isCustom ? 'تصویر اختصاصی فعال است.' : 'تصویر پیش‌فرض مخزن فعال است؛ فایل اختصاصی موجود یا معتبر نیست.' ?></p>
            <label for="upload-<?= e($key) ?>">انتخاب <?= e($label) ?></label>
            <input id="upload-<?= e($key) ?>" name="<?= e($key) ?>" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-branding-preview="preview-<?= e($key) ?>" data-branding-status="status-<?= e($key) ?>" aria-describedby="branding-help status-<?= e($key) ?>">
            <label class="checkbox-label"><input type="checkbox" name="reset_<?= e($key) ?>" value="1"> حذف تصویر اختصاصی و بازگردانی پیش‌فرض</label>
        </fieldset>
    <?php endforeach; ?>
    </div>
    <p>پیش‌نمایش فایل انتخابی تا زمان ذخیره در سایت نمایش داده نمی‌شود. حذف تصویر نیز پس از ذخیره اعمال می‌شود.</p>
    <div class="settings-actions"><button class="admin-button" type="submit">ذخیره تنظیمات</button><a href="<?= e(url('/admin/settings')) ?>">لغو تغییرات</a></div>
</form>
