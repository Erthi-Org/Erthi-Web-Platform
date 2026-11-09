<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ErthiSetup
{
    public const UAPI = '/usr/local/cpanel/bin/uapi';

    public static function automaticAvailable(): bool
    {
        return function_exists('proc_open') && function_exists('posix_geteuid')
            && posix_geteuid() > 0 && is_executable(self::UAPI);
    }

    public static function uapi(string $action, array $parameters = []): array
    {
        $command = [self::UAPI, '--output=json', 'Mysql', $action];
        foreach ($parameters as $key => $value) {
            $command[] = $key.'='.$value;
        }
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('تعذّر تشغيل واجهة cPanel.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        $response = json_decode($output, true);
        $result = $response['result'] ?? $response;
        if ($exit !== 0 || (int) ($result['status'] ?? 0) !== 1) {
            throw new RuntimeException('رفض cPanel العملية '.$action.'. تحقق من صلاحيات MySQL وبادئة الحساب وحدود الاستضافة.');
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

        $permissions = umask(0077);
        $stream = fopen($root.'/.env', 'xb');
        umask($permissions);
        if ($stream === false) {
            throw new RuntimeException('تعذّر إنشاء إعدادات التطبيق. قد يكون التطبيق مثبتًا بالفعل.');
        }
        try {
            if (fwrite($stream, $contents) !== strlen($contents)) {
                throw new RuntimeException('تعذّر حفظ إعدادات التطبيق.');
            }
        } catch (Throwable $error) {
            unlink($root.'/.env');
            throw $error;
        } finally {
            fclose($stream);
        }

        try {
            require_once $root.'/vendor/autoload.php';
            $app = require $root.'/bootstrap/app.php';
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();
            if ($kernel->call('migrate', ['--force' => true]) !== 0) {
                throw new LogicException('Migration failed');
            }
            DB::table('users')->insert([
                'name' => 'Erthi Owner', 'email' => $email, 'password' => Hash::make($password),
                'role' => 'owner', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $error) {
            unlink($root.'/.env');
            throw new RuntimeException('تعذّر إعداد جداول إرثي أو حساب المالك. راجع إعدادات قاعدة البيانات وسجل PHP الخاص بالاستضافة.');
        }
    }
}
