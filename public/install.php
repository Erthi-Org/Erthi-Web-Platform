<?php

declare(strict_types=1);

ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$root = dirname(__DIR__);
$keyFile = $root.'/SETUP-KEY.txt';
if (! is_file($keyFile) || is_file($root.'/.env')) {
    http_response_code(404);
    exit('Not found');
}
require $root.'/installer/Setup.php';
$https = isset($_SERVER['HTTPS']) && in_array(strtolower($_SERVER['HTTPS']), ['on', '1'], true);
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$checks = [
    'اتصال HTTPS' => $https,
    'جذر الموقع يشير إلى مجلد public' => $documentRoot === realpath(__DIR__),
    'PHP 8.4 أو أحدث' => PHP_VERSION_ID >= 80400,
    'امتدادات PHP وGD وWebP' => function_exists('imagewebp') && ! array_filter(
        ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pdo_mysql', 'session', 'tokenizer', 'xml'],
        fn ($extension) => ! extension_loaded($extension)
    ),
    'ملفات التطبيق والواجهة' => is_file($root.'/vendor/autoload.php') && is_file(__DIR__.'/build/manifest.json'),
    'صلاحيات الكتابة' => is_writable($root) && is_writable($root.'/storage') && is_writable($root.'/bootstrap/cache'),
    'جذر تطبيق جديد' => ! is_file($root.'/bootstrap/cache/config.php') && ! is_file($root.'/bootstrap/cache/routes-v7.php'),
];
$ready = ! in_array(false, $checks, true);
$auto = ErthiSetup::automaticAvailable();
$account = $auto && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
$error = $success = null;
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$input = static fn (string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lock = null;
    $created = [];
    try {
        if (! $ready) {
            throw new RuntimeException('أكمل متطلبات الاستضافة أولًا.');
        }
        if (! hash_equals(trim((string) file_get_contents($keyFile)), trim($input('setup_key')))) {
            http_response_code(403);
            throw new RuntimeException('مفتاح التثبيت غير صحيح.');
        }
        $permissions = umask(0077);
        $lock = fopen($root.'/storage/.setup.lock', 'c');
        umask($permissions);
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB) || is_file($root.'/.env') || ! is_file($keyFile)) {
            throw new RuntimeException('عملية تثبيت أخرى قيد التنفيذ أو أن التطبيق مثبّت بالفعل.');
        }

        $email = strtolower(trim($input('owner_email')));
        $password = $input('owner_password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
            ! preg_match('/\A[^\x00]{14,72}\z/', $password) || $password !== $input('owner_confirmation')) {
            throw new RuntimeException('أدخل بريد المالك وكلمة مرور مطابقة من 14 إلى 72 بايت.');
        }
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $host)) {
            throw new RuntimeException('افتح المعالج من النطاق النهائي عبر HTTPS.');
        }
        $base = 'https://'.$host;
        $admin = 'manage-'.bin2hex(random_bytes(12));
        $dbHost = trim($input('db_host')) ?: 'localhost';
        if (! preg_match('/\A[a-z0-9][a-z0-9.-]{0,252}\z/i', $dbHost)) {
            throw new RuntimeException('اسم مضيف قاعدة البيانات غير صالح.');
        }

        $mode = $input('mode');
        if ($mode === 'auto') {
            $prefix = trim($input('db_prefix'));
            if (! $auto || ! preg_match('/\A(?:[a-z0-9]{1,16}_)?\z/', $prefix)) {
                throw new RuntimeException('التثبيت التلقائي يحتاج cPanel UAPI وبادئة حساب صالحة.');
            }
            ErthiSetup::uapi('get_restrictions');
            $tag = bin2hex(random_bytes(4));
            $database = $prefix.'erthi'.$tag;
            $dbUser = $prefix.'eu'.$tag;
            $dbPassword = 'eR9!'.bin2hex(random_bytes(24));
            ErthiSetup::uapi('create_database', ['name' => $database]);
            $created['delete_database'] = $database;
            ErthiSetup::uapi('create_user', ['name' => $dbUser, 'password' => $dbPassword]);
            $created['delete_user'] = $dbUser;
            ErthiSetup::uapi('set_privileges_on_database', ['user' => $dbUser, 'database' => $database, 'privileges' => 'ALL PRIVILEGES']);
        } elseif ($mode === 'existing') {
            $database = trim($input('db_name'));
            $dbUser = trim($input('db_user'));
            $dbPassword = $input('db_password');
            if (! preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $database) ||
                ! preg_match('/\A[a-zA-Z0-9_]{1,32}\z/', $dbUser) || $dbPassword === '') {
                throw new RuntimeException('أدخل بيانات قاعدة فارغة ومستخدمها.');
            }
        } else {
            throw new RuntimeException('اختر طريقة إعداد قاعدة البيانات.');
        }

        try {
            $pdo = new PDO('mysql:host='.$dbHost.';port=3306;dbname='.$database.';charset=utf8mb4', $dbUser, $dbPassword, [PDO::ATTR_TIMEOUT => 5]);
            $empty = $pdo->query('SHOW TABLES')->fetchColumn() === false;
            unset($pdo);
        } catch (PDOException $exception) {
            throw new RuntimeException('تعذّر الاتصال بقاعدة البيانات. تحقق من المضيف والصلاحيات.');
        }
        if (! $empty) {
            throw new RuntimeException('يجب اختيار قاعدة بيانات فارغة ومخصصة لإرثي.');
        }
        ErthiSetup::configure($root, [
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $dbHost, 'DB_PORT' => '3306',
            'DB_DATABASE' => $database, 'DB_USERNAME' => $dbUser, 'DB_PASSWORD' => $dbPassword,
        ], $base, $admin, $email, $password);
        $created = [];
        @unlink($keyFile);
        $success = $base.'/'.$admin.'/access';
    } catch (Throwable $exception) {
        $cleanupFailed = false;
        foreach (array_reverse($created, true) as $action => $name) {
            try {
                ErthiSetup::uapi($action, ['name' => $name]);
            } catch (Throwable) {
                $cleanupFailed = true;
            }
        }
        $error = ($exception instanceof RuntimeException ? $exception->getMessage() : 'تعذّر التثبيت. راجع سجل PHP الخاص بالاستضافة.')
            .($cleanupFailed ? ' تعذّر التراجع عن أحد موارد قاعدة البيانات التي أُنشئت. راجع لوحة cPanel.' : '');
    } finally {
        if (is_resource($lock)) {
            fclose($lock);
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>معالج تثبيت إرثي</title>
<link rel="stylesheet" href="/installer.css">
<main>
<h1>معالج تثبيت إرثي</h1>
<?php if ($success) { ?>
<p role="status">اكتمل التثبيت وإنشاء حساب المالك. احفظ رابط الإدارة الخاص الآن.</p>
<p><a href="<?= $escape($success) ?>"><?= $escape($success) ?></a></p>
<p><a href="/ar">فتح الموقع</a></p>
<?php } else { ?>
<h2>فحص الاستضافة</h2>
<ul><?php foreach ($checks as $label => $ok) { ?><li><?= $escape($label) ?> — <?= $ok ? 'جاهز' : 'يتطلب إعدادًا' ?></li><?php } ?></ul>
<?php if ($error) { ?><p role="alert"><?= $escape($error) ?></p><?php } ?>
<?php if ($ready) { ?>
<form method="post">
<label>مفتاح التثبيت من ملف SETUP-KEY.txt<input name="setup_key" type="password" autocomplete="off" required></label>
<fieldset><legend>قاعدة البيانات</legend>
<label>طريقة الإعداد<select name="mode">
<?php if ($auto) { ?><option value="auto">إنشاء تلقائي عبر cPanel</option><?php } ?>
<option value="existing">استخدام قاعدة فارغة موجودة</option></select></label>
<?php if ($auto) { ?><label>بادئة cPanel للإنشاء التلقائي، بما فيها الشرطة السفلية<input name="db_prefix" value="<?= $escape($account === '' ? '' : $account.'_') ?>" dir="ltr" maxlength="17"></label><?php } else { ?><p>الإنشاء التلقائي يتطلب تشغيل PHP بهوية حساب cPanel وإتاحة UAPI وproc_open.</p><?php } ?>
<label>مضيف MySQL<input name="db_host" value="localhost" dir="ltr" required></label>
<details><summary>بيانات قاعدة موجودة</summary>
<label>اسم القاعدة<input name="db_name" dir="ltr"></label>
<label>مستخدم القاعدة<input name="db_user" dir="ltr"></label>
<label>كلمة مرور القاعدة<input name="db_password" type="password" autocomplete="off"></label>
</details>
</fieldset>
<fieldset><legend>حساب مالك الموقع</legend>
<label>البريد الإلكتروني<input name="owner_email" type="email" dir="ltr" autocomplete="username" required></label>
<label>كلمة مرور المالك<input name="owner_password" type="password" autocomplete="new-password" required minlength="14" maxlength="72"></label>
<label>تأكيد كلمة المرور<input name="owner_confirmation" type="password" autocomplete="new-password" required minlength="14" maxlength="72"></label>
</fieldset>
<button type="submit">ثبّت إرثي الآن</button>
</form>
<?php } ?>
<?php } ?>
</main>
</html>
