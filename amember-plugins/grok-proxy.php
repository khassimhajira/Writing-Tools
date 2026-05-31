<?php
/**
 * Grok transparent proxy — sibling to writehuman-proxy.php.
 *
 * Drops onto the `grok.scholargenie.org` subdomain folder and forwards
 * every request to grok.com while injecting the shared authenticated
 * cookies (sso, sso-rw, x-anonuserid) from a server-side file.
 *
 * What this proxy must defeat:
 *
 *   1. Grok's bundle ships an inline hostname guard similar to
 *      writehuman.ai. We override window.location.hostname BEFORE any
 *      script runs, AND zap the literal IIFE via regex.
 *
 *   2. Sentry session-replay tries to instrument the page and crashes
 *      when our proxy origin doesn't match the configured DSN. We stub
 *      out window.Sentry early.
 *
 *   3. CSP / X-Frame-Options / framing headers — stripped so the
 *      iframe is allowed to render.
 *
 *   4. Absolute URLs to https://grok.com / https://x.com — rewritten to
 *      our proxy origin.
 *
 *   5. Account / billing / pricing UI — hidden via injected stylesheet
 *      so students can't escape the chat surface.
 *
 * Drop this file as `index.php` inside the subdomain document root:
 *   /home/u124071091/domains/scholargenie.org/public_html/grok/index.php
 *
 * Cookie source file:
 *   /home/u124071091/stealth_data/grok_cookie.txt
 *   (plaintext, single line, full Cookie header value with at least
 *   `sso=...; sso-rw=...; x-anonuserid=...`).
 */

// ---------------- Config ----------------
$UPSTREAM       = 'https://grok.com';
$COOKIE_FILE    = '/home/u124071091/stealth_data/grok_cookie.txt';
$AUTH_GATE_URL  = 'https://tools.scholargenie.org/hub/api/auth/me';
$PROXY_HOST     = $_SERVER['HTTP_HOST'] ?? 'grok.scholargenie.org';

// Helper: forward upstream response headers, stripping framing-killers
// and rewriting Set-Cookie / Location. Used in both buffered and
// streaming modes.
function _forward_response_headers($headerLines) {
    global $PROXY_HOST;
    foreach ($headerLines as $line) {
        if ($line === '' || stripos($line, 'HTTP/') === 0) continue;
        $low = strtolower($line);
        if (str_starts_with($low, 'content-encoding:'))                 continue;
        if (str_starts_with($low, 'transfer-encoding:'))                continue;
        if (str_starts_with($low, 'content-length:'))                   continue;
        if (str_starts_with($low, 'content-security-policy:'))          continue;
        if (str_starts_with($low, 'content-security-policy-report-only:')) continue;
        if (str_starts_with($low, 'x-frame-options:'))                  continue;
        if (str_starts_with($low, 'cross-origin-opener-policy:'))       continue;
        if (str_starts_with($low, 'cross-origin-embedder-policy:'))     continue;
        if (str_starts_with($low, 'cross-origin-resource-policy:'))     continue;
        if (str_starts_with($low, 'permissions-policy:'))               continue;
        if (str_starts_with($low, 'report-to:'))                        continue;
        if (str_starts_with($low, 'reporting-endpoints:'))              continue;
        if (str_starts_with($low, 'document-policy:'))                  continue;

        if (str_starts_with($low, 'set-cookie:')) {
            $rewritten = preg_replace('/;\s*Domain=[^;]+/i', '', $line);
            $rewritten = preg_replace('/;\s*SameSite=Strict/i', '; SameSite=Lax', $rewritten);
            header($rewritten, false);
            continue;
        }
        if (str_starts_with($low, 'location:')) {
            $rewritten = preg_replace('#https?://grok\.com#i', 'https://' . $PROXY_HOST, $line);
            header($rewritten, false);
            continue;
        }
        header($line, false);
    }
}

function _isRewritable($contentType) {
    // Only HTML needs server-side rewriting (it\'s the only doc the
    // browser parses with absolute URLs that aren\'t intercepted by
    // our fetch shim). Everything else streams to keep RSC, SSE, JSON
    // streams, and chat tokens flowing chunk-by-chunk. The client-side
    // fetch interceptor handles URL rewriting at request time.
    $ct = strtolower($contentType);
    return strpos($ct, 'text/html') !== false;
}

