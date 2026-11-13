<?php

namespace App\Support;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ReleaseChannel
{
    private const DownloadLimit = 20 * 1024 * 1024;

    private const Repository = 'Erthi-Org/Erthi-Web-Platform';

    public function install(int $sequence, SiteUpdates $updates): void
    {
        $release = $this->check($sequence);
        if ($release['status'] !== 'available') {
            throw new RuntimeException('الحزمة المطلوبة غير متاحة حاليًا على GitHub.');
        }

        $file = tmpfile();
        if ($file === false) {
            throw new RuntimeException('تعذّر إنشاء ملف مؤقت للتحديث.');
        }

        $destination = Utils::streamFor($file);
        try {
            $client = Http::withoutRedirecting()->connectTimeout(5)->timeout(180)
                ->withOptions(['stream' => true, 'read_timeout' => 15]);
            try {
                if (isset($release['asset_id'])) {
                    $response = (clone $client)->accept('application/octet-stream')
                        ->withToken($this->token())
                        ->get('https://api.github.com/repos/'.self::Repository.'/releases/assets/'.$release['asset_id']);
                } else {
                    $response = $client->get($release['url']);
                }
                if ($response->status() === 302) {
                    $response->toPsrResponse()->getBody()->close();
                    $location = $response->header('Location');
                    $target = parse_url($location);
                    if (($target['scheme'] ?? null) !== 'https'
                        || ! in_array($target['host'] ?? '', ['release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)
                        || isset($target['port']) || isset($target['user']) || isset($target['pass'])) {
                        throw new RuntimeException('عنوان تنزيل الحزمة من GitHub غير صالح.');
                    }
                    $response = $client->get($location);
                }
                if (! $response->ok()) {
                    $response->toPsrResponse()->getBody()->close();
                    throw new RuntimeException('تعذّر تنزيل الحزمة من GitHub.');
                }
                $body = $response->toPsrResponse()->getBody();
                try {
                    Utils::copyToStream($body, $destination, self::DownloadLimit + 1);
                } finally {
                    $body->close();
                }
            } catch (ConnectionException|RuntimeException) {
                throw new RuntimeException('تعذّر إكمال تنزيل حزمة التحديث من GitHub. لم يبدأ التثبيت.');
            }
            if (ftell($file) > self::DownloadLimit) {
                throw new RuntimeException('حزمة التحديث تتجاوز 20 ميغابايت.');
            }
            fflush($file);
            $updates->apply(stream_get_meta_data($file)['uri'], $sequence);
        } finally {
            $destination->close();
        }
    }

    public function check(int $sequence): array
    {
        $tag = 'erthi-update-'.$sequence;
        $filename = $tag.'.zip';
        $url = 'https://github.com/'.self::Repository.'/releases/download/'.$tag.'/'.$filename;

        try {
            $request = Http::accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->withoutRedirecting()->connectTimeout(3)->timeout(8);
            if ($this->token() !== '') {
                $request->withToken($this->token());
            }
            $response = $request->get('https://api.github.com/repos/'.self::Repository.'/releases/tags/'.$tag);
        } catch (ConnectionException) {
            return ['status' => 'unavailable', 'sequence' => $sequence];
        }

        if ($response->notFound()) {
            return ['status' => 'missing', 'sequence' => $sequence];
        }
        if (! $response->ok() || $response->json('tag_name') !== $tag || $response->json('draft') !== false) {
            return ['status' => 'unavailable', 'sequence' => $sequence];
        }

        $assets = $response->json('assets');
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (is_array($asset) && ($asset['name'] ?? null) === $filename
                    && ($asset['state'] ?? null) === 'uploaded'
                    && ($asset['browser_download_url'] ?? null) === $url) {
                    $release = ['status' => 'available', 'sequence' => $sequence, 'url' => $url];
                    if ($this->token() !== '') {
                        if (! is_int($asset['id'] ?? null) || $asset['id'] < 1) {
                            return ['status' => 'unavailable', 'sequence' => $sequence];
                        }
                        $release['asset_id'] = $asset['id'];
                    }

                    return $release;
                }
            }
        }

        return ['status' => 'missing', 'sequence' => $sequence];
    }

    private function token(): string
    {
        return trim((string) config('erthi.release_github_token'));
    }
}
