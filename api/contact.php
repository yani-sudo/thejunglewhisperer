<?php
declare(strict_types=1);

/**
 * Contact endpoint — thejunglewhisperer.com
 *
 * Runs on IONOS web hosting (PHP 8.x). Accepts a JSON or form-encoded POST from the
 * booking form, validates it, rate-limits by IP, and relays the enquiry over
 * authenticated SMTP so the message lands in Jason's mailbox with SPF/DKIM intact.
 *
 * Credentials are NOT in this file. They live in config.php beside it (see
 * config.example.php), which is excluded from the deploy bundle and denied by
 * .htaccess. Nothing secret is ever served to a browser.
 *
 * Responds JSON: {"ok":true} or {"ok":false,"error":"..."} with a matching HTTP status.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* ── Portability shims ────────────────────────────────────────────────────────
   Shared hosts vary: mbstring is sometimes missing and some plans still run 7.4.
   These fallbacks keep the endpoint working instead of fatal-erroring on a
   function that does not exist. */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int
    {
        return strlen($value);
    }
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
if (!function_exists('mb_encode_mimeheader')) {
    function mb_encode_mimeheader(string $value, string $charset = 'UTF-8', string $transfer = 'B', string $crlf = "\r\n", int $indent = 0): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

const MAX_BODY_BYTES     = 24000;
const RATE_LIMIT_MAX     = 4;      // submissions
const RATE_LIMIT_WINDOW  = 3600;   // per hour, per IP
const MIN_FILL_MS        = 2500;   // a human cannot type a real enquiry in under 2.5s
const MAX_FORM_AGE_MS    = 21600000; // 6 hours — stale tabs get a clear message

const FIELD_LIMITS = [
    'name'       => 120,
    'email'      => 200,
    'phone'      => 40,
    'location'   => 120,
    'motivation' => 4000,
    'experience' => 40,
    'source'     => 40,
    'notes'      => 2000,
];

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $message): void
{
    respond($status, ['ok' => false, 'error' => $message]);
}

/** Writable directory for rate-limit counters and logs; outside the web root when possible.
 *
 *  On shared hosting the account often cannot write above the document root, so the
 *  fallback is a `private/` folder inside it — which would be fetchable. Whenever the
 *  folder is created it therefore gets its own deny rule and a blank index. */
function private_dir(): string
{
    $candidates = [
        dirname(__DIR__) . '/private',
        __DIR__ . '/.private',
        sys_get_temp_dir() . '/tjw-contact',
    ];

    $guard = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
        . "Options -Indexes\n";

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            continue;
        }

        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', $guard);
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }

        return $dir;
    }

    return sys_get_temp_dir();
}

function client_ip(): string
{
    // REMOTE_ADDR only: X-Forwarded-For is client-controlled and would defeat rate limiting.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ip_key(string $ip): string
{
    return hash('sha256', $ip . '|tjw-contact');
}

/** Sliding-window counter. Returns false when the caller is over the limit. */
function within_rate_limit(string $dir, string $ip): bool
{
    $file = $dir . '/rate-' . ip_key($ip) . '.json';
    $now = time();
    $hits = [];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            foreach ($decoded as $ts) {
                if (is_int($ts) && $ts > $now - RATE_LIMIT_WINDOW) {
                    $hits[] = $ts;
                }
            }
        }
    }

    if (count($hits) >= RATE_LIMIT_MAX) {
        return false;
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);

    return true;
}

function note(string $dir, string $line): void
{
    @file_put_contents(
        $dir . '/contact.log',
        '[' . gmdate('c') . '] ' . $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/** Trim, strip control characters, and cap length. */
function clean(string $value, int $limit): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim($value);

    if (mb_strlen($value, 'UTF-8') > $limit) {
        $value = mb_substr($value, 0, $limit, 'UTF-8');
    }

    return $value;
}

/** Single-line fields must never carry newlines (header injection). */
function one_line(string $value, int $limit): string
{
    return trim(preg_replace('/\s+/u', ' ', clean($value, $limit)) ?? '');
}

// ─── Request guards ──────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail(405, 'This endpoint only accepts POST.');
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$origin = strtolower($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST) ?? '';
    $hostBare = preg_replace('/:\d+$/', '', $host);
    if ($originHost !== $hostBare && $originHost !== $host) {
        fail(403, 'Cross-origin submissions are not accepted.');
    }
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > MAX_BODY_BYTES) {
    fail(413, 'That submission was too large. Please shorten your answers.');
}