// Grok is behind Cloudflare bot challenges. Hostinger's datacenter IP
// gets 403'd ("Just a moment..."). We route every upstream request
// through a residential proxy from the rotating pool — same proxies
// the Node app uses for stealthwriter.
$PROXY_POOL_FILE = '/home/u124071091/stealth_data/grok_upstream_proxies.txt';
$UPSTREAM_PROXY  = '';
if (is_readable($PROXY_POOL_FILE)) {
    $proxies = array_filter(array_map('trim', file($PROXY_POOL_FILE)));
    if (!empty($proxies)) {
        // Sticky-ish: pick by visitor IP + day so the same student keeps
        // hitting the same exit IP for the day. Reduces Cloudflare risk
        // scores and keeps Grok's session-cookie heuristics happy.
        $key = ($_SERVER['REMOTE_ADDR'] ?? '0') . ':' . date('Y-m-d');
        $idx = abs(crc32($key)) % count($proxies);
        $UPSTREAM_PROXY = $proxies[$idx];
    }
}

// ---------------- Auth gate (only on document/iframe nav) ----------------
$secFetchDest = strtolower($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');
$accept       = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
$path         = $_SERVER['REQUEST_URI'] ?? '/';

$isDocumentNav = false;
if ($secFetchDest === 'document' || $secFetchDest === 'iframe') {
    $isDocumentNav = true;
} elseif ($secFetchDest === '') {
    $looksLikeAsset = (bool) preg_match('#\.(js|css|png|jpg|jpeg|webp|svg|gif|ico|woff2?|ttf|otf|webmanifest|map|json|mp4|wasm)(\?|$)#i', $path);
    if (!$looksLikeAsset && strpos($accept, 'text/html') !== false) {
        $isDocumentNav = true;
    }
}

if ($isDocumentNav) {
    $hubToken = $_COOKIE['stealth_hub_token'] ?? '';
    $authed = false;
    if ($hubToken) {
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
        $authed = ($gateStatus === 200);
    }
    if (!$authed) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Sign in required</title>'
           . '<style>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'
           . 'background:linear-gradient(135deg,#0f0720,#1a0f3a);color:#fff;display:flex;'
           . 'align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}'
           . '.card{max-width:420px;text-align:center;background:rgba(255,255,255,0.06);'
           . 'border:1px solid rgba(255,255,255,0.14);border-radius:18px;padding:36px 28px;'
           . 'backdrop-filter:blur(14px);}'
           . 'a{display:inline-block;margin-top:18px;background:#fff;color:#0f0720;font-weight:700;'
           . 'padding:11px 22px;border-radius:11px;text-decoration:none;}</style></head><body>'
           . '<div class="card"><div style="font-size:42px;margin-bottom:8px;">&#128274;</div>'
           . '<h2 style="margin:0 0 6px;">Sign in to use Grok</h2>'
           . '<p style="opacity:0.85;margin:0;">Your Hub session has expired. Sign in to continue.</p>'
           . '<a href="https://tools.scholargenie.org/" target="_top">Back to portal</a></div></body></html>';
        exit;
    }
}

// ---------------- Read shared upstream cookie ----------------
$upstreamCookie = '';
if (is_readable($COOKIE_FILE)) {
    $upstreamCookie = trim(file_get_contents($COOKIE_FILE));
}

// ---------------- Cloudflare /cdn-cgi/* short-circuit ----------------
// Cloudflare auto-injects scripts that POST telemetry to /cdn-cgi/rum,
// /cdn-cgi/speedbrain, /cdn-cgi/zaraz, etc. On grok.com these endpoints
// are served by Cloudflare directly (not the origin), but our subdomain
// only has Cloudflare's basic CDN — those paths return 404. Some of
// Grok's bundled JS treats a 404 here as a fatal error and trips the
// React error boundary ("Something went wrong"). Returning a benign
// 204 No Content tells the beacon it succeeded and the page keeps
// running.
if (preg_match('#^/cdn-cgi/#', $path) || preg_match('#^/monitoring(\?|$)#', $path)) {
    http_response_code(204);
    header('Content-Length: 0');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    exit;
}

