# دیتابیس — Phase 2 + Phase 3

## اتصال

- تابع: `db()` در `config/database.php` — **یک اتصال PDO مشترک، lazy** (فقط در اولین استفاده).
- DSN: `db_dsn()` — از config ساخته می‌شود (برای تست جداگانه استخراج شده).
- `db_set_connection()` فقط برای تست است (جایگزینی اتصال با یک دیتابیس موقت)؛ در مسیر درخواست هرگز صدا زده نمی‌شود.

## امنیت

| مورد | مقدار |
|------|-------|
| `PDO::ATTR_ERRMODE` | `ERRMODE_EXCEPTION` |
| `PDO::ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` |
| `PDO::ATTR_EMULATE_PREPARES` | `false` (Prepared Statements واقعی) |
| کاراکترست | `utf8mb4` / `utf8mb4_unicode_ci` |
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

---

## نصب Schema

دیتابیس باید از قبل وجود داشته باشد (پنل هاست آن را می‌سازد):

```bash
mysql -u USER -p DATABASE < database/schema.sql
mysql -u USER -p DATABASE < database/seed.sql   # فقط توسعه/تست
```

- هدف: **MySQL 5.7+ / MariaDB 10.2+**، موتور **InnoDB**، کاراکترست **utf8mb4**.
- همه‌ی جدول‌ها با `CREATE TABLE IF NOT EXISTS` ساخته می‌شوند؛ اجرای دوباره‌ی فایل **بی‌خطر** است و هیچ داده‌ای حذف یا truncate نمی‌شود.
- `schema.sql` ابزار migration **نیست**: فقط جدول‌های نبوده را می‌سازد و جدول موجود را تغییر نمی‌دهد. تغییر ساختار یک دیتابیس نصب‌شده نیاز به migration plan مستند دارد.
- ترتیب ساخت جدول‌ها به‌گونه‌ای است که هر جدول بعد از جدول‌های مرجعش ساخته می‌شود؛ بنابراین Foreign Keyها بدون `SET FOREIGN_KEY_CHECKS=0` ساخته می‌شوند.

---

## ساختار (Phase 2)

جدول‌های احراز هویت Phase 3 در انتهای `schema.sql` به‌صورت additive اضافه
شده‌اند؛ نصب دوباره جدول‌های موجود را تغییر نمی‌دهد. برای دیتابیس production
قدیمی که فقط schema فاز ۲ را دارد، ساخت جدول‌های auth را با backup و در پنجره‌ی
نگهداری اجرا کنید.

```text
topics ──┐
         ├──> contents ──┬──> events          (1:1)
media ───┘               ├──> reports  (1:1) ──> report_images ──> media
                         ├──> content_media ──> media
                         └──> content_relations ──> contents
```

### `topics`
تاکسونومی مشترک همه‌ی محتواها.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `parent_id` | INT UNSIGNED NULL | خودارجاع؛ یک سطح زیرموضوع (FK → `topics.id`, ON DELETE SET NULL) |
| `slug` | VARCHAR(160) | **UNIQUE** |
| `title` | VARCHAR(160) | |
| `description` | VARCHAR(500) NULL | |
| `sort_order` | INT | ترتیب نمایش |
| `is_active` | TINYINT(1) | فعال/غیرفعال |
| `created_at` / `updated_at` | DATETIME | |

### `media`
رجیستری همه‌ی فایل‌های آپلودی.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `media_type` | ENUM(image, audio, video, document) | |
| `disk_path` | VARCHAR(255) | **UNIQUE** |
| `original_name`, `mime_type`, `file_size` | | متادیتای فایل |
| `title`, `alt_text` | | برای نمایش و دسترس‌پذیری |
| `duration_seconds` | INT UNSIGNED NULL | برای صوت/ویدیو |

> کلید **`UNIQUE (id, media_type)`** به جدول‌های فرزند اجازه می‌دهد با Foreign Key ترکیبی، نوع رسانه را هم الزام کنند.

### `contents`
ستون فقرات مشترک مقاله/خبر/رویداد/گزارش.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `content_type` | ENUM(article, news, event, report) | |
| `topic_id` | INT UNSIGNED NULL | FK → `topics.id`, ON DELETE SET NULL |
| `slug` | VARCHAR(190) | **UNIQUE با `content_type`** |
| `title` | VARCHAR(250) | |
| `summary` | VARCHAR(500) NULL | خلاصه |
| `body` | MEDIUMTEXT NULL | متن کامل |
| `cover_media_id` | BIGINT UNSIGNED NULL | FK → `media.id`, ON DELETE SET NULL |
| `status` | ENUM(draft, published, archived) | پیش‌فرض `draft` |
| `published_at` | DATETIME NULL | |
| `created_at` / `updated_at` | DATETIME | |

