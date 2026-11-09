<?php

use App\Http\Controllers\AdminSite;
use App\Http\Controllers\AdminUpdates;
use App\Http\Controllers\PublicSite;
use App\Http\Middleware\RecordAdminAudit;
use App\Support\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

Route::redirect('/', '/ar', 302);
Route::get('/robots.txt', fn () => response(config('erthi.indexing')
    ? "User-agent: *\nDisallow: /admin\nDisallow: /login\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n"
    : "User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
Route::get('/sitemap.xml', function () {
    abort_unless(config('erthi.indexing'), 404);
    $urls = [];
    foreach (Site::locales(Site::settings()) as $locale) {
        foreach (config('erthi.pages') as $page) {
            $urls[] = '<url><loc>'.e(Site::url($locale, $page)).'</loc></url>';
        }
    }

    return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>', 200, ['Content-Type' => 'application/xml']);
});
Route::get('/media/{id}', [AdminSite::class, 'image'])->whereNumber('id');

$admin = config('erthi.admin_prefix');
$login = $admin === 'admin' ? '/login' : '/'.$admin.'/access';
$logout = $admin === 'admin' ? '/logout' : '/'.$admin.'/exit';
Route::get($login, fn () => Inertia::render('Login', ['locale' => 'ar']))->name('login');
Route::post($login, function (Request $request) {
    $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:72']]);
    if (! Auth::attempt(['email' => strtolower($data['email']), 'password' => $data['password'], 'role' => ['owner', 'editor']])) {
        throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة أو الحساب غير مخوّل.']);
    }
    $request->session()->regenerate();

    return redirect('/'.config('erthi.admin_prefix'));
})->middleware(['guest', 'throttle:6,1']);
Route::post($logout, function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/ar');
})->middleware('auth');

Route::prefix($admin)->middleware(['auth', 'can:manage-erthi', 'throttle:90,1', RecordAdminAudit::class])->group(function (): void {
    Route::get('/{section?}', [AdminSite::class, 'show'])->whereIn('section', AdminSite::Sections);
    Route::delete('/sessions', [AdminSite::class, 'revokeOtherSessions']);
    Route::post('/updates', [AdminUpdates::class, 'store'])->middleware('throttle:5,1,erthi-updates');
    Route::post('/updates/check', [AdminUpdates::class, 'check'])->middleware('throttle:5,1,erthi-release-check');
    Route::post('/updates/rollback', [AdminUpdates::class, 'rollback'])->middleware('throttle:5,1,erthi-updates');
    Route::post('/projects/{id?}', [AdminSite::class, 'project'])->whereNumber('id');
    Route::post('/content', [AdminSite::class, 'content']);
    Route::post('/settings/{section}', [AdminSite::class, 'settings'])->whereIn('section', ['languages', 'identity', 'seo']);
    Route::post('/submissions/{kind}/{id}', [AdminSite::class, 'review'])->whereIn('kind', ['contact', 'contribute'])->whereNumber('id');
    Route::delete('/submissions/{kind}/{id}', [AdminSite::class, 'deleteSubmission'])->whereIn('kind', ['contact', 'contribute'])->whereNumber('id');
    Route::post('/media', [AdminSite::class, 'upload'])->middleware('throttle:12,1');
    Route::post('/users', [AdminSite::class, 'user']);
    Route::post('/users/{id}', [AdminSite::class, 'role'])->whereNumber('id');
});

Route::get('/{locale}/{page?}', [PublicSite::class, 'show'])->whereIn('locale', config('erthi.locales'))
    ->whereIn('page', array_values(array_diff(config('erthi.pages'), ['home'])));
Route::post('/{locale}/{page}', [PublicSite::class, 'submit'])->whereIn('locale', config('erthi.locales'))
    ->whereIn('page', ['contact', 'contribute'])->middleware('throttle:5,1');