// ---------------- assets.grok.com / cdn.grok.com proxies ----------------
// Frontend code that points at the assets/CDN gets rewritten to
// /__grok_assets/... or /__grok_cdn/... by our client-side fetch
// interceptor. We rewrite the path back here and forward to the real
// CDN. Some assets (avatars, private uploads) require auth, so we
// forward the upstream session cookies too.
if (preg_match('#^/__grok_(assets|cdn)(/.*)?$#', $path, $assetMatch)) {
    $cdnHost   = ($assetMatch[1] === 'cdn') ? 'cdn.grok.com' : 'assets.grok.com';
    $assetPath = $assetMatch[2] ?? '/';
    $assetUrl  = 'https://' . $cdnHost . $assetPath;
    $upstreamCookieForAsset = '';
    if (is_readable($COOKIE_FILE)) $upstreamCookieForAsset = trim(file_get_contents($COOKIE_FILE));
    $assetHeaders = [
        'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0'),
        'Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? '*/*'),
        'Referer: https://grok.com/',
        'Origin: https://grok.com',
    ];
    if ($upstreamCookieForAsset !== '') $assetHeaders[] = 'Cookie: ' . $upstreamCookieForAsset;
    $ach = curl_init($assetUrl);
    curl_setopt_array($ach, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $assetHeaders,
    ]);
    $assetBody = curl_exec($ach);
    $assetStatus = curl_getinfo($ach, CURLINFO_HTTP_CODE);
    $assetCT = curl_getinfo($ach, CURLINFO_CONTENT_TYPE);
    curl_close($ach);
    // For images (avatars), serve a transparent fallback on failure so
    // the browser doesn\'t flag a broken image.
    if (($assetStatus < 200 || $assetStatus >= 300) && strpos($assetCT, 'image/') !== false) {
        http_response_code(200);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=300');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        exit;
    }
    http_response_code($assetStatus);
    if ($assetCT) header('Content-Type: ' . $assetCT);
    header('Cache-Control: public, max-age=86400');
    if ($assetBody !== false) echo $assetBody;
    exit;
}

// Parse individual cookies so we can plant them on our subdomain via
// Set-Cookie. Grok's frontend reads its session from document.cookie
// on the client, exactly like Supabase does for WriteHuman.
$plantCookies = [];
if ($upstreamCookie !== '') {
    foreach (explode(';', $upstreamCookie) as $kv) {
        $kv = trim($kv);
        if ($kv === '') continue;
        $eq = strpos($kv, '=');
        if ($eq === false) continue;
        $cname = trim(substr($kv, 0, $eq));
        $cval  = substr($kv, $eq + 1);
        if ($cname === '') continue;
        $plantCookies[$cname] = $cval;
    }
}

// ---------------- Build upstream request ----------------
$method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$upstreamUrl = $UPSTREAM . $path;

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $h[$name] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']))   $h['Content-Type']   = $_SERVER['CONTENT_TYPE'];
        if (isset($_SERVER['CONTENT_LENGTH'])) $h['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        return $h;
    }
}

$forwardHeaders = [];
foreach (getallheaders() as $name => $value) {
    $low = strtolower($name);
    if (in_array($low, [
        'host', 'cookie', 'origin', 'referer', 'content-length',
        'connection', 'accept-encoding', 'sec-fetch-site',
    ], true)) continue;
    $forwardHeaders[] = $name . ': ' . $value;
}
$forwardHeaders[] = 'Host: grok.com';
$forwardHeaders[] = 'Origin: https://grok.com';
$forwardHeaders[] = 'Referer: https://grok.com' . $path;
$forwardHeaders[] = 'Sec-Fetch-Site: same-origin';
if ($upstreamCookie !== '') $forwardHeaders[] = 'Cookie: ' . $upstreamCookie;
$forwardHeaders[] = 'Accept-Encoding: identity';

$body = file_get_contents('php://input');

// ---------------- Execute via cURL ----------------
// ---------------- Execute via cURL ----------------
//
// Two-mode strategy:
//
//   * For requests we KNOW need body rewriting (HTML, JSON, JS, CSS),
//     we buffer the full response, rewrite, and emit. This is the
//     classic mode and is used for the document path on first load.
//
//   * For everything else (streaming RSC `text/x-component`, SSE event
//     streams, opaque chunked responses, large media), we stream chunks
//     to the browser as they arrive using CURLOPT_WRITEFUNCTION. This is
//     critical for Grok\'s chat surface: token-by-token streaming through
//     a buffered proxy looks like "connection closed" to the React tree.
//
// Decide the mode from the request path. Document fetches and known
// rewrite-needed endpoints use buffered mode; everything else streams.
// We resolve content-type from the response headers in streaming mode
// AFTER receiving them, falling back to "stream" for ambiguous cases.

$pathLooksDoc = (
    $path === '/' ||
    $path === '' ||
    preg_match('#^/[^./?]*/?(\?|$)#', $path)  // /foo, /foo/bar, no extension
);

