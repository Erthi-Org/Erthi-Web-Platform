# إرثي | Erthi

الموقع الرسمي لمبادرة إرثي ومشاريعها الإسلامية والتعليمية مفتوحة المصدر. صفحات عربية وإنجليزية، ومعرض مشاريع، ونماذج تواصل وتطوع، ولوحة لإدارة الموقع.

## التشغيل المحلي

المتطلبات PHP 8.4 مع GD وWebP وPDO، وComposer 2، وNode.js 24.

```sh
composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan serve
```

افتح `/ar` أو `/en`. لإنشاء حساب مالك استخدم `php artisan erthi:owner admin@erthi.org` وأدخل كلمة المرور في الطلب المخفي. الإدارة على `/admin`. يستطيع المالك إنشاء محررين وإيقاف وصولهم. يستطيع المحرر إدارة محتوى الموقع وطلبات الزوار.

```sh
npm run check
npm run build
php artisan test
vendor/bin/pint --test
composer audit --locked
npm audit --audit-level=moderate
```

## النشر على cPanel

للتثبيت الجديد من المتصفح راجع [دليل مثبت إرثي](INSTALL-AR.md). حزمة التثبيت تتضمن ملفات الواجهة واعتماديات الإنتاج ومفتاح تفعيل منفصلًا لكل حزمة.

- خصّص لـ `erthi.org` مجلد تطبيق وقاعدة MySQL أو MariaDB ومستخدم قاعدة بيانات مستقلين. اضبط Document Root على مجلد `public/` في تطبيق إرثي.
- أنشئ ملف بيئة خاصًا بإرثي واضبط `APP_ENV=production` و`APP_DEBUG=false` و`APP_URL=https://erthi.org` و`SESSION_SECURE_COOKIE=true`. اترك `SESSION_DOMAIN` بقيمته الافتراضية حتى يبقى الكوكي خاصًا بالمضيف.
- نفّذ `composer install --no-dev --optimize-autoloader` و`npm ci` و`npm run build`، ثم `php artisan key:generate` **مرة واحدة فقط عند التثبيت الجديد** و`php artisan migrate --force` و`php artisan optimize`. احتفظ بالمفتاح عند التحديث.
- امنح عملية PHP الكتابة في `storage/` و`bootstrap/cache/`، واحتفظ بنسخ احتياطية لقاعدة البيانات والوسائط، وجرّب استعادتها في بيئة معزولة. اضبط `upload_max_filesize` على 4 MiB على الأقل و`post_max_size` أعلى منه.
- افحص HTTPS والدخول وحفظ نموذجين تجريبيين على نسخة ما قبل الإنتاج. بعد الموافقة على الإطلاق، اضبط `ERTHI_INDEXING=true` وأعد بناء ذاكرة الإعدادات لتفعيل الفهرسة وخريطة الموقع.

استُلهم تنظيم المسارات وأقسام الإدارة من بنية Nexvary. تطبيق إرثي مستقل ويستخدم بياناته وإعداداته الخاصة.

راجع [الهوية ومصادر الأيقونات](docs/brand/README.md) و[ترخيص الكود](LICENSE).
