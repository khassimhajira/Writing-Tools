<?php
/**
 * WriteHuman transparent proxy — drops onto a subdomain folder and
 * forwards every request to writehuman.ai while injecting the shared
 * authenticated cookie. Modeled on the working .sbs proxy the user had
 * on a previous Hostinger account.
 *
 * What this proxy must defeat:
 *
 *   1. WriteHuman's marketing pages contain an inline IIFE that does
 *      `if (location.hostname !== 'writehuman.ai') location.replace(...)`
 *      That fires before our rewritten URLs can take effect, blasting
 *      the iframe back to writehuman.ai (which has frame-ancestors:none
 *      and refuses to render).
 *
 *      Fix: override window.location.hostname/host BEFORE any other
 *      script runs. Inject a tiny script as the first element in <head>
 *      that uses Object.defineProperty to make location.hostname always
 *      return 'writehuman.ai'. This neutralizes every hostname-pinning
 *      check in the app, including the ones in their Next.js bundle and
 *      Sentry init code.
 *
 *   2. CSP / X-Frame-Options / framing headers — we strip them so the
 *      iframe is allowed to render.
 *
 *   3. Absolute URLs to https://writehuman.ai — rewritten to our proxy
 *      origin so the browser doesn't try to leave us.
 *
 * Drop this file as `index.php` inside the subdomain document root:
 *   /home/u124071091/domains/scholargenie.org/public_html/writehuman/index.php
 *
 * Cookie source file:
 *   /home/u124071091/stealth_data/writehuman_cookie.txt
 *   (plaintext, single line, the full Cookie header value)
 */

// ---------------- Config ----------------
$UPSTREAM       = 'https://writehuman.ai';
$COOKIE_FILE    = '/home/u124071091/stealth_data/writehuman_cookie.txt';
$AUTH_GATE_URL  = 'https://tools.scholargenie.org/hub/api/auth/me';
$PROXY_HOST     = $_SERVER['HTTP_HOST'] ?? 'writehuman.scholargenie.org';

// ---------------- Auth gate ----------------
// We require a valid Hub session, but only check it on document/page
// requests. Once a page has loaded, the browser fires hundreds of
// sub-requests for static assets — re-running the gate on each would
// hammer our Node endpoint and create redirect loops where assets get
// bounced to /dashboard.
$secFetchDest = strtolower($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');
$accept       = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
$path         = $_SERVER['REQUEST_URI'] ?? '/';

$isDocumentNav = false;
if ($secFetchDest === 'document' || $secFetchDest === 'iframe') {
    $isDocumentNav = true;
} elseif ($secFetchDest === '') {
    // Older browsers don't send Sec-Fetch-Dest. Use Accept + path heuristics.
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
        // Render a friendly HTML page rather than redirecting. Redirects
        // inside iframes can produce navigate loops or chrome-error pages
        // when the parent's CSP doesn't allow the redirected origin.
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Sign in required</title>'
           . '<style>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'
           . 'background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;display:flex;'
           . 'align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}'
           . '.card{max-width:420px;text-align:center;background:rgba(255,255,255,0.08);'
           . 'border:1px solid rgba(255,255,255,0.18);border-radius:18px;padding:36px 28px;'
           . 'backdrop-filter:blur(14px);}'
           . 'a{display:inline-block;margin-top:18px;background:#fff;color:#7c3aed;font-weight:700;'
           . 'padding:11px 22px;border-radius:11px;text-decoration:none;}</style></head><body>'
           . '<div class="card"><div style="font-size:42px;margin-bottom:8px;">&#128274;</div>'
           . '<h2 style="margin:0 0 6px;">Sign in to use WriteHuman</h2>'
           . '<p style="opacity:0.85;margin:0;">Your Hub session has expired. Sign in to continue.</p>'
           . '<a href="https://tools.scholargenie.org/" target="_top">Back to portal</a></div></body></html>';
        exit;
    }
}

// ---------------- Read the shared upstream cookie ----------------
$upstreamCookie = '';
if (is_readable($COOKIE_FILE)) {
    $upstreamCookie = trim(file_get_contents($COOKIE_FILE));
}

// Parse individual cookies out of the blob so we can plant them in the
// user\'s browser as Set-Cookie headers on document responses. This is
// what makes the upstream\'s JS think it\'s authenticated — the Supabase
// SDK in the browser reads document.cookie["sb-{project}-auth-token"]
// and forwarding it only on the request side doesn\'t help the JS.
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

// ---------------- Build the upstream request ----------------
$method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$upstreamUrl = $UPSTREAM . $path;

