# Routeها — Phase 1 + Phase 3 + Phase 5 + Phase 6 (سطح عمومی)

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

## Routeهای عمومی و مدیریتی Phase 6 (کتاب‌ها، درس‌ها، پژوهش‌ها، رسانه)

| Method | Path | خروجی | Status |
|--------|------|-------|--------|
| GET | `/books` | فهرست کتاب‌ها + جستجوی عنوان + فیلتر موضوع + pagination | 200 |
| GET | `/books/{slug}` | جزئیات کتاب + محتوای مرتبط | 200 / 404 |
| GET | `/lessons` | درس‌ها به ترتیب `sort_order` + فیلتر موضوع | 200 |
| GET | `/lessons/{slug}` | جزئیات درس + ویدیو/صوت + درس‌های مرتبط | 200 / 404 |
| GET | `/research` | فهرست پژوهش‌ها + جستجو + فیلتر موضوع | 200 |
| GET | `/research/{slug}` | صفحهٔ مطالعهٔ پژوهش (بدنهٔ بلند) | 200 / 404 |
| GET | `/media` | مرکز رسانه + فیلتر نوع (`?type=video|audio`) + pagination | 200 |
| GET | `/admin/books` ، `/admin/lessons` ، `/admin/research` | فهرست مدیریتی (admin) | 200 / 302 / 403 |
| GET | `/admin/{section}/new` | فرم ایجاد (admin) | 200 / 302 / 403 |
| POST | `/admin/{section}/new` | ایجاد + redirect | 303 / 200 (خطا) / 403 |
| GET | `/admin/{section}/edit/{id}` | فرم ویرایش (admin) | 200 / 302 / 403 / 404 |
| POST | `/admin/{section}/edit/{id}` | ویرایش + redirect | 303 / 200 (خطا) / 403 / 404 |
| POST | `/admin/{section}/delete` | حذف (cascade ردیف‌های الحاقی) | 303 / 403 / 404 |

قواعد Phase 6:

> - فقط ردیف‌های `published` با `published_at <= now` در مسیرهای عمومی دیده می‌شوند
>   (فیلتر در لایهٔ SQL؛ `draft`/`archived` از URL عمومی ۴۰۴ می‌گیرند).
> - درسی که `requires_login = 1` است برای مهمان «قفل» است: بدنه و رسانه‌ها
>   اصلاً از سمت سرور ارسال نمی‌شوند؛ فقط خلاصه + پنل ورود نمایش داده می‌شود.
> - `/media` رسانه‌های درس‌های قفل‌شده را از مهمان‌ها پنهان می‌کند.
> - فهرست‌های عمومی و `/media` و `sitemap.xml` در خرابی موقت دیتابیس به
>   حالت خالی (200) تنزل می‌کنند — مانند صفحهٔ خانه.
> - صفحات Phase 6 از همان الگوی SEO فاز ۵ استفاده می‌کنند: title/description،
>   canonical، Open Graph و JSON-LD (`Book`, `LearningResource`, `ScholarlyArticle`).
> - مسیرهای مدیریت محتوا/رسانه/موضوعات برای `editor` و `admin` هستند؛ مدیریت کاربران فقط برای `admin` است و همهٔ تغییرات توکن CSRF دارند.

## Routeهای ثبت‌شده (فقط در `router.php`)

| Method | Path | خروجی | Status |
|--------|------|-------|--------|
| GET | `/` | `pages/home.php` | 200 |
| GET | `/login` | فرم login | 200 |
| POST | `/login` | login و redirect امن محلی | 303 / 422 / 403 |
| POST | `/logout` | حذف session و redirect خانه | 303 |
| GET | `/logout` | ثبت نشده؛ logout نمی‌کند | 404 |
| GET | `/admin` | داشبورد مدیریت (`editor`/`admin`) | 200 / 302 / 403 |
| GET | `/admin/content` | مدیریت همهٔ محتوا، فیلتر و عملیات انتشار/بایگانی/حذف | 200 / 302 / 403 |
| GET/POST | `/admin/media` | کتابخانه رسانه و آپلود امن | 200 / 303 / 403 / 422 |
| GET/POST | `/admin/topics` | مدیریت موضوعات | 200 / 303 / 403 / 422 |
| GET/POST | `/admin/users` | مدیریت کاربران (فقط `admin`) | 200 / 303 / 403 |
| — | هر مسیر نامشخص | `pages/404.php` | 404 |
| غیر-GET | مسیر شناخته‌شده (مثلاً `POST /`) | متن ساده | 405 |

