<?php

namespace App\Support;

use ZipArchive;

final class OwnerSiteChecks
{
    public function seo(array $settings): array
    {
        $content = Site::content();
        $pages = [];
        foreach (Site::locales($settings) as $locale) {
            foreach (config('erthi.pages') as $page) {
                $copy = $content[$locale][$page];
                $seo = Site::seo($settings, $locale, $page, $copy);
                $pages[] = [
                    'locale' => $locale,
                    'url' => $seo['canonical'],
                    'title' => $seo['title'],
                    'has_title' => filled($copy['title']),
                    'description' => $seo['description'],
                ];
            }
        }

        $titles = array_count_values(array_map(fn ($page) => mb_strtolower(trim($page['title'])), $pages));
        $descriptions = array_count_values(array_map(fn ($page) => mb_strtolower(trim($page['description'])), $pages));
        foreach ($pages as $index => $page) {
            $pages[$index]['checks'] = [
                'title' => $page['has_title'],
                'description' => filled($page['description']),
                'unique_title' => $titles[mb_strtolower(trim($page['title']))] === 1,
                'unique_description' => $descriptions[mb_strtolower(trim($page['description']))] === 1,
                'https' => str_starts_with($page['url'], 'https://'),
            ];
            unset($pages[$index]['has_title']);
        }

        return ['indexing' => Site::indexing($settings), 'pages' => $pages];
    }

    public function runtime(array $settings): array
    {
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $driver = config('database.default');

        return [
            'php' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'debug' => ! config('app.debug'),
            'key' => is_string($key) && strlen($key) === 32,
            'https' => str_starts_with(Site::origin($settings), 'https://'),
            'database' => in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)
                && extension_loaded($driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql'),
            'zip' => class_exists(ZipArchive::class),
            'sodium' => extension_loaded('sodium'),
            'storage' => is_writable(storage_path('app/private')),
        ];
    }
}
