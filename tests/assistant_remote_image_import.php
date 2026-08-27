<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\RemoteImageImporter;

$failed = 0;
function checkRemoteImport(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

checkRemoteImport('public_ipv4_is_allowed', RemoteImageImporter::isPublicIp('93.184.216.34'));
checkRemoteImport('public_ipv6_is_allowed', RemoteImageImporter::isPublicIp('2606:4700:4700::1111'));

foreach (['127.0.0.1', '10.0.0.8', '100.64.0.1', '172.16.0.2', '192.0.0.1', '192.168.1.2', '198.18.0.1', '224.0.0.1', '169.254.1.1', '0.0.0.0', '::1', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', 'ff02::1'] as $ip) {
    checkRemoteImport('private_or_reserved_ip_blocked_' . str_replace(['.', ':'], '_', $ip), !RemoteImageImporter::isPublicIp($ip));
}

$resolver = static fn (string $host): array => match ($host) {
    'images.example.test' => ['93.184.216.34'],
    'mixed.example.test' => ['93.184.216.34', '127.0.0.1'],
    default => [],
};

$safe = RemoteImageImporter::validateRemoteUrl(
    'https://images.example.test/photo.png?token=secret',
    $resolver
);
checkRemoteImport('https_url_with_public_dns_is_accepted',
    ($safe['url'] ?? '') === 'https://images.example.test/photo.png?token=secret'
    && ($safe['host'] ?? '') === 'images.example.test'
    && ($safe['addresses'] ?? []) === ['93.184.216.34'],
    json_encode($safe)
);

$invalid = [
    'http://127.0.0.1/a.png',
    'http://localhost/a.png',
    'file:///etc/passwd',
    'https://user:pass@images.example.test/a.png',
    'https://mixed.example.test/a.png',
    'https://unknown.example.test/a.png',
];
foreach ($invalid as $index => $url) {
    $blocked = false;
    try {
        RemoteImageImporter::validateRemoteUrl($url, $resolver);
    } catch (InvalidArgumentException) {
        $blocked = true;
    }
    checkRemoteImport('unsafe_url_blocked_' . ($index + 1), $blocked, $url);
}

$candidates = RemoteImageImporter::normalizeCandidates([
    ['client_id' => 'paste-1', 'url' => 'https://images.example.test/a.png', 'alt' => ' A '],
    ['client_id' => 'paste-2', 'url' => 'https://images.example.test/b.png', 'alt' => 'B'],
    ['client_id' => 'paste-1', 'url' => 'https://images.example.test/a.png', 'alt' => 'duplicate'],
    ['client_id' => '', 'url' => 'https://images.example.test/c.png'],
], 8);
checkRemoteImport('candidate_batch_is_bounded_and_deduplicated',
    count($candidates) === 2
    && $candidates[0] === ['client_id' => 'paste-1', 'url' => 'https://images.example.test/a.png', 'alt' => 'A']
    && $candidates[1]['client_id'] === 'paste-2',
    json_encode($candidates)
);

$permissionFixture = tempnam(sys_get_temp_dir(), 'ppa-ar71-perms-');
if ($permissionFixture === false) {
    checkRemoteImport('downloaded_file_becomes_web_readable', false, 'tempnam failed');
} else {
    file_put_contents($permissionFixture, 'fixture');
    chmod($permissionFixture, 0600);
    clearstatcache(true, $permissionFixture);
    $before = fileperms($permissionFixture) & 0777;
    $madeReadable = false;
    try {
        RemoteImageImporter::ensureWebReadable($permissionFixture);
        clearstatcache(true, $permissionFixture);
        $madeReadable = ((fileperms($permissionFixture) & 0004) !== 0);
    } catch (Throwable $e) {
        $madeReadable = false;
    }
    checkRemoteImport('downloaded_file_becomes_web_readable',
        $before === 0600 && $madeReadable,
        sprintf('before=%o after=%o', $before, fileperms($permissionFixture) & 0777)
    );
    unlink($permissionFixture);
}

$repairSiteId = 987654321;
$repairDir = PP_STORAGE . '/uploads/' . $repairSiteId;
if (!is_dir($repairDir)) mkdir($repairDir, 0775, true);
$repairPath = $repairDir . '/email-ar71-fixture.png';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
file_put_contents($repairPath, $png === false ? '' : $png);
chmod($repairPath, 0600);
$repaired = RemoteImageImporter::repairStoredMedia([
    'path' => 'storage/uploads/' . $repairSiteId . '/email-ar71-fixture.png',
    'mime_type' => 'image/png',
], $repairSiteId);
clearstatcache(true, $repairPath);
checkRemoteImport('existing_email_import_is_repaired_safely',
    $repaired && ((fileperms($repairPath) & 0004) !== 0)
);
checkRemoteImport('repair_is_scoped_to_email_imports',
    !RemoteImageImporter::repairStoredMedia([
        'path' => 'storage/uploads/' . $repairSiteId . '/normal-upload.png',
        'mime_type' => 'image/png',
    ], $repairSiteId)
);
unlink($repairPath);
rmdir($repairDir);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
