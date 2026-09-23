# تست مرورگری — Phase 3

این چک‌لیست فقط foundation احراز هویت را پوشش می‌دهد؛ dashboard و CRUD در این
فاز عمداً ساخته نشده‌اند.

## پیش‌نیاز

- Apache یا `php -S 0.0.0.0:8080 index.php`
- schema.sql نصب شده و یک کاربر توسعه‌ای خارج از repository ساخته شده است.
- credential واقعی در Git، seed.sql یا این فایل قرار نگیرد.

## Guest / login

- [ ] `GET /login` با status 200 باز می‌شود.
- [ ] فرم email، password و hidden CSRF token دارد.
- [ ] email ناشناخته، password غلط و حساب inactive پیام یکسان و غیر افشاگر
      نشان می‌دهند.
- [ ] طول خالی، malformed و بیش از حد مجاز بدون خطای PHP رد می‌شوند.
- [ ] با credential صحیح login موفق است و به redirect محلی می‌رود.
- [ ] query مانند `redirect=https://example.invalid` هرگز به دامنه‌ی خارجی
      redirect نمی‌کند.
- [ ] پس از refresh، session authenticated باقی می‌ماند.

## CSRF / logout / authorization

- [ ] POST login بدون یا با CSRF غلط رد می‌شود.
- [ ] logout فقط با POST فرم انجام می‌شود؛ `GET /logout` logout نمی‌کند.
- [ ] پس از logout، session cookie/authentication از بین می‌رود.
- [ ] route محافظت‌شده بدون session با guard رد می‌شود.
- [ ] editor به admin-only authorization دسترسی ندارد و admin اجازه‌ی سطح
      پایین‌تر را دارد.
- [ ] cookie در HTTPS دارای Secure، HttpOnly و SameSite=Lax است؛ در HTTP لوکال
      Secure خاموش است تا توسعه نشکند.

## Regression

- [ ] homepage و assetهای Phase 1/2 بدون login همچنان کار می‌کنند.
- [ ] مسیرهای `/admin` همچنان تا Phase 4 مسدود هستند.
