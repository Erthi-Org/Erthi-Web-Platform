<?php

namespace App\Support;

final class OwnerTools
{
    public function health(array $settings): array
    {
        $driver = config('database.default');
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        $checks = [
            'إصدار PHP يدعم المنصة (8.4 أو أحدث)' => PHP_VERSION_ID >= 80400,
            'تصحيح الأخطاء معطّل' => ! config('app.debug'),
            'مفتاح التطبيق صالح للتشفير' => is_string($key) && strlen($key) === 32,
            'عنوان الموقع يستخدم HTTPS' => str_starts_with(Site::origin($settings), 'https://'),
            'برنامج تشغيل قاعدة البيانات مدعوم' => in_array($driver, ['sqlite', 'mysql', 'mariadb'], true),
            'امتداد قاعدة البيانات متاح' => match ($driver) {
                'sqlite' => extension_loaded('pdo_sqlite'),
                'mysql', 'mariadb' => extension_loaded('pdo_mysql'),
                default => false,
            },
            'مجلد التخزين الخاص قابل للكتابة' => is_writable(storage_path('app/private')),
            'مجلد قوالب الواجهة قابل للكتابة' => is_writable(storage_path('framework/views')),
        ];
        foreach (['curl', 'dom', 'gd', 'mbstring', 'openssl', 'sodium', 'zip'] as $extension) {
            $checks['امتداد PHP: '.$extension] = extension_loaded($extension);
        }

        return $checks;
    }

    public function seo(array $settings): array
    {
        $content = Site::content();
        $pages = [];
        foreach (Site::locales($settings) as $locale) {
            foreach (config('erthi.pages') as $page) {
                $copy = $content[$locale][$page];
                $description = $settings['description_'.$locale] ?? $copy['body'];
                $pages[] = [
                    'locale' => $locale,
                    'url' => Site::url($settings, $locale, $page),
                    'title' => $copy['title'],
                    'has_title' => filled($copy['title']),
                    'has_description' => filled($description),
                    'description_shortened' => str($description)->limit(160)->toString() !== $description,
                ];
            }
        }

        return [
            'indexing' => Site::indexing($settings),
            'https' => str_starts_with(Site::origin($settings), 'https://'),
            'pages' => $pages,
        ];
    }
}
