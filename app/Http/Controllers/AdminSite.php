<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Site;
use App\Support\SiteUpdates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminSite
{
    public const Sections = ['overview', 'projects', 'content', 'languages', 'contribute', 'contact', 'media', 'identity', 'seo', 'users', 'updates'];

    public function show(Request $request, string $section = 'overview')
    {
        $settings = Site::settings();
        abort_if(in_array($section, ['users', 'updates'], true) && $request->user()->role !== 'owner', 403);
        $data = match ($section) {
            'overview' => [
                'projects' => DB::table('projects')->count(),
                'contribute' => DB::table('submissions')->where('kind', 'contribute')->whereNull('reviewed_at')->count(),
                'contact' => DB::table('submissions')->where('kind', 'contact')->whereNull('reviewed_at')->count(),
                'media' => DB::table('media')->count(),
            ],
            'projects' => $this->paginate('projects'),
            'content' => Site::content(),
            'contact', 'contribute' => $this->paginate('submissions', $section),
            'media', 'identity' => $this->paginate('media'),
            'users' => DB::table('users')->select('id', 'name', 'email', 'role')->latest('id')->paginate(20),
            'updates' => app(SiteUpdates::class)->status() + ['available' => extension_loaded('sodium') && class_exists(\ZipArchive::class)],
            default => [],
        };

        return Inertia::render('Admin', [
            'section' => $section, 'data' => $data, 'locale' => 'ar', 'settings' => $settings,
            'logo' => Site::logo($settings), 'role' => $request->user()->role,
        ]);
    }

    private function paginate(string $table, ?string $kind = null)
    {
        return DB::table($table)->when($kind, fn ($query) => $query->where('kind', $kind))->latest('id')->paginate(20);
    }

    public function project(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'title_ar' => ['required', 'string', 'max:120'],
            'title_en' => ['required', 'string', 'max:120'],
            'summary_ar' => ['required', 'string', 'max:1000'],
            'summary_en' => ['required', 'string', 'max:1000'],
            'repository' => ['required', 'string', 'max:160', 'regex:/\A[A-Za-z0-9-]+\/[A-Za-z0-9_.-]+\z/', Rule::unique('projects')->ignore($id)],
            'icon' => ['required', Rule::in(['quran', 'learn', 'review', 'projects', 'android'])],
            'published' => ['required', 'boolean'],
        ]);
        $data['updated_at'] = now();

        if ($id !== null) {
            abort_unless(DB::table('projects')->where('id', $id)->exists(), 404);
            DB::table('projects')->where('id', $id)->update($data);
        } else {
            DB::table('projects')->insert($data + ['created_at' => now()]);
        }

        return $this->saved();
    }

    public function content(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(config('erthi.locales'))],
            'page' => ['required', Rule::in(config('erthi.pages'))],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        DB::table('content_blocks')->upsert([$data], ['locale', 'page'], ['title', 'body']);

        return $this->saved();
    }

    public function settings(Request $request, string $section)
    {
        $rules = match ($section) {
            'languages' => ['english_enabled' => ['required', 'boolean']],
            'identity' => ['logo_id' => ['present', 'nullable', 'integer', 'exists:media,id']],
            'seo' => [
                'title_ar' => ['required', 'string', 'max:60'], 'title_en' => ['required', 'string', 'max:60'],
                'description_ar' => ['required', 'string', 'max:160'], 'description_en' => ['required', 'string', 'max:160'],
            ],
        };
        $data = $request->validate($rules);

        DB::transaction(function () use ($data): void {
            foreach ($data as $key => $value) {
                if ($value === null) {
                    DB::table('site_settings')->where('key', $key)->delete();
                } else {
                    DB::table('site_settings')->upsert([['key' => $key, 'value' => is_bool($value) ? (string) (int) $value : (string) $value]], ['key'], ['value']);
                }
            }
        });

        return $this->saved();
    }

    public function review(string $kind, int $id)
    {
        $submission = DB::table('submissions')->where('kind', $kind)->where('id', $id);
        abort_unless($submission->exists(), 404);
        $submission->whereNull('reviewed_at')->update(['reviewed_at' => now()]);

        return $this->saved();
    }

    public function deleteSubmission(string $kind, int $id)
    {
        abort_unless(DB::table('submissions')->where('kind', $kind)->where('id', $id)->delete(), 404);

        return $this->saved();
    }

    public function upload(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'image' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:4096', 'dimensions:max_width=2400,max_height=2400'],
        ]);
        $image = @imagecreatefromstring(file_get_contents($data['image']->getRealPath()));
        if ($image === false) {
            throw ValidationException::withMessages(['image' => 'تعذّرت قراءة الصورة.']);
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, 87);
        $bytes = ob_get_clean();
        abort_if($bytes === '', 500);
        $file = 'media/'.str()->uuid().'.webp';
        abort_unless(Storage::disk('local')->put($file, $bytes), 500);

        try {
            DB::table('media')->insert(['name' => $data['name'], 'file' => $file, 'created_at' => now()]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($file);
            throw $exception;
        }

        return $this->saved();
    }

    public function image(int $id)
    {
        $image = DB::table('media')->find($id);
        abort_if($image === null, 404);

        return Storage::disk('local')->response($image->file, 'erthi.webp', [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function user(Request $request)
    {
        abort_unless($request->user()->role === 'owner', 403);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower($request->input('email'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users')],
            'password' => ['required', 'string', 'max:72', 'regex:/\A[^\x00]{14,72}\z/', Password::min(14)],
            'role' => ['required', Rule::in(['editor', 'viewer'])],
        ]);
        $user = new User;
        $user->forceFill([...$data, 'email' => strtolower($data['email']), 'password' => Hash::make($data['password'])])->save();

        return $this->saved();
    }

    public function role(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'owner', 403);
        $data = $request->validate(['role' => ['required', Rule::in(['editor', 'viewer'])]]);
        $user = User::query()->whereKey($id)->where('role', '!=', 'owner')->firstOrFail();
        $user->forceFill($data)->save();

        return $this->saved();
    }

    private function saved()
    {
        return back()->with('notice', 'تم حفظ التغيير.');
    }
}
