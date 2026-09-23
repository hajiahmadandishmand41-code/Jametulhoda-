# تست — Phase 2

## اجرای همه‌ی چک‌ها

```bash
bash tests/run_all.sh
```

یا تک‌تک:

| # | چک | دستور |
|---|-----|-------|
| 1 | Syntax check (همه‌ی فایل‌های PHP) | `bash tests/audit/check_syntax.sh` |
| 2 | Route test (رندر واقعی routeها از طریق Router) | `php tests/run.php` |
| 3 | HTTP test (سرور داخلی PHP + curl) | `bash tests/audit/http_test.sh` |
| 4 | بررسی include/require | `php tests/audit/check_includes.php` |
| 5 | بررسی لینک‌ها (خروجی رندرشده + سورس) | `php tests/audit/check_links.php` |
| 6 | بررسی ساختار فایل‌ها | `php tests/audit/check_structure.php` |
| 7 | Apache / .htaccess test (اختیاری) | `bash tests/audit/apache_test.sh` |
| 8 | بررسی ایستای Schema (SQL) | `php tests/audit/check_schema.php` |

> مرحله‌ی 7 فقط وقتی Apache برای همین سایت در دسترس باشد اجرا می‌شود
> (`APACHE_URL=http://127.0.0.1:8088` پیش‌فرض)؛ در غیر این صورت SKIPPED
> اعلام می‌شود و روی نتیجه‌ی کل تأثیری ندارد.

`exit code 0` = سبز. قانون: تا همه سبز نباشند، Phase بعد شروع نمی‌شود.

## ساختار تست‌ها

```text
tests/
├── run.php          # اجرای سویت‌ها (unit + integration + security)
├── run_all.sh       # اجرای همه‌ی چک‌ها (شامل 6 مرحله)
├── bootstrap.php    # بارگذاری هسته‌ی برنامه بدون dispatch
├── lib/             # هارنس تست (بدون وابستگی خارجی)
├── unit/            # Router، Helpers، Config
├── integration/     # routeها + اتصال DB + Schema/DataLayer/Seed
├── security/        # امنیت پایه + SQL injection + امنیت دیتابیس
├── browser/         # چک‌لیست دستی مرورگر
├── lib/             # هارنس تست + SchemaSandbox
└── audit/           # اسکریپت‌های چک 1,3,4,5,6,8
```

### تست‌های دیتابیس (Phase 2)

| فایل | پوشش |
|---|---|
| `integration/SchemaTest.php` | پارس و نصب `schema.sql`، وجود جدول‌ها/FK/Index/UNIQUE، idempotent بودن |
| `integration/DataLayerTest.php` | Insert/Select/Update/Delete، گزارش↔تصاویر، محتوا↔رسانه، محتوای مرتبط، یکتایی slug، draft/published، transaction، متن فارسی |
| `integration/SeedTest.php` | نصب `seed.sql` و درستی روابط داده‌ی آزمایشی |
| `security/SqlInjectionTest.php` | بی‌اثر بودن payloadها + بررسی سورس برای interpolation ناامن |
| `security/DatabaseSecurityTest.php` | عدم نشت credential/DSN در خطاها |
| `audit/check_schema.php` | بررسی ایستای SQL بدون نیاز به سرور دیتابیس |

#### بک‌اند اجرای تست‌های دیتابیس

`tests/lib/SchemaSandbox.php` همان `database/schema.sql` را نصب می‌کند و به‌صورت خودکار یکی از دو مسیر را انتخاب می‌کند:

1. **MySQL/MariaDB** (ترجیحی) — وقتی `config/local.php` به یک سرور در دسترس اشاره کند؛ Schema **بدون هیچ تغییری** اجرا می‌شود.
2. **SQLite in-memory** (fallback) — وقتی MySQL در دسترس نباشد؛ همان فایل با یک ترجمه‌ی صریح و محدود اجرا می‌شود (`SchemaSandbox::translate`). SQLite با `PRAGMA foreign_keys=ON` کلیدهای خارجی، UNIQUE، NOT NULL، CHECK و CASCADE را واقعاً اعمال می‌کند، پس تست‌های رابطه‌ای معنادار می‌مانند.

> نکته: مسیر fallback عمداً محدود است و `ENGINE`/`CHARSET` را نمی‌سنجد؛ آن موارد در `SchemaTest` و `check_schema.php` روی **متن خود فایل** بررسی می‌شوند تا در هر دو حالت پوشش داده شوند.
> برای اطمینان کامل از رفتار production، تست‌ها را یک‌بار با MySQL واقعی هم اجرا کنید.

## قرارداد نوشتن تست جدید

1. فایل: `tests/<group>/<Name>Test.php` با کلاس هم‌نام.
2. متدهای تست با پیشوند `test` (public).
3. از `TestCase` (tests/lib) برای assertها استفاده کنید.
4. تست نباید به شبکه/سرور خارجی وابسته باشد؛ برای DB فقط local.php.

## پیش‌نیاز

- PHP 8.1+ (تست روی 8.4 انجام شده) با `pdo_mysql` و `mbstring`
- برای HTTP test: `curl`
- برای تست DB: یک MySQL/MariaDB محلی مطابق `config/local.php`
  (اختیاری — بدون آن، تست‌های دیتابیس روی SQLite in-memory اجرا می‌شوند
  و مسیر خطای امن اتصال هم تست می‌شود)