// We can\'t know the content-type ahead of time for arbitrary requests,
// so we make a single cURL call but use a streaming writer that buffers
// only when the response is rewriteable. The writer collects header
// bytes first, decides streaming vs buffering, and dispatches.

$_streamMode    = null;     // null until we know
$_streamBuffer  = '';
$_responseHeaders = [];
$_statusEmitted = false;
$_responseStatus = 200;

$ch = curl_init($upstreamUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_POSTFIELDS     => $body !== '' ? $body : null,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    // Header callback: collect, then on first body byte decide mode.
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$_responseHeaders, &$_responseStatus) {
        $trim = trim($line);
        if ($trim === '') return strlen($line);
        if (preg_match('#^HTTP/[\d.]+ (\d+)#', $trim, $m)) {
            $_responseStatus = (int)$m[1];
            $_responseHeaders = [];   // reset on redirect chain
            return strlen($line);
        }
        $_responseHeaders[] = $trim;
        return strlen($line);
    },
    // Body writer: decides mode on first chunk. Streams or buffers.
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (
        &$_streamMode, &$_streamBuffer, &$_responseHeaders, &$_statusEmitted, &$_responseStatus, &$_isStreamRewritable
    ) {
        if ($_streamMode === null) {
            // Determine mode from headers we collected.
            $contentType = '';
            foreach ($_responseHeaders as $h) {
                if (stripos($h, 'content-type:') === 0) {
                    $contentType = substr($h, 13);
                    break;
                }
            }
            $rewritable = _isRewritable($contentType);
            $_streamMode = $rewritable ? 'buffer' : 'stream';

            if ($_streamMode === 'stream') {
                // Emit headers immediately so the client can start parsing.
                http_response_code($_responseStatus);
                _forward_response_headers($_responseHeaders);
                // Disable PHP-level output buffering so flush() goes to the wire.
                if (function_exists('ob_implicit_flush')) ob_implicit_flush(true);
                while (ob_get_level() > 0) ob_end_flush();
                $_statusEmitted = true;
            }
        }

        if ($_streamMode === 'stream') {
            echo $chunk;
            if (function_exists('flush')) @flush();
            return strlen($chunk);
        } else {
            $_streamBuffer .= $chunk;
            return strlen($chunk);
        }
    },
]);
if ($UPSTREAM_PROXY) {
    curl_setopt($ch, CURLOPT_PROXY, $UPSTREAM_PROXY);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 25);
}

$execOk = curl_exec($ch);
$err    = curl_error($ch);
curl_close($ch);

if ($err) {
    if (!$_statusEmitted) {
        http_response_code(502);
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="font-family:sans-serif;text-align:center;padding:14% 20px;">'
           . '<h2 style="color:#0f0720;">Service unavailable</h2>'
           . '<p>Grok cannot be reached right now. Please try again in a moment.</p>'
           . '</div>';
    }
    error_log('[grok-proxy] curl error: ' . $err);
    exit;
}

// In stream mode we already emitted the body. Done.
if ($_streamMode === 'stream') exit;

// In buffer mode we still need to rewrite + send.
$body = $_streamBuffer;
$status = $_responseStatus;
$rawHeaders = implode("\r\n", $_responseHeaders);

// If the response was empty (e.g. 204), skip rewrites entirely.
if ($body === '' || $body === false) {
    http_response_code($status);
    _forward_response_headers($_responseHeaders);
    exit;
}

// ---------------- Detect content type ----------------
$contentTypeHeader = '';
foreach (preg_split("/\r?\n/", $rawHeaders) as $line) {
    if (stripos($line, 'content-type:') === 0) { $contentTypeHeader = strtolower($line); break; }
}
$isHtml = (strpos($contentTypeHeader, 'text/html') !== false);
$isJson = (strpos($contentTypeHeader, 'application/json') !== false);
$isJs   = (strpos($contentTypeHeader, 'javascript') !== false);
$isCss  = (strpos($contentTypeHeader, 'text/css') !== false);
$isRsc  = (strpos($contentTypeHeader, 'text/x-component') !== false);

