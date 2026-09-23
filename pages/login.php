<?php

declare(strict_types=1);

/**
 * Public login form — Phase 3 only. No user-management UI is included.
 */
$email = (string) ($loginEmail ?? '');
$redirectTarget = (string) ($redirectTarget ?? '/');
$error = (string) ($loginError ?? '');
?>
<section class="auth-card" aria-labelledby="login-heading">
    <h1 id="login-heading">ورود به حساب کاربری</h1>

    <?php if ($error !== ''): ?>
        <p class="form-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
        <?= csrf_field() ?>
        <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">

        <label for="login-email">ایمیل</label>
        <input id="login-email" name="email" type="email" value="<?= e($email) ?>" required maxlength="254" autocomplete="username">

        <label for="login-password">گذرواژه</label>
        <input id="login-password" name="password" type="password" required maxlength="4096" autocomplete="current-password">

        <button class="btn" type="submit">ورود</button>
    </form>
</section>
