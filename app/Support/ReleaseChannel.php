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
            $client = Http::withoutRedirecting()->connectTimeout(5)->timeout(30)
                ->withOptions(['stream' => true, 'read_timeout' => 10]);
            try {
                $response = $client->get($release['url']);
                if ($response->status() === 302) {
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
                    throw new RuntimeException('تعذّر تنزيل الحزمة من GitHub.');
                }
                $body = $response->toPsrResponse()->getBody();
                try {
                    Utils::copyToStream($body, $destination, self::DownloadLimit + 1);
                } finally {
                    $body->close();
                }
            } catch (ConnectionException|RuntimeException $error) {
                throw new RuntimeException('تعذّر تنزيل حزمة التحديث من GitHub.', previous: $error);
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
            $response = Http::accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->withoutRedirecting()->connectTimeout(3)->timeout(8)
                ->get('https://api.github.com/repos/'.self::Repository.'/releases/tags/'.$tag);
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
                    return ['status' => 'available', 'sequence' => $sequence, 'url' => $url];
                }
            }
        }

        return ['status' => 'missing', 'sequence' => $sequence];
    }
}
