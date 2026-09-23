# Routeها — Phase 1 + Phase 3 + Phase 5 (سطح عمومی)

## Routeهای عمومی Phase 5

| Method | Path | خروجی | Status |
|--------|------|-------|--------|
| GET | `/` | صفحهٔ خانه (خبر اصلی، بخش‌ها، موضوعات) | 200 |
| GET | `/news` | فهرست خبرها + pagination | 200 |
| GET | `/news/{slug}` | جزئیات خبر منتشرشده | 200 / 404 |
| GET | `/articles` | فهرست مقالات | 200 |
| GET | `/articles/{slug}` | جزئیات مقاله | 200 / 404 |
| GET | `/reports` | فهرست گزارش‌ها | 200 |
| GET | `/reports/{slug}` | جزئیات گزارش + گالری | 200 / 404 |
| GET | `/events` | فهرست رویدادها | 200 |
| GET | `/events/{slug}` | جزئیات رویداد (زمان/مکان) | 200 / 404 |
| GET | `/topics/{slug}` | محتوای یک موضوع + pagination | 200 / 404 |
| GET | `/search?q=…` | جستجو (حداقل ۲ نویسه) | 200 |
| GET | `/sitemap.xml` | نقشهٔ سایت XML | 200 |
| GET | `/robots.txt` | robots (Disallow: /admin) | 200 |

> فقط محتوای `published` با `published_at <= now` در مسیرهای عمومی دیده می‌شود؛
> `draft`/`archived` از URL عمومی قابل دسترسی نیست (فیلتر در لایهٔ SQL).
> صفحات جستجو و ۴۰۴ با `noindex` علامت می‌خورند.

## Routeهای ثبت‌شده (فقط در `router.php`)

| Method | Path | خروجی | Status |
|--------|------|-------|--------|
| GET | `/` | `pages/home.php` | 200 |
| GET | `/login` | فرم login | 200 |
| POST | `/login` | login و redirect امن محلی | 303 / 422 / 403 |
| POST | `/logout` | حذف session و redirect خانه | 303 |
| GET | `/logout` | ثبت نشده؛ logout نمی‌کند | 404 |
| GET | `/admin` | داشبورد مدیریت (فقط admin) | 200 / 302 / 403 |
| — | هر مسیر نامشخص | `pages/404.php` | 404 |
| غیر-GET | مسیر شناخته‌شده (مثلاً `POST /`) | متن ساده | 405 |

`POST /login` و `POST /logout` قبل از هر تغییر state توکن CSRF session-backed را
بررسی می‌کنند. پیام خطای credentials برای unknown email، wrong password و
inactive account عمداً یکسان است. در Phase 4.1 فقط داشبورد `/admin` فعال است؛
بخش‌های تحریریه و CRUD هنوز route ندارند.

## قوانین سرو URL

| درخواست | رفتار Apache (Shared Hosting) | رفتار `php -S` (تست/لوکال) |
|---------|-------------------------------|-----------------------------|
| `/` | 200 — homepage | 200 — homepage |
| `/login` | 200 — login form | 200 — login form |
| `//` ، `////` | 200 — نرمال‌سازی به `/` | 200 |
| `/assets/*` | سرو مستقیم فایل | سرو از طریق index.php (فقط assets/uploads) |
| `/config/…` ، `/app/…` ، `/pages/…` ، `/tests/…` ، `/database/…` ، `/docs/…` ، `/logs/…` ، dotfiles | 403 (بلاک در .htaccess) | 404 (پاس‌فال‌ترینگ و روتر) |
| `/admin` | مهمان: redirect به login؛ user/editor: 403؛ admin: 200 | 404 (بدون front controller) |
| `…` (نامشخص) | 404 — صفحه‌ی 404 | 404 — صفحه‌ی 404 |

> تفاوت 403/404 برای پوشه‌های داخلی بین Apache و سرور داخلی PHP طبیعی است؛
> در هر دو حالت دسترسی **ممنوع** است. تست HTTP (php -S) مقدار 404 را بررسی می‌کند.

## Authentication guards

Routeهای آینده باید guard را در سمت سرور صدا بزنند:

```php
if (!requireAuth()) {
    // redirect to /login or return a 401/403 appropriate to the route
}
if (!requireRole('admin')) {
    // return 403; UI hiding is not authorization
}
```

`currentUser()`, `isAuthenticated()`, `requireGuest()`, `requireRole()` و
`authorize()` در `app/Middleware/AuthGuards.php` تعریف شده‌اند.
