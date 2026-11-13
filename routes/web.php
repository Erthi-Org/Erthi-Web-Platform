<?php

use App\Http\Controllers\AdminSite;
use App\Http\Controllers\AdminUpdates;
use App\Http\Controllers\PublicSite;
use App\Http\Middleware\RecordAdminAudit;
use App\Support\Site;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/ar', 302);
Route::get('/robots.txt', function () {
    $settings = Site::settings();

    return response(Site::indexing($settings)
        ? "User-agent: *\nDisallow: /admin\nDisallow: /login\nSitemap: ".Site::origin($settings)."/sitemap.xml\n"
        : "User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});
Route::get('/sitemap.xml', function () {
    $settings = Site::settings();
    abort_unless(Site::indexing($settings), 404);
    $urls = [];
    foreach (Site::locales($settings) as $locale) {
        foreach (config('erthi.pages') as $page) {
            $urls[] = '<url><loc>'.e(Site::url($settings, $locale, $page)).'</loc></url>';
        }
    }

    return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>', 200, ['Content-Type' => 'application/xml']);
});
Route::get('/media/{id}', [AdminSite::class, 'image'])->whereNumber('id');

$admin = config('erthi.admin_prefix');
Route::prefix($admin)->middleware(['auth', 'can:manage-erthi', 'throttle:90,1', RecordAdminAudit::class])->group(function (): void {
    Route::get('/{section?}', [AdminSite::class, 'show'])->whereIn('section', AdminSite::Sections);
    Route::post('/safescan', [AdminSite::class, 'scan'])->middleware('throttle:5,1,erthi-safescan');
    Route::delete('/sessions', [AdminSite::class, 'revokeOtherSessions']);
    Route::post('/updates', [AdminUpdates::class, 'store'])->middleware('throttle:5,1,erthi-updates');
    Route::post('/updates/check', [AdminUpdates::class, 'check'])->middleware('throttle:5,1,erthi-release-check');
    Route::post('/updates/install-release/{sequence}', [AdminUpdates::class, 'installRelease'])->whereNumber('sequence')->middleware('throttle:5,1,erthi-updates');
    Route::post('/updates/rollback', [AdminUpdates::class, 'rollback'])->middleware('throttle:5,1,erthi-updates');
    Route::post('/projects/{id?}', [AdminSite::class, 'project'])->whereNumber('id');
    Route::post('/content', [AdminSite::class, 'content']);
    Route::post('/settings', [AdminSite::class, 'settings'])->defaults('section', 'settings');
    Route::post('/settings/{section}', [AdminSite::class, 'settings'])->whereIn('section', ['languages', 'identity', 'seo']);
    Route::post('/submissions/{kind}/{id}', [AdminSite::class, 'review'])->whereIn('kind', ['contact', 'contribute'])->whereNumber('id');
    Route::delete('/submissions/{kind}/{id}', [AdminSite::class, 'deleteSubmission'])->whereIn('kind', ['contact', 'contribute'])->whereNumber('id');
    Route::post('/media', [AdminSite::class, 'upload'])->middleware('throttle:12,1');
    Route::post('/users', [AdminSite::class, 'user'])->middleware('throttle:8,1,erthi-create-account');
    Route::post('/users/{id}', [AdminSite::class, 'role'])->whereNumber('id');
});

Route::get('/{locale}/{page?}', [PublicSite::class, 'show'])->whereIn('locale', config('erthi.locales'))
    ->whereIn('page', array_values(array_diff(config('erthi.pages'), ['home'])));
Route::post('/{locale}/{page}', [PublicSite::class, 'submit'])->whereIn('locale', config('erthi.locales'))
    ->whereIn('page', ['contact', 'contribute'])->middleware('throttle:5,1');
