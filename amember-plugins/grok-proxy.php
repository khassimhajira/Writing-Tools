<?php
/**
 * Grok transparent proxy v3 — full streaming, no body rewrites.
 *
 * Architectural lessons from v1/v2:
 *
 *   * Buffering ANY response (HTML included) breaks Grok\'s React-Server-
 *     Component parser. RSC payloads are embedded in the streaming HTML
 *     as `<script>self.__next_f.push([...])</script>` blocks containing
 *     length-prefixed JSON. str_replace() over the body desyncs those
 *     prefixes → "Connection closed" at module-evaluation time.
 *
 *   * Solution: stream EVERY response chunk-for-chunk to the browser.
 *     For HTML we still need a one-time `<head>` script injection — we
 *     do that at the wire level by buffering only the first ~16KB until
 *     we see `<head>`, then flushing once with the injection appended,
 *     and streaming the rest of the body verbatim.
 *
 *   * URL rewriting is handled CLIENT-SIDE by our injected fetch / XHR /
 *     WebSocket interceptor. Server never touches the body bytes.
 *
 *   * cdn.grok.com chunks are NOT proxied — Cloudflare bot-protection
 *     blocks proxy IPs but accepts the user\'s real browser. We let the
 *     browser fetch directly from the CDN.
 */

// ---------------- Config ----------------
$UPSTREAM       = 'https://grok.com';
$COOKIE_FILE    = '/home/u124071091/stealth_data/grok_cookie.txt';
$AUTH_GATE_URL  = 'https://tools.scholargenie.org/hub/api/auth/me';
$PROXY_HOST     = $_SERVER['HTTP_HOST'] ?? 'grok.scholargenie.org';

// Helper: forward upstream response headers, stripping framing-killers
// and rewriting Set-Cookie / Location.
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

// Sticky residential proxy pool — Cloudflare bot-protection on grok.com
// 403s Hostinger\'s datacenter IP. Each visitor sticks to one exit IP
// per day for session-cookie consistency.
$PROXY_POOL_FILE = '/home/u124071091/stealth_data/grok_upstream_proxies.txt';
$UPSTREAM_PROXY  = '';
if (is_readable($PROXY_POOL_FILE)) {
    $proxies = array_filter(array_map('trim', file($PROXY_POOL_FILE)));
    if (!empty($proxies)) {
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

// ---------------- Cloudflare /cdn-cgi/* short-circuit ----------------
if (preg_match('#^/cdn-cgi/#', $path) || preg_match('#^/monitoring\?o=\d+&p=\d+#', $path)) {
    http_response_code(204);
    header('Content-Length: 0');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    exit;
}

// ---------------- api.grok.com proxy ----------------
// Grok\'s frontend calls api.grok.com for tasks, conversations, etc.
// We proxy these through the same residential pool so the API thinks
// the call comes from a real browser. Streaming pass-through, no body
// rewrites — these are JSON/SSE responses that React parses verbatim.
if (preg_match('#^/__grok_api(/.*)?$#', $path, $apiMatch)) {
    $apiPath = $apiMatch[1] ?? '/';
    $apiUrl  = 'https://api.grok.com' . $apiPath;
    $upstreamCookieForApi = is_readable($COOKIE_FILE) ? trim(file_get_contents($COOKIE_FILE)) : '';

    $apiHeaders = [];
    foreach (getallheaders() as $name => $value) {
        $low = strtolower($name);
        if (in_array($low, [
            'host', 'cookie', 'origin', 'referer', 'content-length',
            'connection', 'accept-encoding', 'sec-fetch-site',
        ], true)) continue;
        $apiHeaders[] = $name . ': ' . $value;
    }
    $apiHeaders[] = 'Host: api.grok.com';
    $apiHeaders[] = 'Origin: https://grok.com';
    $apiHeaders[] = 'Referer: https://grok.com/';
    $apiHeaders[] = 'Sec-Fetch-Site: same-site';
    if ($upstreamCookieForApi !== '') $apiHeaders[] = 'Cookie: ' . $upstreamCookieForApi;
    $apiHeaders[] = 'Accept-Encoding: identity';

    $apiBody = file_get_contents('php://input');

    $apiCh = curl_init($apiUrl);
    $_apiHdrs = [];
    $_apiStatus = 200;
    $_apiHdrFlushed = false;
    curl_setopt_array($apiCh, [
        CURLOPT_CUSTOMREQUEST  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        CURLOPT_HTTPHEADER     => $apiHeaders,
        CURLOPT_POSTFIELDS     => $apiBody !== '' ? $apiBody : null,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$_apiHdrs, &$_apiStatus) {
            $t = trim($line);
            if ($t === '') return strlen($line);
            if (preg_match('#^HTTP/[\d.]+ (\d+)#', $t, $m)) { $_apiStatus = (int)$m[1]; $_apiHdrs = []; return strlen($line); }
            $_apiHdrs[] = $t;
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$_apiHdrs, &$_apiStatus, &$_apiHdrFlushed) {
            if (!$_apiHdrFlushed) {
                http_response_code($_apiStatus);
                _forward_response_headers($_apiHdrs);
                if (function_exists('ob_implicit_flush')) ob_implicit_flush(true);
                while (ob_get_level() > 0) ob_end_flush();
                $_apiHdrFlushed = true;
            }
            echo $chunk;
            if (function_exists('flush')) @flush();
            return strlen($chunk);
        },
    ]);
    if ($UPSTREAM_PROXY) {
        curl_setopt($apiCh, CURLOPT_PROXY, $UPSTREAM_PROXY);
        curl_setopt($apiCh, CURLOPT_TIMEOUT, 240);
        curl_setopt($apiCh, CURLOPT_CONNECTTIMEOUT, 25);
    }
    curl_exec($apiCh);
    $apiErr = curl_error($apiCh);
    curl_close($apiCh);
    if (!$_apiHdrFlushed) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo '{"error":"api_unreachable"}';
    }
    if ($apiErr) error_log('[grok-proxy.api] ' . $apiUrl . ' err=' . $apiErr);
    exit;
}

