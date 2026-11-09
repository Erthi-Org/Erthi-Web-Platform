<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SiteUpdates
{
    private const MaxBytes = 32 * 1024 * 1024;

    public function status(): array
    {
        $file = storage_path('app/private/site-updates/state.json');

        return is_file($file)
            ? json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR)
            : ['key' => '', 'sequence' => 0];
    }

    public function enroll(string $encoded): void
    {
        $key = base64_decode($encoded, true);
        if (! extension_loaded('sodium') || $key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('أدخل مفتاح Ed25519 العام بصيغة Base64.');
        }

        $this->locked(function () use ($encoded): void {
            if ($this->status()['key'] !== '') {
                throw new RuntimeException('مفتاح التوقيع مسجّل بالفعل.');
            }
            $this->writeState(['key' => $encoded, 'sequence' => 0]);
        });
    }

    public function apply(string $path): void
    {
        $this->locked(function () use ($path): void {
            $state = $this->status();
            if (! extension_loaded('sodium') || ! class_exists(ZipArchive::class) || $state['key'] === '') {
                throw new RuntimeException('يلزم تسجيل مفتاح التوقيع وتفعيل امتدادي ZIP وSodium.');
            }

            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('حزمة ZIP غير صالحة.');
            }
            try {
                [$sequence, $files] = $this->verifiedFiles($zip, $state);
                $this->install($files, ['key' => $state['key'], 'sequence' => $sequence]);
            } finally {
                $zip->close();
            }
        });
    }

    private function verifiedFiles(ZipArchive $zip, array $state): array
    {
        $manifest = $zip->getFromName('manifest.json', 65537);
        $signature = $zip->getFromName('signature.ed25519', SODIUM_CRYPTO_SIGN_BYTES + 1);
        if (! is_string($manifest) || strlen($manifest) > 65536 || ! is_string($signature) ||
            strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
            ! sodium_crypto_sign_verify_detached($signature, $manifest, base64_decode($state['key'], true))) {
            throw new RuntimeException('توقيع حزمة التحديث غير صالح.');
        }

        $release = json_decode($manifest, true);
        $sequence = $release['sequence'] ?? 0;
        $hashes = $release['files'] ?? [];
        if (($release['product'] ?? '') !== 'erthi-web' || ! is_int($sequence) || $sequence !== $state['sequence'] + 1 ||
            ! is_array($hashes) || count($hashes) < 1 || count($hashes) > 500 || $zip->numFiles !== count($hashes) + 2) {
            throw new RuntimeException('بيان الحزمة غير صالح أو إصدار التحديث قديم.');
        }

        $files = [];
        $remaining = self::MaxBytes;
        foreach ($hashes as $name => $hash) {
            if (! is_string($name) || ! self::allowed($name) || ! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                throw new RuntimeException('تحتوي الحزمة على ملف خارج مسارات التحديث المسموح بها.');
            }
            $entry = $zip->statName('files/'.$name);
            if ($entry === false || $entry['size'] > $remaining) {
                throw new RuntimeException('ملفات الحزمة ناقصة أو تتجاوز الحد المسموح.');
            }
            $bytes = $zip->getFromName('files/'.$name);
            if (! is_string($bytes) || ! hash_equals($hash, hash('sha256', $bytes))) {
                throw new RuntimeException('تغيّر محتوى أحد ملفات التحديث.');
            }
            $remaining -= strlen($bytes);
            $files[$name] = $bytes;
        }

        if (isset($files['public/build/manifest.json'])) {
            $manifest = $files['public/build/manifest.json'];
            unset($files['public/build/manifest.json']);
            $files['public/build/manifest.json'] = $manifest;
        }

        return [$sequence, $files];
    }

    public static function allowed(string $name): bool
    {
        if (str_starts_with($name, 'public/')) {
            return preg_match('~\Apublic/(?:build|brand|icons)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?:json|js|css|woff2?|svg|webp|png|jpe?g|avif|ico)\z~', $name) === 1;
        }

        return preg_match('~\A(?:app|config|routes|resources/views|resources/data|lang)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+(?:\.blade)?\.(?:php|json)\z~', $name) === 1;
    }

    private function install(array $files, array $state): void
    {
        $backup = storage_path('app/private/site-updates/backup-'.$state['sequence'].'-'.bin2hex(random_bytes(6)));
        $changed = [];
        foreach ($files as $name => $bytes) {
            $target = $this->target($name);
            if (file_exists($target)) {
                File::ensureDirectoryExists(dirname($backup.'/'.$name), 0700);
                if (! copy($target, $backup.'/'.$name)) {
                    throw new RuntimeException('تعذّر أخذ نسخة احتياطية من الملفات.');
                }
            }
        }

        try {
            foreach ($files as $name => $bytes) {
                $target = $this->target($name);
                File::ensureDirectoryExists(dirname($target));
                $this->replace($target, $bytes);
                $changed[] = $name;
            }
            $this->clearCompiled();
            $this->writeState($state);
        } catch (Throwable $error) {
            try {
                foreach (array_reverse($changed) as $name) {
                    $target = $this->target($name);
                    if (is_file($backup.'/'.$name)) {
                        $this->replace($target, File::get($backup.'/'.$name));
                    } elseif (! unlink($target)) {
                        throw new RuntimeException('تعذّر حذف ملف أضيف أثناء التحديث.');
                    }
                }
                $this->clearCompiled();
            } catch (Throwable $rollback) {
                report($rollback);
                throw new RuntimeException('تعذّر إكمال التراجع. النسخة الاحتياطية محفوظة في مجلد التحديثات الخاص.');
            }
            report($error);
            throw new RuntimeException('تعذّر تطبيق التحديث وأُعيدت الملفات السابقة.');
        }
        File::deleteDirectory($backup);
    }

    private function target(string $name): string
    {
        $path = base_path();
        foreach (explode('/', $name) as $segment) {
            $path .= '/'.$segment;
            if (is_link($path)) {
                throw new RuntimeException('مسار التحديث يحتوي على رابط رمزي.');
            }
        }
        if (file_exists($path) && ! is_file($path)) {
            throw new RuntimeException('وجهة التحديث ليست ملفًا.');
        }

        return $path;
    }

    private function replace(string $path, string $bytes, int $mode = 0644): void
    {
        $temporary = tempnam(dirname($path), '.erthi-');
        if ($temporary === false || dirname($temporary) !== dirname($path)) {
            if ($temporary !== false) {
                @unlink($temporary);
            }
            throw new RuntimeException('تعذّر تجهيز ملف التحديث داخل مساره.');
        }

        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) ||
                ! chmod($temporary, $mode) || ! rename($temporary, $path)) {
                throw new RuntimeException('تعذّر استبدال ملف التحديث.');
            }
            if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function clearCompiled(): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            if (Artisan::call($command) !== 0) {
                throw new RuntimeException('تعذّر تحديث ذاكرة ملفات Laravel.');
            }
        }
    }

    private function writeState(array $state): void
    {
        $this->replace(storage_path('app/private/site-updates/state.json'), json_encode($state, JSON_THROW_ON_ERROR), 0600);
    }

    private function locked(callable $work): void
    {
        $dir = storage_path('app/private/site-updates');
        File::ensureDirectoryExists($dir, 0700);
        $lock = fopen($dir.'/update.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('تعذّر قفل عملية التحديث.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('هناك عملية تحديث أخرى جارية.');
            }
            $work();
        } finally {
            fclose($lock);
        }
    }
}