// ---------------- Rewrite URLs ----------------
if (($isHtml || $isJson || $isJs || $isCss || $isRsc) && $body !== '') {
    $body = str_replace('https://grok.com',  'https://' . $PROXY_HOST, $body);
    $body = str_replace('http://grok.com',   'https://' . $PROXY_HOST, $body);
    $body = str_replace('//grok.com',        '//' . $PROXY_HOST,       $body);
    $body = str_replace('https:\\/\\/grok.com', 'https:\\/\\/' . $PROXY_HOST, $body);
    // Also rewrite the assets CDN so avatars/static load through us.
    $body = str_replace('https://assets.grok.com',  'https://' . $PROXY_HOST . '/__grok_assets', $body);
    $body = str_replace('https:\\/\\/assets.grok.com', 'https:\\/\\/' . $PROXY_HOST . '\\/__grok_assets', $body);
    // And the JS chunk CDN so Next.js script src tags resolve through us.
    $body = str_replace('https://cdn.grok.com',  'https://' . $PROXY_HOST . '/__grok_cdn', $body);
    $body = str_replace('https:\\/\\/cdn.grok.com', 'https:\\/\\/' . $PROXY_HOST . '\\/__grok_cdn', $body);

    // Neutralize generic hostname-guard IIFEs that redirect to grok.com.
    $body = preg_replace(
        '/\(function\(\)\{var h=location\.hostname;[^}]+\}\)\(\)/',
        '(function(){})()',
        $body
    );
    $body = preg_replace(
        '/\(function\(\)\{var h=location\.hostname;[^}]+\}\)\(\)/u',
        '(function(){})()',
        $body
    );
}

