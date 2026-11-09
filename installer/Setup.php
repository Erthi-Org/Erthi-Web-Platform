<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ErthiSetup
{
    public const UAPI = '/usr/local/cpanel/bin/uapi';

    public static function account(): string
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid') || posix_geteuid() === 0) {
            return '';
        }

        $account = posix_getpwuid(posix_geteuid())['name'] ?? '';

        return preg_match('/\A[a-z][a-z0-9_]{0,31}\z/i', $account) ? $account : '';
    }

    public static function automaticAvailable(): bool
    {
        return function_exists('proc_open') && self::account() !== '' && is_executable(self::UAPI);
    }

    public static function uapi(string $action, array $parameters = []): array
    {
        if (! self::automaticAvailable()) {
            throw new RuntimeException('واجهة cPanel المحلية غير متاحة لهوية تشغيل PHP. استخدم قاعدة فارغة موجودة.');
        }

        $command = [self::UAPI, '--user='.self::account(), '--output=json', 'Mysql', $action];
        foreach ($parameters as $key => $value) {
            $command[] = $key.'='.$value;
        }

        $stderr = tmpfile();
        if ($stderr === false) {
            throw new RuntimeException('تعذّر إنشاء ملف تشخيص مؤقت لواجهة cPanel.');
        }
        try {
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('تعذّر تشغيل واجهة cPanel.');
            }
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exit = proc_close($process);
            rewind($stderr);
            $diagnostic = stream_get_contents($stderr, 8192);
        } finally {
            fclose($stderr);
        }

        $response = json_decode($output ?: '', true);
        $result = $response['result'] ?? $response;
        if ($exit !== 0 || (int) ($result['status'] ?? 0) !== 1) {
            $reported = array_filter((array) ($result['errors'] ?? $response['errors'] ?? []), 'is_string');
            $detail = strtolower(implode(' ', $reported).' '.$diagnostic);
            $reason = match (true) {
                preg_match('/quota|limit|maximum|too many/', $detail) === 1 => 'بلغ الحساب حد موارد قواعد البيانات. راجع الحصة في cPanel.',
                preg_match('/prefix|invalid.*name|name.*invalid/', $detail) === 1 => 'اسم القاعدة أو المستخدم مرفوض. طابق البادئة الظاهرة في معالج قواعد البيانات.',
                preg_match('/permission denied|not permitted|not authorized|access denied|must be root|privilege|disabled|feature/', $detail) === 1 => 'واجهة cPanel رفضت الصلاحية أو الميزة غير متاحة لهوية تشغيل PHP. استخدم قاعدة فارغة موجودة.',
                default => 'الاستضافة لم تُرجع سببًا يمكن عرضه. استخدم قاعدة فارغة موجودة إذا تكرر الخطأ.',
            };
            throw new RuntimeException('رفض cPanel العملية '.$action.'. '.$reason.' (رمز الخروج: '.$exit.').');
        }

        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    public static function envValue(string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0") || str_contains($value, "'")) {
            throw new RuntimeException('تتضمن بيانات الاتصال محارف غير مدعومة.');
        }

        return "'".$value."'";
    }

    public static function configure(string $root, array $db, string $url, string $prefix, string $email, string $password): void
    {
        $values = [
            'APP_NAME' => 'Erthi', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'APP_URL' => $url, 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_LOCALE' => 'ar', 'APP_FALLBACK_LOCALE' => 'en', 'LOG_LEVEL' => 'warning',
            'SESSION_DRIVER' => 'database', 'SESSION_ENCRYPT' => 'true', 'SESSION_SECURE_COOKIE' => 'true',
            'SESSION_COOKIE' => 'erthi_session', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database',
            'ERTHI_INDEXING' => 'false', 'ERTHI_ADMIN_PREFIX' => $prefix,
        ] + $db;
        $quoted = ['APP_URL', 'APP_KEY', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
        $contents = '';
        foreach ($values as $name => $value) {
            $contents .= $name.'='.(in_array($name, $quoted, true) ? self::envValue((string) $value) : $value).PHP_EOL;
        }

        if (is_file($root.'/.env')) {
            throw new RuntimeException('التطبيق مثبّت بالفعل.');
        }

        $temporaryName = '.env.install-'.bin2hex(random_bytes(16));
        $temporaryPath = $root.'/'.$temporaryName;
        $permissions = umask(0077);
        try {
            $written = file_put_contents($temporaryPath, $contents, LOCK_EX);
        } finally {
            umask($permissions);
        }

        $stage = 'تهيئة Laravel';
        $schema = null;
        $schemaName = null;
        $ownsNewTables = false;
        try {
            if ($written !== strlen($contents)) {
                throw new RuntimeException('Could not write the temporary environment');
            }
            require_once $root.'/vendor/autoload.php';
            // Validate syntax before Laravel's environment loader can terminate the request.
            Dotenv::parse($contents);
            $app = require $root.'/bootstrap/app.php';
            $app->loadEnvironmentFrom($temporaryName);
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();

            $stage = 'التحقق من اتصال Laravel';
            $connection = DB::connection();
            $expected = array_filter([
                'driver' => $db['DB_CONNECTION'] ?? null,
                'host' => $db['DB_HOST'] ?? null,
                'database' => $db['DB_DATABASE'] ?? null,
                'username' => $db['DB_USERNAME'] ?? null,
                'password' => $db['DB_PASSWORD'] ?? null,
            ], static fn ($value) => $value !== null);
            foreach ($expected as $key => $value) {
                if ($connection->getConfig($key) !== $value) {
                    throw new RuntimeException('The effective database configuration differs from the installer input');
                }
            }

            $schema = $connection->getSchemaBuilder();
            $schemaName = $connection->getDriverName() === 'sqlite' ? 'main' : $connection->getDatabaseName();
            if ($schema->getTableListing($schemaName, false) !== []) {
                throw new RuntimeException('The target database must be empty');
            }
            $ownsNewTables = true;

            $stage = 'إنشاء جداول إرثي';
            if ($kernel->call('migrate', ['--force' => true]) !== 0) {
                throw new LogicException('Migration failed');
            }
            $stage = 'إنشاء حساب المالك';
            $connection->table('users')->insert([
                'name' => 'Erthi Owner', 'email' => $email, 'password' => Hash::make($password),
                'role' => 'owner', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $stage = 'تفعيل الموقع';
            // The web installer holds the installation lock across this operation.
            if (file_exists($root.'/.env') || ! rename($temporaryPath, $root.'/.env')) {
                throw new RuntimeException('Could not activate the installation');
            }
        } catch (Throwable $error) {
            $cleanupFailed = false;
            if ($ownsNewTables) {
                try {
                    $tables = ['media', 'submissions', 'site_settings', 'content_blocks', 'projects',
                        'failed_jobs', 'job_batches', 'jobs', 'cache_locks', 'cache', 'sessions',
                        'password_reset_tokens', 'users', 'migrations'];
                    foreach (array_intersect($tables, $schema->getTableListing($schemaName, false)) as $table) {
                        $schema->dropIfExists($table);
                    }
                } catch (Throwable $cleanup) {
                    $cleanupFailed = true;
                    error_log('Erthi setup rollback failed ['.$cleanup::class.']');
                }
            }

            $driverCode = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
            $code = $driverCode > 0 ? 'MySQL '.$driverCode : substr(strrchr('\\'.$error::class, '\\'), 1);
            error_log('Erthi setup failed at '.$stage.' ['.$code.']');
            throw new RuntimeException('تعذّر إكمال مرحلة '.$stage.' ('.$code.').'
                .($cleanupFailed ? ' تعذّر تنظيف الجداول التي أنشأتها هذه المحاولة.' : ''));
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
