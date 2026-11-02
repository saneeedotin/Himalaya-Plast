<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Web Push to browsers, written against the standards so it needs no library:
 *   RFC 8291  message encryption (ECDH P-256 + HKDF + AES-128-GCM, "aes128gcm")
 *   RFC 8292  VAPID (an ES256 token that tells the push service who is sending)
 *   RFC 8030  the HTTP request to the browser's push service
 *
 * The VAPID key pair is made once and kept in config/vapid.php (git-ignored). Sending needs PHP's openssl.
 * Browsers only allow push on HTTPS (or localhost), which the deployment guide covers.
 */
final class Push
{
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';   // SubjectPublicKeyInfo header for a P-256 point
    private const CURVE_OID = '06082a8648ce3d030107';

    // ------------------------------------------------------------------ base64url

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    // ------------------------------------------------------------------ keys

    /** @return array{public:string,private_pem:string,subject:string} public is the base64url 65-byte point browsers need */
    public static function keys(): array
    {
        static $keys = null;
        if ($keys !== null) {
            return $keys;
        }
        $subject = (string) Config::get('push.subject', 'mailto:admin@localhost.invalid');
        $configured = Config::get('push.private_pem');
        $pub = Config::get('push.public');
        if (is_string($configured) && $configured !== '' && is_string($pub) && $pub !== '') {
            return $keys = ['public' => $pub, 'private_pem' => $configured, 'subject' => $subject];
        }
        $file = HPOS_ROOT . '/config/vapid.php';
        if (is_file($file)) {
            $k = require $file;
            if (is_array($k) && !empty($k['public']) && !empty($k['private_pem'])) {
                return $keys = ['public' => (string) $k['public'], 'private_pem' => (string) $k['private_pem'], 'subject' => $subject];
            }
        }
        $made = self::generateKeyPair();
        if (@file_put_contents($file, "<?php\n// VAPID key pair for Web Push. Keep it private; if you change it, every browser must subscribe again.\nreturn " . var_export($made, true) . ";\n") === false) {
            throw new \RuntimeException('Cannot save config/vapid.php. Make the config folder writable, or set push.public and push.private_pem in config.php.');
        }
        return $keys = $made + ['subject' => $subject];
    }

    /** @return array{public:string,private_pem:string} */
    public static function generateKeyPair(): array
    {
        $key = self::newEcKey();
        $d = openssl_pkey_get_details($key);
        $point = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $pem = '';
        openssl_pkey_export($key, $pem, null, self::opensslConfig());
        return ['public' => self::b64u($point), 'private_pem' => $pem];
    }

    private static function opensslConfig(): array
    {
        $found = null;
        foreach ([getenv('OPENSSL_CONF') ?: '', dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf', '/usr/lib/ssl/openssl.cnf'] as $c) {
            if ($c !== '' && is_file($c)) {
                $found = $c;
                break;
            }
        }
        return $found === null ? [] : ['config' => $found];
    }

    private static function newEcKey(): \OpenSSLAsymmetricKey
    {
        $opts = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($opts) ?: openssl_pkey_new($opts + self::opensslConfig());
        if (!$key) {
            throw new \RuntimeException('PHP openssl cannot make an EC key: ' . (string) openssl_error_string());
        }
        return $key;
    }

    /** A public key from its raw 65-byte point. */
    private static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        $der = hex2bin(self::SPKI_PREFIX) . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if (!$k) {
            throw new \RuntimeException('Not a valid P-256 public key.');
        }
        return $k;
    }