// ---------------- HTML-only patches ----------------
if ($isHtml && $body !== '') {
    // Strip Cloudflare's auto-injected RUM / Speed-Brain / Zaraz scripts
    // from the upstream HTML. They reference /cdn-cgi/* endpoints and
    // /__cf/* assets that don't exist on our subdomain. The React error
    // boundary trips when their callbacks fail.
    $body = preg_replace(
        '#<script[^>]*?(?:/cdn-cgi/|cloudflare-static|speedbrain|/beacon\.min\.js)[^<]*?</script>#is',
        '',
        $body
    );
    // Some are loaded via inline <script> with the script body referencing
    // those paths. Zap any inline script that calls /cdn-cgi/.
    $body = preg_replace(
        '#<script[^>]*>[^<]*?/cdn-cgi/[^<]*?</script>#is',
        '',
        $body
    );

    foreach ($plantCookies as $cname => $cval) {
        $cookieHeader = $cname . '=' . $cval . '; Path=/; Max-Age=2592000; Secure; SameSite=Lax';
        header('Set-Cookie: ' . $cookieHeader, false);
    }

    $hostnameOverride = '<script>(function(){'
        . 'try{Object.defineProperty(location,"hostname",{configurable:true,get:function(){return "grok.com";}});'
        . 'Object.defineProperty(location,"host",{configurable:true,get:function(){return "grok.com";}});'
        . '}catch(e){}'
        // Stub Sentry to a no-op so its session-replay tracing doesn\'t
        // fight our location override.
        . 'try{window.__SENTRY__={};window.SENTRY_RELEASE={};window.Sentry={'
        . 'init:function(){},captureException:function(){},captureMessage:function(){},'
        . 'addBreadcrumb:function(){},configureScope:function(){},withScope:function(fn){try{fn({setTag:function(){},setExtra:function(){},setUser:function(){}})}catch(e){}},'
        . 'setUser:function(){},setTag:function(){},setExtra:function(){},setContext:function(){},'
        . 'getCurrentHub:function(){return{getClient:function(){return null;},getScope:function(){return{setTag:function(){},setUser:function(){}};}};}'
        . '};}catch(e){}'
        // Capture errors so they\'re visible in DevTools as a structured
        // log line, even if React\'s own boundary swallows them. Helps
        // us diagnose what\'s actually triggering "Something went wrong".
        . 'try{var BAD_NOOP=/^xx_does_not_match$/;'
        . 'window.addEventListener("error",function(e){'
            . 'try{console.warn("[grok-proxy.error]",e&&e.message,e&&e.filename,e&&(e.lineno+":"+e.colno),e&&e.error&&e.error.stack);}catch(_){}'
        . '},true);'
        . 'window.addEventListener("unhandledrejection",function(e){'
            . 'try{var r=e&&e.reason; console.warn("[grok-proxy.unhandled]", r&&r.message||String(r), r&&r.stack||"");}catch(_){}'
        . '});'
        . '}catch(e){}'
        // Swallow Cloudflare RUM beacons. The minified JS posts to
        // /cdn-cgi/rum on every interaction; if the response isn\'t 200,
        // their callback throws an exception that bubbles up to React\'s
        // error boundary. We make it always succeed-as-noop.
        // We ALSO use this layer to rewrite outgoing URLs from grok.com
        // to our proxy host. Server-side rewriting was buffering streams;
        // doing it client-side keeps streams flowing.
        . 'try{var PROXY_HOST=location.host;'
        . 'function _rewriteUrl(u){'
            . 'try{if(typeof u!=="string")return u;'
            . 'if(u.indexOf("https://grok.com")===0)return "https://"+PROXY_HOST+u.slice(16);'
            . 'if(u.indexOf("http://grok.com")===0)return "https://"+PROXY_HOST+u.slice(15);'
            . 'if(u.indexOf("//grok.com")===0)return "//"+PROXY_HOST+u.slice(10);'
            . 'if(u.indexOf("https://assets.grok.com")===0)return "https://"+PROXY_HOST+"/__grok_assets"+u.slice(23);'
            . 'if(u.indexOf("https://cdn.grok.com")===0)return "https://"+PROXY_HOST+"/__grok_cdn"+u.slice(20);'
            . '}catch(e){}return u;'
        . '}'
        . 'var _origFetch=window.fetch;'
        . 'window.fetch=function(input,init){'
            . 'try{var url=typeof input==="string"?input:(input&&input.url)||"";'
            . 'if(url && (url.indexOf("/cdn-cgi/")!==-1 || url.indexOf("/monitoring?")!==-1 || url.indexOf("/monitoring/")!==-1)){return Promise.resolve(new Response(null,{status:204,statusText:"No Content"}));}'
            . 'if(typeof input==="string"){var ru=_rewriteUrl(input);if(ru!==input)input=ru;}'
            . 'else if(input && input.url){var ru2=_rewriteUrl(input.url);if(ru2!==input.url){input=new Request(ru2,input);}}'
            . '}catch(e){}'
            . 'return _origFetch.apply(this,[input,init]);'
        . '};'
        . 'var _origSend=XMLHttpRequest.prototype.send;'
        . 'var _origOpen=XMLHttpRequest.prototype.open;'
        . 'XMLHttpRequest.prototype.open=function(method,url){'
            . 'this.__cdn_cgi=(typeof url==="string"&&(url.indexOf("/cdn-cgi/")!==-1||url.indexOf("/monitoring?")!==-1||url.indexOf("/monitoring/")!==-1));'
            . 'if(typeof url==="string")arguments[1]=_rewriteUrl(url);'
            . 'return _origOpen.apply(this,arguments);'
        . '};'
        . 'XMLHttpRequest.prototype.send=function(){'
            . 'if(this.__cdn_cgi){'
                . 'var self=this;setTimeout(function(){'
                    . 'try{Object.defineProperty(self,"readyState",{value:4,configurable:true});'
                    . 'Object.defineProperty(self,"status",{value:204,configurable:true});'
                    . 'Object.defineProperty(self,"responseText",{value:"",configurable:true});'
                    . 'if(typeof self.onreadystatechange==="function")self.onreadystatechange();'
                    . 'if(typeof self.onload==="function")self.onload();'
                    . '}catch(e){}'
                . '},0);return;'
            . '}'
            . 'return _origSend.apply(this,arguments);'
        . '};'
        // WebSocket URL rewrite — Grok may open ws://grok.com/* for live
        // chat; route to our subdomain so the WS handshake reaches us.
        . 'try{var _OrigWS=window.WebSocket;'
        . 'window.WebSocket=function(url,protocols){'
            . 'try{if(typeof url==="string"){'
                . 'if(url.indexOf("wss://grok.com")===0)url="wss://"+PROXY_HOST+url.slice(14);'
                . 'else if(url.indexOf("ws://grok.com")===0)url="wss://"+PROXY_HOST+url.slice(13);'
            . '}}catch(e){}'
            . 'return new _OrigWS(url,protocols);'
        . '};'
        . 'window.WebSocket.prototype=_OrigWS.prototype;'
        . 'window.WebSocket.CONNECTING=_OrigWS.CONNECTING;'
        . 'window.WebSocket.OPEN=_OrigWS.OPEN;'
        . 'window.WebSocket.CLOSING=_OrigWS.CLOSING;'
        . 'window.WebSocket.CLOSED=_OrigWS.CLOSED;'
        . '}catch(e){}'
        . 'if(navigator.sendBeacon){var _origBeacon=navigator.sendBeacon.bind(navigator);'
            . 'navigator.sendBeacon=function(url,data){'
                . 'try{if(typeof url==="string" && (url.indexOf("/cdn-cgi/")!==-1||url.indexOf("/monitoring")!==-1))return true;'
                . 'if(typeof url==="string")url=_rewriteUrl(url);}catch(e){}'
                . 'return _origBeacon(url,data);'
            . '};}'
        . '}catch(e){}'
        . '})();</script>';

    // ---------------- Cosmetic hider ----------------
    // Hide UI that would expose the master account or let students leave
    // the chat surface. Same pattern as writehuman-proxy.php. We're
    // conservative: only hide elements whose VISIBLE TEXT or HREF clearly
    // marks them as account / billing / sign-out / pricing controls.
    $cosmeticHider = '<style>'
        // Common nav anchors by href.
        . 'a[href*="/account"],'
        . 'a[href*="/settings/account"],'
        . 'a[href*="/billing"],'
        . 'a[href*="/pricing"],'
        . 'a[href*="/upgrade"],'
        . 'a[href*="/subscribe"],'
        . 'a[href*="/sign-out"],'
        . 'a[href*="/signout"],'
        . 'a[href*="/logout"],'
        . 'a[href*="/api"],'
        . 'a[href*="/affiliate"]'
        . '{display:none !important;}'
        // The big "Sign Up" / "Log In" buttons that show before our
        // injected cookies have been picked up by the SPA.
        . 'button[data-testid*="sign-up" i],'
        . 'button[data-testid*="login" i],'
        . 'button[data-testid*="upgrade" i],'
        . 'a[data-testid*="sign-up" i],'
        . 'a[data-testid*="login" i]'
        . '{display:none !important;}'
        . '</style>';

    $hideScript = '<script>(function(){'
        . 'function hideByText(){'
        . 'var bad=/^(My Account|Account|Settings|Billing|Subscription|Manage Subscription|Sign Out|Sign out|Logout|Log Out|Log out|Pricing|Upgrade|Upgrade to Pro|Upgrade to SuperGrok|Subscribe|API|Affiliate|Refer)$/i;'
        . 'var nodes=document.querySelectorAll("header a,header button,nav a,nav button,aside a,aside button,[role=menu] a,[role=menu] button,[role=menuitem]");'
        . 'for(var i=0;i<nodes.length;i++){'
            . 'var t=(nodes[i].textContent||"").trim();'
            . 'if(bad.test(t)){'
                . 'var n=nodes[i];'
                . 'try{n.style.display="none";'
                    . 'if(n.parentElement && n.parentElement.tagName==="LI"){n.parentElement.style.display="none";}'
                . '}catch(e){}'
            . '}'
        . '}'
        . '}'
        . 'function start(){hideByText();var mo=new MutationObserver(hideByText);mo.observe(document.documentElement,{subtree:true,childList:true});}'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start);}else{start();}'
        . '})();</script>';

    $body = preg_replace(
        '/<head([^>]*)>/i',
        '<head$1>' . $hostnameOverride . $cosmeticHider . $hideScript . '<base href="https://' . htmlspecialchars($PROXY_HOST, ENT_QUOTES) . '/">',
        $body,
        1
    );
}

// ---------------- Forward upstream headers (buffer mode only) ----------------
http_response_code($status);
_forward_response_headers($_responseHeaders);

if ($isHtml) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

echo $body;
