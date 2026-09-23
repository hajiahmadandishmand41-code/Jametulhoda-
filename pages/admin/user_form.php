<?php
/**
 * Admin: User create/edit form.
 *
 * @var string $action
 * @var array<string,mixed> $user
 * @var bool $isEdit
 * @var list<string> $errors
 */
$isEdit = !empty($isEdit);
$errors = $errors ?? [];
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">مدیریت کاربران</p>
        <h2><?= $isEdit ? 'ویرایش کاربر' : 'کاربر جدید' ?></h2>
    </div>
</section>

<?php if ($errors): ?>
    <div class="form-errors" role="alert">
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form class="admin-form" method="post" action="<?= e($action) ?>" novalidate>
    <?= csrf_field() ?>
    <label><span>نام</span>
        <input name="name" value="<?= e($user['name'] ?? '') ?>" required maxlength="160">
    </label>
    <label><span>ایمیل</span>
        <input name="email" type="email" value="<?= e($user['email'] ?? '') ?>" required maxlength="254" <?= $isEdit ? 'readonly' : '' ?>>
    </label>
    <label><span>رمز عبور<?= $isEdit ? ' (خالی بگذارید تا تغییر نکند)' : '' ?></span>
        <input name="password" type="password" <?= $isEdit ? '' : 'required' ?> minlength="8" maxlength="4096">
    </label>
    <label><span>نقش</span>
        <select name="role">
            <?php foreach (['admin' => 'مدیر', 'editor' => 'ویرایشگر', 'user' => 'کاربر'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= (($user['role'] ?? 'user') === $v) ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div>
        <button class="admin-button" type="submit">ذخیره</button>
        <a href="<?= e(url('/admin/users')) ?>">انصراف</a>
    </div>
</form>
