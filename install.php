<?php

declare(strict_types=1);

/**
 * Browser installer for shared hosting (Apache + PHP + MySQL).
 *
 * This file intentionally has no external dependency and never hardcodes
 * credentials. Successful installation writes the user-provided credentials to
 * config/local.php (git-ignored) and creates config/installed.lock (git-ignored)
 * to prevent accidental reinstallation.
 */

require __DIR__ . '/config/config.php';
require __DIR__ . '/app/Helpers/functions.php';
require __DIR__ . '/app/Helpers/schema.php';
require __DIR__ . '/app/Helpers/migrations.php';
ini_set('display_errors', '0');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('jametulhoda_install');
    session_start();
}
if (empty($_SESSION['install_csrf']) || !is_string($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$lockFile = __DIR__ . '/config/installed.lock';
$localConfigFile = __DIR__ . '/config/local.php';
$errors = [];
$steps = [];
$installed = false;

/** @return array<string,string> */
function installer_old_input(): array
{
    return [
        'site_name' => trim((string) ($_POST['site_name'] ?? Config::get('app.name', 'جامة‌الهدی'))),
        'db_host' => trim((string) ($_POST['db_host'] ?? 'sql304.infinityfree.com')),
        'db_name' => trim((string) ($_POST['db_name'] ?? 'if0_42959770_jametulhoda')),
        'db_user' => trim((string) ($_POST['db_user'] ?? 'if0_42959770')),
        'db_port' => trim((string) ($_POST['db_port'] ?? '3306')),
        'admin_name' => trim((string) ($_POST['admin_name'] ?? 'Haji')),
        'admin_email' => trim((string) ($_POST['admin_email'] ?? 'hajiahmads299@gmail.com')),
    ];
}

/** @return list<array{label:string,ok:bool,message:string}> */
function installer_requirements(): array
{
    $checks = [];
    $checks[] = [
        'label' => 'PHP 8.3+',
        'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'message' => 'نسخه فعلی: ' . PHP_VERSION,
    ];
    foreach (['pdo' => 'PDO', 'pdo_mysql' => 'PDO MySQL', 'mbstring' => 'mbstring', 'fileinfo' => 'fileinfo', 'session' => 'Session', 'json' => 'JSON'] as $extension => $label) {
        $checks[] = [
            'label' => $label,
            'ok' => extension_loaded($extension),
            'message' => extension_loaded($extension) ? 'فعال است' : 'فعال نیست',
        ];
    }
    $checks[] = [
        'label' => 'قابلیت نوشتن config',
        'ok' => is_writable(__DIR__ . '/config'),
        'message' => is_writable(__DIR__ . '/config') ? 'قابل نوشتن' : 'پوشه config قابل نوشتن نیست',
    ];
    $checks[] = [
        'label' => 'Schema SQL',
        'ok' => is_file(__DIR__ . '/database/schema.sql'),
        'message' => is_file(__DIR__ . '/database/schema.sql') ? 'یافت شد' : 'database/schema.sql وجود ندارد',
    ];

    $checks[] = [
        'label' => 'ذخیره تصاویر هویت سایت',
        'ok' => is_writable(__DIR__ . '/uploads/site') && is_file(__DIR__ . '/uploads/site/.htaccess'),
        'message' => 'پوشه uploads/site باید قابل نوشتن و دارای .htaccess باشد.',
    ];

    return $checks;
}

function installer_requirements_ok(): bool
{
    foreach (installer_requirements() as $check) {
        if (!$check['ok']) {
            return false;
        }
    }

    return true;
}

function installer_safe_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $candidate = str_replace('T', ' ', $value);
    if (strlen($candidate) === 16) {
        $candidate .= ':00';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $candidate);

    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

/** @param array<string,string> $input @return list<string> */
function installer_validate_input(array $input): array
{
    $errors = [];
    if ($input['site_name'] === '' || mb_strlen($input['site_name'], 'UTF-8') > 160) {
        $errors[] = 'نام سایت الزامی است و حداکثر ۱۶۰ نویسه می‌تواند باشد.';
    }
    if ($input['db_host'] === '' || strlen($input['db_host']) > 255) {
        $errors[] = 'Database Host معتبر نیست.';
    }
    if ($input['db_name'] === '' || strlen($input['db_name']) > 128) {
        $errors[] = 'Database Name معتبر نیست.';
    }
    if ($input['db_user'] === '' || strlen($input['db_user']) > 128) {
        $errors[] = 'Database User معتبر نیست.';
    }
    if (!ctype_digit($input['db_port']) || (int) $input['db_port'] < 1 || (int) $input['db_port'] > 65535) {
        $errors[] = 'Database Port باید عددی بین ۱ و ۶۵۵۳۵ باشد.';
    }
    if ($input['admin_name'] === '' || mb_strlen($input['admin_name'], 'UTF-8') > 160) {
        $errors[] = 'نام مدیر الزامی است و حداکثر ۱۶۰ نویسه می‌تواند باشد.';
    }
    if (!filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL) || strlen($input['admin_email']) > 254) {
        $errors[] = 'ایمیل مدیر معتبر نیست.';
    }

    $password = (string) ($_POST['admin_password'] ?? '');
    $passwordConfirmation = (string) ($_POST['admin_password_confirmation'] ?? '');
    if (strlen($password) < 10 || strlen($password) > 4096) {
        $errors[] = 'رمز مدیر باید حداقل ۱۰ نویسه باشد.';
    }
    if ($password !== $passwordConfirmation) {
        $errors[] = 'رمز مدیر و تکرار آن یکسان نیست.';
    }

    return $errors;
}

function installer_connect(array $input, string $password): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $input['db_host'],
        (int) $input['db_port'],
        $input['db_name']
    );

    $pdo = new PDO($dsn, $input['db_user'], $password, $options);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

    return $pdo;
}

