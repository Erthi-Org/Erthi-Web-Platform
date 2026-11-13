<?php

namespace App\Http\Controllers;

use App\Support\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PublicSite
{
    public function show(Request $request, string $locale, ?string $page = null)
    {
        $page ??= 'home';
        $settings = Site::settings();
        $locales = Site::locales($settings);
        abort_unless(in_array($locale, $locales, true), 404);
        app()->setLocale($locale);
        $content = Site::content()[$locale];
        $title = $content[$page]['title'];
        $description = $settings['description_'.$locale] ?? $content[$page]['body'];
        $titleSuffix = $settings['title_'.$locale] ?? ($locale === 'ar' ? 'إرثي' : 'Erthi');

        return Inertia::render('Public', [
            'locale' => $locale,
            'page' => $page,
            'developmentTopic' => $page === 'contribute' && $request->query('topic') === 'projects',
            'locales' => $locales,
            'content' => $content,
            'logo' => Site::logo($settings),
            'channels' => [
                'email' => $settings['contact_email'] ?? 'admin@erthi.org',
                'website' => parse_url(Site::origin($settings), PHP_URL_HOST),
                'facebook' => $settings['facebook_url'] ?? null,
                'youtube' => $settings['youtube_url'] ?? null,
                'x' => $settings['x_url'] ?? null,
            ],
            'projects' => in_array($page, ['home', 'projects'], true)
                ? Site::projects($locale, $page === 'home')
                : [],
            'seo' => [
                'title' => $title.' | '.$titleSuffix,
                'description' => str($description)->limit(160)->toString(),
                'canonical' => Site::url($settings, $locale, $page),
                'alternates' => array_combine($locales, array_map(fn ($lang) => Site::url($settings, $lang, $page), $locales))
                    + ['x-default' => Site::url($settings, 'ar', $page)],
                'image' => Site::origin($settings).'/brand/social.jpg',
                'index' => Site::indexing($settings),
            ],
        ]);
    }

    public function submit(Request $request, string $locale, string $page)
    {
        abort_unless(in_array($locale, Site::locales(Site::settings()), true), 404);
        app()->setLocale($locale);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'topic' => $page === 'contribute' ? ['required', Rule::in(config('erthi.topics'))] : ['prohibited'],
            'consent' => ['accepted'],
            'website' => ['prohibited'],
        ]);

        DB::table('submissions')->insert([
            'kind' => $page,
            'name' => $data['name'],
            'email' => $data['email'],
            'message' => $data['message'],
            'topic' => $data['topic'] ?? null,
            'created_at' => now(),
        ]);

        return back()->with('notice', $locale === 'ar' ? 'وصلت رسالتك إلى فريق إرثي.' : 'The Erthi team received your submission.');
    }
}
