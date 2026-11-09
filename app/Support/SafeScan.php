<?php

namespace App\Support;

final class SafeScan
{
    public const MaxKilobytes = 10240;

    public function scan(string $path): string
    {
        $socketPath = config('erthi.clamd_socket');
        if (! is_string($socketPath) || ! str_starts_with($socketPath, '/')) {
            return 'unavailable';
        }

        $stream = @stream_socket_client('unix://'.$socketPath, timeout: 2);
        if ($stream === false) {
            return 'unavailable';
        }

        try {
            stream_set_timeout($stream, 5);
            $file = @fopen($path, 'rb');
            if ($file === false) {
                return 'unavailable';
            }
            try {
                if (@fwrite($stream, "zINSTREAM\0") !== 10) {
                    return 'unavailable';
                }
                $remaining = self::MaxKilobytes * 1024;
                while (! feof($file)) {
                    $chunk = fread($file, 8192);
                    if ($chunk === false || ($remaining -= strlen($chunk)) < 0) {
                        return 'unavailable';
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $frame = pack('N', strlen($chunk)).$chunk;
                    if (@fwrite($stream, $frame) !== strlen($frame)) {
                        return 'unavailable';
                    }
                }
                if (@fwrite($stream, pack('N', 0)) !== 4) {
                    return 'unavailable';
                }
            } finally {
                fclose($file);
            }

            $result = @stream_get_line($stream, 1024, "\0");

            return match (true) {
                $result === 'stream: OK' => 'clean',
                is_string($result) && preg_match('/\Astream: .+ FOUND\z/', $result) === 1 => 'detected',
                default => 'unavailable',
            };
        } finally {
            fclose($stream);
        }
    }
}
