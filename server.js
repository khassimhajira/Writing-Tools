require('dotenv').config();

// Global Fatal Error Logger
process.on('uncaughtException', (err) => {
    console.error('FATAL UNCAUGHT EXCEPTION:', err.stack || err);
    // Give time for log to write
    setTimeout(() => process.exit(1), 500);
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('UNHANDLED PROMISE REJECTION:', reason);
});
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const httpProxy = require('http-proxy');
const cookieParser = require('cookie-parser');
const path = require('path');
const jwt = require('jsonwebtoken');
const { HttpsProxyAgent } = require('https-proxy-agent');
const axios = require('axios');

// MVC Modules
const { get, run, query, db, pickLeastLoadedCookie, countRecentUsage, recordUsage, checkAndRecordUsage, USAGE_WINDOW_MS } = require('./database');
const { router: authRouter, JWT_SECRET } = require('./routes/auth');
const adminRouter = require('./routes/admin');

const app = express();
const server = http.createServer(app);
const io = new Server(server);
app.set('io', io);

// Active Socket Users
const activeUsers = new Map(); // socket.id -> userId
io.on('connection', (socket) => {
    socket.on('authenticate', (token) => {
        try {
            const verified = jwt.verify(token, JWT_SECRET);
            activeUsers.set(socket.id, verified.id);
            socket.join(`user_${verified.id}`); // Join a room for targeted updates
            const uniqueUserIds = Array.from(new Set(activeUsers.values()));
            if (verified.role === 'admin') {
                socket.join('admins');
                socket.emit('proxy_activity_update', Array.from(activeProxyUsers.keys()));
            }
            io.emit('presence_update', uniqueUserIds);

        } catch(e) {}
    });

    socket.on('admin_signal', (data) => {
        // Broadcaster for real-time admin actions
        if (data.type === 'assignment_update') {
            io.to(`user_${data.userId}`).emit('force_refresh');
        }
        if (data.type === 'global_update') {
            io.emit('global_reload');
        }
    });

    socket.on('disconnect', () => {
        activeUsers.delete(socket.id);
        const uniqueUserIds = Array.from(new Set(activeUsers.values()));
        io.emit('presence_update', uniqueUserIds);
    });
});

app.use(cookieParser());

// Static Files & Pages
app.get('/', (req, res) => res.redirect('/dashboard'));

// AUTH GATEKEEPER: All dashboard traffic must be authenticated via aMember
app.get('/dashboard', (req, res) => {
    const token = req.cookies.stealth_hub_token;
    
    // If no token, redirect to aMember Login, but tell it to come back to the "Access Tools" page
    if (!token) {
        // This URL sends them to login and then takes them straight to your new button page!
        const amLoginUrl = 'https://app.scholargenie.org/login?amember_redirect_url=https://app.scholargenie.org/content/p/id/1/';
        return res.redirect(amLoginUrl);
    }
    
    try {
        jwt.verify(token, JWT_SECRET);
        res.sendFile(path.join(__dirname, 'public', 'dashboard.html'));
    } catch(e) {
        res.redirect('https://app.scholargenie.org/login');
    }
});

app.get('/admin', (req, res) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.sendFile(path.join(__dirname, 'public', 'admin-login.html'));
    
    try {
        const verified = jwt.verify(token, JWT_SECRET);
        if (verified.role === 'admin') {
            return res.sendFile(path.join(__dirname, 'public', 'admin.html'));
        } else {
            return res.sendFile(path.join(__dirname, 'public', 'admin-login.html'));
        }
    } catch(e) {
        return res.sendFile(path.join(__dirname, 'public', 'admin-login.html'));
    }
});

app.use('/', express.static(path.join(__dirname, 'public')));

// Hub Console API Routes
const hubParsers = [express.json(), express.urlencoded({ extended: true })];

app.get('/hub/api/admin/active-users', (req, res) => {
    res.json(Array.from(activeUsers.values()));
});

app.get('/hub/api/admin/active-sessions', (req, res) => {
    res.json(Array.from(activeProxyUsers.keys()));
});

app.use('/hub/api/auth', hubParsers, authRouter);
app.use('/hub/api/admin', hubParsers, adminRouter);


// Safety net for Hub API routes
app.use('/hub/api', hubParsers, (req, res) => {
    res.status(404).json({ error: 'Hub API route not found' });
});





// --- PROXY ROTATION ENGINE ---
let proxyAgents = [];

// HTTP keep-alive options applied to every HttpsProxyAgent we create. Reuses
// TCP+TLS sessions across requests instead of doing a fresh handshake every
// time. Saves 200-500ms per request on residential proxies. Safe defaults
// chosen so we don't pile up too many idle sockets.
const PROXY_AGENT_OPTS = {
    keepAlive: true,
    keepAliveMsecs: 1000,
    maxSockets: 64,
    maxFreeSockets: 16,
    timeout: 30000,
    scheduling: 'lifo'
};

