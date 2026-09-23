<?php

declare(strict_types=1);

/**
 * Installer — جامة‌الهدی
 *
 * One-time setup script for shared hosting (InfinityFree).
 * After successful installation, it locks itself and cannot be re-run
 * without removing the lock file.
 *
 * Security:
 *  - No credentials are hardcoded or committed.
 *  - Passwords are hashed with password_hash().
 *  - CSRF protection on the form.
 *  - Lock file prevents re-installation.
 */

// ---------------------------------------------------------------------------
// Bootstrap (minimal — no full app yet)
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=UTF-8');

$basePath = __DIR__;

// ---------------------------------------------------------------------------
// Lock check
// ---------------------------------------------------------------------------
$lockFile = $basePath . '/config/.install_lock';

if (is_file($lockFile)) {
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب انجام شده</title>';
    echo '<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:Tahoma,sans-serif;background:#f6f7fb;color:#1c2333;display:flex;align-items:center;justify-content:center;min-height:100vh}.box{background:#fff;border:1px solid #e3e7f0;border-radius:16px;padding:40px;max-width:500px;text-align:center;box-shadow:0 8px 24px rgba(0,0,0,.06)}.box h1{color:#1a5c3a;margin-bottom:12px}.box p{color:#5b6478;margin-bottom:20px}.btn{display:inline-block;background:#1a5c3a;color:#fff;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700;margin:4px}.btn:hover{background:#0d3a22}</style>';
    echo '</head><body><div class="box"><h1>✅ نصب قبلاً انجام شده است</h1><p>سایت شما نصب شده و آماده استفاده است. برای نصب مجدد، فایل قفل را حذف کنید.</p>';
    echo '<a class="btn" href="' . htmlspecialchars(dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/') . '/">صفحه اصلی</a> ';
    echo '<a class="btn" href="' . htmlspecialchars(dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/') . '/admin">پنل مدیریت</a>';
    echo '</div></body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function install_e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function install_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_start();
    }
    if (empty($_SESSION['_install_csrf'])) {
        $_SESSION['_install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_install_csrf'];
}

function install_csrf_verify(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $expected = $_SESSION['_install_csrf'] ?? '';
    return is_string($token) && $token !== '' && hash_equals($expected, $token);
}

// ---------------------------------------------------------------------------
// CSS
// ---------------------------------------------------------------------------
$css = <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tahoma,"Segoe UI",sans-serif;background:#f6f7fb;color:#1c2333;line-height:1.8;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px}
.installer{background:#fff;border:1px solid #e3e7f0;border-radius:16px;padding:32px;max-width:600px;width:100%;box-shadow:0 8px 24px rgba(0,0,0,.06)}
.installer h1{font-size:1.6rem;margin-bottom:8px;color:#1a5c3a;text-align:center}
.installer .subtitle{color:#5b6478;text-align:center;margin-bottom:24px;font-size:.9rem}
.installer h2{font-size:1.1rem;margin:24px 0 12px;color:#1a5c3a;border-bottom:2px solid #e3e7f0;padding-bottom:8px}
.installer label{display:block;margin-bottom:14px;font-size:.88rem;color:#59657a}
.installer label span{display:block;margin-bottom:4px;font-weight:600}
.installer input,.installer select{width:100%;padding:10px 12px;border:1px solid #d6ddea;border-radius:8px;font:inherit;font-size:.92rem;background:#fff}
.installer input:focus,.installer select:focus{outline:3px solid #1a5c3a40;border-color:#1a5c3a}
.installer .btn{display:block;width:100%;padding:14px;background:#1a5c3a;color:#fff;border:0;border-radius:10px;font:inherit;font-size:1rem;font-weight:700;cursor:pointer;margin-top:20px}
.installer .btn:hover{background:#0d3a22}
.installer .btn:disabled{opacity:.6;cursor:not-allowed}
.error{color:#9b1c1c;background:#fff1f1;border:1px solid #e7aaaa;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.88rem}
.success{color:#1a5c3a;background:#e8f7ed;border:1px solid #a8d5b8;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.88rem}
.check-list{list-style:none;padding:0;margin:0 0 16px}
.check-list li{padding:6px 0;border-bottom:1px solid #f0f2f6;font-size:.88rem;display:flex;gap:8px;align-items:center}
.check-ok{color:#237744}.check-fail{color:#9b1c1c}.check-warn{color:#94651b}
.step-links{display:flex;gap:12px;margin-top:20px;justify-content:center;flex-wrap:wrap}
.step-links a{display:inline-block;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem}
.step-links .btn-primary{background:#1a5c3a;color:#fff}
.step-links .btn-primary:hover{background:#0d3a22}
.step-links .btn-ghost{background:transparent;color:#1a5c3a;border:1px solid #1a5c3a}
.step-links .btn-ghost:hover{background:#e8f7ed}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
@media(max-width:500px){.grid-2{grid-template-columns:1fr}}
.progress{display:flex;gap:4px;margin-bottom:24px}
.progress span{flex:1;height:4px;border-radius:2px;background:#e3e7f0}
.progress .done{background:#1a5c3a}
CSS;

// ---------------------------------------------------------------------------
// Process
// ---------------------------------------------------------------------------
$step = (string) ($_GET['step'] ?? 'check');
$errors = [];
$successes = [];
$dbConfig = [];

// --- STEP 1: Environment check ---
if ($step === 'check') {
    $checks = [];

    // PHP version
    $checks[] = [
        'label' => 'نسخهٔ PHP (≥ 8.1)',
        'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'detail' => PHP_VERSION,
    ];

    // PDO
    $checks[] = [
        'label' => 'افزونهٔ PDO',
        'ok' => extension_loaded('pdo'),
        'detail' => extension_loaded('pdo') ? 'فعال' : 'غیرفعال',
    ];

    // PDO MySQL
    $checks[] = [
        'label' => 'درایور PDO MySQL',
        'ok' => extension_loaded('pdo_mysql'),
        'detail' => extension_loaded('pdo_mysql') ? 'فعال' : 'غیرفعال',
    ];

    // JSON
    $checks[] = [
        'label' => 'افزونهٔ JSON',
        'ok' => extension_loaded('json'),
        'detail' => extension_loaded('json') ? 'فعال' : 'غیرفعال',
    ];

    // Mbstring
    $checks[] = [
        'label' => 'افزونهٔ mbstring',
        'ok' => extension_loaded('mbstring'),
        'detail' => extension_loaded('mbstring') ? 'فعال' : 'غیرفعال',
    ];

    // Fileinfo
    $checks[] = [
        'label' => 'افزونهٔ fileinfo',
        'ok' => extension_loaded('fileinfo'),
        'detail' => extension_loaded('fileinfo') ? 'فعال' : 'غیرفعال',
    ];

    // config/ writable
    $checks[] = [
        'label' => 'دسترسی نوشتن در config/',
        'ok' => is_writable($basePath . '/config'),
        'detail' => is_writable($basePath . '/config') ? 'بله' : 'خیر',
    ];

    // uploads/ writable
    $checks[] = [
        'label' => 'دسترسی نوشتن در uploads/',
        'ok' => is_writable($basePath . '/uploads'),
        'detail' => is_writable($basePath . '/uploads') ? 'بله' : 'خیر',
    ];

    // logs/ writable
    @mkdir($basePath . '/logs', 0775, true);
    $checks[] = [
        'label' => 'دسترسی نوشتن در logs/',
        'ok' => is_writable($basePath . '/logs'),
        'detail' => is_writable($basePath . '/logs') ? 'بله' : 'خیر',
    ];

    // schema.sql exists
    $checks[] = [
        'label' => 'فایل schema.sql',
        'ok' => is_file($basePath . '/database/schema.sql'),
        'detail' => is_file($basePath . '/database/schema.sql') ? 'موجود' : 'یافت نشد',
    ];

    $allOk = true;
    foreach ($checks as $c) {
        if (!$c['ok']) {
            $allOk = false;
        }
    }
} elseif ($step === 'database') {
    // --- STEP 2: Database form ---
    // Show the form; validation happens on POST
} elseif ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- STEP 3: Do the installation ---

    // CSRF
    $csrf = (string) ($_POST['_csrf'] ?? '');
    if (!install_csrf_verify($csrf)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً صفحه را رفرش کنید.';
        $step = 'database';
    } else {
        // Collect inputs
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $dbHost = trim((string) ($_POST['db_host'] ?? ''));
        $dbName = trim((string) ($_POST['db_name'] ?? ''));
        $dbUser = trim((string) ($_POST['db_user'] ?? ''));
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $dbPort = trim((string) ($_POST['db_port'] ?? '3306'));
        $adminName = trim((string) ($_POST['admin_name'] ?? ''));
        $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $adminPass = (string) ($_POST['admin_pass'] ?? '');
        $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');

        // Validate
        if ($siteName === '' || mb_strlen($siteName, 'UTF-8') > 160) {
            $errors[] = 'نام سایت الزامی است.';
        }
        if ($dbHost === '') {
            $errors[] = 'هاست پایگاه داده الزامی است.';
        }
        if ($dbName === '') {
            $errors[] = 'نام پایگاه داده الزامی است.';
        }
        if ($dbUser === '') {
            $errors[] = 'نام کاربری پایگاه داده الزامی است.';
        }
        if ($dbPort === '' || !ctype_digit($dbPort)) {
            $dbPort = '3306';
        }
        if ($adminName === '' || mb_strlen($adminName, 'UTF-8') > 160) {
            $errors[] = 'نام مدیر الزامی است.';
        }
        if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'ایمیل مدیر نامعتبر است.';
        }
        if (strlen($adminPass) < 8) {
            $errors[] = 'رمز مدیر باید حداقل ۸ نویسه باشد.';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = 'رمزهای عبور مطابقت ندارند.';
        }

        if ($errors === []) {
            // Test DB connection
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;charset=utf8mb4',
                    $dbHost,
                    $dbPort
                );
                $testPdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 10,
                ]);

                // Create database if not exists
                $dbNameSafe = preg_replace('/[^A-Za-z0-9_]/', '', $dbName);
                $testPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameSafe}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $testPdo->exec("USE `{$dbNameSafe}`");
            } catch (PDOException $e) {
                $errors[] = 'اتصال به پایگاه داده ناموفق بود. لطفاً اطلاعات را بررسی کنید.';
                $step = 'database';
            }

            if ($errors === []) {
                // Load schema helpers
                require_once $basePath . '/app/Helpers/functions.php';
                require_once $basePath . '/config/config.php';
                require_once $basePath . '/app/Helpers/schema.php';

                try {

                    $schemaPath = $basePath . '/database/schema.sql';
                    $sql = file_get_contents($schemaPath);
                    if ($sql === false) {
                        throw new RuntimeException('Cannot read schema.sql');
                    }

                    // Execute schema statements
                    $statements = sql_split_statements($sql);
                    foreach ($statements as $stmt) {
                        $testPdo->exec($stmt);
                    }

                    // Apply migrations
                    $migrationDir = $basePath . '/database/migrations';
                    if (is_dir($migrationDir)) {
                        $migrationFiles = glob($migrationDir . '/*.sql');
                        sort($migrationFiles);
                        foreach ($migrationFiles as $migrationFile) {
                            $migrationSql = file_get_contents($migrationFile);
                            if ($migrationSql !== false && trim($migrationSql) !== '') {
                                $migrationStmts = sql_split_statements($migrationSql);
                                foreach ($migrationStmts as $migStmt) {
                                    try {
                                        $testPdo->exec($migStmt);
                                    } catch (PDOException $e) {
                                        // Ignore "already exists" errors from re-running migrations
                                        $code = (string) $e->getCode();
                                        if ($code !== '42S01' && $code !== '23000' && strpos($e->getMessage(), 'Duplicate') === false) {
                                            log_error('Migration error in ' . basename($migrationFile) . ': ' . $e->getMessage());
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // Create admin user
                    $hash = password_hash($adminPass, PASSWORD_DEFAULT);

                    // Check if admin already exists
                    $existing = $testPdo->prepare("SELECT COUNT(*) FROM `users` WHERE `email` = ?");
                    $existing->execute([$adminEmail]);
                    if ((int) $existing->fetchColumn() > 0) {
                        // Update existing
                        $updateStmt = $testPdo->prepare(
                            "UPDATE `users` SET `name` = ?, `password_hash` = ?, `role` = 'admin', `is_active` = 1 WHERE `email` = ?"
                        );
                        $updateStmt->execute([$adminName, $hash, $adminEmail]);
                    } else {
                        $insertStmt = $testPdo->prepare(
                            "INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `is_active`) VALUES (?, ?, ?, 'admin', 1)"
                        );
                        $insertStmt->execute([$adminName, $adminEmail, $hash]);
                    }

                    // Verify tables exist
                    $requiredTables = ['topics', 'media', 'contents', 'events', 'reports', 'report_images', 'content_media', 'content_relations', 'books', 'lessons', 'research', 'users', 'login_attempts'];
                    $missingTables = [];
                    foreach ($requiredTables as $table) {
                        $check = $testPdo->prepare("SHOW TABLES LIKE ?");
                        $check->execute([$table]);
                        if ($check->fetch() === false) {
                            $missingTables[] = $table;
                        }
                    }

                    if ($missingTables !== []) {
                        $errors[] = 'جدول‌های زیر ایجاد نشدند: ' . implode('، ', $missingTables);
                        $step = 'database';
                    }

                    if ($errors === []) {
                        // Write config/local.php
                        $localConfig = sprintf(
                            '<?php' . "\n\n" . 'return [' . "\n"
                            . "    'app' => [\n"
                            . "        'name' => %s,\n"
                            . "        'environment' => 'production',\n"
                            . "    ],\n"
                            . "    'db' => [\n"
                            . "        'host' => %s,\n"
                            . "        'port' => %s,\n"
                            . "        'name' => %s,\n"
                            . "        'user' => %s,\n"
                            . "        'password' => %s,\n"
                            . "    ],\n"
                            . "];\n",
                            var_export($siteName, true),
                            var_export($dbHost, true),
                            var_export($dbPort, true),
                            var_export($dbName, true),
                            var_export($dbUser, true),
                            var_export($dbPass, true)
                        );

                        $configWritten = file_put_contents(
                            $basePath . '/config/local.php',
                            $localConfig,
                            LOCK_EX
                        ) !== false;

                        if (!$configWritten) {
                            $errors[] = 'فایل پیکربندی نوشته نشد. دسترسی config/ را بررسی کنید.';
                        } else {
                            // Write lock file
                            file_put_contents($lockFile, date('Y-m-d H:i:s') . "\n", LOCK_EX);

                            // Protect config
                            @chmod($basePath . '/config/local.php', 0640);

                            $step = 'done';
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = 'خطا در نصب پایگاه داده: ' . $e->getMessage();
                    $step = 'database';
                }
            }
        } else {
            $step = 'database';
        }
    }
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نصب جامة‌الهدی</title>
    <meta name="robots" content="noindex, nofollow">
    <style><?= $css ?></style>
</head>
<body>
<div class="installer">
    <h1>🕌 نصب جامة‌الهدی</h1>
    <p class="subtitle">نصب‌کنندهٔ خودکار وب‌سایت جامة‌الهدی</p>

    <div class="progress">
        <span class="<?= in_array($step, ['database', 'install', 'done']) ? 'done' : '' ?>"></span>
        <span class="<?= in_array($step, ['install', 'done']) ? 'done' : '' ?>"></span>
        <span class="<?= $step === 'done' ? 'done' : '' ?>"></span>
    </div>

    <?php if ($step === 'check'): ?>
        <h2>مرحلهٔ ۱: بررسی سیستم</h2>
        <ul class="check-list">
            <?php foreach ($checks as $c): ?>
                <li>
                    <span class="<?= $c['ok'] ? 'check-ok' : 'check-fail' ?>"><?= $c['ok'] ? '✅' : '❌' ?></span>
                    <span><?= install_e($c['label']) ?> — <strong><?= install_e($c['detail']) ?></strong></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($allOk): ?>
            <a class="btn" href="?step=database" style="display:block;text-align:center;text-decoration:none;color:#fff;background:#1a5c3a;padding:14px;border-radius:10px;font-weight:700;font-size:1rem">مرحلهٔ بعد: پیکربندی پایگاه داده</a>
        <?php else: ?>
            <p class="error">لطفاً مشکلات بالا را برطرف کنید و دوباره تلاش کنید.</p>
        <?php endif; ?>

    <?php elseif ($step === 'database'): ?>
        <h2>مرحلهٔ ۲: پیکربندی</h2>

        <?php if ($errors): ?>
            <div class="error" role="alert">
                <ul style="margin:0;padding-right:18px">
                    <?php foreach ($errors as $err): ?>
                        <li><?= install_e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="?step=install">
            <input type="hidden" name="_csrf" value="<?= install_e(install_csrf_token()) ?>">

            <h2>اطلاعات سایت</h2>
            <label><span>نام سایت</span>
                <input name="site_name" value="<?= install_e($_POST['site_name'] ?? 'جامة‌الهدی') ?>" required maxlength="160" placeholder="جامة‌الهدی">
            </label>

            <h2>پایگاه داده</h2>
            <div class="grid-2">
                <label><span>هاست</span>
                    <input name="db_host" value="<?= install_e($_POST['db_host'] ?? 'localhost') ?>" required placeholder="localhost">
                </label>
                <label><span>پورت</span>
                    <input name="db_port" value="<?= install_e($_POST['db_port'] ?? '3306') ?>" required pattern="\d+" placeholder="3306">
                </label>
            </div>
            <label><span>نام پایگاه داده</span>
                <input name="db_name" value="<?= install_e($_POST['db_name'] ?? '') ?>" required placeholder="نام دیتابیس">
            </label>
            <div class="grid-2">
                <label><span>نام کاربری</span>
                    <input name="db_user" value="<?= install_e($_POST['db_user'] ?? '') ?>" required>
                </label>
                <label><span>رمز عبور</span>
                    <input name="db_pass" type="password" value="">
                </label>
            </div>

            <h2>حساب مدیر</h2>
            <label><span>نام مدیر</span>
                <input name="admin_name" value="<?= install_e($_POST['admin_name'] ?? '') ?>" required maxlength="160">
            </label>
            <label><span>ایمیل مدیر</span>
                <input name="admin_email" type="email" value="<?= install_e($_POST['admin_email'] ?? '') ?>" required maxlength="254">
            </label>
            <div class="grid-2">
                <label><span>رمز مدیر</span>
                    <input name="admin_pass" type="password" required minlength="8" maxlength="4096">
                </label>
                <label><span>تکرار رمز</span>
                    <input name="admin_pass2" type="password" required minlength="8" maxlength="4096">
                </label>
            </div>

            <button class="btn" type="submit">نصب</button>
        </form>

    <?php elseif ($step === 'done'): ?>
        <h2 style="text-align:center;color:#1a5c3a">✅ نصب با موفقیت انجام شد</h2>
        <p class="success" style="text-align:center">Installation completed successfully</p>
        <p style="text-align:center;color:#5b6478;margin-bottom:8px">سایت جامة‌الهدی با موفقیت نصب شد. اکنون می‌توانید وارد پنل مدیریت شوید.</p>

        <?php
        $base = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        if ($base === '/' || $base === '\\') {
            $base = '';
        }
        ?>

        <div class="step-links">
            <a class="btn-primary" href="<?= install_e($base) ?>/admin">ورود به پنل مدیریت</a>
            <a class="btn-ghost" href="<?= install_e($base) ?>/">مشاهدهٔ سایت</a>
        </div>

        <p style="text-align:center;color:#94651b;font-size:.82rem;margin-top:20px">
            ⚠️ برای امنیت، فایل install.php را از سرور حذف کنید یا دسترسی آن را محدود کنید.
        </p>
    <?php endif; ?>
</div>
</body>
</html>
