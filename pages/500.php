<?php

declare(strict_types=1);

$noindex = true;
?>
<section class="not-found error-page" aria-labelledby="server-error-heading">
    <p class="not-found-code" aria-hidden="true">۵۰۰</p>
    <h1 id="server-error-heading">سرویس موقتاً در دسترس نیست</h1>
    <p>در پردازش این صفحه مشکلی پیش آمد. لطفاً چند لحظه بعد دوباره تلاش کنید.</p>
    <p class="not-found-actions">
        <a class="btn" href="<?= e(url('/')) ?>">بازگشت به صفحهٔ اصلی</a>
        <a class="btn btn-ghost" href="<?= e(url('/search')) ?>">جستجو در سایت</a>
    </p>
</section>
