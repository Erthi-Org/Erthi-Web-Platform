<?php

// Run from an Erthi checkout with Composer dependencies installed.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Support\SiteUpdates;

try {
    if (($argv[1] ?? '') === 'keygen' && count($argv) === 3) {
        $pair = sodium_crypto_sign_keypair();
        $mode = umask(0077);
        $file = fopen($argv[2], 'xb');
        umask($mode);
        if ($file === false) {
            throw new RuntimeException('Choose a new private-key path outside the checkout.');
        }
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair)).PHP_EOL;
        $written = fwrite($file, $secret);
        fclose($file);
        if ($written !== strlen($secret)) {
            unlink($argv[2]);
            throw new RuntimeException('Could not save the private key.');
        }
        echo base64_encode(sodium_crypto_sign_publickey($pair)).PHP_EOL;
        exit;
    }

    if (($argv[1] ?? '') !== 'pack' || count($argv) < 6 || ! ctype_digit($argv[3]) ||
        (int) $argv[3] < 1 || count($argv) > 505) {
        throw new RuntimeException("Usage: sign-update.php keygen PRIVATE_KEY_FILE\n       sign-update.php pack PRIVATE_KEY_FILE SEQUENCE OUTPUT.zip PATH [PATH...]");
    }
    $secret = base64_decode(trim((string) file_get_contents($argv[2])), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('Invalid signing key.');
    }
    $contents = $hashes = [];
    $total = 0;
    foreach (array_slice($argv, 5) as $path) {
        if (! SiteUpdates::allowed($path) || ! is_file($path) || isset($contents[$path])) {
            throw new RuntimeException('Invalid or duplicate file: '.$path);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || ($total += strlen($bytes)) > 32 * 1024 * 1024) {
            throw new RuntimeException('Files are unreadable or exceed the update size limit.');
        }
        $contents[$path] = $bytes;
        $hashes[$path] = hash('sha256', $bytes);
    }
    $manifest = json_encode(['product' => 'erthi-web', 'sequence' => (int) $argv[3], 'files' => $hashes], JSON_THROW_ON_ERROR);
    if (strlen($manifest) > 65536) {
        throw new RuntimeException('Update manifest is too large.');
    }
    $zip = new ZipArchive;
    if ($zip->open($argv[4], ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Choose a new ZIP output path.');
    }
    $entries = ['manifest.json' => $manifest, 'signature.ed25519' => sodium_crypto_sign_detached($manifest, $secret)];
    foreach ($contents as $path => $bytes) {
        $entries['files/'.$path] = $bytes;
    }
    foreach ($entries as $path => $bytes) {
        if (! $zip->addFromString($path, $bytes)) {
            $zip->close();
            unlink($argv[4]);
            throw new RuntimeException('Could not write the ZIP.');
        }
    }
    if (! $zip->close() || filesize($argv[4]) > 20 * 1024 * 1024) {
        @unlink($argv[4]);
        throw new RuntimeException('Could not save the ZIP within the 20 MiB upload limit.');
    }
    echo $argv[4].PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
