<?php

declare(strict_types=1);

$statusCode = (string) ($statusCode ?? '403');
$message = (string) ($message ?? 'درخواست انجام نشد.');
$backPath = (string) ($backPath ?? '/admin');
?>
<section class="admin-status-card" aria-labelledby="admin-status-heading">
    <span class="admin-status-code" aria-hidden="true"><?= e($statusCode) ?></span>
    <div>
        <p class="admin-kicker">وضعیت درخواست</p>
        <h2 id="admin-status-heading"><?= e((string) ($title ?? 'درخواست انجام نشد')) ?></h2>
        <p><?= e($message) ?></p>
        <div class="admin-status-actions">
            <a class="admin-button" href="<?= e(url($backPath)) ?>">بازگشت به پنل</a>
            <a class="admin-button admin-button-secondary" href="<?= e(url('/')) ?>">مشاهدهٔ سایت</a>
        </div>
    </div>
</section>