### `events` و `reports`
توسعه‌ی ۱:۱ روی `contents` (کلید اصلی = `content_id`).

- `events`: `starts_at`, `ends_at`, `location` + `CHECK (ends_at >= starts_at)`
- `reports`: `event_date`, `location`

هر دو با Foreign Key **ترکیبی** `(content_id, content_type)` به `contents (id, content_type)` وصل‌اند، بنابراین یک ردیف `events` هرگز نمی‌تواند به محتوایی از نوع دیگر بچسبد.

### `report_images`
گالری تصاویر یک گزارش (پشتیبانی از **چند تصویر**).

| ستون | توضیح |
|---|---|
| `report_id` | FK → `reports.content_id`, ON DELETE CASCADE |
| `media_id` + `media_type` | FK ترکیبی → `media (id, media_type)`, ON DELETE CASCADE |
| `caption`, `sort_order` | |
| | **UNIQUE (report_id, media_id)** |

### `content_media`
اتصال رسانه (صوت/ویدیو/سند/تصویر اضافی) به هر محتوا.

| ستون | توضیح |
|---|---|
| `content_id`, `media_id` | کلید اصلی مرکب (= قانون یکتایی) |
| `role` | ENUM(attachment, audio, video, document) |
| `sort_order` | |

### `content_relations`
محتوای مرتبط (جهت‌دار) با ترتیب دلخواه سردبیر.

| ستون | توضیح |
|---|---|
| `content_id`, `related_content_id` | کلید اصلی مرکب؛ هر دو FK → `contents.id` ON DELETE CASCADE |
| `sort_order` | |
| | `CHECK (content_id <> related_content_id)` |

---

## Indexها

| جدول | Index | چرا |
|---|---|---|
| `topics` | `uq_topics_slug` (UNIQUE), `idx_topics_parent`, `idx_topics_active_order` | آدرس عمومی، درخت موضوع، فهرست فعال |
| `media` | `uq_media_disk_path` (UNIQUE), `uq_media_id_type` (UNIQUE), `idx_media_type_created` | جلوگیری از فایل تکراری، FK ترکیبی، فهرست بر اساس نوع |
| `contents` | `uq_contents_type_slug` (UNIQUE), `uq_contents_id_type` (UNIQUE), `idx_contents_listing`, `idx_contents_status_published`, `idx_contents_topic`, `idx_contents_slug`, `idx_contents_cover` | آدرس عمومی، فهرست‌های «منتشرشده‌ی هر نوع»، فیلتر موضوع |
| `events` | `idx_events_starts_at` | رویدادهای پیش‌رو/گذشته |
| `reports` | `idx_reports_event_date` | ترتیب زمانی گزارش‌ها |
| `report_images` | `uq_report_images_report_media` (UNIQUE), `idx_report_images_order`, `idx_report_images_media` | گالری مرتب، بدون تصویر تکراری |
| `content_media` | PK(content_id, media_id), `idx_content_media_media`, `idx_content_media_order` | رسانه‌های یک محتوا |
| `content_relations` | PK(content_id, related_content_id), `idx_content_relations_related`, `idx_content_relations_order` | محتوای مرتبط |

## رفتار ON DELETE / ON UPDATE

| رابطه | ON DELETE | دلیل |
|---|---|---|
| `contents.topic_id` → `topics` | SET NULL | حذف موضوع نباید محتوا را حذف کند |
| `contents.cover_media_id` → `media` | SET NULL | حذف تصویر جلد نباید مقاله را حذف کند |
| `topics.parent_id` → `topics` | SET NULL | زیرموضوع‌ها به ریشه منتقل می‌شوند |
| `events`/`reports` → `contents` | CASCADE | ردیف توسعه بدون والد بی‌معنی است |
| `report_images` → `reports` / `media` | CASCADE | جلوگیری از رکورد یتیم در گالری |
| `content_media` → `contents` / `media` | CASCADE | لینک بدون دو سرش بی‌معنی است |
| `content_relations` → `contents` (هر دو سمت) | CASCADE | لینک بدون مقصد بی‌معنی است |

همه‌ی Foreign Keyها `ON UPDATE CASCADE` دارند.

