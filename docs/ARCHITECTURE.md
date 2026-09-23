# معماری — Phase 1 (هسته) + Phase 2 (داده) + Phase 3 (احراز هویت)

سایت فارسی، RTL، بدون فریمورک؛ با PHP خام برای Shared Hosting / InfinityFree.

## جریان درخواست

```text
Browser
  → .htaccess (mod_rewrite)
      · بلاک پوشه‌های داخلی (config, app, views, pages, tests, database, docs, logs) → 403
      · فایل‌های واقعی (assets, uploads) مستقیم سرو می‌شوند
      · بقیه → index.php
  → index.php (Front Controller)
      1. بارگذاری: Helpers → Config → Router → Database → Auth services
      2. مدیریت فایل‌های استاتیک (فقط assets/uploads — صرفاً برای php -S)
      3. Router::dispatch() → route table در router.php
  → pages/<page>.php  +  views/layouts/main.php  →  HTML

احراز هویت فقط از مسیر مرکزی `SessionManager`، `AuthService` و
`AuthGuards` عبور می‌کند؛ صفحه‌ها مستقیماً `session_start()` یا SQL اجرا نمی‌کنند.
```

## نقش پوشه‌ها

| پوشه | نقش |
|---|---|
| `index.php` / `router.php` | نقطه‌ی ورود و جدول routeها |
| `config/` | تنظیمات (پایه + `local.php` خارج از Git) و اتصال DB |
| `app/Helpers/` | توابع مشترک (`e`, `url`, `asset`, `view`, `log_error`, ...) |
| `app/Router.php` | کلاس Router |
| `app/Repositories/` | لایه‌ی داده‌ی Phase 2 و `UserRepository` برای احراز هویت |
| `app/Services/` | `SessionManager`, `Csrf`, `AuthService`, `LoginRateLimiter` |
| `app/Middleware/` | نگهبان‌های سبک و مستقل `AuthGuards` |
| `app/Controllers, Models` | برای فازهای بعدی؛ در Phase 3 ساخته نمی‌شوند |
| `pages/` | نمای هر صفحه (فقط HTML — بدون `<html>`) |
| `views/layouts/` | Layout اصلی |
| `views/components, partials` | آماده برای فازهای بعدی |
| `assets/` | css/js/img/fonts |
| `uploads/` | رسانه‌های آپلودی (Phase 3) — اجرای کد در آن ممنوع |
| `database/` | `schema.sql` (ساختار Phase 2 + جدول‌های auth) و `seed.sql` (داده‌ی آزمایشی؛ بدون کاربر) |
| `admin/` | نقطه ورود Apache برای پنل؛ درخواست‌ها به front controller می‌روند |
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

## احراز هویت و authorization (Phase 3)

- `GET /login` فرم ورود را نشان می‌دهد؛ `POST /login` فقط ایمیل و گذرواژه را
  از طریق `AuthService` و `UserRepository` بررسی می‌کند.
- پاسخ شکست ورود برای ایمیل ناشناخته، گذرواژه‌ی غلط و حساب غیرفعال یکسان است.
  پس از موفقیت، قبل از ذخیره‌ی snapshot کاربر، `session_regenerate_id(true)` اجرا
  می‌شود. snapshot شامل password hash نیست.
- `POST /logout` تنها مسیر خروج است و GET برای خروج ثبت نشده است. logout توکن
  CSRF را بررسی، session cookie را حذف و session را destroy می‌کند.
- `AuthGuards` توابع `currentUser()`, `isAuthenticated()`, `requireAuth()`,
  `requireGuest()`, `requireRole()` و `authorize()` را ارائه می‌کند. این‌ها
  boolean برمی‌گردانند تا هر route پاسخ مناسب خود را بدهد؛ الزام همیشه باید
  در سمت سرور و قبل از عمل محافظت‌شده انجام شود.
- نقش‌های پایه: `user`, `editor`, `admin`. سطح بالاتر، دسترسی سطح پایین‌تر
  را نیز دارد؛ policy قابل توسعه در `AuthGuards` متمرکز است.
- شروع session فقط در `SessionManager` انجام می‌شود: cookie-only، strict mode،
  HttpOnly، SameSite=Lax و Secure در HTTPS. session ID هرگز در URL قرار نمی‌گیرد.
- CSRF از `random_bytes(32)` توکن جداگانه‌ی session-backed می‌سازد؛ مقایسه با
  `hash_equals()` انجام می‌شود و توکن پس از login/موارد لازم rotate می‌شود.
- `LoginRateLimiter` با جدول aggregate `login_attempts`، تلاش‌های ناموفق یک
  email/IP را برای پنجره‌ی محدود نگه می‌دارد و به سرویس بیرونی وابسته نیست.

## پنل مدیریت — Phase 4.1

`GET /admin` تنها route فعال این مرحله است و با `requireRole('admin')` محافظت می‌شود.
داشبورد آمار را فقط از Repositoryها می‌خواند؛ هیچ SQL در view نیست. منوی بخش‌های
آینده عمداً غیرفعال است و route جعلی ندارد. خروج همچنان فقط `POST /logout` با CSRF است.

## افزودن صفحه جدید (فازهای بعدی)

1. فایل `pages/<name>.php` بسازید (فقط محتوای body).
2. route را در `router.php` ثبت کنید.
3. فایل را در `tests/` پوشش دهید.

## خطاها و لاگ

- **production:** خطاها در `logs/error.log` ثبت می‌شوند و به کاربر پیام عمومی 500 داده می‌شود.
- **development** (`local.php` → `environment: development`): استک خطا نمایش داده می‌شود (فقط محلی).

## امنیت پایه (Phase 1 + Phase 3)

- .htaccess: بلاک پوشه‌های داخلی، Headers امنیتی، بلاک‌لیست
- uploads: اجرای کد غیرمجاز
- PDO: `ERRMODE_EXCEPTION` + `EMULATE_PREPARES=false` + `utf8mb4`
- passwordها فقط با `password_hash()` ذخیره و با `password_verify()` بررسی می‌شوند.
- ورودی‌ها با prepared statement، CSRF، محدودیت طول و redirect محلی کنترل می‌شوند.
- لاگ بدون password، password hash، session ID، CSRF token یا credential دیتابیس.
