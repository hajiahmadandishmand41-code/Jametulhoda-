<?php

declare(strict_types=1);

/**
 * 404 page — unknown URL (Phase 1, refined in Phase 5).
 */
$noindex = true;
?>
<section class="not-found">
    <p class="not-found-code"><?= e(fa_digits('404')) ?></p>
    <h1>صفحه پیدا نشد</h1>
    <p>صفحه‌ای که دنبالش می‌گردید وجود ندارد یا جابه‌جا شده است.</p>
    <p class="not-found-actions">
        <a class="btn" href="<?= e(url('/')) ?>">برگشت به صفحهٔ اصلی</a>
        <a class="btn btn-ghost" href="<?= e(url('/news')) ?>">آخرین خبرها</a>
    </p>
</section>