---

## تصمیم‌های معماری

### ۱. یک جدول `contents` به‌جای چهار جدول جدا
مقاله، خبر، رویداد و گزارش ستون‌های مشترک زیادی دارند (slug، عنوان، خلاصه، متن، وضعیت، تاریخ انتشار، موضوع، جلد). با یک جدول مشترک:

- رسانه‌ها، محتوای مرتبط و جست‌وجو روی **یک Foreign Key واقعی** کار می‌کنند، نه روی کلید polymorphic که دیتابیس نمی‌تواند تضمینش کند؛
- افزودن نوع محتوای جدید فقط یک مقدار در ENUM است، نه چهار جدول و چهار جدول واسط.

ستون‌های اختصاصی هر نوع در جدول‌های ۱:۱ (`events`, `reports`) می‌مانند تا `contents` پر از ستون NULL نشود.

### ۲. صوت و ویدیو موجودیت مستقل نیستند
طبق Blueprint، صوت/ویدیو «محتوای مرتبط و زمینه‌ای» هستند. بنابراین در `media` ذخیره و از طریق `content_media` به محتوای والد وصل می‌شوند؛ نه جدول مستقل دارند و نه بخش مستقل در صفحه‌ی اصلی.

### ۳. Foreign Key ترکیبی برای الزام نوع
`events`/`reports` با `(content_id, content_type)` و `report_images` با `(media_id, media_type)` وصل می‌شوند. این کار باعث می‌شود «رویدادی که در واقع مقاله است» یا «فایل صوتی داخل گالری تصاویر» در سطح **دیتابیس** غیرممکن باشد، نه فقط در سطح PHP.

### ۴. `CHECK` + اعتبارسنجی در PHP
`CHECK` فقط در MySQL 8.0.16+ و MariaDB 10.2+ اجرا می‌شود و نسخه‌های قدیمی‌تر آن را نادیده می‌گیرند. بنابراین همان قوانین (خودارجاعی محتوای مرتبط، نوع رسانه‌ی گالری، ترتیب تاریخ رویداد) در Repository هم بررسی می‌شوند.

### ۵. `published_at` به‌جای `NOW()` با پارامتر bind می‌شود
مقایسه‌ی زمان انتشار با `date('Y-m-d H:i:s')` انجام می‌شود تا **تایم‌زون برنامه** (`app.timezone`) ملاک باشد، نه تایم‌زون سرور دیتابیس.

---

## جدول‌های احراز هویت (Phase 3)

### `users`

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | شناسه‌ی کاربر |
| `name` | VARCHAR(160) | نام نمایشی؛ UTF-8/فارسی |
| `email` | VARCHAR(254) UNIQUE | شناسه‌ی ورود، normalize شده با trim/lowercase |
| `password_hash` | VARCHAR(255) | فقط خروجی `password_hash()`؛ plaintext هرگز ذخیره نمی‌شود |
| `role` | VARCHAR(32) | `admin`, `editor`, `user`; برای توسعه‌ی policy از ENUM استفاده نشده |
| `is_active` | TINYINT(1) | حساب غیرفعال اجازه‌ی ورود یا authorization ندارد |
| `created_at`, `updated_at` | DATETIME | timestampهای ساخت/تغییر |
| `last_login_at` | DATETIME NULL | آخرین ورود موفق |

روی email unique و روی `(role, is_active)` و `last_login_at` index وجود دارد.
بررسی role و همه‌ی اعتبارسنجی‌های ورودی در `UserRepository` نیز تکرار می‌شود؛
CHECK دیتابیس روی سرورهایی که آن را enforce می‌کنند لایه‌ی اضافی است.

### `login_attempts`

یک ردیف aggregate برای fingerprint هش‌شده‌ی email/IP نگه می‌دارد: `failed_count`,
`first_attempt_at`, `last_attempt_at`. password، session ID و CSRF token در آن
وجود ندارد. `LoginRateLimiter` در پنجره‌ی ۱۵ دقیقه‌ای پس از ۵ شکست، login را
برای همان fingerprint رد می‌کند و ورود موفق ردیف را پاک می‌کند. این راهکار فقط
به PDO/MySQL و session استاندارد PHP نیاز دارد و برای shared hosting قابل حمل
است. پاک‌سازی دوره‌ای ردیف‌های قدیمی می‌تواند در maintenance آینده اضافه شود؛
کد هنگام ثبت شکست، ردیف‌های قدیمی‌تر از دو پنجره را opportunistically پاک می‌کند؛
در Phase 3 هیچ cron یا سرویس خارجی لازم نیست.

