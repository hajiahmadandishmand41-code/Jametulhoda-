<?php

declare(strict_types=1);

/** @var array<string,mixed> $item */
/** @var string $action */
/** @var list<string> $errors */

$isEdit = isset($item['id']) && (int) $item['id'] > 0;
$errors = $errors ?? [];
$roleLabels = ['admin' => 'مدیر', 'editor' => 'ویرایشگر', 'user' => 'کاربر'];
?>
<section class="admin-welcome">
    <div>
        <p class="admin-kicker">مدیریت کاربران</p>
        <h2><?= $isEdit ? 'ویرایش کاربر' : 'کاربر جدید' ?></h2>
    </div>
    <a class="admin-button" href="<?= e(url('/admin/users')) ?>">بازگشت به کاربران</a>
</section>

<?php if ($errors): ?>
<div class="form-errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="admin-form" method="post" action="<?= e($action) ?>" novalidate>
    <?= csrf_field() ?>
    <label>نام<input name="name" value="<?= e((string) ($item['name'] ?? '')) ?>" maxlength="160" required></label>
    <label>ایمیل<input name="email" type="email" dir="ltr" value="<?= e((string) ($item['email'] ?? '')) ?>" maxlength="254" required></label>
    <label>نقش
        <select name="role">
            <?php foreach ($roleLabels as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= (string) ($item['role'] ?? 'user') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= !isset($item['is_active']) || (int) $item['is_active'] === 1 ? 'checked' : '' ?>> حساب فعال باشد</label>
    <label><?= $isEdit ? 'رمز جدید (در صورت تغییر)' : 'رمز عبور' ?><input name="password" type="password" minlength="10" maxlength="4096" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>></label>
    <label>تکرار رمز<input name="password_confirmation" type="password" minlength="10" maxlength="4096" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>></label>
    <div><button class="admin-button" type="submit">ذخیره</button> <a href="<?= e(url('/admin/users')) ?>">انصراف</a></div>
</form>