`POST /login` و `POST /logout` قبل از هر تغییر state توکن CSRF session-backed را
بررسی می‌کنند. پیام خطای credentials برای unknown email، wrong password و
inactive account عمداً یکسان است. مسیرهای CRUD مدیریت محتوا، دانش، رسانه، موضوعات و کاربران در همین Route table ثبت شده‌اند.

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

## مرجع به‌روز Admin و Branding (2026-09-23)

این جدول بر توضیحات تاریخی فازهای بالا اولویت دارد. تمام مسیرها در `router.php` هستند؛ تمام POSTهای مدیریتی CSRF دارند. مهمان به login هدایت می‌شود، نقش غیرمجاز 403 می‌گیرد.

| Method | Path | نقش |
|---|---|---|
| GET | `/admin` | editor/admin |
| GET | `/admin/content` | editor/admin |
| GET | `/admin/news`, `/admin/articles`, `/admin/reports`, `/admin/events` | editor/admin |
| GET/POST | `/admin/content/new` | editor/admin |
| GET/POST | `/admin/content/edit/{id}` | editor/admin |
| GET | `/admin/content/{id}` | redirect به ویرایش |
| POST | `/admin/content/{id}/publish`, `/unpublish`, `/archive`, `/delete`, `/relation` | editor/admin |
| GET/POST | `/admin/media` | editor/admin |
| POST | `/admin/media/{id}/delete` | editor/admin |
| GET/POST | `/admin/topics` | editor/admin |
| POST | `/admin/topics/{id}/edit`, `/admin/topics/{id}/delete` | editor/admin |
| GET | `/admin/users` | فقط admin؛ POST این مسیر ثبت نشده |
| GET/POST | `/admin/users/new`, `/admin/users/edit/{id}` | فقط admin |
| POST | `/admin/users/{id}/delete` | فقط admin |
| GET | `/admin/{section}` | editor/admin؛ section: books/lessons/research |
| GET/POST | `/admin/{section}/new`, `/admin/{section}/edit/{id}` | editor/admin |
| GET | `/admin/{section}/{id}` | redirect به ویرایش |
| POST | `/admin/{section}/{id}/publish`, `/unpublish`, `/archive`, `/delete` | editor/admin |
| GET | `/admin/settings` | فقط admin؛ فرم و پیش‌نمایش |
| POST | `/admin/settings` | فقط admin؛ ذخیره نام/توضیح/سه تصویر و بازگردانی؛ 303 موفق، 422 ورودی، 403 CSRF، 503 ذخیره |
| POST | `/admin/settings/upgrade` | فقط admin؛ ارتقای صریح با runner نصب؛ 303 موفق، 403 CSRF، 503 خطا |

`GET /admin/topics/{id}/edit` فرم جدا نیست؛ ویرایش موضوع داخل فهرست است و این URL فقط POST دارد. هیچ عملیات delete/reset/publish با GET انجام نمی‌شود.

فرم generic محتوا برای کتاب/درس/پژوهش به فرم تخصصی موجود هدایت می‌شود. نام و نقش admin برای تنظیمات و مدیریت کاربران علاوه بر session با رکورد فعال DB بررسی می‌شود تا session قدیمی پس از تنزل/حذف حساب مجوز نداشته باشد.

تمام صفحات عمومی شامل Home، News، Articles، Reports، Events، Books، Lessons، Research، Media، Topics، Search، Login، 404 و 500 از layout مشترک و تنظیمات برند استفاده می‌کنند. Installer قبل از نصب عمداً تصویر ثابت مخزن را دارد. OG اختصاصی مطلب اولویت دارد؛ در نبود آن تصویر OG تنظیمات و سپس fallback استفاده می‌شود. مسیرها با helperهای subdirectory ساخته می‌شوند؛ robots نیز prefix نصب را رعایت می‌کند.