$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (str_contains($contentType, 'application/json')) {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail(400, 'Malformed request.');
    }
    $data = $decoded;
} else {
    $data = $_POST;
}

$dir = private_dir();

// Honeypot: the field is hidden from humans. Bots fill it. Answer with a plain
// success so the bot has no signal, but send nothing.
if (trim((string) ($data['website'] ?? '')) !== '') {
    note($dir, 'honeypot hit from ' . ip_key(client_ip()));
    respond(200, ['ok' => true]);
}

// Time trap: a form completed faster than a human can read it is a bot.
$elapsed = (int) ($data['elapsed'] ?? 0);
if ($elapsed > 0 && $elapsed < MIN_FILL_MS) {
    note($dir, 'time-trap hit from ' . ip_key(client_ip()));
    respond(200, ['ok' => true]);
}
if ($elapsed > MAX_FORM_AGE_MS) {
    fail(400, 'This page has been open a while. Please reload it and try again.');
}

$name       = one_line((string) ($data['name'] ?? ''), FIELD_LIMITS['name']);
$email      = one_line((string) ($data['email'] ?? ''), FIELD_LIMITS['email']);
$phone      = one_line((string) ($data['phone'] ?? ''), FIELD_LIMITS['phone']);
$location   = one_line((string) ($data['location'] ?? ''), FIELD_LIMITS['location']);
$motivation = clean((string) ($data['motivation'] ?? ''), FIELD_LIMITS['motivation']);
$notes      = clean((string) ($data['notes'] ?? ''), FIELD_LIMITS['notes']);
$experience = one_line((string) ($data['experience'] ?? ''), FIELD_LIMITS['experience']);
$source     = one_line((string) ($data['source'] ?? ''), FIELD_LIMITS['source']);

if ($name === '' || mb_strlen($name) < 2) {
    fail(422, 'Please tell us your name.');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(422, 'That email address does not look right.');
}
if ($motivation === '' || mb_strlen($motivation) < 10) {
    fail(422, 'Please add a sentence or two about what draws you to the expedition.');
}
if (preg_match('/[\r\n]/', $email) || preg_match('/[\r\n]/', $name)) {
    fail(422, 'That submission contained invalid characters.');
}

if (!within_rate_limit($dir, client_ip())) {
    fail(429, 'You have sent several enquiries already. Please email Jason directly if it is urgent.');
}

// ─── Compose ─────────────────────────────────────────────────────────────────

$experienceLabels = [
    'beginner'     => 'Beginner — new to wilderness expeditions',
    'intermediate' => 'Intermediate — some hiking / camping',
    'advanced'     => 'Advanced — comfortable in remote terrain',
    'expert'       => 'Expert — extensive expedition experience',
];
$sourceLabels = [
    'friend'  => 'From a friend',
    'social'  => 'Social media',
    'tierra'  => 'Tierra de Sueños',
    'podcast' => 'Bird Banter Podcast',
    'search'  => 'Google / Search',
    'other'   => 'Other',
];

$lines = [
    'NEW EXPEDITION ENQUIRY',
    str_repeat('=', 46),
    'Name:      ' . $name,
    'Email:     ' . $email,
];
if ($phone !== '')    { $lines[] = 'Phone:     ' . $phone; }
if ($location !== '') { $lines[] = 'Based in:  ' . $location; }
if ($experience !== '') {
    $lines[] = 'Experience: ' . ($experienceLabels[$experience] ?? $experience);
}
if ($source !== '') {
    $lines[] = 'Found via: ' . ($sourceLabels[$source] ?? $source);
}

$lines[] = '';
$lines[] = 'What draws them to this expedition:';
$lines[] = $motivation;

if ($notes !== '') {
    $lines[] = '';
    $lines[] = 'Anything else:';
    $lines[] = $notes;
}

$lines[] = '';
$lines[] = str_repeat('-', 46);
$lines[] = 'Received: ' . gmdate('Y-m-d H:i:s') . ' UTC';
$lines[] = 'Page:     ' . ($_SERVER['HTTP_REFERER'] ?? 'unknown');

$body = implode("\n", $lines);
$subject = mb_substr('Website enquiry — ' . $name, 0, 150, 'UTF-8');

// ─── Config ──────────────────────────────────────────────────────────────────

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    note($dir, 'MISSING CONFIG: api/config.php not found');
    fail(503, 'The contact form is not configured yet. Please email Jason directly.');
}

