# معماری — Phase 1 (هسته) + Phase 2 (داده)

سایت فارسی، RTL، بدون فریمورک؛ با PHP خام برای Shared Hosting / InfinityFree.

## جریان درخواست

```text
Browser
  → .htaccess (mod_rewrite)
      · بلاک پوشه‌های داخلی (config, app, views, pages, tests, database, docs, logs) → 403
      · فایل‌های واقعی (assets, uploads) مستقیم سرو می‌شوند
      · بقیه → index.php
  → index.php (Front Controller)
      1. بارگذاری: Helpers → Config → Router → Database
      2. مدیریت فایل‌های استاتیک (فقط assets/uploads — صرفاً برای php -S)
      3. Router::dispatch() → route table در router.php
  → pages/<page>.php  +  views/layouts/main.php  →  HTML
```

## نقش پوشه‌ها

| پوشه | نقش |
|---|---|
| `index.php` / `router.php` | نقطه‌ی ورود و جدول routeها |
| `config/` | تنظیمات (پایه + `local.php` خارج از Git) و اتصال DB |
| `app/Helpers/` | توابع مشترک (`e`, `url`, `asset`, `view`, `log_error`, ...) |
| `app/Router.php` | کلاس Router |
| `app/Repositories/` | لایه‌ی داده‌ی Phase 2 (Content, Topic, Media, Report, Event + Base) |
| `app/Controllers, Models, Services, Middleware` | آماده برای فازهای بعدی (هنوز خالی) |
| `pages/` | نمای هر صفحه (فقط HTML — بدون `<html>`) |
| `views/layouts/` | Layout اصلی |
| `views/components, partials` | آماده برای فازهای بعدی |
| `assets/` | css/js/img/fonts |
| `uploads/` | رسانه‌های آپلودی (Phase 3) — اجرای کد در آن ممنوع |
| `database/` | `schema.sql` (ساختار واقعی Phase 2) و `seed.sql` (داده‌ی آزمایشی) |
| `admin/` | پنل مدیریت (Phase 4) — فعلاً `Require all denied` |
| `tests/` | تست‌های خودکار + چک‌لیست دستی |
| `docs/` | مستندات |
| `logs/` | لاگ خطا (از وب بلاک) |

## قوانین معماری

1. **تک نقطه‌ی ورود:** فقط `index.php`؛ همه URLها از Router می‌گذرند.
2. **Escaping:** هر خروجی پویا با `e()` escape می‌شود (XSS baseline).
3. **URLها:** فقط با `url()` / `asset()` ساخته می‌شوند (یک منبع حقیقت).
4. **دیتابیس:** فقط با `db()` و Prepared Statements؛ اتصال lazy است.
   دسترسی به داده از طریق helperهای `db_*()` و Repositoryها انجام می‌شود؛
   هیچ مقداری داخل متن SQL درج نمی‌شود و نام جدول/ستون از ورودی کاربر نمی‌آید
   (جزئیات در `docs/DATABASE.md`).
5. **`dispatch()` آخرین statement از `index.php` است.**
6. **Layout:** `views/layouts/main.php` مالک `<html>…<body>` است؛ صفحات فقط محتوا می‌دهند.

## افزودن صفحه جدید (فازهای بعدی)

1. فایل `pages/<name>.php` بسازید (فقط محتوای body).
2. route را در `router.php` ثبت کنید.
3. فایل را در `tests/` پوشش دهید.

## خطاها و لاگ

- **production:** خطاها در `logs/error.log` ثبت می‌شوند و به کاربر پیام عمومی 500 داده می‌شود.
- **development** (`local.php` → `environment: development`): استک خطا نمایش داده می‌شود (فقط محلی).

## امنیت پایه (Phase 1)

- .htaccess: بلاک پوشه‌های داخلی، Headers امنیتی، بلاک‌لیست
- uploads: اجرای کد غیرمجاز
- PDO: `ERRMODE_EXCEPTION` + `EMULATE_PREPARES=false` + `utf8mb4`
- لاگ بدون نشت اطلاعات حساس
