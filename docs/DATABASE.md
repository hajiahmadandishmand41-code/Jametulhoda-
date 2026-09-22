# دیتابیس — Phase 1

## اتصال

- تابع: `db()` در `config/database.php` — **یک اتصال PDO مشترک، lazy** (فقط در اولین استفاده).
- DSN: `db_dsn()` — از config ساخته می‌شود (برای تست جداگانه استخراج شده).

## امنیت

| مورد | مقدار |
|------|-------|
| `PDO::ATTR_ERRMODE` | `ERRMODE_EXCEPTION` |
| `PDO::ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` |
| `PDO::ATTR_EMULATE_PREPARES` | `false` (Prepared Statements واقعی) |
| کاراکترست | `utf8mb4` |
| خطای اتصال | لاگ در `logs/error.log` بدون نشت DSN/رمز؛ پیام عمومی به کاربر |

## تنظیمات محیطی

کپی `config/local.example.php` → `config/local.php` (خارج از Git):

```php
return [
    'app' => ['environment' => 'development'],
    'db'  => [
        'host' => 'localhost',
        'name' => 'your_database',
        'user' => 'your_user',
        'password' => 'your_password',
    ],
];
```

## وضعیت Phase 1

- هیچ جدولی هنوز وجود ندارد؛ `schema.sql` و `seed.sql` placeholder هستند.
- جدول‌های محتوا در Phase 2 و جدول‌های کاربری در Phase 4 اضافه می‌شوند.
- تک اعتبارسنجی ساختاری Phase 1: `SELECT 1` + بررسی گزینه‌های امنیتی PDO (در `tests/integration/DatabaseTest.php`).