// Build a tiny <script> that hydrates Supabase-style auth tokens from the
// user's stored cookies into localStorage, BEFORE the proxied site's bundle
// runs. This is the workaround that makes Supabase-auth sites (writehuman.ai,
// many others) work inside a cross-domain proxy. Returns '' if no Supabase
// tokens are detected in the cookie blob.
function buildSbBridgeScript(cookieStr) {
    if (!cookieStr) return '';
    try {
        const parts = cookieStr.split(';').map(c => c.trim());
        const sbKeys = {};   // ref -> { 0: '...', 1: '...' }
        const sbSimple = {}; // single-cookie variants
        parts.forEach(p => {
            const eq = p.indexOf('=');
            if (eq < 0) return;
            const name = p.substring(0, eq);
            const value = p.substring(eq + 1);
            const m = name.match(/^sb-([a-z0-9-]+)-auth-token(?:\.([0-9]+))?$/i);
            if (!m) return;
            const ref = m[1];
            const idx = m[2];
            if (idx === undefined) {
                sbSimple[ref] = value;
            } else {
                if (!sbKeys[ref]) sbKeys[ref] = {};
                sbKeys[ref][idx] = value;
            }
        });

        const items = [];
        for (const ref of Object.keys(sbKeys)) {
            const ordered = Object.keys(sbKeys[ref]).map(Number).sort((a,b)=>a-b)
                .map(i => sbKeys[ref][i]).join('');
            let raw = ordered;
            if (raw.startsWith('base64-')) raw = raw.substring(7);
            let decoded;
            try { decoded = Buffer.from(raw, 'base64').toString('utf8'); } catch (_) { continue; }
            try { JSON.parse(decoded); } catch (_) { continue; }
            items.push({ key: 'sb-' + ref + '-auth-token', json: decoded });
        }
        for (const ref of Object.keys(sbSimple)) {
            let raw = sbSimple[ref];
            if (raw.startsWith('base64-')) raw = raw.substring(7);
            let decoded;
            try { decoded = Buffer.from(raw, 'base64').toString('utf8'); } catch (_) { continue; }
            try { JSON.parse(decoded); } catch (_) { continue; }
            items.push({ key: 'sb-' + ref + '-auth-token', json: decoded });
        }

        if (items.length === 0) return '';
        const payload = JSON.stringify(items);

        // Extract the projectRef + parsed session for each token. The interceptor
        // uses these to reply to Supabase REST calls with synthetic responses.
        const sessions = [];
        for (const it of items) {
            try {
                const parsed = JSON.parse(it.json);
                const m = it.key.match(/^sb-([a-z0-9-]+)-auth-token$/i);
                const ref = m ? m[1] : null;
                if (!ref || !parsed) continue;
                sessions.push({ ref, session: parsed });
            } catch (_) {}
        }
        const sessionsPayload = JSON.stringify(sessions);

        console.log('[Hub-SB] bridge prepared with ' + items.length + ' Supabase ref(s) + ' + sessions.length + ' session(s) for fetch mock');

        // The injected script does TWO things:
        //   1. Write the auth tokens to localStorage (original behavior).
        //   2. Wrap window.fetch and XMLHttpRequest so that any call to
        //      `https://<ref>.supabase.co/auth/v1/...` is intercepted and
        //      answered locally with a synthesized response built from the
        //      JWT we already have. This stops Supabase's SDK from making
        //      a real network call that would fail (CORS / network) and
        //      cause it to clear the session and redirect.
        const interceptor =
            '(function(){' +
                'var sessions = ' + sessionsPayload + ';' +
                'if (!sessions.length) return;' +
                'var byRef = {};' +
                'sessions.forEach(function(s){ byRef[s.ref] = s.session; });' +
                'function jsonResponse(obj, status){ status = status||200; ' +
                    'var body = JSON.stringify(obj);' +
                    'return new Response(body, { status: status, statusText: status===200?\"OK\":\"Error\", headers: { \"Content-Type\": \"application/json\" } });' +
                '}' +
                'function answer(url){' +
                    'try {' +
                        'var u = new URL(url, location.href);' +
                        'var m = u.host.match(/^([a-z0-9-]+)\\\\.supabase\\\\.co$/i);' +
                        'if (!m) return null;' +
                        'var ref = m[1].toLowerCase();' +
                        'var sess = byRef[ref];' +
                        'if (!sess) return null;' +
                        'var path = u.pathname;' +
                        // /auth/v1/user — return the user object.
                        'if (path === \"/auth/v1/user\") {' +
                            'console.log(\"[Hub-SB] mock /auth/v1/user\");' +
                            'return jsonResponse(sess.user || {});' +
                        '}' +
                        // /auth/v1/token — refresh flow. Return the same session as if it succeeded.
                        // This avoids real refresh churn. Token will eventually expire upstream
                        // but the user can re-paste cookies.
                        'if (path === \"/auth/v1/token\") {' +
                            'console.log(\"[Hub-SB] mock /auth/v1/token\");' +
                            'return jsonResponse({' +
                                'access_token: sess.access_token,' +
                                'token_type: sess.token_type || \"bearer\",' +
                                'expires_in: sess.expires_in || 3600,' +
                                'expires_at: sess.expires_at || (Math.floor(Date.now()/1000) + 3600),' +
                                'refresh_token: sess.refresh_token,' +
                                'user: sess.user' +
                            '});' +
                        '}' +
                        // /auth/v1/logout — pretend success but DO NOT clear localStorage.
                        'if (path === \"/auth/v1/logout\") {' +
                            'console.log(\"[Hub-SB] mock /auth/v1/logout (no-op)\");' +
                            'return jsonResponse({});' +
                        '}' +
                        // Any other auth path — return empty 200 to keep SDK happy.
                        'if (path.indexOf(\"/auth/v1/\") === 0) {' +
                            'console.log(\"[Hub-SB] mock other auth:\", path);' +
                            'return jsonResponse({});' +
                        '}' +
                    '} catch(e) {}' +
                    'return null;' +
                '}' +
                // Wrap fetch
                'var origFetch = window.fetch;' +
                'window.fetch = function(input, init){' +
                    'try {' +
                        'var url = typeof input === \"string\" ? input : (input && input.url) || \"\";' +
                        'var fake = answer(url);' +
                        'if (fake) return Promise.resolve(fake);' +
                    '} catch(e) {}' +
                    'return origFetch.apply(this, arguments);' +
                '};' +
                // Wrap XHR
                'var origOpen = XMLHttpRequest.prototype.open;' +
                'var origSend = XMLHttpRequest.prototype.send;' +
                'XMLHttpRequest.prototype.open = function(method, url){' +
                    'this.__hub_sb_url = url;' +
                    'return origOpen.apply(this, arguments);' +
                '};' +
                'XMLHttpRequest.prototype.send = function(body){' +
                    'var url = this.__hub_sb_url;' +
                    'try {' +
                        'var u = new URL(url, location.href);' +
                        'var m = u.host.match(/^([a-z0-9-]+)\\\\.supabase\\\\.co$/i);' +
                        'if (m && byRef[m[1].toLowerCase()]) {' +
                            'var fake = answer(url);' +
                            'if (fake) {' +
                                'var xhr = this;' +
                                'fake.text().then(function(text){' +
                                    'try {' +
                                        'Object.defineProperty(xhr, \"readyState\", { configurable:true, get: function(){ return 4; } });' +
                                        'Object.defineProperty(xhr, \"status\", { configurable:true, get: function(){ return 200; } });' +
                                        'Object.defineProperty(xhr, \"responseText\", { configurable:true, get: function(){ return text; } });' +
                                        'Object.defineProperty(xhr, \"response\", { configurable:true, get: function(){ return text; } });' +
                                        'xhr.dispatchEvent(new Event(\"readystatechange\"));' +
                                        'xhr.dispatchEvent(new Event(\"load\"));' +
                                        'xhr.dispatchEvent(new Event(\"loadend\"));' +
                                    '} catch(e){}' +
                                '});' +
                                'return;' +
                            '}' +
                        '}' +
                    '} catch(e) {}' +
                    'return origSend.apply(this, arguments);' +
                '};' +
                'console.log(\"[Hub-SB] supabase fetch/XHR interceptor active for refs:\", Object.keys(byRef));' +

                // Navigation guard: stop the redirect loop without breaking
                // intentional navigation. We allow nav only if explicitly
                // triggered by a user click (event in flight); programmatic
                // window.location = "/" / .reload() / .replace("/") within
                // 1500ms of page load are blocked. Once the SPA has had
                // time to settle, normal navigation works again.
                '(function(){' +
                    'var pageStart = Date.now();' +
                    'var inUserGesture = false;' +
                    'document.addEventListener(\"click\", function(){ inUserGesture = true; setTimeout(function(){inUserGesture=false;}, 100); }, true);' +
                    'var origAssign = location.assign.bind(location);' +
                    'var origReplace = location.replace.bind(location);' +
                    'var origReload = location.reload.bind(location);' +
                    'function shouldBlock(target){' +
                        'var settling = (Date.now() - pageStart) < 1500;' +
                        'if (settling && !inUserGesture) {' +
                            'console.warn(\"[Hub-SB] BLOCKED programmatic navigation during settle to:\", target);' +
                            'return true;' +
                        '}' +
                        'return false;' +
                    '}' +
                    'location.assign = function(url){ if (shouldBlock(url)) return; return origAssign(url); };' +
                    'location.replace = function(url){ if (shouldBlock(url)) return; return origReplace(url); };' +
                    'location.reload = function(){ if (shouldBlock(\"reload\")) return; return origReload.apply(this, arguments); };' +
                    // Trap href setter on Location.
                    'try {' +
                        'var locProto = Object.getPrototypeOf(location);' +
                        'var hrefDesc = Object.getOwnPropertyDescriptor(locProto, \"href\");' +
                        'if (hrefDesc && hrefDesc.set) {' +
                            'var origSet = hrefDesc.set;' +
                            'Object.defineProperty(locProto, \"href\", {' +
                                'configurable: true,' +
                                'get: hrefDesc.get,' +
                                'set: function(url){ if (shouldBlock(url)) return; return origSet.call(this, url); }' +
                            '});' +
                        '}' +
                    '} catch(e) { console.warn(\"[Hub-SB] href trap failed:\", e); }' +
                    'console.log(\"[Hub-SB] navigation guard active for first 1500ms\");' +
                '})();' +
            '})();';

        return '<script id="hub-sb-bridge">(function(){try{' +
                  'var items=' + payload + ';' +
                  'for(var i=0;i<items.length;i++){var it=items[i];try{localStorage.setItem(it.key,it.json);}catch(e){}}' +
                  '}catch(e){}})();</script>' +
               '<script id="hub-sb-intercept">' + interceptor + '</script>';
    } catch(e) {
        console.error('[Hub-SB] bridge prep failed:', e.message);
        return '';
    }
}

// Determine a sensible Cache-Control header for a proxied response.
//
//   - HTML / JSON / API responses: `no-store`. Same as before. Auth-bearing.
//   - Static assets (images, fonts, css, woff/2): aggressive 1-hour cache.
//     These don't change per-user and don't carry secrets.
//   - JS files: NOT cached. We rewrite them on the fly (location-shim,
//     domain-rewrite, service-specific neuters), and the rewritten version
//     can change between deploys. Browser-side caching of patched JS would
//     mean a deploy-time fix doesn't reach users until their cache expires
//     — the exact bug that kept Grok stuck on stale Sentry-replay code.
//     Negligible performance cost: our origin still serves gzipped, and the
//     browser still uses the in-memory parsed bundle within a single page.
function pickCacheControl(req, contentType, statusCode) {
    if (statusCode && statusCode >= 400) return 'no-store';
    const ct = (contentType || '').toLowerCase();
    const url = req.url || '';

    // Definitely never cache: HTML, JSON, RSC, API.
    if (ct.includes('text/html')) return 'no-store';
    if (ct.includes('application/json')) return 'no-store';
    if (ct.includes('text/x-component')) return 'no-store';
    if (url.includes('/api/') || url.includes('/_next/data/')) return 'no-store';

    // Long-cache static assets that don't get rewritten.
    if (/\.(png|jpe?g|gif|webp|avif|svg|ico|bmp)(\?|$)/i.test(url)) return 'private, max-age=3600';
    if (/\.(woff2?|ttf|otf|eot)(\?|$)/i.test(url))            return 'private, max-age=86400';
    if (/\.(css)(\?|$)/i.test(url))                            return 'private, max-age=3600';
    if (ct.startsWith('image/'))                               return 'private, max-age=3600';
    if (ct.startsWith('font/') || ct.includes('font/woff'))    return 'private, max-age=86400';
    if (ct.includes('text/css'))                               return 'private, max-age=3600';

    // JS files: rewritten on the fly. Force revalidation so a deploy
    // immediately reaches the browser.
    if (/\.(js|mjs)(\?|$)/i.test(url) || ct.includes('javascript')) {
        return 'no-store';
    }

    // Default: don't cache to stay safe.
    return 'no-store';
}

async function loadProxies() {
    try {
        const rows = await query('SELECT url FROM proxies WHERE status = "active"');
        const urls = rows.map(r => r.url);
        
        const newAgents = [];
        urls.forEach(url => {
            try {
                newAgents.push(new HttpsProxyAgent(url, PROXY_AGENT_OPTS));
            } catch(e) {
                console.error(`[Proxy] Skipping invalid URL: ${url}`);
            }
        });

        // Fallback to .env if DB is empty
        if (newAgents.length === 0 && process.env.PROXY_LIST) {
            const envUrls = (process.env.PROXY_LIST || '').split(',').map(u => u.trim()).filter(u => u.length > 5);
            envUrls.forEach(url => {
                try {
                    newAgents.push(new HttpsProxyAgent(url, PROXY_AGENT_OPTS));
                } catch(e) {}
            });
        }

        proxyAgents = newAgents;
        console.log(`[Init] ${proxyAgents.length} Proxy Agents ready (keep-alive ON).`);
    } catch(e) {
        console.error('[Init] Proxy load error:', e);
    }
}
loadProxies();

// Listen for updates from Admin API
io.on('connection', (socket) => {
    socket.on('proxies_updated', () => {
        console.log('[Proxy] Admin updated proxy list. Reloading...');
        loadProxies();
    });
});

let globalProxyCounter = 0;

// IP-based Sticky Service Tracking (Fixes 404s for background requests)
const ipStickyMap = new Map();
function setStickyService(ip, slug) {
    ipStickyMap.set(ip, { slug, expiry: Date.now() + 600000 }); // 10 minutes
}
function getStickyService(ip) {
    const data = ipStickyMap.get(ip);
    if (data && data.expiry > Date.now()) return data.slug;
    ipStickyMap.delete(ip);
    return null;
}

function getProxyAgent(userId) {
    if (proxyAgents.length === 0) return null;
    
    const mode = process.env.PROXY_ROTATION_MODE || 'sticky';
    
    if (mode === 'random') {
        return proxyAgents[Math.floor(Math.random() * proxyAgents.length)];
    }
    
    if (mode === 'round-robin') {
        const agent = proxyAgents[globalProxyCounter % proxyAgents.length];
        globalProxyCounter++;
        return agent;
    }
    
    if (mode === 'sticky' && userId) {
        // Sticky assignment: Same proxy for same user to avoid session resets
        const userHash = String(userId).split('').reduce((a, b) => a + b.charCodeAt(0), 0);
        return proxyAgents[userHash % proxyAgents.length];
    }
    
    return proxyAgents[0]; // Fallback to first
}

