# تست — Phase 1

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
├── integration/     # رندر واقعی routeها + اتصال DB
├── security/        # بررسی‌های امنیتی پایه
├── browser/         # چک‌لیست دستی مرورگر
└── audit/           # اسکریپت‌های چک 1,3,4,5,6
```

## قرارداد نوشتن تست جدید

1. فایل: `tests/<group>/<Name>Test.php` با کلاس هم‌نام.
2. متدهای تست با پیشوند `test` (public).
3. از `TestCase` (tests/lib) برای assertها استفاده کنید.
4. تست نباید به شبکه/سرور خارجی وابسته باشد؛ برای DB فقط local.php.

## پیش‌نیاز

- PHP 8.1+ (تست روی 8.4 انجام شده) با `pdo_mysql` و `mbstring`
- برای HTTP test: `curl`
- برای تست DB: یک MySQL/MariaDB محلی مطابق `config/local.php` (اختیاری —
  بدون DB، مسیر خطای امن تست می‌شود)
