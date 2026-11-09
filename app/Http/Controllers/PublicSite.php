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
            'projects' => in_array($page, ['home', 'projects'], true)
                ? DB::table('projects')->where('published', true)->latest('id')->when($page === 'home', fn ($query) => $query->limit(4))
                    ->get(["title_{$locale} as title", "summary_{$locale} as summary", 'repository', 'icon'])
                : [],
            'seo' => [
                'title' => $title.' | '.$titleSuffix,
                'description' => str($description)->limit(160)->toString(),
                'canonical' => Site::url($locale, $page),
                'alternates' => array_combine($locales, array_map(fn ($lang) => Site::url($lang, $page), $locales))
                    + ['x-default' => Site::url('ar', $page)],
                'image' => rtrim(config('app.url'), '/').'/brand/social.jpg',
                'index' => config('erthi.indexing'),
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
