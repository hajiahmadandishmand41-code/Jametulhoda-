# Routeها — Phase 1 + Phase 3

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
