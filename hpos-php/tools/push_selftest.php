<?php
declare(strict_types=1);

/**
 * Checks the Web Push cryptography without any network: the worked example in RFC 8291 section 5 must come out
 * byte for byte, a message must survive an encrypt/decrypt round trip with fresh keys, and a VAPID token must verify.
 *
 *   php tools/push_selftest.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Push;

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : "  ($detail)");
};

// ---- RFC 8291, section 5 and appendix A: fixed keys, salt and plaintext, and the exact body that must result
$plain = 'When I grow up, I want to be a watermelon';
$asPrivate = Push::unb64u('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$asPublic = Push::unb64u('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$uaPublic = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
$authSecret = 'BTBZMqHH6r4Tts7J_aSIgg';
$salt = Push::unb64u('DGv6ra1nlYgDCS1FRnbzlw');
$expected = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';
try {
    $body = Push::encrypt($plain, $uaPublic, $authSecret, ['key' => Push::privateKeyFromRaw($asPrivate, $asPublic), 'salt' => $salt]);
    $check('RFC 8291 example: the encrypted body matches the standard byte for byte', Push::b64u($body) === $expected, Push::b64u($body));
} catch (Throwable $e) {
    $check('RFC 8291 example: the encrypted body matches the standard byte for byte', false, $e->getMessage());
}

// ---- a round trip with a made-up browser
try {
    $ua = Push::generateKeyPair();
    $uaKey = openssl_pkey_get_private($ua['private_pem']);
    $auth = Push::b64u(random_bytes(16));
    $msg = json_encode(['title' => 'Order SO-2026-00001 confirmed', 'body' => 'Alpha Auto, total 40000.00', 'url' => '/orders/abc']);
    $enc = Push::encrypt($msg, $ua['public'], $auth);
    $check('round trip: a message encrypted for a browser decrypts back to the same text', Push::decrypt($enc, $uaKey, $auth) === $msg);
    $check('round trip: two encryptions of the same message differ (fresh key and salt each time)', Push::encrypt($msg, $ua['public'], $auth) !== $enc);
    $tampered = $enc;
    $tampered[strlen($tampered) - 3] = $tampered[strlen($tampered) - 3] ^ "\x01";
    try {
        Push::decrypt($tampered, $uaKey, $auth);
        $check('round trip: a tampered message is refused', false, 'decrypted');
    } catch (Throwable) {
        $check('round trip: a tampered message is refused', true);
    }
    $check('the body header names the record size 4096 and a 65-byte sender key', substr($enc, 16, 4) === pack('N', 4096) && ord($enc[20]) === 65);
} catch (Throwable $e) {
    $check('round trip', false, $e->getMessage());
}

// ---- a VAPID token that verifies with the matching public key
try {
    $pair = Push::generateKeyPair();
    $keys = $pair + ['subject' => 'mailto:ops@example.test'];
    $header = Push::vapidAuthorization('https://fcm.googleapis.com/fcm/send/abc', $keys, 1_700_000_000);
    preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header, $m);
    $check('VAPID: the header has a three-part token and the public key', count($m) === 5 && $m[4] === $pair['public']);
    $claims = json_decode(Push::unb64u($m[2] ?? ''), true) ?: [];
    $check('VAPID: audience is the push service origin, subject and expiry are set', ($claims['aud'] ?? '') === 'https://fcm.googleapis.com' && ($claims['sub'] ?? '') === 'mailto:ops@example.test' && ($claims['exp'] ?? 0) === 1_700_000_000 + 43200);
    $raw = Push::unb64u($m[3] ?? '');
    $toDer = static function (string $r, string $s): string {
        $int = static fn (string $b): string => "\x02" . chr(strlen($b = ltrim($b, "\0")) + (ord($b[0] ?? "\0") & 0x80 ? 1 : 0)) . (ord($b[0] ?? "\0") & 0x80 ? "\0" : '') . $b;
        $body = $int($r) . $int($s);
        return "\x30" . chr(strlen($body)) . $body;
    };
    $point = Push::unb64u($pair['public']);
    $pub = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point), 64, "\n") . "-----END PUBLIC KEY-----\n");
    $ok = strlen($raw) === 64 && openssl_verify(($m[1] ?? '') . '.' . ($m[2] ?? ''), $toDer(substr($raw, 0, 32), substr($raw, 32)), $pub, OPENSSL_ALGO_SHA256) === 1;
    $check('VAPID: the signature verifies against the public key', $ok);
} catch (Throwable $e) {
    $check('VAPID', false, $e->getMessage());
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