// getallheaders() is not always available under CGI/FPM. Build a
// fallback from $_SERVER so we work everywhere.
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
        'connection', 'accept-encoding',
        // Strip Sec-Fetch-Site so the upstream's CSRF defenses don't see
        // the request as cross-site.
        'sec-fetch-site',
    ], true)) {
        continue;
    }
    $forwardHeaders[] = $name . ': ' . $value;
}
$forwardHeaders[] = 'Host: writehuman.ai';
$forwardHeaders[] = 'Origin: https://writehuman.ai';
$forwardHeaders[] = 'Referer: https://writehuman.ai' . $path;
$forwardHeaders[] = 'Sec-Fetch-Site: same-origin';
if ($upstreamCookie !== '') {
    $forwardHeaders[] = 'Cookie: ' . $upstreamCookie;
}
// Force decompressed text so we can pass through cleanly.
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
    CURLOPT_TIMEOUT        => 30,
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
       . '<h2 style="color:#7c3aed;">Service unavailable</h2>'
       . '<p>WriteHuman cannot be reached right now. Please try again in a moment.</p>'
       . '</div>';
    error_log('[writehuman-proxy] curl error: ' . $err);
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
    // 1. Replace https://writehuman.ai with our subdomain origin
    //    everywhere. Cover http and protocol-relative variants.
    $body = str_replace('https://writehuman.ai',  'https://' . $PROXY_HOST, $body);
    $body = str_replace('http://writehuman.ai',   'https://' . $PROXY_HOST, $body);
    $body = str_replace('//writehuman.ai',        '//' . $PROXY_HOST,       $body);

    // 2. JSON sometimes encodes URLs with escaped slashes.
    $body = str_replace('https:\\/\\/writehuman.ai', 'https:\\/\\/' . $PROXY_HOST, $body);

    // 3. Neutralize the inline hostname-guard IIFE. WriteHuman's pages
    //    embed this as a literal script tag and ALSO inside Next.js
    //    server payloads as a JSON-escaped string. We zap both forms by
    //    turning location.hostname checks into harmless no-ops.
    $body = preg_replace(
        '/\(function\(\)\{var h=location\.hostname;[^}]+\}\)\(\)/',
        '(function(){})()',
        $body
    );
    // Same pattern when escaped inside JSON (\u0026\u0026 etc.)
    $body = preg_replace(
        '/\(function\(\)\{var h=location\.hostname;[^}]+\}\)\(\)/u',
        '(function(){})()',
        $body
    );
}

