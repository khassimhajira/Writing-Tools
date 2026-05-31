<?php
/**
 * WriteHuman transparent proxy — drops onto a subdomain folder and
 * forwards every request to writehuman.ai while injecting the shared
 * authenticated cookie. Modeled on the working .sbs proxy the user had
 * on a previous Hostinger account.
 *
 * Goal: make WriteHuman think it lives at the proxy hostname so all its
 * Next.js chunk URLs, /api/ routes, and Supabase callbacks resolve
 * naturally. No HTML rewriting needed — the upstream sees a single
 * hostname with no path prefix, which is what its bundled router and
 * Sentry expect.
 *
 * Drop this file as `index.php` inside the subdomain document root:
 *   /home/u124071091/domains/scholargenie.org/public_html/writehuman/index.php
 *
 * The cookie blob is injected from the AUTH_COOKIE constant below, which
 * we update whenever cookies expire. Single shared cookie pool — same
 * approach as the cookies table in the Hub DB, but read at request time
 * here from a sibling file so we don't need to hit a database.
 *
 * Cookie source file:
 *   /home/u124071091/stealth_data/writehuman_cookie.txt
 *   (plaintext, single line, the full Cookie header value)
 *
 * Optional auth gate: if AUTH_GATE_URL is set, every request first hits
 * that URL with the visitor's stealth_hub_token cookie and only proceeds
 * if it returns 200. That ties subdomain access to a valid Hub session
 * without us re-implementing JWT / single-session here.
 */

// ---------------- Config ----------------
$UPSTREAM       = 'https://writehuman.ai';
$COOKIE_FILE    = '/home/u124071091/stealth_data/writehuman_cookie.txt';
$AUTH_GATE_URL  = 'https://tools.scholargenie.org/hub/api/auth/me';
$ALLOWED_HOSTS  = ['writehuman.scholargenie.org']; // hostname(s) we accept

// ---------------- Auth gate ----------------
// If the visitor doesn't have a valid Hub session, bounce to login.
// We forward their stealth_hub_token cookie to /hub/api/auth/me — if
// that returns 200 they're a paying user; anything else and we redirect
// them to the dashboard which itself redirects to aMember login.
$hubToken = isset($_COOKIE['stealth_hub_token']) ? $_COOKIE['stealth_hub_token'] : '';
if (!$hubToken) {
    header('Location: https://tools.scholargenie.org/dashboard');
    exit;
}
$gateCh = curl_init($AUTH_GATE_URL);
curl_setopt_array($gateCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => ['Cookie: stealth_hub_token=' . $hubToken],
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_NOBODY         => true,
]);
curl_exec($gateCh);
$gateStatus = curl_getinfo($gateCh, CURLINFO_HTTP_CODE);
curl_close($gateCh);
if ($gateStatus !== 200) {
    header('Location: https://tools.scholargenie.org/dashboard');
    exit;
}

// ---------------- Read the shared upstream cookie ----------------
$upstreamCookie = '';
if (is_readable($COOKIE_FILE)) {
    $upstreamCookie = trim(file_get_contents($COOKIE_FILE));
}
// Fail-soft: if the cookie file is missing or empty we still try the
// request (writehuman might serve the public homepage). The user will
// see the marketing page rather than the chat UI, which is at least a
// sign that the proxy itself works.

// ---------------- Build the upstream request ----------------
$path  = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$upstreamUrl = $UPSTREAM . $path;

// Forward most request headers, but replace Host/Cookie/Origin/Referer
// so writehuman.ai sees what it expects.
$forwardHeaders = [];
foreach (getallheaders() as $name => $value) {
    $low = strtolower($name);
    // Skip headers that would confuse the upstream or are owned by us.
    if (in_array($low, ['host', 'cookie', 'origin', 'referer', 'content-length', 'connection', 'accept-encoding'], true)) {
        continue;
    }
    $forwardHeaders[] = $name . ': ' . $value;
}
$forwardHeaders[] = 'Host: writehuman.ai';
$forwardHeaders[] = 'Origin: https://writehuman.ai';
$forwardHeaders[] = 'Referer: https://writehuman.ai' . $path;
if ($upstreamCookie !== '') {
    $forwardHeaders[] = 'Cookie: ' . $upstreamCookie;
}
// Force decompressed text so we can pass through cleanly.
$forwardHeaders[] = 'Accept-Encoding: identity';

// Read the request body (POST/PUT). PHP-FPM gives us this via php://input.
$body = file_get_contents('php://input');

// ---------------- Execute via cURL ----------------
$ch = curl_init($upstreamUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_POSTFIELDS     => $body !== '' ? $body : null,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="font-family:sans-serif;text-align:center;padding:14% 20px;">'
       . '<h2 style="color:#7c3aed;">Service unavailable</h2>'
       . '<p>WriteHuman cannot be reached right now. Please try again in a moment.</p>'
       . '</div>';
    error_log('[writehuman-proxy] curl error: ' . $err);
    exit;
}

$rawHeaders = substr($response, 0, $headerSize);
$body       = substr($response, $headerSize);

// ---------------- Forward upstream headers ----------------
http_response_code($status);
foreach (preg_split("/\r?\n/", $rawHeaders) as $line) {
    if ($line === '' || stripos($line, 'HTTP/') === 0) continue;
    $low = strtolower($line);
    // Strip headers that would force the browser to bypass us.
    if (str_starts_with($low, 'content-encoding:'))  continue;
    if (str_starts_with($low, 'transfer-encoding:')) continue;
    if (str_starts_with($low, 'content-length:'))    continue;
    if (str_starts_with($low, 'content-security-policy:'))  continue;
    if (str_starts_with($low, 'x-frame-options:'))   continue;
    // Rewrite Set-Cookie domain so cookies stick to OUR subdomain, not writehuman.ai.
    if (str_starts_with($low, 'set-cookie:')) {
        $rewritten = preg_replace('/;\s*Domain=[^;]+/i', '', $line);
        $rewritten = preg_replace('/;\s*SameSite=None/i', '; SameSite=Lax', $rewritten);
        header($rewritten, false);
        continue;
    }
    header($line, false);
}

echo $body;
