<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use SplFileObject;

final class AdminAudit
{
    public function record(int $actor, string $action, string $method, int $status): void
    {
        Log::build([
            'driver' => 'daily',
            'path' => storage_path('app/private/admin-audit/events.log'),
            'days' => 14,
            'locking' => true,
            'permission' => 0600,
            'formatter' => JsonFormatter::class,
        ])->info('admin.request', compact('actor', 'action', 'method', 'status'));
    }

    public function latest(): array
    {
        $files = glob(storage_path('app/private/admin-audit/events-????-??-??.log')) ?: [];
        rsort($files, SORT_STRING);
        $events = [];
        foreach (array_slice($files, 0, 14) as $path) {
            $file = new SplFileObject($path);
            $file->seek(PHP_INT_MAX);
            for ($line = $file->key(); $line >= 0; $line--) {
                $file->seek($line);
                $entry = json_decode($file->current(), true);
                if (is_array($entry) && isset($entry['datetime'], $entry['context'])) {
                    $events[] = ['at' => $entry['datetime'], ...$entry['context']];
                }
                if (count($events) === 50) {
                    return $events;
                }
            }
        }

        return $events;
    }
}