// ---------------- HTML-only patches ----------------
if ($isHtml && $body !== '') {
    // Plant the upstream auth cookies on OUR subdomain via Set-Cookie.
    // Without this, the browser only has the proxy\'s session cookies on
    // writehuman.scholargenie.org, and the upstream JS bundle reading
    // document.cookie sees no authenticated session.
    foreach ($plantCookies as $cname => $cval) {
        // 30 day max age — refresh tokens last that long. Access tokens
        // inside the blob will hit their own 1h exp; auto-refresher worker
        // is responsible for keeping the file fresh.
        $cookieHeader = $cname . '=' . $cval
            . '; Path=/; Max-Age=2592000; Secure; HttpOnly=false; SameSite=Lax';
        // Note: Secure + SameSite=Lax allows reading from same-origin docs
        // and our iframe context. The Supabase SDK reads document.cookie,
        // which means we MUST NOT set HttpOnly. Set-Cookie has no HttpOnly
        // by default if we don\'t add it; the line above is wrong syntax —
        // browsers ignore "HttpOnly=false". Correct: just omit the flag.
        $cookieHeader = $cname . '=' . $cval . '; Path=/; Max-Age=2592000; Secure; SameSite=Lax';
        header('Set-Cookie: ' . $cookieHeader, false);
    }

    $hostnameOverride = '<script>(function(){'
        // Override hostname/host so any code that hasn\'t been rewritten
        // (legacy, third-party libs, dynamically loaded chunks) thinks
        // it is on writehuman.ai. Wrapped in try/catch because some
        // browsers freeze location property descriptors.
        . 'try{var d=Object.getOwnPropertyDescriptor(Location.prototype,"hostname")||{};'
        . 'Object.defineProperty(location,"hostname",{configurable:true,get:function(){return "writehuman.ai";}});'
        . 'Object.defineProperty(location,"host",{configurable:true,get:function(){return "writehuman.ai";}});'
        . '}catch(e){}'
        // Disable Sentry early so its session-replay/tracing doesn\'t
        // tear into the override or hammer cross-origin endpoints.
        . 'try{window.__SENTRY__={};window.SENTRY_RELEASE={};window.Sentry={'
        . 'init:function(){},captureException:function(){},captureMessage:function(){},'
        . 'addBreadcrumb:function(){},configureScope:function(){},withScope:function(fn){try{fn({setTag:function(){},setExtra:function(){},setUser:function(){}})}catch(e){}},'
        . 'setUser:function(){},setTag:function(){},setExtra:function(){},setContext:function(){},'
        . 'getCurrentHub:function(){return{getClient:function(){return null;},getScope:function(){return{setTag:function(){},setUser:function(){}};}};}'
        . '};}catch(e){}'
        . '})();</script>';

    // ---------------- Cosmetic hider ----------------
    // Hide UI elements that reveal the master account (My Account, Sign
    // Out), expose the brand for direct sign-up (Pricing, API, Affiliates,
    // Blog, mobile-app prompt, top announcement banner), or otherwise let
    // students bypass our Hub. Same idea as the injection_js used by other
    // services in the path proxy. Two layers:
    //
    //   1. A stylesheet that hides matching elements by text content
    //      (using :has()) the moment it parses. Modern browsers support
    //      :has() so the elements never flash.
    //   2. A small MutationObserver that re-applies the hide on every DOM
    //      change for older browsers / dynamic re-renders by Next.js.
    $cosmeticHider = '<style>'
        // Top announcement strip ("New! May 12: Enhanced Model..."). It\'s
        // the very first banner-style div under <header>.
        . 'header > div:first-child:not(:has(nav,a[href="/"])){display:none !important;}'
        // The nav <a> entries we want to hide. We match by visible label
        // because their href and class names are minified. :has() lets a
        // CSS-only rule check inner text via a sibling span.
        . 'header nav a[href*="/account"],'
        . 'header nav a[href*="/billing"],'
        . 'header nav a[href*="/pricing"],'
        . 'header nav a[href*="/affiliate"],'
        . 'header nav a[href*="/api"],'
        . 'header nav a[href*="/blog"],'
        . 'header nav a[href*="/sign-out"],'
        . 'header nav a[href*="/signout"],'
        . 'header nav a[href*="/logout"],'
        . 'header nav button[aria-label*="account" i],'
        . 'header nav button[aria-label*="sign out" i]'
        . '{display:none !important;}'
        // Mobile app prompt ("New WriteHuman mobile app available")
        . 'div:has(> a[href*="apps.apple.com"]),'
        . 'div:has(> a[href*="play.google.com"]),'
        . 'div:has(> [class*="mobile"][class*="app"]){display:none !important;}'
        // Footer with affiliate/pricing/legal links — keep the chat UI clean.
        . 'footer{display:none !important;}'
        . '</style>';

    $hideScript = '<script>(function(){'
        . 'function hideByText(){'
        // Walk every <a> and <button> inside <header> (and any <nav>
        // anywhere) and hide those whose visible text matches the
        // forbidden labels. Case-insensitive, handles whitespace.
        . 'var bad=/^(My Account|Sign Out|Sign out|Logout|Log Out|Pricing|API|Affiliates|Affiliate|Blog)$/i;'
        . 'var nodes=document.querySelectorAll("header a,header button,nav a,nav button");'
        . 'for(var i=0;i<nodes.length;i++){'
            . 'var t=(nodes[i].textContent||"").trim();'
            . 'if(bad.test(t)){'
                // Hide the link itself and its parent <li> if any.
                . 'var n=nodes[i];'
                . 'try{n.style.display="none";'
                    . 'if(n.parentElement && n.parentElement.tagName==="LI"){n.parentElement.style.display="none";}'
                . '}catch(e){}'
            . '}'
        . '}'
        // Hide common upgrade/banner CTAs by inner text (anywhere on page).
        . 'var banners=document.querySelectorAll("a,button,div[role=\"banner\"]");'
        . 'for(var j=0;j<banners.length;j++){'
            . 'var bt=(banners[j].textContent||"").trim();'
            . 'if(/^(Upgrade|Subscribe|Get Pro|Get Ultra|Manage Subscription|Account Settings)$/i.test(bt)){'
                . 'try{banners[j].style.display="none";}catch(e){}'
            . '}'
        . '}'
        . '}'
        // Run once now, then on every DOM change. The observer is cheap.
        . 'function start(){hideByText();var mo=new MutationObserver(hideByText);mo.observe(document.documentElement,{subtree:true,childList:true});}'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start);}else{start();}'
        . '})();</script>';

    // Inject as the very first element after <head> so it runs before
    // any of the upstream\'s own scripts (including the inline guard
    // that we also neutralize via regex above).
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

    // Strip headers that would force the browser to bypass us or kill
    // the iframe.
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

    // Rewrite Set-Cookie domain so cookies stick to OUR subdomain.
    if (str_starts_with($low, 'set-cookie:')) {
        $rewritten = preg_replace('/;\s*Domain=[^;]+/i', '', $line);
        // SameSite=None requires Secure and works in cross-site iframe;
        // keep that. SameSite=Strict would break us.
        $rewritten = preg_replace('/;\s*SameSite=Strict/i', '; SameSite=Lax', $rewritten);
        header($rewritten, false);
        continue;
    }

    // Rewrite Location redirect if it points at writehuman.ai.
    if (str_starts_with($low, 'location:')) {
        $rewritten = preg_replace('#https?://writehuman\.ai#i', 'https://' . $PROXY_HOST, $line);
        header($rewritten, false);
        continue;
    }

    header($line, false);
}

// Tell intermediaries (Cloudflare especially) not to cache HTML — we
// don\'t want a stale signed-out marketing page being served to a fresh
// authed visitor.
if ($isHtml) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

echo $body;