// Active Proxy Sessions
const activeProxyUsers = new Map(); // userId -> lastActivityTimestamp

// Debounce map for usage counting. A single user click on a SPA can fire
// multiple back-to-back POSTs (real action + analytics). We collapse anything
// inside DEBOUNCE_MS into a single "use".
//   key: `${userId}:${serviceId}`  ->  last counted timestamp
const lastCountedUsage = new Map();
const USAGE_DEBOUNCE_MS = 5000;

const proxy = httpProxy.createProxyServer({
    changeOrigin: true,
    ws: true
});

// REMOVED: const target = 'https://stealthwriter.ai'; (Now dynamic)


// Catch-all Proxy Middleware (Must be last)
app.use(async (req, res, next) => {
    // --- DEBUG ROUTE: Test Proxies via Browser ---
    if (req.url === '/debug/test-proxies') {
        const results = [];
        const rows = await query('SELECT url FROM proxies WHERE status = "active"');
        let urls = rows.map(r => r.url);
        if (urls.length === 0) {
            urls = (process.env.PROXY_LIST || '').split(',').map(u => u.trim()).filter(u => u.length > 5);
        }
        
        results.push(`<h1>Proxy Diagnostic Tool</h1>`);
        results.push(`<p>Testing ${urls.length} proxies...</p>`);
        results.push(`<hr>`);

        for (let i = 0; i < urls.length; i++) {
            const url = proxyUrls[i];
            const masked = url.split('@')[1] || url;
            try {
                const agent = new HttpsProxyAgent(url);
                const start = Date.now();
                const testRes = await axios.get('https://api.ipify.org?format=json', { 
                    httpsAgent: agent, 
                    timeout: 8000,
                    headers: { 'User-Agent': 'Mozilla/5.0' }
                });
                results.push(`<div style="color:green">[${i+1}] ✅ SUCCESS: ${testRes.data.ip} (${Date.now() - start}ms) - ${masked}</div>`);
            } catch (err) {
                results.push(`<div style="color:red">[${i+1}] ❌ FAILED: ${err.message} - ${masked}</div>`);
            }
        }
        
        results.push(`<hr><p>Testing DIRECT connection (No Proxy)...</p>`);
        try {
            const start = Date.now();
            const directRes = await axios.get('https://api.ipify.org?format=json', { timeout: 8000 });
            results.push(`<div style="color:blue">✅ Direct IP: ${directRes.data.ip} (${Date.now() - start}ms)</div>`);
        } catch (err) {
            results.push(`<div style="color:orange">⚠️ Direct Connection Failed: ${err.message}</div>`);
        }

        return res.send(`<body style="font-family:monospace;background:#f0f0f0;padding:20px;">${results.join('<br>')}</body>`);
    }

    // Redirect /hub to root to ensure landing page visibility
    if (req.url === '/hub' || req.url === '/hub/') {
        return res.redirect('/');
    }

    console.log(`[Incoming Request] ${req.method} ${req.url} (Referer: ${req.headers.referer || 'none'})`);
    res.setHeader('X-Hub-Debug', 'v1.0.2-bypass-litespeed');

    // --- BLOCK TELEMETRY & ANALYTICS ---
    const blockedPaths = ['/ces/v1/', 'statsig', 'datadoghq', '/track', 'sentry.io'];
    if (blockedPaths.some(bp => req.url.includes(bp))) {
        res.setHeader('Access-Control-Allow-Origin', '*');
        res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, PATCH, DELETE');
        res.setHeader('Access-Control-Allow-Headers', '*');
        return res.status(200).json({});
    }

    // --- BLOCK SENTRY TUNNELS ---
    // Many Next.js apps (Grok, etc.) tunnel Sentry events through their own
    // origin at /monitoring?o=...&p=...&r=... so they're not blocked by ad-blockers.
    // We don't want those firing through our proxy: they 400 because the
    // upstream tunnel route only accepts traffic from the real origin host,
    // and they're noise. Return 204 immediately.
    if (/^\/monitoring(\?|$)/.test(req.url) || /^\/api\/monitoring(\?|$)/.test(req.url)) {
        res.setHeader('Access-Control-Allow-Origin', '*');
        res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
        res.setHeader('Access-Control-Allow-Headers', '*');
        return res.status(204).end();
    }

    // Don't proxy static assets or APIs of the hub itself
    const hubUIRoutes = ['/dashboard', '/dashboard/', '/admin', '/admin/', '/'];
    const isHubRoute = hubUIRoutes.includes(req.url) || 
                       req.url.startsWith('/hub/api') || 
                       req.url.startsWith('/socket.io') || 
                       req.url.startsWith('/style.css') || 
                       req.url.startsWith('/scripts.js') || 
                       req.url.startsWith('/auth.js') || 
                       req.url.startsWith('/assets') || 
                       req.url.startsWith('/fonts') || 
                       req.url.includes('LOGO.png') || 
                       req.url.includes('FAVICON.png');

    // --- 0. Global Static Proxy (For CDN assets like gstatic, googlevideo, etc) ---
    const staticProxyMatch = req.url.match(/^\/proxy-static\/([^\/]+)(\/.*)?$/);
    if (staticProxyMatch) {
        const hostname = staticProxyMatch[1];
        const path = staticProxyMatch[2] || '/';
        const target = `https://${hostname}`;
        
        req.url = path;
        req.headers['host'] = hostname;
        req.headers['origin'] = target;
        req.headers['referer'] = target;
        delete req.headers['cookie']; // Important: Don't leak Hub cookies to 3rd parties
        
        return proxy.web(req, res, { target, changeOrigin: true, selfHandleResponse: false });
    }

    if (isHubRoute) {

        if (req.url === '/' && !req.headers.referer?.includes('/dashboard') && !req.headers.referer?.includes('/admin')) {
            return res.sendFile(path.join(__dirname, 'public', 'index.html'));
        }
        return next();
    }

    // --- 1. Identify Service (God Mode: Slug -> Referer -> Cookie -> IP Sticky) ---
    const realIp = req.headers['x-forwarded-for']?.split(',')[0].trim() || req.ip;

    // ---- Path-duplication collapse ----
    // Some target sites (e.g. WriteHuman) use bare absolute paths like
    // <a href="/dashboard"> in their HTML. The browser resolves those against
    // the iframe's current URL — which is already /proxy/<slug>/ — so it
    // produces /proxy/<slug>/proxy/<slug>/dashboard, then on the next click
    // /proxy/<slug>/proxy/<slug>/proxy/<slug>/dashboard, etc. The path keeps
    // growing recursively and every request becomes invalid.
    //
    // Collapse repeated /proxy/<slug>/ prefixes back to a single one. This
    // is a no-op for normal traffic; the pattern only ever appears when the
    // browser has resolved a base-relative link inside an already-proxied page.
    const dupRe = /^(\/proxy\/[^\/]+\/)(?:\/?proxy\/[^\/]+\/)+/;
    if (dupRe.test(req.url)) {
        const fixed = req.url.replace(dupRe, '$1');
        console.log(`[Proxy] Collapsing recursive proxy path: ${req.url.slice(0, 100)}... -> ${fixed.slice(0, 100)}`);
        // Issue a redirect so the browser updates its URL bar / referer chain.
        // 308 preserves the method (works for POSTs too).
        return res.redirect(308, fixed);
    }

    const proxyMatch = req.url.match(/^\/proxy\/([^\/]+)(\/.*)?$/);
    const refererMatch = req.headers.referer?.match(/\/proxy\/([^\/]+)/);
    const sessionMatch = req.cookies?.stealth_proxy_last_slug || getStickyService(realIp);

    // Trailing Slash Normalization: /proxy/chatgpt -> /proxy/chatgpt/
    if (proxyMatch && !proxyMatch[2]) {
        return res.redirect(req.url + '/');
    }

    let serviceSlug = null;
    let targetPath = '/';

    if (proxyMatch) {
        serviceSlug = proxyMatch[1];
        targetPath = proxyMatch[2] || '/';
        // Stickiness
        res.cookie('stealth_proxy_last_slug', serviceSlug, { maxAge: 300000, path: '/' });
        setStickyService(realIp, serviceSlug);
    } else if (refererMatch) {
        serviceSlug = refererMatch[1];
        targetPath = req.url;
    } else if (sessionMatch) {
        serviceSlug = sessionMatch;
        targetPath = req.url;
    }

    // Special Case: Surgical Rescue for CDN/Assets even without a clear slug
    const isAssetPath = req.url.includes('/cdn/') || req.url.includes('/cdn-cgi/') || req.url.includes('/fonts/') || 
                       req.url.includes('/_next/') || req.url.includes('.js') || req.url.includes('.css');

    if (!serviceSlug) {
        // If it's a known asset path but we lost the slug, attempt one last IP rescue
        if (isAssetPath) {
            const lastSlug = getStickyService(realIp);
            if (lastSlug) {
                console.log(`[Proxy] Surgical Rescue (IP-Based) for asset: ${req.url}`);
                serviceSlug = lastSlug;
                targetPath = req.url;
            }
        }
    }

    if (!serviceSlug) {
        return next(); 
    }


    // 2. Auth & Permission Check
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).send('<div style="color:black;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">Please Login via the Hub.</div>');

    try {
        const verified = jwt.verify(token, JWT_SECRET);
        
        // Fetch Service & Assignment
        console.log(`[Proxy] Finding service for slug: ${serviceSlug}`);
        const service = await get('SELECT * FROM services WHERE slug = ?', [serviceSlug]);
        if (!service) {
            console.warn(`[Proxy] Service NOT FOUND for slug: ${serviceSlug}`);
            return res.status(404).send('Service not found.');
        }

        const assignment = await get(`
            SELECT a.cookie_id, a.daily_limit_override, u.status, c.data as cookie_data 
            FROM user_assignments a
            JOIN users u ON a.user_id = u.id
            LEFT JOIN cookies c ON a.cookie_id = c.id
            WHERE a.user_id = ? AND a.service_id = ?
        `, [verified.id, service.id]);

        if (!assignment || assignment.status === 'blocked') {
            return res.status(403).send('<div style="color:red;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">Access Denied. Please contact Admin.</div>');
        }

        if (!assignment.cookie_id || !assignment.cookie_data) {
            return res.status(403).send('<div style="color:black;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">No premium slot assigned for this tool.</div>');
        }

        // --- DAILY USAGE LIMIT ---
        // STRICT MODE: a POST/PUT only counts toward the cap if its URL
        // contains the service's configured `billable_path` (e.g.
        // "/api/humanize" for StealthWriter). Everything else — autosave,
        // draft sync, telemetry, alternatives refresh, GETs — never counts.
        //
        // Per-user override: user_assignments.daily_limit_override, when set,
        // overrides the service's default daily_limit for THIS user only.
        // NULL = inherit service default.
        const isWriteRequest = req.method === 'POST' || req.method === 'PUT';
        const billablePath = (service.billable_path || '').trim();
        const userOverride = assignment.daily_limit_override ? parseInt(assignment.daily_limit_override, 10) : null;
        const serviceDefault = service.daily_limit ? parseInt(service.daily_limit, 10) : 0;
        // Effective cap for this user. Override > service default.
        // 0 / null on either layer = unlimited (override 0 also disables cap).
        const effectiveLimit = (userOverride !== null && Number.isFinite(userOverride))
            ? userOverride
            : serviceDefault;

        const isBillableAction = isWriteRequest &&
                                  billablePath.length > 0 &&
                                  (req.url || '').indexOf(billablePath) !== -1;

        if (effectiveLimit > 0 && isBillableAction) {
            // Debounce: if the same user fired another POST inside the window,
            // don't count it again — but ALSO don't run the gate, so we don't
            // double-count or over-throttle. The first POST already recorded.
            const key = `${verified.id}:${service.id}`;
            const lastT = lastCountedUsage.get(key) || 0;
            const inDebounce = (Date.now() - lastT) < USAGE_DEBOUNCE_MS;

            if (!inDebounce) {
                try {
                    const verdict = await checkAndRecordUsage(verified.id, service.id, effectiveLimit);

                    if (!verdict.allowed) {
                        const resetAt = verdict.resetAt;
                        const minsLeft = Math.max(1, Math.ceil((resetAt - Date.now()) / 60000));
                        const hrsLeft = (minsLeft / 60).toFixed(1);
                        res.status(429);
                        res.setHeader('Retry-After', Math.ceil((resetAt - Date.now()) / 1000));

                        const accept = (req.headers['accept'] || '').toLowerCase();
                        const isApiPath = /\/api\/|\.json($|\?)/i.test(req.url);
                        const wantsJson = accept.includes('application/json') ||
                                           req.headers['x-requested-with'] === 'XMLHttpRequest' ||
                                           isApiPath;

                        const friendlyMsg = `You've used your daily allowance of ${effectiveLimit} ${service.name} actions. Your access resets in about ${hrsLeft} hours (${minsLeft} min). Contact your administrator if you need more.`;

                        if (wantsJson) {
                            res.setHeader('Content-Type', 'application/json');
                            return res.send(JSON.stringify({
                                error: friendlyMsg,
                                message: friendlyMsg,
                                detail: friendlyMsg,
                                limit_reached: true,
                                daily_limit: effectiveLimit,
                                used: verdict.used,
                                reset_at: resetAt,
                                reset_in_minutes: minsLeft,
                                reset_in_hours: Number(hrsLeft)
                            }));
                        }

                        return res.send(`
                            <div style="font-family:sans-serif;color:#0f172a;text-align:center;padding:60px 20px;max-width:560px;margin:8% auto;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
                                <div style="font-size:48px;margin-bottom:8px;">⏳</div>
                                <h2 style="margin:0 0 12px;color:#dc2626;">Daily limit reached</h2>
                                <p style="margin:0 0 8px;font-size:1.1rem;">You've used <b>${service.name}</b> ${verdict.used} times in the last 24 hours.</p>
                                <p style="margin:0 0 20px;color:#475569;">Your daily allowance is <b>${effectiveLimit}</b>. Try again in about <b>${hrsLeft} hours</b> (${minsLeft} min).</p>
                                <p style="font-size:0.85rem;color:#64748b;">Need more? Contact your administrator.</p>
                            </div>
                        `);
                    }

                    // Allowed and recorded.
                    lastCountedUsage.set(key, Date.now());

                    // Notify admins for the live usage view.
                    io.to('admins').emit('usage_recorded', {
                        user_id: verified.id, service_id: service.id, ts: Date.now()
                    });
                    // Notify the user's own session so the dashboard badge refreshes.
                    io.to(`user_${verified.id}`).emit('usage_recorded', {
                        user_id: verified.id, service_id: service.id, ts: Date.now()
                    });
                } catch (e) {
                    // If the gate itself errored, fail OPEN with a log line.
                    // Better to occasionally let one through than break the
                    // service entirely.
                    console.error('[Usage] gate failed:', e.message);
                }
            }
        }

        // Track Activity
        activeProxyUsers.set(verified.id, Date.now());
        io.to('admins').emit('proxy_activity_update', Array.from(activeProxyUsers.keys()));

        let rawCookieData = assignment.cookie_data || '';
        let processedCookie = rawCookieData;
        if (rawCookieData.trim().startsWith('[') && rawCookieData.trim().endsWith(']')) {
            try {
                const arr = JSON.parse(rawCookieData.trim());
                if (Array.isArray(arr)) {
                    processedCookie = arr.map(c => `${c.name || ''}=${c.value || ''}`).join('; ');
                }
            } catch(e) {}
        }
        
        // 3. Prepare Proxy Headers (Common for all requests)
        const sanitizedCookie = processedCookie
                                  .replace(/[\r\n]/gm, '')
                                  .replace(/[^\x20-\x7E]/g, '')
                                  .trim();
        
        // Prepare Proxy Request
        req.userCookieData = sanitizedCookie;
        req.serviceInjectionJS = service.injection_js;
        
        let safeTargetUrl = service.target_url || '';
        if (safeTargetUrl && !safeTargetUrl.startsWith('http')) {
            safeTargetUrl = 'https://' + safeTargetUrl;
        }
        req.targetUrl = safeTargetUrl;
        
        req.headers['cookie'] = sanitizedCookie;
        req.headers['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        
        let targetUrlObj;
        try {
            targetUrlObj = new URL(safeTargetUrl);
        } catch(err) {
            return res.status(200).send('<div style="color:red;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">Error: Invalid Target URL configured for this service. URL: ' + safeTargetUrl + '</div>');
        }

        req.headers['origin'] = targetUrlObj.origin;
        req.headers['host'] = targetUrlObj.host;
        req.url = targetPath;
        req.headers['referer'] = targetUrlObj.origin + targetPath;

        // Strip out security headers that leak the proxy domain
        delete req.headers['sec-fetch-dest'];
        delete req.headers['sec-fetch-mode'];
        delete req.headers['sec-fetch-site'];

        // Determine if this is an HTML page request vs an API/asset/RSC request
        const hasFileExtension = /\.[a-zA-Z0-9]{1,10}(\?|$)/.test(targetPath);
        const isApiCall = targetPath.includes('/api/') || targetPath.includes('/_next/data/');
        const acceptsHtml = req.headers['accept']?.includes('text/html') || req.headers['accept']?.includes('application/xhtml+xml');
        
        // Next.js RSC streaming — MUST NOT buffer
        const isRSC = req.headers['rsc'] === '1' || 
                      req.headers['next-router-state-tree'] ||
                      req.headers['next-router-prefetch'] ||
                      req.headers['next-url'];
        
        // Only buffer if it's a genuine first-page HTML load OR a JavaScript file (for patching)
        const shouldBuffer = !isRSC && !isApiCall && 
                             (acceptsHtml || targetPath === '/' || targetPath === '' || targetPath.endsWith('.js'));
        
        const currentAgent = getProxyAgent(verified.id);

        // Manual Fail-Safe Timeout (20 seconds)
        const watchdog = setTimeout(() => {
            if (!res.headersSent) {
                console.error(`[Proxy] Request WATCHDOG TIMEOUT for ${targetUrlObj.origin} ${currentAgent ? '(Proxy Mode)' : '(Direct Mode)'}`);
                try {
                    res.status(200).send(`<div style="color:red;text-align:center;margin-top:20%;font-size:1.5rem;font-family:sans-serif;">
                        <b>Proxy Timeout:</b> The target server (${targetUrlObj.hostname}) is taking too long.<br>
                        <small style="color:gray;">This usually means the proxy IP is blocked or slow.</small>
                    </div>`);
                } catch(e) {}
            }
        }, 20000);

        res.on('finish', () => clearTimeout(watchdog));
        res.on('close', () => clearTimeout(watchdog));

        // --- NEW ENGINE: Axios for Buffered Responses (HTML/Components) ---
        if (shouldBuffer) {
            req.shouldBufferResponse = true;
            console.log(`[Proxy] Forwarding via Axios: ${targetUrlObj.origin}${req.url} ${currentAgent ? '(Proxy Mode)' : '(Direct Mode)'}`);
            
            try {
                // Prepare headers for Axios
                const forwardHeaders = { ...req.headers };
                delete forwardHeaders.host;
                delete forwardHeaders['content-length'];
                delete forwardHeaders['accept-encoding']; // Ask for plain text
                
                // Add Anti-Detection Headers
                forwardHeaders['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/124.0.0.0';
                if (acceptsHtml) {
                    forwardHeaders['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
                } else if (!forwardHeaders['Accept']) {
                    forwardHeaders['Accept'] = '*/*';
                }
                forwardHeaders['Accept-Language'] = 'en-US,en;q=0.9';
                forwardHeaders['Sec-Ch-Ua'] = '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"';
                forwardHeaders['Sec-Ch-Ua-Mobile'] = '?0';
                forwardHeaders['Sec-Ch-Ua-Platform'] = '"Windows"';

                const axiosConfig = {
                    method: req.method,
                    url: `${targetUrlObj.origin}${req.url}`,
                    headers: forwardHeaders,
                    httpsAgent: currentAgent || undefined,
                    timeout: 25000,
                    maxContentLength: Infinity,
                    maxBodyLength: Infinity,
                    validateStatus: () => true,
                    responseType: 'arraybuffer',
                    decompress: true // Ensure we get decompressed data for patching
                };

                // Only pipe the request stream for methods that can have a body
                if (req.method !== 'GET' && req.method !== 'HEAD') {
                    axiosConfig.data = req;
                }

                const response = await axios(axiosConfig);

                // --- PROCESSING ---
                let bodyBuffer = Buffer.from(response.data);
                let processedBuffer = bodyBuffer;
                let contentType = response.headers['content-type'] || '';
                const realIp = req.headers['x-forwarded-for']?.split(',')[0].trim() || req.ip;
                const proxyBase = `/proxy/${service.slug}/`;

                // Sticky Service Tracking
                res.cookie('HUB_ACTIVE_SERVICE', service.slug, { maxAge: 300000, path: '/' });
                setStickyService(realIp, service.slug);

                if (contentType.includes('text/html')) {
                    let html = bodyBuffer.toString('utf8');
                    
                    // 1. Ultimate Hijacker (Universal Path Locker)
                    const targetDomain = targetUrlObj.host;
                    const hijackScript = `
                    <script>
                        (function() {
                            const proxyBase = '${proxyBase}';
                            const targetDomain = '${targetDomain}';
                            const g = globalThis;
                            
                            const targetOrigin = 'https://' + targetDomain;
                            
                            const patchUrl = (url) => {
                                if (typeof url !== 'string' || !url) return url;
                                if (url.startsWith('blob:') || url.startsWith('data:')) return url;
                                
                                // Catch absolute URLs to the target domain or spoofed domain
                                const absRegex = new RegExp('^(https?:)?//([a-zA-Z0-9.-]*\\.)?' + targetDomain.replace(/\\./g, '\\\\.'));
                                if (absRegex.test(url)) {
                                    url = url.replace(absRegex, '');
                                    if (!url.startsWith('/')) url = '/' + url;
                                    
                                    // PREVENT DOUBLE PROXY PREFIX
                                    if (url.toLowerCase().startsWith(proxyBase.toLowerCase())) {
                                        return url; // Already has it!
                                    }
                                    return proxyBase + url.substring(1);
                                }
                                // Catch relative URLs
                                if (url.startsWith('/') && !url.toLowerCase().startsWith(proxyBase.toLowerCase()) && !url.startsWith('//')) {
                                    return proxyBase + url.substring(1);
                                }
                                return url;
                            };

                            // --- IDENTITY CLOAK: Pretend to be at chatgpt.com ---
                            const hubLocation = new Proxy(g.location, {
                                get: (t, p) => {
                                    if (p === 'pathname') {
                                        return t.pathname.startsWith(proxyBase) ? (t.pathname.replace(proxyBase, '/') || '/') : t.pathname;
                                    }
                                    if (p === 'href') {
                                        const path = hubLocation.pathname + t.search + t.hash;
                                        return targetOrigin + path;
                                    }
                                    if (p === 'origin') return targetOrigin;
                                    if (p === 'host' || p === 'hostname') return targetDomain;
                                    if (p === 'toString') return () => hubLocation.href;
                                    if (p === 'assign' || p === 'replace') return (url) => t[p](patchUrl(url));
                                    if (p === 'reload') return () => t.reload();
                                    
                                    let v = t[p];
                                    return typeof v === 'function' ? v.bind(t) : v;
                                },
                                set: (t, p, v) => {
                                    if (p === 'href') { t.href = patchUrl(v); return true; }
                                    t[p] = v; return true;
                                }
                            });

                            g.__HUB_LOCATION__ = hubLocation;
                            g.__HL__ = hubLocation;

                            // Close Remix/React Location Leaks
                            //
                            // Two crashes we have to dodge here:
                            //   (a) bound Object/Array etc lose their static methods
                            //       (Object.getOwnPropertyDescriptor disappears).
                            //   (b) NOT binding plain instance methods triggers
                            //       "Illegal invocation" because they need
                            //       this === realWindow.
                            // Solution: always bind, then copy own props from the
                            // original onto the bound result. This gives us the
                            // right receiver AND preserves Object.assign, Object.keys,
                            // Promise.resolve, Array.from, etc.
                            const realDefaultView = document.defaultView;
                            const __bindCache = new WeakMap();
                            // Only skip the few props that bound functions
                            // already auto-define. Crucially, DO copy prototype,
                            // so constructors like CSSStyleDeclaration, HTMLElement,
                            // XMLHttpRequest, URL, etc. keep their prototype chain
                            // accessible. Sentry session-replay walks
                            // CSSStyleDeclaration.prototype.setProperty -- without
                            // our prototype copy that returns undefined and crashes.
                            const __bindSkip = new Set(['length', 'name', 'arguments', 'caller']);
                            const __safeBind = (fn, target) => {
                                if (__bindCache.has(fn)) return __bindCache.get(fn);
                                let bound;
                                try { bound = fn.bind(target); } catch (_) { return fn; }
                                try {
                                    const names = Object.getOwnPropertyNames(fn);
                                    for (const n of names) {
                                        if (__bindSkip.has(n)) continue;
                                        try {
                                            const desc = Object.getOwnPropertyDescriptor(fn, n);
                                            if (desc) {
                                                // prototype on a bound fn is non-writable
                                                // and non-configurable, so we have to use
                                                // defineProperty with a value descriptor
                                                // instead of going through the existing slot.
                                                Object.defineProperty(bound, n, desc);
                                            }
                                        } catch (_) {}
                                    }
                                    const syms = Object.getOwnPropertySymbols(fn);
                                    for (const s of syms) {
                                        try {
                                            const desc = Object.getOwnPropertyDescriptor(fn, s);
                                            if (desc) Object.defineProperty(bound, s, desc);
                                        } catch (_) {}
                                    }
                                } catch (_) {}
                                __bindCache.set(fn, bound);
                                return bound;
                            };
                            const __dvProxy = new Proxy(realDefaultView, {
                                get: (target, prop) => {
                                    if (prop === 'location') return hubLocation;
                                    const val = Reflect.get(target, prop, target);
                                    if (typeof val !== 'function') return val;
                                    return __safeBind(val, target);
                                }
                            });
                            Object.defineProperty(document, 'defaultView', {
                                get: () => __dvProxy,
                                configurable: true
                            });

                            // URL Constructor Interceptor (Bulletproof)
                            const _URL = g.URL;
                            g.URL = function(url, base) {
                                if (arguments.length < 2 || base === undefined) {
                                    return new _URL(url);
                                }
                                
                                let actualBase = base;
                                if (base === hubLocation || base === g.location) {
                                    actualBase = hubLocation.href;
                                } else if (base && typeof base === 'object' && base.href) {
                                    actualBase = base.href;
                                }
                                
                                try {
                                    // 1. If url is absolute, base is ignored anyway. Skip base validation to prevent crashes.
                                    return new _URL(url);
                                } catch(e) {
                                    // 2. url is relative. Try with the provided base.
                                    try { 
                                        return new _URL(url, actualBase); 
                                    } catch(e2) { 
                                        // 3. Provided base is invalid. Force our valid proxy base to prevent the crash.
                                        return new _URL(url, hubLocation.href);
                                    }
                                }
                            };
                            g.URL.prototype = _URL.prototype;
                            Object.assign(g.URL, _URL);

                            // Disable Service Workers
                            if (g.navigator?.serviceWorker) {
                                g.navigator.serviceWorker.register = () => new Promise(() => {});
                            }

                            // Dynamic Element Patcher
                            const _createElement = document.createElement;
                            document.createElement = function(tag) {
                                const el = _createElement.apply(this, arguments);
                                const t = tag.toLowerCase();
                                if (['script', 'link', 'img', 'iframe'].includes(t)) {
                                    const _set = el.setAttribute;
                                    el.setAttribute = function(n, v) {
                                        if (n === 'src' || n === 'href') v = patchUrl(v);
                                        return _set.call(this, n, v);
                                    };
                                    ['src', 'href'].forEach(p => {
                                        Object.defineProperty(el, p, {
                                            get: () => el.getAttribute(p),
                                            set: (v) => el.setAttribute(p, v)
                                        });
                                    });
                                }
                                return el;
                            };

                            const _fetch = window.fetch;
                            window.fetch = function(input, init) {
                                if (input instanceof Request) {
                                    const patched = patchUrl(input.url);
                                    if (patched !== input.url) return _fetch.call(window, new Request(patched, input), init);
                                } else if (typeof input === 'string') {
                                    input = patchUrl(input);
                                }
                                return _fetch.call(window, input, init);
                            };

                            const _open = XMLHttpRequest.prototype.open;
                            XMLHttpRequest.prototype.open = function(m, u) {
                                return _open.call(this, m, patchUrl(u), ...Array.from(arguments).slice(2));
                            };

                            const _push = history.pushState;
                            history.pushState = function() { arguments[2] = patchUrl(arguments[2]); return _push.apply(this, arguments); };
                            const _replace = history.replaceState;
                            history.replaceState = function() { arguments[2] = patchUrl(arguments[2]); return _replace.apply(this, arguments); };
                            
                            console.log('[Hub] Perfect Identity Active.');
                        })();
                    </script>`;
                    
                    html = html.replace('<head>', `<head>${hijackScript}`);
                    if (service.injection_js) html = html.replace('</head>', `${service.injection_js}</head>`);

                    // Supabase localStorage bridge — for sites like WriteHuman
                    // that store their session in localStorage. Inject right
                    // after <head> so it runs before the site's own bundles.
                    const sbBridge = buildSbBridgeScript(req.userCookieData);
                    if (sbBridge) {
                        html = html.replace('<head>', '<head>' + sbBridge);
                        console.log('[Hub-SB] Injected (Axios path) for ' + service.slug);
                    }

                    // 2. JS Redirection in HTML
                    // Word-bounded so we don't mangle identifiers like
                    // `window.locationInfo`, `document.locationHandler`, etc.
                    html = html.replace(/window\.location\b/g, 'globalThis.__HL__');
                    html = html.replace(/document\.location\b/g, 'globalThis.__HL__');

                    // 3. Domain Rewriting
                    const ownDomainRegex = new RegExp(`(https?:)?//([a-zA-Z0-9.-]*\\.)?${targetDomain.replace(/\./g, '\\.')}`, 'g');
                    html = html.replace(ownDomainRegex, `/proxy/${service.slug}`);

                    processedBuffer = Buffer.from(html);
                } 
                else if (contentType.includes('javascript') || req.url.includes('.js') || (contentType.includes('octet-stream') && req.url.includes('.js'))) {
                    let js = bodyBuffer.toString('utf8');

                    const targetDomain = targetUrlObj.host;
                    // Skip-rewrite shortcut: if neither rewrite trigger appears
                    // in this file, ship the original bytes untouched. Saves
                    // multiple regex passes on big chunks of vendor bundles.
                    const needsRewrite =
                        js.indexOf('window.location') !== -1 ||
                        js.indexOf('document.location') !== -1 ||
                        js.indexOf(targetDomain) !== -1 ||
                        js.indexOf('/cdn/') !== -1;

                    if (needsRewrite) {
                        // MINIMAL SURGERY: Only replace the two unambiguous browser location accessors.
                        // Word-bounded so identifiers like `window.locationBar`,
                        // `document.locationBar` etc. are not mangled.
                        js = js.replace(/window\.location\b/g, 'globalThis.__HL__');
                        js = js.replace(/document\.location\b/g, 'globalThis.__HL__');

                        // Domain Rewriting in JS (CRITICAL: catches hardcoded absolute API URLs)
                        const ownDomainRegex = new RegExp(`(https?:)?//([a-zA-Z0-9.-]*\\.)?${targetDomain.replace(/\./g, '\\.')}`, 'g');
                        js = js.replace(ownDomainRegex, `/proxy/${service.slug}`);

                        // Force relative paths for dynamic imports
                        const cleanBase = proxyBase.endsWith('/') ? proxyBase.slice(0, -1) : proxyBase;
                        js = js.replace(/from\s*["'](\/cdn\/[^"']+)["']/g, `from "${cleanBase}$1"`);
                        js = js.replace(/import\s*\(["'](\/cdn\/[^"']+)["']\)/g, `import("${cleanBase}$1")`);

                        // ---- Sentry session-replay neuter (Grok-specific) ----
                        // Sentry's bundled session-replay tries to record DOM
                        // events on the iframe's cross-origin document and dies.
                        // We make its core methods no-ops so init completes
                        // cleanly. Only target patterns that are uniquely
                        // Sentry-SDK to avoid colliding with Grok's app code.
                        if (service.slug === 'grok') {
                            // `startRecording(){try{` is the exact Sentry replay signature.
                            js = js.replace(/\bstartRecording\(\)\{try\{/g, 'startRecording(){return;try{');
                            // `_initializeRecording(){` only appears in Sentry replay.
                            js = js.replace(/\b_initializeRecording\(\)\{/g, '_initializeRecording(){return;');
                            // `sendBufferedReplayOrFlush` is unique to Sentry replay.
                            js = js.replace(/\bsendBufferedReplayOrFlush\(\)\{/g, 'sendBufferedReplayOrFlush(){return Promise.resolve();');
                        }

                        processedBuffer = Buffer.from(js);
                    } else {
                        // No rewrite needed — pass original bytes straight through.
                        processedBuffer = bodyBuffer;
                    }
                }

                // --- SEND RESPONSE ---
                // Pass through upstream headers EXCEPT:
                //   - body-length related (we may have rewritten the bytes)
                //   - security headers we strip to allow iframing
                //   - cache headers (we set our own with pickCacheControl)
                //   - validators (etag/last-modified) — if we left these the
                //     browser would 304-revalidate to upstream's untouched
                //     bytes, defeating any rewrite or fix we deployed.
                const skipHeaders = new Set([
                    'content-length',
                    'content-security-policy',
                    'x-frame-options',
                    'transfer-encoding',
                    'content-encoding',
                    'cache-control',
                    'etag',
                    'last-modified',
                    'expires',
                    'age'
                ]);
                Object.keys(response.headers).forEach(key => {
                    if (!skipHeaders.has(key.toLowerCase())) {
                        res.setHeader(key, response.headers[key]);
                    }
                });

                // Set Cache-Control AFTER the header copy so we always win.
                res.setHeader('Cache-Control', pickCacheControl(req, contentType, response.status));

                res.status(response.status === 500 ? 501 : response.status);
                return res.send(processedBuffer);

            } catch (err) {
                console.error(`[Proxy Axios Error] ${err.message}`);
                res.status(200).send(`<div style="color:red;text-align:center;margin-top:20%;font-size:1.5rem;font-family:sans-serif;">
                    <b>Proxy Engine Error:</b> ${err.message}<br>
                    <small style="color:gray;">Target: ${targetUrlObj.hostname}</small>
                </div>`);
            }
            return;
        }

        // --- OLD ENGINE: http-proxy for Streaming Static Assets ---
        req.shouldBufferResponse = false;
        console.log(`[Proxy] Streaming via http-proxy: ${targetUrlObj.origin} ${currentAgent ? '(Proxy Mode)' : '(Direct Mode)'}`);
        proxy.web(req, res, { 
            target: targetUrlObj.origin, 
            selfHandleResponse: false, 
            agent: currentAgent,
            changeOrigin: true,
            secure: false,
            proxyTimeout: 15000, 
            timeout: 15000 
        });


    } catch(e) {
        console.error('Proxy Middleware Error:', e);
        return res.status(200).send(`<div style="color:red;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">Proxy Middleware Error: ${e.message}</div>`);
    }
});



// Cleanup inactive proxy users every 30 seconds
setInterval(() => {
    const now = Date.now();
    let changed = false;
    for (const [userId, lastSeen] of activeProxyUsers.entries()) {
        if (now - lastSeen > 60000) { // 1 minute inactivity
            activeProxyUsers.delete(userId);
            changed = true;
        }
    }
    if (changed) io.to('admins').emit('proxy_activity_update', Array.from(activeProxyUsers.keys()));
}, 30000);

// Dynamic injection script is now fetched from the database per service


proxy.on('proxyRes', (proxyRes, req, res) => {
    try {
        // Prevent LiteSpeed from intercepting 500 errors and replacing the body with its own template.
        // By changing 500 to 501 (Not Implemented), we ensure the user sees the ACTUAL error from ChatGPT.
        if (proxyRes.statusCode === 500) {
            proxyRes.statusCode = 501;
        }

        // --- ALWAYS strip security headers for ALL proxy responses (streamed AND buffered) ---
        const headersToStrip = [
            'content-security-policy',
            'content-security-policy-report-only',
            'x-frame-options',
            'x-content-type-options',
            'cross-origin-opener-policy',
            'cross-origin-embedder-policy',
            'cross-origin-resource-policy',
            'permissions-policy',
            'strict-transport-security',
            'x-xss-protection'
        ];
        headersToStrip.forEach(h => { delete proxyRes.headers[h]; });

        // Rewrite redirect Location headers for ALL responses
        if (proxyRes.headers['location']) {
            const slugMatch = req.originalUrl?.match(/\/proxy\/([^\/]+)/);
            if (slugMatch) {
                const slug = slugMatch[1];
                let loc = proxyRes.headers['location'];
                if (loc.startsWith('/')) {
                    proxyRes.headers['location'] = `/proxy/${slug}${loc}`;
                } else if (loc.startsWith('http') && req.targetUrl) {
                    try {
                        const locUrl = new URL(loc);
                        const targetUrlObj = new URL(req.targetUrl);
                        if (locUrl.host === targetUrlObj.host || locUrl.host.endsWith('.' + targetUrlObj.host.replace('www.', ''))) {
                            proxyRes.headers['location'] = `/proxy/${slug}${locUrl.pathname}${locUrl.search}${locUrl.hash}`;
                        }
                    } catch(e) {}
                }
            }
        }

        // For non-buffered (streamed) responses, we're done — http-proxy pipes the rest automatically
        if (!req.shouldBufferResponse || res.headersSent) return;

        // Only pipe through RSC-specific responses (not regular HTML)
        const resContentType = (proxyRes.headers['content-type'] || '');
        const isRSCResponse = resContentType.includes('text/x-component') || 
                               resContentType.includes('application/octet-stream');
        
        if (isRSCResponse) {
            try {
                res.writeHead(proxyRes.statusCode, proxyRes.headers);
                proxyRes.pipe(res);
            } catch(e) {
                console.error('RSC Streaming Error:', e.message);
                res.end();
            }
            return;
        }

    let body = [];

    proxyRes.on('data', chunk => body.push(chunk));
    proxyRes.on('end', () => {
        try {
            body = Buffer.concat(body);
            
            // Pass through the original status code (crucial for redirects like 308)
            res.status(proxyRes.statusCode);

            // Set headers for buffered responses (CSP already stripped above)
            Object.keys(proxyRes.headers).forEach(key => {
                const lowKey = key.toLowerCase();
                if (lowKey === 'set-cookie' || lowKey === 'cache-control' || lowKey === 'etag' || lowKey === 'content-length') {
                    return;
                }
                
                let val = proxyRes.headers[key];
                try { res.setHeader(key, val); } catch(e) {}
            });

            // Smart cache header. Static assets (images, fonts, css) get a
            // private cache so the browser doesn't re-download them through
            // the proxy on every page navigation. Auth-bearing responses
            // (HTML / JSON / API) keep `no-store`.
            res.setHeader('Cache-Control', pickCacheControl(req, proxyRes.headers['content-type'], proxyRes.statusCode));


            // Session Sync (Mobile-Optimized)
            if (req.userCookieData) {
                const cookieArray = req.userCookieData.split(';').map(c => c.trim()).filter(c => c);
                cookieArray.forEach(c => {
                    try { res.append('Set-Cookie', `${c}; Path=/; SameSite=Lax`); } catch(e) {}
                });
            }


            const contentType = proxyRes.headers['content-type'] || '';

        if (contentType.includes('text/html')) {
            let html = body.toString();

            // Supabase localStorage bridge (also for the streamed HTML path).
            const sbBridge2 = buildSbBridgeScript(req.userCookieData);
            if (sbBridge2) {
                html = html.replace('<head>', '<head>' + sbBridge2);
                console.log('[Hub-SB] Injected (Streamed path) for ' + (req.url || '/'));
            }
            
            // --- 4. Dynamic Link Rewriting ---
            // Step A: Rewrite the TOOL'S OWN domain to go through /proxy/{slug}/ (authenticated, with cookies)
            if (req.targetUrl) {
                try {
                    const targetUrlObj = new URL(req.targetUrl);
                    const targetHost = targetUrlObj.host;
                    const targetDomain = targetHost.replace(/^www\./, '');
                    const slugMatch = req.originalUrl.match(/\/proxy\/([^\/]+)/);
                    if (slugMatch) {
                        const slug = slugMatch[1];
                        // Rewrite https://writehuman.ai/path and //writehuman.ai/path to /proxy/writehuman/path
                        const ownDomainRegex = new RegExp(`(https?:)?//([a-zA-Z0-9.-]*\\.)?${targetDomain.replace(/\./g, '\\.')}`, 'g');
                        html = html.replace(ownDomainRegex, `/proxy/${slug}`);
                    }
                } catch(e) {}
            }

            // Step B: Rewrite 3RD PARTY CDN domains to go through /proxy-static/ (no cookies needed)
            const domainsToProxy = [
                'chatgpt.com',
                'openai.com',
                'oaistatic.com',
                'oaiusercontent.com',
                'netflix.com',
                'nflxext.com',
                'nflximg.net',
                'nflximg.com',
                'nflxvideo.net',
                'nflxso.net',
                'google.com',
                'gstatic.com',
                'googlevideo.com',
                'ytimg.com',
                'ggpht.com'
            ];

            domainsToProxy.forEach(domain => {
                // Catches https://, http://, and //
                const regex = new RegExp(`(https?:)?//([a-zA-Z0-9.-]+\\.)?${domain.replace('.', '\\.')}`, 'g');
                html = html.replace(regex, (match) => {
                    const cleanMatch = match.replace(/^(https?:)?\/\//, '');
                    return `/proxy-static/${cleanMatch}`;
                });
            });


            // --- 5. Core Injection (Path Masking & Navigation Hijack) ---
            const coreInjectionJS = `
            <script>
            (function() {
                const proxyPrefix = '/proxy/' + window.location.pathname.split('/')[2];
                
                // 1. Pathname Cloaking (For React Router)
                const originalPathname = window.location.pathname;
                Object.defineProperty(window.document, 'URL', {
                    get: () => window.location.href.replace(window.location.origin + proxyPrefix, window.location.origin)
                });

                // 2. Navigation Hijacking (pushState/replaceState)
                const patchState = (type) => {
                    const orig = history[type];
                    return function() {
                        let url = arguments[2];
                        if (url && !url.startsWith('http') && !url.startsWith(proxyPrefix)) {
                            arguments[2] = proxyPrefix + (url.startsWith('/') ? '' : '/') + url;
                        }
                        return orig.apply(this, arguments);
                    };
                };
                history.pushState = patchState('pushState');
                history.replaceState = patchState('replaceState');

                // 3. XHR/Fetch URL Rewriting
                const origOpen = XMLHttpRequest.prototype.open;
                XMLHttpRequest.prototype.open = function(method, url) {
                    this.__hubMethod = method; // captured for the action-done event below
                    if (url && !url.startsWith('http') && !url.startsWith(proxyPrefix) && !url.startsWith('/cdn/')) {
                        url = proxyPrefix + (url.startsWith('/') ? '' : '/') + url;
                    }
                    return origOpen.apply(this, arguments);
                };

                const origFetch = window.fetch;
                window.fetch = function(input, init) {
                    if (typeof input === 'string' && !input.startsWith('http') && !input.startsWith(proxyPrefix) && !input.startsWith('/cdn/')) {
                        input = proxyPrefix + (input.startsWith('/') ? '' : '/') + input;
                    }
                    const method = (init && init.method) || (typeof input === 'object' && input && input.method) || 'GET';
                    return origFetch.apply(this, arguments).then(async (resp) => {
                        const r = await handle429Response(resp);
                        // After any successful POST/PUT, nudge the parent dashboard's pill.
                        try {
                            if (r && r.ok && (method === 'POST' || method === 'PUT')) {
                                window.parent && window.parent.postMessage({ type: 'hub:action-done' }, '*');
                            }
                        } catch(_) {}
                        return r;
                    });
                };

                // ---- Daily-limit toast ----
                // When the proxy gate returns 429 with a JSON body, show a
                // sleek glass-morph toast over the page so the user gets a
                // clean, branded message instead of the host site's cryptic
                // "Failed to connect" type errors.
                let _hubToastInterval = null;
                function fmtCountdown(ms) {
                    if (ms <= 0) return 'resetting…';
                    const totalSec = Math.floor(ms / 1000);
                    const h = Math.floor(totalSec / 3600);
                    const m = Math.floor((totalSec % 3600) / 60);
                    const s = totalSec % 60;
                    const pad = (n) => n < 10 ? '0' + n : '' + n;
                    if (h > 0) return h + 'h ' + pad(m) + 'm ' + pad(s) + 's';
                    if (m > 0) return m + 'm ' + pad(s) + 's';
                    return s + 's';
                }
                function showLimitToast(detail) {
                    try {
                        const existing = document.getElementById('hub-limit-toast');
                        if (existing) existing.remove();
                        if (_hubToastInterval) { clearInterval(_hubToastInterval); _hubToastInterval = null; }

                        const wrap = document.createElement('div');
                        wrap.id = 'hub-limit-toast';
                        wrap.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;width:calc(100% - 24px);max-width:480px;box-sizing:border-box;';
                        const used = detail && detail.used != null ? detail.used : '';
                        const cap = detail && detail.daily_limit != null ? detail.daily_limit : '';
                        const resetAt = detail && detail.reset_at ? detail.reset_at : null;

                        wrap.innerHTML = '<div style="padding:14px 16px;border-radius:14px;backdrop-filter:blur(20px) saturate(180%);-webkit-backdrop-filter:blur(20px) saturate(180%);background:rgba(15,23,42,0.85);color:#fff;box-shadow:0 18px 48px rgba(0,0,0,0.45),inset 0 1px 0 rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.12);display:flex;gap:12px;align-items:flex-start;animation:hub-toast-in 0.35s cubic-bezier(.2,.9,.2,1);">' +
                            '<div style="font-size:22px;line-height:1;">⏳</div>' +
                            '<div style="flex:1;min-width:0;">' +
                                '<div style="font-weight:700;font-size:0.92rem;margin-bottom:4px;">Daily limit reached</div>' +
                                '<div style="font-size:0.78rem;color:#cbd5e1;line-height:1.45;">You have used <b>' + used + ' / ' + cap + '</b> of your daily allowance.</div>' +
                                (resetAt ? '<div style="margin-top:6px;font-size:0.78rem;color:#fef3c7;font-variant-numeric:tabular-nums;">Resets in <span id="hub-toast-countdown">…</span></div>' : '') +
                            '</div>' +
                            '<button id="hub-limit-toast-close" style="background:transparent;border:none;color:rgba(255,255,255,0.6);cursor:pointer;font-size:18px;padding:0 4px;line-height:1;">×</button>' +
                        '</div>';

                        const style = document.createElement('style');
                        style.textContent = '@keyframes hub-toast-in{from{opacity:0;transform:translate(-50%,-12px);}to{opacity:1;transform:translate(-50%,0);}}@media(max-width:480px){#hub-limit-toast{top:8px !important;}}';
                        document.head.appendChild(style);
                        document.body.appendChild(wrap);
                        document.getElementById('hub-limit-toast-close').onclick = () => {
                            wrap.remove();
                            if (_hubToastInterval) { clearInterval(_hubToastInterval); _hubToastInterval = null; }
                        };

                        if (resetAt) {
                            const cd = document.getElementById('hub-toast-countdown');
                            const tick = () => {
                                const ms = resetAt - Date.now();
                                if (ms <= 0) {
                                    if (cd) cd.textContent = 'resetting…';
                                    if (_hubToastInterval) { clearInterval(_hubToastInterval); _hubToastInterval = null; }
                                    // Soft-fade the toast after reset.
                                    setTimeout(() => { if (wrap.parentNode) wrap.remove(); }, 4000);
                                    return;
                                }
                                if (cd) cd.textContent = fmtCountdown(ms);
                            };
                            tick();
                            _hubToastInterval = setInterval(tick, 1000);
                        }
                        // No auto-dismiss when resetAt is shown — user can close manually.
                        // If no resetAt we still auto-dismiss after 12s.
                        if (!resetAt) {
                            setTimeout(() => { if (wrap.parentNode) wrap.remove(); }, 12000);
                        }
                    } catch(e) { console.warn('[Hub] toast failed:', e); }
                }

                async function handle429Response(response) {
                    if (!response || response.status !== 429) return response;
                    try {
                        const clone = response.clone();
                        const data = await clone.json();
                        if (data && data.limit_reached) {
                            showLimitToast(data);
                            // Notify the parent dashboard so it can refresh its
                            // floating usage pill.
                            try { window.parent && window.parent.postMessage({ type: 'hub:limit-toast', data }, '*'); } catch(_) {}
                        }
                    } catch(e) { /* not JSON, ignore */ }
                    return response;
                }

                // Wrap XHR responses so we can also catch 429s on classic AJAX.
                const origXhrSend = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.send = function() {
                    const xhr = this;
                    this.addEventListener('load', function() {
                        if (this.status === 429) {
                            try {
                                const data = JSON.parse(this.responseText || '{}');
                                if (data && data.limit_reached) {
                                    showLimitToast(data);
                                    try { window.parent && window.parent.postMessage({ type: 'hub:limit-toast', data }, '*'); } catch(_) {}
                                }
                            } catch(_) {}
                        } else if (this.status >= 200 && this.status < 300) {
                            const m = (xhr.__hubMethod || '').toUpperCase();
                            if (m === 'POST' || m === 'PUT') {
                                try { window.parent && window.parent.postMessage({ type: 'hub:action-done' }, '*'); } catch(_) {}
                            }
                        }
                    });
                    return origXhrSend.apply(this, arguments);
                };
                console.log('[Hub] Pathname cloaking active. Prefix:', proxyPrefix);

                // Link click interceptor: catch <a href="/path"> clicks before
                // the browser navigates and rewrite the URL to /proxy/<slug>/path.
                // Without this, sites that use bare absolute paths in their
                // anchor tags (like WriteHuman) cause recursive path growth
                // because the browser resolves "/path" against the current
                // iframe URL which is already /proxy/<slug>/.
                document.addEventListener('click', function(ev){
                    var a = ev.target && (ev.target.closest ? ev.target.closest('a') : null);
                    if (!a) return;
                    var raw = a.getAttribute('href');
                    if (!raw) return;
                    // Only rewrite bare absolute paths starting with a single '/'.
                    if (raw[0] !== '/' || raw[1] === '/') return;
                    if (raw.indexOf(proxyPrefix) === 0) return;
                    if (raw.indexOf('/cdn/') === 0 || raw.indexOf('/cdn-cgi/') === 0) return;
                    // Skip if it's already a full proxy URL.
                    var fixed = proxyPrefix + raw;
                    a.setAttribute('href', fixed);
                }, true);
            })();
            </script>
            `;

            // Inject service-specific JS + Core Masking
            if (req.serviceInjectionJS) {
                html = html.replace('</body>', `${req.serviceInjectionJS}${coreInjectionJS}</body>`);
            } else {
                html = html.replace('</body>', `${coreInjectionJS}</body>`);
            }
            res.send(html);
        } else {
            res.end(body);
        }
        } catch (e) {
            console.error('Proxy Response Processing Error:', e);
            if (!res.headersSent) {
                res.status(200).send(`<div style="color:red;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">Error loading tool interface: ${e.message}</div>`);
            } else {
                res.end();
            }
        }
    });
    } catch (globalErr) {
        console.error('Fatal ProxyRes Handler Error:', globalErr);
        if (!res.headersSent) {
            try { res.status(200).send(`<div style="color:red;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">Fatal Proxy Error: ${globalErr.message}</div>`); } catch(e) {}
        }
    }
});


// Explicit WebSocket Upgrade Handling (Critical for Mobile Progress)
server.on('upgrade', async (req, socket, head) => {
    // Ignore internal Socket.io traffic so it doesn't get destroyed or proxied
    if (req.url.startsWith('/socket.io/')) {
        return;
    }

    // Identify Service from URL Slug for WebSockets
    const realIp = req.headers['x-forwarded-for']?.split(',')[0].trim() || req.ip;
    const proxyMatch = req.url.match(/^\/proxy\/([^\/]+)(\/.*)?$/);
    const refererMatch = req.headers.referer?.match(/\/proxy\/([^\/]+)/);
    
    let serviceSlug = null;
    let targetPath = req.url;

    if (proxyMatch) {
        serviceSlug = proxyMatch[1];
        targetPath = proxyMatch[2] || '/';
    } else if (refererMatch) {
        serviceSlug = refererMatch[1];
    } else {
        serviceSlug = req.cookies?.['HUB_ACTIVE_SERVICE'] || getStickyService(realIp);
    }

    if (!serviceSlug) {
        // If we can't identify the service for a WS upgrade, we must destroy it
        return socket.destroy();
    }

    // 1. Identify Service from Slug
    let service = await get('SELECT * FROM services WHERE slug = ?', [serviceSlug]);
    
    if (!service) {
        console.warn(`[Proxy] WebSocket Upgrade FAILED: Service not found for slug: ${serviceSlug}`);
        return socket.destroy();
    }

    try {
        let safeTargetUrl = service.target_url || '';
        if (safeTargetUrl && !safeTargetUrl.startsWith('http')) {
            safeTargetUrl = 'https://' + safeTargetUrl;
        }
        let targetUrlObj;
        try {
            targetUrlObj = new URL(safeTargetUrl);
        } catch (e) {
            return socket.destroy();
        }
        
        req.url = targetPath;
        
        // Dynamic Agent for WebSockets
        const currentAgent = getProxyAgent(null); // No userId easily accessible here for WS upgrade usually

        proxy.ws(req, socket, head, { 
            target: targetUrlObj.origin, 
            changeOrigin: true, 
            agent: currentAgent,
            proxyTimeout: 15000,
            timeout: 15000
        });
    } catch(e) {
        socket.destroy();
    }
});


// --- AUTOMATED BACKGROUND SYNC ---
// Runs every 10 minutes to keep Hub Admin in sync with aMember Pro
async function autoSyncAmember() {
    if (process.env.AMEMBER_ENABLE !== 'true') return;
    
    console.log('[Background Sync] Starting scheduled sync with aMember...');
    try {
        const { syncAmemberUsers } = require('./amember');
        const amUsers = await syncAmemberUsers();
        if (!amUsers || amUsers.length === 0) return;

        // 1. Update/Add Users
        const allServices = await query('SELECT id, slug, amember_product_id FROM services');
        
        for (const amUser of amUsers) {
            const email = amUser.email;
            if (!email) continue;
            let hubUser = await get('SELECT id FROM users WHERE email = ?', [email]);

            if (!hubUser) {
                const username = amUser.login || amUser.name_f || `user_${amUser.user_id}`;
                const result = await run(
                    'INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)',
                    [username, email, amUser.pass, 'user', 'active']
                );
                hubUser = { id: result.lastID };
            } else {
                await run('UPDATE users SET password_hash = ? WHERE id = ?', [amUser.pass, hubUser.id]);
            }

            // --- PRODUCT-BASED ASSIGNMENT ---
            const userProducts = (amUser.product_ids || '').split(',').map(id => id.trim());

            for (const service of allServices) {
                if (hubUser.id) {
                    const mappedProductId = String(service.amember_product_id || '').trim();
                    const hasAccessInAmember = mappedProductId ? userProducts.includes(mappedProductId) : false;

                    if (hasAccessInAmember) {
                        const existingAssignment = await get('SELECT id FROM user_assignments WHERE user_id = ? AND service_id = ?', [hubUser.id, service.id]);
                        if (!existingAssignment) {
                            const cookie = await pickLeastLoadedCookie(service.id);
                            await run('INSERT OR IGNORE INTO user_assignments (user_id, service_id, cookie_id) VALUES (?, ?, ?)', [hubUser.id, service.id, cookie ? cookie.id : null]);
                        }
                    } else {
                        await run('DELETE FROM user_assignments WHERE user_id = ? AND service_id = ?', [hubUser.id, service.id]);
                    }
                }
            }
        }

        // 2. Purge Deleted Users
        const amEmails = amUsers.map(u => u.email).filter(Boolean);
        const amLogins = amUsers.map(u => u.login).filter(Boolean);
        const hubUsers = await query('SELECT id, email, username FROM users WHERE role != "admin"');
        
        for (const hubUser of hubUsers) {
            const exists = amEmails.includes(hubUser.email) || amLogins.includes(hubUser.username);
            if (!exists) {
                await run('DELETE FROM users WHERE id = ?', [hubUser.id]);
            }
        }
        
        console.log('[Background Sync] Complete.');
        // Notify admin panels in real-time to refresh their data
        io.to('admins').emit('force_refresh');
    } catch (e) {
        console.error('[Background Sync] Failed:', e.message);
    }
}

// Start background sync: Initial run after 10s, then every 60 seconds for near-real-time
setTimeout(autoSyncAmember, 10000);
setInterval(autoSyncAmember, 60 * 1000);

// --- STEALTH HEADER SPOOFING ---
proxy.on('proxyReq', (proxyReq, req, res, options) => {
    if (req.targetUrl) {
        try {
            const targetUrlObj = new URL(req.targetUrl);
            // Overwrite Host header to match target (Critical for Cloudflare/ChatGPT)
            proxyReq.setHeader('Host', targetUrlObj.host);
            
            // Spoof Modern Chrome on Windows 10
            proxyReq.setHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
            
            // ONLY spoof navigation headers if it's a document request, otherwise API requests will fail with Protocol Errors
            const isDoc = req.headers['sec-fetch-dest'] === 'document' || req.headers['accept']?.includes('text/html');
            if (isDoc) {
                proxyReq.setHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8');
                proxyReq.setHeader('Sec-Fetch-Dest', 'document');
                proxyReq.setHeader('Sec-Fetch-Mode', 'navigate');
                proxyReq.setHeader('Sec-Fetch-Site', 'none');
                proxyReq.setHeader('Sec-Fetch-User', '?1');
                proxyReq.setHeader('Upgrade-Insecure-Requests', '1');
            } else {
                // For API/Asset requests, forward the original headers if present
                if (req.headers['accept']) proxyReq.setHeader('Accept', req.headers['accept']);
            }

            console.log(`[Proxy Outbound] ${req.method} -> ${req.targetUrl}`);
        } catch(e) {}
    }
});

// Error Handling & Graceful Shutdown
process.on('unhandledRejection', (reason, promise) => {
    console.error('Unhandled Rejection at:', promise, 'reason:', reason);
});

proxy.on('error', (err, req, res) => {
    console.error('Proxy Engine Error:', err.message);
    
    // For WebSocket requests, res is actually a Socket object.
    // Socket objects do not have writeHead or headersSent.
    if (res && res.writeHead) {
        if (!res.headersSent) {
            res.writeHead(200, { 'Content-Type': 'text/html' });
            res.end(`<div style="color:red;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">
                Reverse Proxy Error: Could not reach the target server.<br>
                <small style="font-size:1rem;color:gray;">Error: ${err.message}</small>
            </div>`);
        }
    } else if (res && typeof res.destroy === 'function') {
        // It's a raw Socket
        res.destroy();
    }
});

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log('\n================================================');
    console.log('      WRITING TOOLS HUB (M-V-C ARCHITECTURE)     ');
    console.log('================================================');
    console.log('Hub URL:  http://localhost:' + PORT);
    console.log('Admin:    http://localhost:' + PORT + '/admin');
    console.log('Database: Active');
    console.log('Sockets:  Active');
    console.log('================================================\n');
    
    autoSyncAmember();
});