/** @param array<string,string> $input */
function installer_write_local_config(array $input, string $dbPassword, string $path): void
{
    $config = [
        'app' => [
            'name' => $input['site_name'],
            'environment' => 'production',
            'url' => 'https://jametulhoda.gt.tc' . site_base_path(),
            'timezone' => 'Asia/Kabul',
        ],
        'db' => [
            'host' => $input['db_host'],
            'port' => $input['db_port'],
            'name' => $input['db_name'],
            'user' => $input['db_user'],
            'password' => $dbPassword,
            'charset' => 'utf8mb4',
        ],
    ];

    $content = "<?php\n\ndeclare(strict_types=1);\n\n"
        . "/**\n * Local installation config generated by install.php.\n * Contains database credentials; this file is git-ignored.\n */\n\n"
        . 'return ' . var_export($config, true) . ";\n";

    if (@file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('نوشتن config/local.php ممکن نشد. دسترسی نوشتن پوشه config را بررسی کنید.');
    }
    @chmod($path, 0640);
}

/** @return list<string> */
function installer_verify_schema(PDO $pdo): array
{
    $errors = [];
    $requiredTables = [
        'users', 'contents', 'topics', 'media', 'books', 'lessons', 'research',
        'reports', 'events', 'report_images', 'content_media', 'content_relations',
        'login_attempts', 'schema_migrations', 'site_settings',
    ];
    foreach ($requiredTables as $table) {
        if (!installer_table_exists($pdo, $table)) {
            $errors[] = 'جدول ضروری پیدا نشد: ' . $table;
        }
    }

    $columnChecks = [
        ['users', 'password_hash'], ['users', 'role'], ['contents', 'content_type'],
        ['contents', 'status'], ['contents', 'cover_media_id'], ['media', 'disk_path'],
        ['topics', 'slug'], ['login_attempts', 'fingerprint'],
        ['site_settings', 'setting_key'], ['site_settings', 'setting_value'],
    ];
    foreach ($columnChecks as [$table, $column]) {
        if (installer_column_type($pdo, $table, $column) === '') {
            $errors[] = 'ستون ضروری پیدا نشد: ' . $table . '.' . $column;
        }
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL");
    if ((int) $stmt->fetchColumn() < 8) {
        $errors[] = 'Foreign Keyهای اصلی دیتابیس کامل نصب نشده‌اند.';
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME IN ('uq_users_email','uq_contents_type_slug','uq_topics_slug','uq_media_disk_path')");
    if ((int) $stmt->fetchColumn() < 4) {
        $errors[] = 'Unique constraintهای اصلی دیتابیس کامل نصب نشده‌اند.';
    }

    return $errors;
}

function installer_create_admin(PDO $pdo, array $input, string $password): int
{
    $email = strtolower($input['admin_email']);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `users` WHERE `email` = ?');
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new RuntimeException('یک کاربر با ایمیل مدیر واردشده از قبل وجود دارد. برای جلوگیری از بازنویسی، نصب متوقف شد.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('ساخت رمز عبور امن ممکن نشد.');
    }

    $stmt = $pdo->prepare('INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `is_active`) VALUES (?, ?, ?, ?, 1)');
    $stmt->execute([$input['admin_name'], $email, $hash, 'admin']);

    return (int) $pdo->lastInsertId();
}

function installer_write_lock(string $path, string $siteName): void
{
    $payload = json_encode([
        'installed_at' => date(DATE_ATOM),
        'site' => $siteName,
        'version' => '2026-09-23',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if (!is_string($payload) || @file_put_contents($path, $payload . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('نصب انجام شد اما ساخت فایل قفل ممکن نشد. دسترسی نوشتن config را بررسی کنید.');
    }
    @chmod($path, 0640);
}

$old = installer_old_input();

if (is_file($lockFile)) {
    $locked = true;
} else {
    $locked = false;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $old = installer_old_input();
        $submittedToken = is_string($_POST['install_csrf'] ?? null) ? (string) $_POST['install_csrf'] : '';
        if (!hash_equals((string) $_SESSION['install_csrf'], $submittedToken)) {
            $errors[] = 'درخواست نصب معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
        }
        if (is_file($localConfigFile) && empty($_POST['confirm_overwrite'])) {
            $errors[] = 'config/local.php از قبل وجود دارد. برای ادامه باید گزینهٔ تأیید بازنویسی را فعال کنید.';
        }
        if (!installer_requirements_ok()) {
            $errors[] = 'پیش‌نیازهای نصب کامل نیستند.';
        } else {
            $errors = array_merge($errors, installer_validate_input($old));
        }

        if ($errors === []) {
            $dbPassword = (string) ($_POST['db_password'] ?? '');
            $adminPassword = (string) ($_POST['admin_password'] ?? '');
            $stage = 'اتصال MySQL';
            try {
                $pdo = installer_connect($old, $dbPassword);
                $steps[] = 'اتصال MySQL با موفقیت برقرار شد.';

                $stage = 'نصب Schema';
                $schemaStatements = schema_install($pdo, 'schema.sql');
                $steps[] = 'Schema اصلی نصب/بررسی شد (' . $schemaStatements . ' statement).';

                $stage = 'اجرای Migration';
                $migrationResult = installer_apply_migrations($pdo);
                $steps[] = 'Migrationها بررسی شدند: اجرا ' . $migrationResult['executed'] . '، ثبت ' . $migrationResult['recorded'] . '، عبور ' . $migrationResult['skipped'] . '.';

                $stage = 'اعتبارسنجی Schema';
                $schemaErrors = installer_verify_schema($pdo);
                if ($schemaErrors !== []) {
                    throw new RuntimeException(implode(' ', $schemaErrors));
                }
                $steps[] = 'جدول‌ها، ستون‌ها، Foreign Keyها و Unique Indexهای اصلی تأیید شدند.';

                $stage = 'نوشتن تنظیمات محلی';
                installer_write_local_config($old, $dbPassword, $localConfigFile);
                $steps[] = 'config/local.php ساخته شد.';

                $stage = 'ایجاد مدیر';
                installer_create_admin($pdo, $old, $adminPassword);
                $steps[] = 'حساب مدیر اولیه با password_hash() ساخته شد.';

                $stage = 'ساخت قفل نصب';
                installer_write_lock($lockFile, $old['site_name']);
                $steps[] = 'Installer قفل شد و نصب مجدد بدون حذف قفل ممکن نیست.';
                $installed = true;
            } catch (Throwable $e) {
                // Driver/exception text may contain user names, SQL values and secrets.
                // Display only allowlisted numeric diagnostics and our own stage label.
                $diagnostic = '';
                if ($e instanceof PDOException) {
                    $state = (string) $e->getCode();
                    $number = $e->errorInfo[1] ?? null;
                    if (preg_match('/\A[A-Z0-9]{5}\z/', $state)) {
                        $diagnostic .= ' SQLSTATE: ' . $state;
                    }
                    if (is_int($number)) {
                        $diagnostic .= ' MySQL: ' . $number;
                    }
                }
                $errors[] = 'نصب در مرحله «' . $stage . '» متوقف شد.' . $diagnostic
                    . ' اتصال، مجوزهای دیتابیس و دسترسی نوشتن پوشه‌ها را بررسی کنید. رمزها و متن خام خطا نمایش داده نمی‌شوند.';
            }
        }
    }
}

$requirements = installer_requirements();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب <?= e((string) Config::get('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/main.css')) ?>">
</head>
<body class="install-page">
<main class="install-shell" id="main">
    <section class="install-card" aria-labelledby="install-title">
        <div class="install-brand">
            <img src="<?= e(asset('img/logo.svg')) ?>" alt="نشان <?= e((string) Config::get('app.name')) ?>" width="72" height="72">
            <div>
                <p class="admin-kicker">راه‌اندازی اولیه</p>
                <h1 id="install-title">نصب وب‌سایت <?= e((string) Config::get('app.name')) ?></h1>
            </div>
        </div>

        <?php if ($locked): ?>
            <div class="install-success" role="status">
                <h2>Installer قفل است</h2>
                <p>این سایت قبلاً نصب شده است. برای ارتقای نسخه از تنظیمات سایت در پنل مدیر استفاده کنید؛ قفل نصب را حذف نکنید.</p>
                <div class="install-actions">
                    <a class="btn" href="<?= e(url('/login')) ?>">ورود</a>
                    <a class="btn btn-ghost" href="<?= e(url('/admin')) ?>">پنل مدیریت</a>
                </div>
            </div>
        <?php elseif ($installed): ?>
            <div class="install-success" role="status">
                <h2>Installation completed successfully</h2>
                <ol class="install-steps">
                    <?php foreach ($steps as $step): ?><li><?= e($step) ?></li><?php endforeach; ?>
                </ol>
                <div class="install-actions">
                    <a class="btn" href="<?= e(url('/login')) ?>">ورود به سایت</a>
                    <a class="btn btn-ghost" href="<?= e(url('/admin')) ?>">رفتن به پنل مدیریت</a>
                </div>
            </div>
        <?php else: ?>
            <p class="install-intro">اطلاعات دیتابیس و مدیر اولیه را وارد کنید. رمزها و اطلاعات دیتابیس در Repository ذخیره نمی‌شوند.</p>

            <section class="install-requirements" aria-label="پیش‌نیازها">
                <?php foreach ($requirements as $check): ?>
                    <div class="requirement <?= $check['ok'] ? 'is-ok' : 'is-bad' ?>">
                        <strong><?= e($check['label']) ?></strong>
                        <span><?= e($check['message']) ?></span>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php if ($errors !== []): ?>
                <div class="form-errors" role="alert">
                    <h2>نصب انجام نشد</h2>
                    <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form class="install-form" method="post" action="<?= e(url('/install.php')) ?>" autocomplete="off">
                <input type="hidden" name="install_csrf" value="<?= e((string) $_SESSION['install_csrf']) ?>">
                <?php if (is_file($localConfigFile)): ?>
                    <label class="checkbox-label install-confirm"><input type="checkbox" name="confirm_overwrite" value="1"> config/local.php موجود است؛ بازنویسی آگاهانه را تأیید می‌کنم.</label>
                <?php endif; ?>
                <fieldset>
                    <legend>اطلاعات سایت</legend>
                    <label>نام سایت<input name="site_name" required maxlength="160" value="<?= e($old['site_name']) ?>"></label>
                </fieldset>

                <fieldset>
                    <legend>Database</legend>
                    <div class="install-grid">
                        <label>Database Host<input name="db_host" required maxlength="255" value="<?= e($old['db_host']) ?>" dir="ltr"></label>
                        <label>Database Name<input name="db_name" required maxlength="128" value="<?= e($old['db_name']) ?>" dir="ltr"></label>
                        <label>Database User<input name="db_user" required maxlength="128" value="<?= e($old['db_user']) ?>" dir="ltr"></label>
                        <label>Database Password<input name="db_password" type="password" autocomplete="new-password" dir="ltr"></label>
                        <label>Database Port<input name="db_port" required inputmode="numeric" value="<?= e($old['db_port']) ?>" dir="ltr"></label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>مدیر اولیه</legend>
                    <div class="install-grid">
                        <label>نام مدیر<input name="admin_name" required maxlength="160" value="<?= e($old['admin_name']) ?>"></label>
                        <label>ایمیل مدیر<input name="admin_email" type="email" required maxlength="254" value="<?= e($old['admin_email']) ?>" dir="ltr"></label>
                        <label>رمز مدیر<input name="admin_password" type="password" required minlength="10" maxlength="4096" autocomplete="new-password"></label>
                        <label>تکرار رمز مدیر<input name="admin_password_confirmation" type="password" required minlength="10" maxlength="4096" autocomplete="new-password"></label>
                    </div>
                </fieldset>

                <button class="btn" type="submit">شروع نصب امن</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
