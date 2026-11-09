<?php

namespace App\Http\Controllers;

use App\Support\ReleaseChannel;
use App\Support\SiteUpdates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class AdminUpdates
{
    public function store(Request $request, SiteUpdates $updates): RedirectResponse
    {
        abort_unless($request->user()->role === 'owner', 403);
        $data = $request->validate([
            'password' => ['required', 'string', 'current_password'],
            'key' => ['nullable', 'string', 'max:64', 'required_without:bundle', 'prohibits:bundle'],
            'bundle' => ['nullable', 'file', 'max:20480', 'required_without:key', 'prohibits:key'],
        ]);

        $field = isset($data['bundle']) ? 'bundle' : 'key';
        try {
            if ($field === 'bundle') {
                $updates->apply($data['bundle']->getPathname());
            } else {
                $updates->enroll($data['key']);
            }
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages([$field => $error->getMessage()]);
        }

        return back()->with('notice', $field === 'bundle' ? 'تم تثبيت تحديث الموقع.' : 'تم تسجيل مفتاح التوقيع.');
    }

    public function check(Request $request, SiteUpdates $updates, ReleaseChannel $channel): RedirectResponse
    {
        abort_unless($request->user()->role === 'owner', 403);
        $sequence = $updates->status()['sequence'] + 1;

        return redirect('/'.config('erthi.admin_prefix').'/updates')
            ->with('release_check', $channel->check($sequence));
    }

    public function rollback(Request $request, SiteUpdates $updates): RedirectResponse
    {
        abort_unless($request->user()->role === 'owner', 403);
        $request->validate(['password' => ['required', 'string', 'current_password']]);

        try {
            $updates->rollback();
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['password' => $error->getMessage()]);
        }

        return back()->with('notice', 'تمت استعادة ملفات الإصدار السابق.');
    }
}
