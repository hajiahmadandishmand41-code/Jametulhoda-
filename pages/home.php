<?php

declare(strict_types=1);

/**
 * Homepage — Phase 1 (core).
 *
 * In Phase 2 the "coming soon" cards are replaced with real content
 * (latest articles, news, upcoming events, latest reports) from the database.
 */
?>
<section class="hero">
    <h1><?= e(Config::get('app.name')) ?></h1>
    <p class="hero-lead"><?= e(Config::get('app.description')) ?></p>
    <span class="hero-note">نسخهٔ ۱ — هستهٔ سیستم آماده است</span>
</section>

<section class="sections" aria-label="بخش‌های سایت">
    <h2 class="visually-hidden">بخش‌های سایت</h2>
    <div class="grid">
        <article class="card">
            <h3>مقالات</h3>
            <p>به‌زودی</p>
        </article>
        <article class="card">
            <h3>اخبار</h3>
            <p>به‌زودی</p>
        </article>
        <article class="card">
            <h3>رویدادها</h3>
            <p>به‌زودی</p>
        </article>
        <article class="card">
            <h3>گزارش‌ها</h3>
            <p>به‌زودی</p>
        </article>
    </div>
</section>
