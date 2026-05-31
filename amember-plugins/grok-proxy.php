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
$ch = curl_init($upstreamUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_POSTFIELDS     => $body !== '' ? $body : null,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response   = curl_exec($ch);
$status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$err        = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="font-family:sans-serif;text-align:center;padding:14% 20px;">'
       . '<h2 style="color:#0f0720;">Service unavailable</h2>'
       . '<p>Grok cannot be reached right now. Please try again in a moment.</p>'
       . '</div>';
    error_log('[grok-proxy] curl error: ' . $err);
    exit;
}

$rawHeaders = substr($response, 0, $headerSize);
$body       = substr($response, $headerSize);

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
    foreach ($plantCookies as $cname => $cval) {
        $cookieHeader = $cname . '=' . $cval . '; Path=/; Max-Age=2592000; Secure; SameSite=Lax';
        header('Set-Cookie: ' . $cookieHeader, false);
    }

    $hostnameOverride = '<script>(function(){'
        . 'try{Object.defineProperty(location,"hostname",{configurable:true,get:function(){return "grok.com";}});'
        . 'Object.defineProperty(location,"host",{configurable:true,get:function(){return "grok.com";}});'
        . '}catch(e){}'
        . 'try{window.__SENTRY__={};window.SENTRY_RELEASE={};window.Sentry={'
        . 'init:function(){},captureException:function(){},captureMessage:function(){},'
        . 'addBreadcrumb:function(){},configureScope:function(){},withScope:function(fn){try{fn({setTag:function(){},setExtra:function(){},setUser:function(){}})}catch(e){}},'
        . 'setUser:function(){},setTag:function(){},setExtra:function(){},setContext:function(){},'
        . 'getCurrentHub:function(){return{getClient:function(){return null;},getScope:function(){return{setTag:function(){},setUser:function(){}};}};}'
        . '};}catch(e){}'
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

// ---------------- Forward upstream headers ----------------
http_response_code($status);
foreach (preg_split("/\r?\n/", $rawHeaders) as $line) {
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

if ($isHtml) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

echo $body;
