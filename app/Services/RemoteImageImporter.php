<?php

namespace App\Services;

/**
 * Importa imágenes remotas pegadas desde correo sin convertir el servidor en
 * un proxy arbitrario. Cada salto se valida y cURL queda fijado a una IP
 * pública ya resuelta para reducir DNS rebinding.
 */
final class RemoteImageImporter
{
    public const MAX_ITEMS = 8;
    public const MAX_REDIRECTS = 3;
    public const MAX_PIXELS = 25_000_000;

    /** @var callable(string):array<int,string> */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? [self::class, 'resolveHost'];
    }

    /** @param array<int,mixed> $raw @return array<int,array{client_id:string,url:string,alt:string}> */
    public static function normalizeCandidates(array $raw, int $limit = self::MAX_ITEMS): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $candidate) {
            if (!is_array($candidate) || count($out) >= max(0, min(self::MAX_ITEMS, $limit))) break;
            $clientId = trim((string) ($candidate['client_id'] ?? ''));
            $url = trim((string) ($candidate['url'] ?? ''));
            if ($clientId === '' || $url === '' || isset($seen[$clientId])) continue;
            if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $clientId)) continue;
            $seen[$clientId] = true;
            $out[] = [
                'client_id' => $clientId,
                'url' => mb_substr($url, 0, 4096),
                'alt' => mb_substr(trim((string) ($candidate['alt'] ?? '')), 0, 500),
            ];
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $raw
     * @return array<int,array{client_id:string,ok:bool,item?:array<string,mixed>,error?:string}>
     */
    public function importBatch(array $raw, int $siteId, ?int $userId): array
    {
        $results = [];
        foreach (self::normalizeCandidates($raw) as $candidate) {
            try {
                $row = $this->importOne($candidate['url'], $candidate['alt'], $siteId, $userId);
                $path = '/' . ltrim((string) ($row['path'] ?? ''), '/');
                $results[] = [
                    'client_id' => $candidate['client_id'],
                    'ok' => true,
                    'item' => [
                        'id' => (int) ($row['id'] ?? 0),
                        'url' => base_url(ltrim($path, '/')),
                        'path' => $path,
                        'name' => (string) ($row['original_name'] ?? ''),
                        'alt_text' => (string) ($row['alt_text'] ?? ''),
                        'mime_type' => (string) ($row['mime_type'] ?? ''),
                        'width' => (int) ($row['width'] ?? 0),
                        'height' => (int) ($row['height'] ?? 0),
                        'file_size' => (int) ($row['file_size'] ?? 0),
                    ],
                ];
            } catch (\Throwable $e) {
                $host = (string) (parse_url($candidate['url'], PHP_URL_HOST) ?: 'invalid');
                error_log('[RemoteImageImporter] host=' . mb_substr($host, 0, 180) . ' failed: ' . $e->getMessage());
                $results[] = [
                    'client_id' => $candidate['client_id'],
                    'ok' => false,
                    'error' => 'remote_image_unavailable',
                ];
            }
        }
        return $results;
    }

    private function importOne(string $url, string $alt, int $siteId, ?int $userId): array
    {
        $download = $this->download($url);
        $tmp = $download['path'];
        $mime = $download['mime'];
        $ext = MediaService::ALLOWED[$mime];
        $dir = MediaService::ensureSiteDir($siteId);
        $filename = 'email-' . bin2hex(random_bytes(16)) . '.' . $ext;
        $absolute = $dir . '/' . $filename;
        $relative = 'storage/uploads/' . $siteId . '/' . $filename;

        try {
            if (!@rename($tmp, $absolute)) {
                if (!@copy($tmp, $absolute)) throw new \RuntimeException('move_failed');
                @unlink($tmp);
            }
            return MediaService::storeFromBinary($absolute, $relative, $mime, $siteId, $userId, [
                'original_name' => 'imagen-correo.' . $ext,
                'alt_text' => $alt,
                'source' => 'upload',
            ]);
        } catch (\Throwable $e) {
            @unlink($tmp);
            @unlink($absolute);
            throw $e;
        }
    }

    /** @return array{path:string,mime:string} */
    private function download(string $initialUrl): array
    {
        $url = $initialUrl;
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $validated = self::validateRemoteUrl($url, $this->resolver);
            $response = self::requestOnce($validated);
            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                @unlink($response['path']);
                if ($redirect === self::MAX_REDIRECTS || $response['location'] === '') {
                    throw new \RuntimeException('redirect_rejected');
                }
                $url = self::resolveRedirectUrl($url, $response['location']);
                continue;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                @unlink($response['path']);
                throw new \RuntimeException('http_' . $response['status']);
            }

            $mime = self::detectImageMime($response['path']);
            if ($mime === null) {
                @unlink($response['path']);
                throw new \RuntimeException('invalid_image');
            }
            $dimensions = @getimagesize($response['path']);
            $pixels = is_array($dimensions) ? ((int) $dimensions[0] * (int) $dimensions[1]) : 0;
            if ($pixels <= 0 || $pixels > self::MAX_PIXELS) {
                @unlink($response['path']);
                throw new \RuntimeException('image_dimensions_rejected');
            }
            return ['path' => $response['path'], 'mime' => $mime];
        }
        throw new \RuntimeException('too_many_redirects');
    }

    /**
     * @param callable(string):array<int,string>|null $resolver
     * @return array{url:string,host:string,port:int,addresses:array<int,string>}
     */
    public static function validateRemoteUrl(string $url, ?callable $resolver = null): array
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \InvalidArgumentException('invalid_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) throw new \InvalidArgumentException('invalid_url');
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('invalid_scheme_or_host');
        }
        if (isset($parts['user']) || isset($parts['pass'])) throw new \InvalidArgumentException('credentials_forbidden');
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) throw new \InvalidArgumentException('localhost_forbidden');
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            throw new \InvalidArgumentException('port_forbidden');
        }

        $resolve = $resolver ?? [self::class, 'resolveHost'];
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_values(array_unique($resolve($host)));
        if ($addresses === []) throw new \InvalidArgumentException('dns_failed');
        foreach ($addresses as $address) {
            if (!self::isPublicIp((string) $address)) throw new \InvalidArgumentException('private_address');
        }
        return ['url' => $url, 'host' => $host, 'port' => $port, 'addresses' => $addresses];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        $blocked = str_contains($ip, ':')
            ? [
                '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96',
                '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
            ]
            : [
                '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
                '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
                '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
                '224.0.0.0/4', '240.0.0.0/4',
            ];
        foreach ($blocked as $cidr) {
            if (self::ipInCidr($ip, $cidr)) return false;
        }
        return true;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixRaw] = explode('/', $cidr, 2);
        $addressBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) return false;
        $prefix = (int) $prefixRaw;
        $whole = intdiv($prefix, 8);
        $bits = $prefix % 8;
        if ($whole > 0 && substr($addressBytes, 0, $whole) !== substr($networkBytes, 0, $whole)) return false;
        if ($bits === 0) return true;
        $mask = (0xff << (8 - $bits)) & 0xff;
        return (ord($addressBytes[$whole]) & $mask) === (ord($networkBytes[$whole]) & $mask);
    }

    /** @return array<int,string> */
    private static function resolveHost(string $host): array
    {
        $addresses = [];
        if (function_exists('dns_get_record')) {
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                if (!empty($record['ip'])) $addresses[] = (string) $record['ip'];
                if (!empty($record['ipv6'])) $addresses[] = (string) $record['ipv6'];
            }
        }
        if ($addresses === []) {
            foreach ((array) @gethostbynamel($host) as $address) $addresses[] = (string) $address;
        }
        return array_values(array_unique($addresses));
    }

    /** @param array{url:string,host:string,port:int,addresses:array<int,string>} $target
     *  @return array{status:int,path:string,location:string}
     */
    private static function requestOnce(array $target): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('curl_unavailable');
        $tmp = tempnam(sys_get_temp_dir(), 'ppa-mail-img-');
        if ($tmp === false) throw new \RuntimeException('temp_failed');
        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            @unlink($tmp);
            throw new \RuntimeException('temp_open_failed');
        }

        $bytes = 0;
        $location = '';
        $ch = curl_init($target['url']);
        $address = $target['addresses'][0];
        $resolveAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'PromptPress/1.1 remote-image-import',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $resolveAddress],
            CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($handle, &$bytes): int {
                $bytes += strlen($chunk);
                if ($bytes > MediaService::MAX_SIZE) return 0;
                return (int) fwrite($handle, $chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);
        if ($ok === false || $bytes === 0 || $bytes > MediaService::MAX_SIZE) {
            @unlink($tmp);
            throw new \RuntimeException($bytes > MediaService::MAX_SIZE ? 'too_large' : 'download_failed:' . $error);
        }
        return ['status' => $status, 'path' => $tmp, 'location' => $location];
    }

    private static function detectImageMime(string $path): ?string
    {
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path) ?: null;
                finfo_close($finfo);
            }
        }
        return is_string($mime) && isset(MediaService::ALLOWED[$mime]) ? $mime : null;
    }

    private static function resolveRedirectUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) return $location;
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('invalid_redirect');
        }
        if (str_starts_with($location, '//')) return $parts['scheme'] . ':' . $location;
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) $origin .= ':' . (int) $parts['port'];
        if (str_starts_with($location, '/')) return $origin . $location;
        $path = (string) ($parts['path'] ?? '/');
        $dir = str_replace('\\', '/', dirname($path));
        return $origin . rtrim($dir === '.' ? '/' : $dir, '/') . '/' . $location;
    }
}
