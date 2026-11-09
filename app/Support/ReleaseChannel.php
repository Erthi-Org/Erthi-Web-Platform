<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ReleaseChannel
{
    private const Repository = 'Erthi-Org/Erthi-Web-Platform';

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