## Data Layer

### Helperها (`config/database.php`)

| تابع | کار |
|---|---|
| `db_run($sql, $params)` | prepare + execute |
| `db_all` / `db_one` / `db_value` | خواندن چند ردیف / یک ردیف / یک مقدار |
| `db_execute` | نوشتن؛ تعداد ردیف‌های تأثیرگرفته |
| `db_insert($table, $data)` | INSERT از map و برگرداندن id |
| `db_update($table, $data, $where)` | UPDATE با شرط برابری |
| `db_delete($table, $where)` | DELETE (بدون شرط اجرا نمی‌شود) |
| `db_transaction($callback)` | commit در موفقیت، rollback در استثنا (nested-safe) |
| `db_assert_identifiers()` | whitelist نام جدول/ستون |

### Repositoryها (`app/Repositories/`)

| کلاس | مسئولیت |
|---|---|
| `BaseRepository` | `find`, `count`, `delete`, clamp کردن limit/offset، whitelist ستون مرتب‌سازی |
| `ContentRepository` | CRUD روی `contents` + انتشار/پیش‌نویس + محتوای مرتبط |
| `TopicRepository` | موضوع‌ها و زیرموضوع‌ها |
| `MediaRepository` | رجیستری رسانه + اتصال/قطع اتصال به محتوا |
| `ReportRepository` | گزارش + گالری چندتصویری (`syncImages` داخل transaction) |
| `EventRepository` | رویداد + فهرست پیش‌رو/گذشته |
| `UserRepository` | ساخت/بازیابی کاربر، یکتایی email، hash گذرواژه و وضعیت active |

### قوانین الزامی

1. **همیشه Prepared Statement**؛ هیچ مقداری داخل متن SQL درج نمی‌شود.
2. نام جدول/ستون هرگز از ورودی کاربر نمی‌آید (و با `db_assert_identifiers` محافظت می‌شود).
3. `LIMIT`/`OFFSET` به int تبدیل و clamp می‌شوند.
4. هر نوشتن روی بیش از یک جدول داخل `db_transaction` انجام می‌شود.
5. مقادیر ENUM (نوع محتوا، وضعیت و نوع رسانه) و role قبل از رسیدن به SQL
   اعتبارسنجی می‌شوند.

---

## داده‌ی آزمایشی (`seed.sql`)

> **فقط test/development — در production اجرا نشود.**

حداقل داده برای آزمودن روابط: یک موضوع + یک زیرموضوع، یک مقاله‌ی منتشرشده، یک پیش‌نویس، یک خبر، یک رویداد، یک گزارش با **دو تصویر**، یک صوت متصل به مقاله، یک ویدیو متصل به خبر و یک رابطه‌ی «محتوای مرتبط».

همه‌ی ردیف‌ها با پیشوند `test-` در slug یا مسیر `dev/` در `disk_path` قابل تشخیص‌اند. دستور پاک‌سازی در انتهای `seed.sql` مستند شده است.

## وضعیت تست

- `tests/integration/SchemaTest.php` — پارس، نصب، جدول‌ها، FKها، Indexها، UNIQUEها، idempotent بودن
- `tests/integration/DataLayerTest.php` — CRUD، روابط، slug، draft/published، transaction، فارسی
- `tests/integration/SeedTest.php` — نصب seed و درستی روابطش
- `tests/security/SqlInjectionTest.php` — مقاومت در برابر SQL injection
- `tests/security/DatabaseSecurityTest.php` — عدم نشت credential در خطاها
- `tests/audit/check_schema.php` — بررسی ایستای SQL بدون نیاز به سرور دیتابیس

## ملاحظات نصب و production

- `seed.sql` عمداً هیچ کاربر یا passwordی ایجاد نمی‌کند. کاربر واقعی باید با
  مسیر امن provisioning خارج از Git ساخته شود؛ password production در repository
  یا مستندات قرار نمی‌گیرد.
- تغییر schema روی نصب موجود migration خودکار نیست. قبل از release، وجود
  `users` و `login_attempts` را با backup و یک migration کنترل‌شده بررسی کنید.
- تست‌های auth در SQLite in-memory fallback اجرا می‌شوند اگر MySQL/MariaDB
  در دسترس نباشد؛ این fallback جایگزین runtime test روی MySQL production نیست.