    /** A private key from its raw 32-byte value and its public point (used to rebuild keys for the RFC test vector). */
    public static function privateKeyFromRaw(string $d, string $point): \OpenSSLAsymmetricKey
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $d . "\xa0\x0a" . hex2bin(self::CURVE_OID) . "\xa1\x44\x03\x42\x00" . $point;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        $k = openssl_pkey_get_private($pem);
        if (!$k) {
            throw new \RuntimeException('Not a valid P-256 private key.');
        }
        return $k;
    }

    // ------------------------------------------------------------------ message encryption (RFC 8291)

    /**
     * Encrypts $payload for one browser. $uaPublic and $authSecret are the base64url values the browser gave when it
     * subscribed. $fixed ('key' => OpenSSL key, 'salt' => 16 bytes) exists only so the RFC's worked example can be checked.
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?array $fixed = null): string
    {
        $uaPoint = self::unb64u($uaPublic);
        $auth = self::unb64u($authSecret);
        if (strlen($uaPoint) !== 65 || $uaPoint[0] !== "\x04" || strlen($auth) < 16) {
            throw new \InvalidArgumentException('The browser subscription keys are not valid.');
        }
        $asKey = $fixed['key'] ?? self::newEcKey();
        $d = openssl_pkey_get_details($asKey);
        $asPoint = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $salt = $fixed['salt'] ?? random_bytes(16);

        $secret = openssl_pkey_derive(self::publicKeyFromPoint($uaPoint), $asKey, 32);
        if ($secret === false) {
            throw new \RuntimeException('Key agreement failed: ' . (string) openssl_error_string());
        }
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPoint . $asPoint, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        // header: salt, record size (4096), key id length, key id (our public key); then the one record
        return $salt . pack('N', 4096) . chr(65) . $asPoint . $cipher . $tag;
    }

    /** The reverse of encrypt(), for tests: a browser's side. */
    public static function decrypt(string $body, \OpenSSLAsymmetricKey $uaKey, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPoint = substr($body, 21, $idLen);
        $record = substr($body, 21 + $idLen);
        $d = openssl_pkey_get_details($uaKey);
        $uaPoint = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $secret = openssl_pkey_derive(self::publicKeyFromPoint($asPoint), $uaKey, 32);
        $auth = self::unb64u($authSecret);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPoint . $asPoint, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($record, -16));
        if ($plain === false) {
            throw new \RuntimeException('Cannot decrypt.');
        }
        return rtrim($plain, "\x02\0");
    }

    // ------------------------------------------------------------------ VAPID (RFC 8292)

    /** The Authorization header value for a push to $endpoint. */
    public static function vapidAuthorization(string $endpoint, ?array $keys = null, ?int $now = null): string
    {
        $keys ??= self::keys();
        $p = parse_url($endpoint);
        $aud = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        $head = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u(json_encode(['aud' => $aud, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $keys['subject']], JSON_UNESCAPED_SLASHES));
        $signed = $head . '.' . $claims;
        $key = openssl_pkey_get_private($keys['private_pem']);
        if (!$key || !openssl_sign($signed, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Cannot sign the VAPID token: ' . (string) openssl_error_string());
        }
        return 'vapid t=' . $signed . '.' . self::b64u(self::derToRaw($der)) . ', k=' . $keys['public'];
    }

    /** ECDSA signatures come out of openssl as DER; JWT wants the raw 64 bytes r||s. */
    private static function derToRaw(string $der): string
    {
        $i = 2;
        if (ord($der[1]) & 0x80) {
            $i += ord($der[1]) & 0x7f;
        }
        $parts = [];
        for ($n = 0; $n < 2; $n++) {
            $len = ord($der[$i + 1]);
            $parts[] = str_pad(ltrim(substr($der, $i + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
            $i += 2 + $len;
        }
        return $parts[0] . $parts[1];
    }

    // ------------------------------------------------------------------ who we are willing to post to

    /** Hosts of the browsers' push services. A subscription points anywhere its browser says, so only these are trusted. */
    private const PUSH_HOSTS = [
        '/(^|\.)googleapis\.com$/',            // Chrome, Edge, Brave, Opera, Android (FCM)
        '/(^|\.)push\.services\.mozilla\.com$/', // Firefox
        '/(^|\.)notify\.windows\.com$/',       // Edge on Windows (WNS)
        '/(^|\.)push\.apple\.com$/',           // Safari
    ];

    /** True for an https address on a known push service (or a host listed in config push.allowed_hosts, for testing). */
    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        $host = strtolower((string) ($p['host'] ?? ''));
        if (($p['scheme'] ?? '') !== 'https' || $host === '' || isset($p['user']) || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (self::PUSH_HOSTS as $re) {
            if (preg_match($re, $host)) {
                return true;
            }
        }
        return in_array($host, (array) Config::get('push.allowed_hosts', []), true);
    }

    // ------------------------------------------------------------------ sending

    /**
     * One push to one browser. Returns the push service's HTTP status (201 = accepted), or 0 when it could not be reached.
     *
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     * @return array{status:int,error:string}
     */
    public static function send(array $sub, string $payload, int $ttl = 86400, string $urgency = 'normal'): array
    {
        if (!self::isAllowedEndpoint($sub['endpoint'])) {
            return ['status' => 0, 'error' => 'That address is not a known browser push service.'];
        }
        try {
            $body = self::encrypt($payload, $sub['p256dh'], $sub['auth']);
            $headers = [
                'Authorization: ' . self::vapidAuthorization($sub['endpoint']),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: ' . $ttl,
                'Urgency: ' . $urgency,
            ];
        } catch (\Throwable $e) {
            return ['status' => 0, 'error' => $e->getMessage()];
        }
        return self::post($sub['endpoint'], $headers, $body);
    }

    /** PHP's own HTTPS first; where it cannot verify certificates (a bare Windows install), the curl program. */
    private static function post(string $url, array $headers, string $body): array
    {
        if (getenv('HPOS_PUSH_DRY_RUN')) {
            return ['status' => 201, 'error' => ''];   // tests and demos: pretend the push service accepted it
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\nContent-Length: " . strlen($body),
            'content' => $body, 'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res !== false && isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            return ['status' => (int) $m[1], 'error' => ''];
        }
        $curl = self::curlBinary();
        if ($curl === null || !function_exists('proc_open')) {
            return ['status' => 0, 'error' => 'Could not reach the push service (PHP cannot verify HTTPS certificates here; set openssl.cafile in php.ini).'];
        }
        $cmd = [$curl, '-sS', '-o', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', '-w', '%{http_code}', '--max-time', '10', '-X', 'POST', '--data-binary', '@-'];
        foreach ($headers as $h) {
            array_push($cmd, '-H', $h);
        }
        $cmd[] = $url;
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['status' => 0, 'error' => 'Could not start curl.'];
        }
        fwrite($pipes[0], $body);
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $code = (int) $out;
        return $code > 0 ? ['status' => $code, 'error' => ''] : ['status' => 0, 'error' => $err !== '' ? $err : 'No answer from the push service.'];
    }

    private static function curlBinary(): ?string
    {
        foreach (PHP_OS_FAMILY === 'Windows' ? ['C:\\Windows\\System32\\curl.exe'] : ['/usr/bin/curl', '/usr/local/bin/curl', '/bin/curl'] as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ notifications

    /** The JSON the service worker turns into a notification. */
    public static function payloadFor(array $n): string
    {
        return (string) json_encode([
            'title' => mb_substr((string) $n['title'], 0, 120),
            'body' => mb_substr((string) $n['message'], 0, 240),
            'url' => url((string) ($n['action_url'] ?: '/notifications')),
            'tag' => (string) $n['id'],
            'priority' => (string) $n['priority'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Sends everything waiting: each pending notification goes to the browsers of people who may see it and have not read it.
     * Safe to call often. Returns how many notifications were handled and how many pushes went out.
     *
     * @return array{notifications:int,sent:int,dropped:int,failed:int}
     */
    public static function dispatchPending(int $limit = 20): array
    {
        $out = ['notifications' => 0, 'sent' => 0, 'dropped' => 0, 'failed' => 0];
        try {
            $pending = Db::rows('sp_push_pending', [$limit]);
        } catch (\Throwable) {
            return $out;
        }
        foreach ($pending as $n) {
            $targets = Db::rows('sp_push_targets', [$n['id']]);
            $unreachable = 0;
            $payload = self::payloadFor($n);
            $urgency = $n['priority'] === 'CRITICAL' ? 'high' : 'normal';
            foreach ($targets as $t) {
                $r = self::send($t, $payload, 86400, $urgency);
                if ($r['status'] >= 200 && $r['status'] < 300) {
                    Db::call('sp_push_ok', [$t['id']]);
                    $out['sent']++;
                } elseif ($r['status'] === 404 || $r['status'] === 410) {
                    Db::call('sp_push_drop', [$t['id']]);   // the browser unsubscribed or was reset
                    $out['dropped']++;
                } else {
                    $out['failed']++;
                    if ($r['status'] === 0) {
                        $unreachable++;
                    }
                }
            }
            // leave it waiting for another try only when the push service could not be reached at all
            if ($targets === [] || $unreachable < count($targets)) {
                Db::call('sp_push_done', [$n['id']]);
            }
            $out['notifications']++;
        }
        return $out;
    }
}