$config = require $configFile;
if (!is_array($config)) {
    note($dir, 'INVALID CONFIG: api/config.php did not return an array');
    fail(503, 'The contact form is misconfigured. Please email Jason directly.');
}

$to = $config['to'] ?? [];
if (is_string($to)) {
    $to = [$to];
}
$to = array_values(array_filter(array_map('trim', (array) $to)));
if ($to === []) {
    note($dir, 'INVALID CONFIG: no recipient');
    fail(503, 'The contact form is misconfigured. Please email Jason directly.');
}

// ─── SMTP transport (no dependencies; IONOS expects authenticated submission) ─

final class SmtpException extends RuntimeException {}

function smtp_read(array $fp, string $expectPrefix = ''): string
{
    $response = '';

    while (($line = fgets($fp['sock'], 512)) !== false) {
        $response .= $line;
        // Multiline replies use "250-..." for continuation and "250 ..." for the last line.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    if ($response === '') {
        throw new SmtpException('No response from the mail server.');
    }
    if ($expectPrefix !== '' && !str_starts_with($response, $expectPrefix)) {
        throw new SmtpException('Unexpected SMTP reply: ' . trim($response));
    }

    return $response;
}

function smtp_cmd(array $fp, string $command, string $expect, bool $hide = false): string
{
    fwrite($fp['sock'], $command . "\r\n");
    return smtp_read($fp, $expect);
}

function smtp_send(array $config, array $to, string $from, string $fromName, string $replyTo, string $subject, string $body): void
{
    $host = $config['smtp_host'] ?? 'smtp.ionos.com';
    $port = (int) ($config['smtp_port'] ?? 587);
    $secure = strtolower((string) ($config['smtp_secure'] ?? 'tls'));
    $user = (string) ($config['smtp_user'] ?? '');
    $pass = (string) ($config['smtp_pass'] ?? '');

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$sock) {
        throw new SmtpException('Could not reach the mail server: ' . $errstr);
    }
    stream_set_timeout($sock, 15);

    $fp = ['sock' => $sock];

    try {
        smtp_read($fp, '220');
        smtp_cmd($fp, 'EHLO thejunglewhisperer.com', '250');

        if ($secure === 'tls') {
            smtp_cmd($fp, 'STARTTLS', '220');
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new SmtpException('Could not start TLS with the mail server.');
            }
            smtp_cmd($fp, 'EHLO thejunglewhisperer.com', '250');
        }

        if ($user !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', '334');
            smtp_cmd($fp, base64_encode($user), '334');
            smtp_cmd($fp, base64_encode($pass), '235');
        }

        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', '250');
        foreach ($to as $recipient) {
            smtp_cmd($fp, 'RCPT TO:<' . $recipient . '>', '25');
        }
        smtp_cmd($fp, 'DATA', '354');

        $headers = [
            'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Reply-To: ' . ($replyTo !== '' ? $replyTo : $from),
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@thejunglewhisperer.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: tjw-contact',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");

        fwrite($sock, $message . "\r\n.\r\n");
        smtp_read($fp, '250');
        smtp_cmd($fp, 'QUIT', '221');
    } finally {
        @fclose($sock);
    }
}

$from     = (string) ($config['from'] ?? 'jason@thejunglewhisperer.com');
$fromName = (string) ($config['from_name'] ?? 'The Jungle Whisperer');

try {
    smtp_send($config, $to, $from, $fromName, $email, $subject, $body);
    note($dir, 'sent to ' . implode(',', $to) . ' from ' . ip_key(client_ip()));
} catch (Throwable $e) {
    note($dir, 'SMTP FAILURE: ' . $e->getMessage());

    // Last resort: PHP's own mail transport, if the host has it configured.
    $fallback = (bool) ($config['fallback_mail'] ?? false);
    $sent = false;

    if ($fallback) {
        $headers = 'From: ' . $fromName . ' <' . $from . ">\r\n"
            . 'Reply-To: ' . $email . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8';
        $sent = @mail(implode(',', $to), $subject, $body, $headers);
        note($dir, $sent ? 'fallback mail() succeeded' : 'fallback mail() failed');
    }

    if (!$sent) {
        fail(502, 'Our mail server did not accept the message. Please email Jason directly — the address is on this page.');
    }
}

respond(200, ['ok' => true]);