// ---------------- assets.grok.com proxy ----------------
if (preg_match('#^/__grok_assets(/.*)?$#', $path, $assetMatch)) {
    $assetPath = $assetMatch[1] ?? '/';
    $assetUrl  = 'https://assets.grok.com' . $assetPath;
    $upstreamCookieForAsset = is_readable($COOKIE_FILE) ? trim(file_get_contents($COOKIE_FILE)) : '';
    $assetHeaders = [
        'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0'),
        'Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? '*/*'),
        'Accept-Language: ' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US,en;q=0.9'),
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
    if ($UPSTREAM_PROXY) {
        curl_setopt($ach, CURLOPT_PROXY, $UPSTREAM_PROXY);
        curl_setopt($ach, CURLOPT_TIMEOUT, 90);
    }
    $assetBody = curl_exec($ach);
    $assetStatus = curl_getinfo($ach, CURLINFO_HTTP_CODE);
    $assetCT = curl_getinfo($ach, CURLINFO_CONTENT_TYPE);
    curl_close($ach);
    if (($assetStatus < 200 || $assetStatus >= 300) && (strpos($assetCT, 'image/') !== false || preg_match('#\.(png|jpg|jpeg|gif|webp|svg|ico)#i', $assetPath))) {
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

// ---------------- Read shared upstream cookie ----------------
$upstreamCookie = is_readable($COOKIE_FILE) ? trim(file_get_contents($COOKIE_FILE)) : '';

// Parse cookies for client-side planting on HTML responses.
$plantCookies = [];
if ($upstreamCookie !== '') {
    foreach (explode(';', $upstreamCookie) as $kv) {
        $kv = trim($kv);
        if ($kv === '') continue;
        $eq = strpos($kv, '=');
        if ($eq === false) continue;
        $cname = trim(substr($kv, 0, $eq));
        $cval  = substr($kv, $eq + 1);
        if ($cname !== '') $plantCookies[$cname] = $cval;
    }
}

// ---------------- Build the head injection script ----------------
//
// One self-contained <script> we paste right after `<head>` on HTML
// responses. Handles: hostname override, Sentry stub, fetch/XHR/WebSocket
// URL rewriting, telemetry stub, cosmetic hiding, error-boundary hide.
$INJECT = '<script>(function(){'
    . 'try{Object.defineProperty(location,"hostname",{configurable:true,get:function(){return "grok.com";}});'
    . 'Object.defineProperty(location,"host",{configurable:true,get:function(){return "grok.com";}});'
    . '}catch(e){}'
    // Intercept location.assign / replace / href setters so anything
    // that tries to navigate the iframe to grok.com gets routed back to
    // our subdomain. This catches Grok\'s "wrong host detected, fix it
    // by redirecting" code paths.
    . 'try{var _PH=location.host;'
    . 'function _fixLoc(u){'
        . 'try{if(typeof u!=="string")return u;'
        . 'if(u.indexOf("https://grok.com")===0)return "https://"+_PH+u.slice(16);'
        . 'if(u.indexOf("http://grok.com")===0)return "https://"+_PH+u.slice(15);'
        . 'if(u.indexOf("//grok.com")===0)return "//"+_PH+u.slice(10);'
        . '}catch(e){}return u;'
    . '}'
    . 'var _origAssign=location.assign.bind(location);'
    . 'var _origReplace=location.replace.bind(location);'
    . 'location.assign=function(u){return _origAssign(_fixLoc(u));};'
    . 'location.replace=function(u){return _origReplace(_fixLoc(u));};'
    // Patch the `href` setter on Location.prototype so direct
    // assignment (`location.href = "..."`) is also intercepted. This is
    // the common pattern in production bundles.
    . 'try{var _hrefDesc=Object.getOwnPropertyDescriptor(Location.prototype,"href");'
    . 'if(_hrefDesc && _hrefDesc.set){var _origHrefSet=_hrefDesc.set;'
    . 'Object.defineProperty(Location.prototype,"href",{configurable:true,enumerable:true,get:_hrefDesc.get,set:function(v){return _origHrefSet.call(this,_fixLoc(v));}});}'
    . '}catch(e){}'
    // Patch `window.location = "..."` — this triggers a special setter
    // on the Window prototype that ultimately calls Location.href.
    . 'try{var _windowLocDesc=Object.getOwnPropertyDescriptor(window,"location") || Object.getOwnPropertyDescriptor(Object.getPrototypeOf(window),"location");'
    . 'if(_windowLocDesc && _windowLocDesc.set){var _origWindowLocSet=_windowLocDesc.set;'
    . 'Object.defineProperty(window,"location",{configurable:true,get:_windowLocDesc.get,set:function(v){return _origWindowLocSet.call(this, typeof v==="string"?_fixLoc(v):v);}});}'
    . '}catch(e){}'
    // Patch document.location too (alias of window.location).
    . 'try{var _docLocDesc=Object.getOwnPropertyDescriptor(Document.prototype,"location") || Object.getOwnPropertyDescriptor(document,"location");'
    . 'if(_docLocDesc && _docLocDesc.set){var _origDocLocSet=_docLocDesc.set;'
    . 'Object.defineProperty(document,"location",{configurable:true,get:_docLocDesc.get,set:function(v){return _origDocLocSet.call(this, typeof v==="string"?_fixLoc(v):v);}});}'
    . '}catch(e){}'
    // window.open too — Grok may open an oauth window pointing to grok.com.
    . 'var _origOpen=window.open;'
    . 'window.open=function(u,t,f){return _origOpen.call(window,_fixLoc(u),t,f);};'
    // Iframe src — set via setAttribute or property. Patch
    // HTMLIFrameElement to rewrite src on assignment.
    . 'var _ifDesc=Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype,"src")||Object.getOwnPropertyDescriptor(HTMLElement.prototype,"src");'
    . 'if(_ifDesc && _ifDesc.set){var _origSet=_ifDesc.set;Object.defineProperty(HTMLIFrameElement.prototype,"src",{configurable:true,enumerable:true,get:_ifDesc.get,set:function(v){return _origSet.call(this,_fixLoc(v));}});}'
    // Patch History API in case Grok uses pushState/replaceState with
    // an absolute grok.com URL (uncommon but cheap to cover).
    . 'try{var _ps=history.pushState.bind(history);var _rs=history.replaceState.bind(history);'
    . 'history.pushState=function(s,t,u){return _ps(s,t,_fixLoc(u));};'
    . 'history.replaceState=function(s,t,u){return _rs(s,t,_fixLoc(u));};'
    . '}catch(e){}'
    . '}catch(e){}'
    // Strip CSP / X-Frame meta tags as soon as they appear. Grok\'s SSR
    // injects <meta http-equiv="Content-Security-Policy" content="...
    // frame-ancestors x.com ..."> which the browser enforces at parse
    // time, blocking us from rendering inside the iframe. We use a
    // MutationObserver fallback AND poll the head for a few seconds.
    . 'try{function _stripCspMeta(){'
        . 'var metas=document.querySelectorAll("meta[http-equiv]");'
        . 'for(var i=0;i<metas.length;i++){'
            . 'var v=(metas[i].getAttribute("http-equiv")||"").toLowerCase();'
            . 'if(v==="content-security-policy"||v==="x-frame-options"){'
                . 'try{metas[i].remove();}catch(e){}'
            . '}'
        . '}'
    . '}'
    . '_stripCspMeta();'
    . 'if(document.documentElement){'
        . 'var _cspObs=new MutationObserver(_stripCspMeta);'
        . '_cspObs.observe(document.documentElement,{subtree:true,childList:true});'
        // Stop observing after 5s — by then the head is parsed.
        . 'setTimeout(function(){try{_cspObs.disconnect();}catch(e){}},5000);'
    . '}'
    . '}catch(e){}'
    . 'try{window.__SENTRY__={};window.SENTRY_RELEASE={};window.Sentry={'
    . 'init:function(){},captureException:function(){},captureMessage:function(){},'
    . 'addBreadcrumb:function(){},configureScope:function(){},withScope:function(fn){try{fn({setTag:function(){},setExtra:function(){},setUser:function(){}})}catch(e){}},'
    . 'setUser:function(){},setTag:function(){},setExtra:function(){},setContext:function(){},'
    . 'getCurrentHub:function(){return{getClient:function(){return null;},getScope:function(){return{setTag:function(){},setUser:function(){}};}};}'
    . '};}catch(e){}'
    // Diagnostic: surface real errors in console as warnings.
    . 'try{window.addEventListener("error",function(e){'
        . 'try{console.warn("[grok-proxy.error]",e&&e.message,e&&e.filename,e&&(e.lineno+":"+e.colno),e&&e.error&&e.error.stack);}catch(_){}'
    . '},true);'
    . 'window.addEventListener("unhandledrejection",function(e){'
        . 'try{var r=e&&e.reason; console.warn("[grok-proxy.unhandled]", r&&r.message||String(r), r&&r.stack||"");}catch(_){}'
    . '});'
    . '}catch(e){}'
    // URL rewriter + telemetry stub for fetch/XHR/sendBeacon.
    . 'try{var PROXY_HOST=location.host;'
    . 'function _rw(u){'
        . 'try{if(typeof u!=="string")return u;'
        . 'if(u.indexOf("https://grok.com")===0)return "https://"+PROXY_HOST+u.slice(16);'
        . 'if(u.indexOf("http://grok.com")===0)return "https://"+PROXY_HOST+u.slice(15);'
        . 'if(u.indexOf("//grok.com")===0)return "//"+PROXY_HOST+u.slice(10);'
        . 'if(u.indexOf("https://assets.grok.com")===0)return "https://"+PROXY_HOST+"/__grok_assets"+u.slice(23);'
        . 'if(u.indexOf("https://api.grok.com")===0)return "https://"+PROXY_HOST+"/__grok_api"+u.slice(20);'
        . '}catch(e){}return u;'
    . '}'
    . 'function _tele(url){'
        // Only stub TRUE telemetry endpoints — anything that\'s strictly
        // observability and won\'t affect Grok\'s app logic. We\'re narrow
        // here on purpose: a too-broad stub looks like "FetchError" to
        // Grok\'s API client and trips the React error boundary.
        . 'try{'
            . 'if(url.indexOf("/cdn-cgi/rum")!==-1)return true;'
            . 'if(url.indexOf("/cdn-cgi/speedbrain")!==-1)return true;'
            . 'if(url.indexOf("/cdn-cgi/zaraz")!==-1)return true;'
            . 'if(url.indexOf("sentry-cdn.com")!==-1)return true;'
            . 'if(url.indexOf(".ingest.sentry.io")!==-1)return true;'
            // Grok\'s sentry monitoring path (note: project endpoints
            // also live under /monitoring sometimes; only short-circuit
            // when the query string explicitly includes Sentry\'s o= and p=
            // params).
            . 'if(/[?&]o=\\d+/.test(url) && /[?&]p=\\d+/.test(url) && url.indexOf("/monitoring")!==-1)return true;'
        . '}catch(e){}return false;'
    . '}'
    . 'var _of=window.fetch;'
    . 'window.fetch=function(input,init){'
        . 'try{var url=typeof input==="string"?input:(input&&input.url)||"";'
        . 'if(url && _tele(url)){return Promise.resolve(new Response(null,{status:204,statusText:"No Content"}));}'
        . 'if(typeof input==="string"){var ru=_rw(input);if(ru!==input)input=ru;}'
        . '}catch(e){}'
        . 'return _of.apply(this,[input,init]);'
    . '};'
    . 'var _os=XMLHttpRequest.prototype.send;'
    . 'var _oo=XMLHttpRequest.prototype.open;'
    . 'XMLHttpRequest.prototype.open=function(method,url){'
        . 'this.__t=(typeof url==="string"&&_tele(url));'
        . 'if(typeof url==="string")arguments[1]=_rw(url);'
        . 'return _oo.apply(this,arguments);'
    . '};'
    . 'XMLHttpRequest.prototype.send=function(){'
        . 'if(this.__t){'
            . 'var s=this;setTimeout(function(){'
                . 'try{Object.defineProperty(s,"readyState",{value:4,configurable:true});'
                . 'Object.defineProperty(s,"status",{value:204,configurable:true});'
                . 'Object.defineProperty(s,"responseText",{value:"",configurable:true});'
                . 'if(typeof s.onreadystatechange==="function")s.onreadystatechange();'
                . 'if(typeof s.onload==="function")s.onload();'
                . '}catch(e){}'
            . '},0);return;'
        . '}'
        . 'return _os.apply(this,arguments);'
    . '};'
    . 'try{var _OW=window.WebSocket;'
    . 'window.WebSocket=function(url,protocols){'
        . 'try{if(typeof url==="string"){'
            . 'if(url.indexOf("wss://grok.com")===0)url="wss://"+PROXY_HOST+url.slice(14);'
            . 'else if(url.indexOf("ws://grok.com")===0)url="wss://"+PROXY_HOST+url.slice(13);'
        . '}}catch(e){}'
        . 'return new _OW(url,protocols);'
    . '};'
    . 'window.WebSocket.prototype=_OW.prototype;'
    . 'window.WebSocket.CONNECTING=_OW.CONNECTING;'
    . 'window.WebSocket.OPEN=_OW.OPEN;'
    . 'window.WebSocket.CLOSING=_OW.CLOSING;'
    . 'window.WebSocket.CLOSED=_OW.CLOSED;'
    . '}catch(e){}'
    . 'if(navigator.sendBeacon){var _ob=navigator.sendBeacon.bind(navigator);'
        . 'navigator.sendBeacon=function(url,data){'
            . 'try{if(typeof url==="string" && _tele(url))return true;'
            . 'if(typeof url==="string")url=_rw(url);}catch(e){}'
            . 'return _ob(url,data);'
        . '};}'
    . '}catch(e){}'
    // Cosmetic hider for nav items + error-boundary fallback UI.
    . 'try{function _hideErrAndNav(){'
        . 'var bad=/^(My Account|Account|Settings|Billing|Subscription|Manage Subscription|Sign Out|Sign out|Logout|Log Out|Log out|Pricing|Upgrade|Upgrade to Pro|Upgrade to SuperGrok|Subscribe|API|Affiliate|Refer)$/i;'
        . 'var nodes=document.querySelectorAll("header a,header button,nav a,nav button,aside a,aside button,[role=menu] a,[role=menu] button,[role=menuitem]");'
        . 'for(var i=0;i<nodes.length;i++){'
            . 'var t=(nodes[i].textContent||"").trim();'
            . 'if(bad.test(t)){var n=nodes[i];try{n.style.display="none";'
                . 'if(n.parentElement && n.parentElement.tagName==="LI")n.parentElement.style.display="none";'
                . '}catch(e){}}'
        . '}'
        // Error boundary fallback
        . 'var divs=document.querySelectorAll("h1,h2,h3,p,div");'
        . 'for(var j=0;j<divs.length;j++){'
            . 'var dt=(divs[j].textContent||"").trim();'
            . 'if(dt==="Something went wrong"||dt==="Something unexpected happened. We\'re working to prevent this in the future."){'
                . 'var p=divs[j].closest("[role=\\"alert\\"]")||divs[j].parentElement;'
                . 'if(p && !p.__hp){p.__hp=true;p.style.display="none";}'
            . '}'
        . '}'
    . '}'
    . 'function _boot(){_hideErrAndNav();var mo=new MutationObserver(_hideErrAndNav);mo.observe(document.documentElement,{subtree:true,childList:true});}'
    . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",_boot);else _boot();'
    . '}catch(e){}'
    . '})();</script>'
    . '<style>'
    . 'a[href*="/account"],a[href*="/settings/account"],a[href*="/billing"],a[href*="/pricing"],'
    . 'a[href*="/upgrade"],a[href*="/subscribe"],a[href*="/sign-out"],a[href*="/signout"],'
    . 'a[href*="/logout"],a[href*="/api"],a[href*="/affiliate"]{display:none !important;}'
    . 'button[data-testid*="sign-up" i],button[data-testid*="login" i],button[data-testid*="upgrade" i],'
    . 'a[data-testid*="sign-up" i],a[data-testid*="login" i]{display:none !important;}'
    . '</style>';

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

$reqBody = file_get_contents('php://input');

// ---------------- Streaming proxy ----------------
//
// Single mode: stream every chunk. For HTML, we buffer until we see
// `<head>`, inject our script, then flush + stream verbatim.

$_responseHeaders = [];
$_responseStatus  = 200;
$_isHtml          = null;     // determined after headers
$_headersFlushed  = false;
$_headBuffer      = '';       // only used while waiting for <head>
$_headInjected    = false;
$_cookiesPlanted  = false;
// Sliding-window tail used to defang inline <meta http-equiv="Content-
// Security-Policy"> tags that appear AFTER head injection. We hold back
// the last ~512 bytes of the stream so we can scan-and-rewrite across
// chunk boundaries before flushing.
$_streamTail      = '';

$ch = curl_init($upstreamUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_POSTFIELDS     => $reqBody !== '' ? $reqBody : null,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$_responseHeaders, &$_responseStatus) {
        $trim = trim($line);
        if ($trim === '') return strlen($line);
        if (preg_match('#^HTTP/[\d.]+ (\d+)#', $trim, $m)) {
            $_responseStatus = (int)$m[1];
            $_responseHeaders = [];
            return strlen($line);
        }
        $_responseHeaders[] = $trim;
        return strlen($line);
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (
        &$_responseHeaders, &$_responseStatus, &$_isHtml, &$_headersFlushed,
        &$_headBuffer, &$_headInjected, &$_cookiesPlanted, &$_streamTail,
        $INJECT, $plantCookies, $PROXY_HOST
    ) {
        // Helper: defang any <meta http-equiv="Content-Security-Policy">
        // and <meta http-equiv="X-Frame-Options"> tags by changing the
        // http-equiv value to something the browser ignores. Keeps the
        // stream byte-aligned (same length).
        $defangCsp = function ($s) {
            $s = preg_replace_callback(
                '/http-equiv\s*=\s*["\'](Content-Security-Policy|X-Frame-Options|Content-Security-Policy-Report-Only)["\']/i',
                function ($m) {
                    // Pad to same length so byte offsets don\'t shift.
                    $orig = $m[1];
                    $repl = 'x-disabled-csp-by-proxy-' . str_pad('', max(0, strlen($orig) - 24), 'x');
                    if (strlen($repl) < strlen($orig)) {
                        $repl = str_pad($repl, strlen($orig), 'x');
                    } elseif (strlen($repl) > strlen($orig)) {
                        $repl = substr($repl, 0, strlen($orig));
                    }
                    return 'http-equiv="' . $repl . '"';
                },
                $s
            );
            return $s;
        };

        // Determine HTML-ness once.
        if ($_isHtml === null) {
            $ct = '';
            foreach ($_responseHeaders as $h) {
                if (stripos($h, 'content-type:') === 0) { $ct = strtolower($h); break; }
            }
            $_isHtml = (strpos($ct, 'text/html') !== false);
        }

        // Flush headers (once). For HTML we plant Set-Cookie before
        // flushing so the browser receives them with the doc.
        if (!$_headersFlushed) {
            http_response_code($_responseStatus);
            _forward_response_headers($_responseHeaders);
            if ($_isHtml) {
                if (!$_cookiesPlanted) {
                    foreach ($plantCookies as $cname => $cval) {
                        header('Set-Cookie: ' . $cname . '=' . $cval . '; Path=/; Max-Age=2592000; Secure; SameSite=Lax', false);
                    }
                    $_cookiesPlanted = true;
                }
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
            }
            // Disable PHP output buffering so chunks reach the wire.
            if (function_exists('ob_implicit_flush')) ob_implicit_flush(true);
            while (ob_get_level() > 0) ob_end_flush();
            $_headersFlushed = true;
        }

        // Non-HTML: stream verbatim.
        if (!$_isHtml) {
            echo $chunk;
            if (function_exists('flush')) @flush();
            return strlen($chunk);
        }

        // HTML pre-injection: buffer until we see <head>, then inject.
        if (!$_headInjected) {
            $_headBuffer .= $chunk;
            if (preg_match('/<head[^>]*>/i', $_headBuffer, $m, PREG_OFFSET_CAPTURE)) {
                $tag = $m[0][0];
                $tagPos = $m[0][1];
                $tagEnd = $tagPos + strlen($tag);
                $before = substr($_headBuffer, 0, $tagEnd);
                $after  = substr($_headBuffer, $tagEnd);
                // Defang any meta-CSP that\'s already in the buffered head section.
                $after = $defangCsp($after);
                $base   = '<base href="https://' . htmlspecialchars($PROXY_HOST, ENT_QUOTES) . '/">';
                echo $before . $INJECT . $base . $after;
                if (function_exists('flush')) @flush();
                $_headBuffer = '';
                $_headInjected = true;
                return strlen($chunk);
            }
            if (strlen($_headBuffer) > 262144) {
                echo $defangCsp($_headBuffer);
                if (function_exists('flush')) @flush();
                $_headBuffer = '';
                $_headInjected = true;
            }
            return strlen($chunk);
        }

        // HTML post-injection: pure passthrough WITH meta-CSP defanging
        // via a sliding-window tail. We hold back the last 512 bytes so
        // a meta-CSP tag that crosses a chunk boundary still gets caught.
        $combined = $_streamTail . $chunk;
        if (strlen($combined) > 512) {
            $emit = substr($combined, 0, strlen($combined) - 512);
            $_streamTail = substr($combined, -512);
            echo $defangCsp($emit);
            if (function_exists('flush')) @flush();
        } else {
            $_streamTail = $combined;
        }
        return strlen($chunk);
    },
]);
if ($UPSTREAM_PROXY) {
    curl_setopt($ch, CURLOPT_PROXY, $UPSTREAM_PROXY);
    curl_setopt($ch, CURLOPT_TIMEOUT, 240);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 25);
}

$execOk = curl_exec($ch);
$err    = curl_error($ch);
curl_close($ch);

// If we never got headers (connection refused / DNS / proxy auth fail),
// emit a 502.
if (!$_headersFlushed) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="font-family:sans-serif;text-align:center;padding:14% 20px;">'
       . '<h2 style="color:#0f0720;">Service unavailable</h2>'
       . '<p>Grok cannot be reached right now. Please try again in a moment.</p>'
       . '</div>';
    if ($err) error_log('[grok-proxy] curl error (no headers): ' . $err);
    exit;
}

// HTML responses where we never saw <head> — flush whatever we buffered.
if ($_isHtml && !$_headInjected && $_headBuffer !== '') {
    echo $_headBuffer;
    if (function_exists('flush')) @flush();
}

// Flush any held-back tail bytes from the sliding-window meta-CSP defang.
if ($_isHtml && $_headInjected && $_streamTail !== '') {
    // Run one final defang on the tail before flushing.
    $tail = preg_replace_callback(
        '/http-equiv\s*=\s*["\'](Content-Security-Policy|X-Frame-Options|Content-Security-Policy-Report-Only)["\']/i',
        function ($m) {
            $orig = $m[1];
            $repl = 'x-disabled-csp-by-proxy-' . str_pad('', max(0, strlen($orig) - 24), 'x');
            if (strlen($repl) < strlen($orig)) $repl = str_pad($repl, strlen($orig), 'x');
            elseif (strlen($repl) > strlen($orig)) $repl = substr($repl, 0, strlen($orig));
            return 'http-equiv="' . $repl . '"';
        },
        $_streamTail
    );
    echo $tail;
    if (function_exists('flush')) @flush();
    $_streamTail = '';
}

if ($err) error_log('[grok-proxy] curl error after partial response: ' . $err);
