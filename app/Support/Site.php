<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Site
{
    public static function settings(): array
    {
        return DB::table('site_settings')->pluck('value', 'key')->all();
    }

    public static function locales(array $settings): array
    {
        return ($settings['english_enabled'] ?? '1') === '1' ? ['ar', 'en'] : ['ar'];
    }

    public static function content(): array
    {
        $content = json_decode(file_get_contents(resource_path('data/site.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (DB::table('content_blocks')->get() as $block) {
            $content[$block->locale][$block->page] = ['title' => $block->title, 'body' => $block->body];
        }

        return $content;
    }

    public static function projects(string $locale, bool $home): array
    {
        $defaults = json_decode(file_get_contents(resource_path('data/projects.json')), true, flags: JSON_THROW_ON_ERROR);
        $catalog = array_column($defaults, null, 'repository');
        $projects = [];
        foreach (DB::table('projects')->latest('id')->get() as $project) {
            $entry = $catalog[$project->repository] ?? null;
            unset($catalog[$project->repository]);
            if ($project->published) {
                $projects[] = [
                    'title' => $project->{"title_{$locale}"},
                    'summary' => $project->{"summary_{$locale}"},
                    'repository' => $project->repository,
                    'icon' => $project->icon,
                    'platform' => $entry === null ? 'android' : $entry['platform'],
                ];
            }
        }

        foreach ($catalog as $project) {
            $projects[] = [
                'title' => $project['title_'.$locale],
                'summary' => $project['summary_'.$locale],
                'repository' => $project['repository'],
                'icon' => $project['icon'],
                'platform' => $project['platform'],
            ];
        }

        return $home ? array_slice($projects, 0, 5) : $projects;
    }

    public static function origin(array $settings): string
    {
        return rtrim($settings['site_url'] ?? config('app.url'), '/');
    }

    public static function indexing(array $settings): bool
    {
        return ($settings['public_indexing'] ?? null) === null
            ? config('erthi.indexing')
            : $settings['public_indexing'] === '1';
    }

    public static function url(array $settings, string $locale, string $page = 'home'): string
    {
        return self::origin($settings).'/'.$locale.($page === 'home' ? '' : '/'.$page);
    }

    public static function logo(array $settings): ?string
    {
        return isset($settings['logo_id']) ? '/media/'.(int) $settings['logo_id'] : null;
    }
}
