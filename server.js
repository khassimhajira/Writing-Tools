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
const { get, run, query, db } = require('./database');
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
// Initialize Proxy Agents with Safety Catch
const proxyUrls = (process.env.PROXY_LIST || '').split(',').map(u => u.trim()).filter(u => u && u.length > 5);
const proxyAgents = [];
proxyUrls.forEach(url => {
    try {
        proxyAgents.push(new HttpsProxyAgent(url));
    } catch(e) {
        console.error(`[Init] Skipping invalid proxy URL: ${url}`);
    }
});
console.log(`[Init] ${proxyAgents.length} Proxy Agents ready.`);
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
        const proxyUrls = (process.env.PROXY_LIST || '').split(',').map(u => u.trim()).filter(u => u && u.length > 5);
        
        results.push(`<h1>Proxy Diagnostic Tool</h1>`);
        results.push(`<p>Testing ${proxyUrls.length} proxies from .env...</p>`);
        results.push(`<hr>`);

        for (let i = 0; i < proxyUrls.length; i++) {
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
        return res.status(200).json({});
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
            SELECT a.cookie_id, u.status, c.data as cookie_data 
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
                forwardHeaders['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
                forwardHeaders['Accept-Language'] = 'en-US,en;q=0.9';
                forwardHeaders['Sec-Ch-Ua'] = '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"';
                forwardHeaders['Sec-Ch-Ua-Mobile'] = '?0';
                forwardHeaders['Sec-Ch-Ua-Platform'] = '"Windows"';

                const axiosConfig = {
                    method: req.method,
                    url: `${targetUrlObj.origin}${req.url}`,
                    headers: forwardHeaders,
                    data: req, // Pipe the raw request stream directly (Essential for POST/PUT)
                    httpsAgent: currentAgent || undefined,
                    timeout: 25000,
                    maxContentLength: Infinity,
                    maxBodyLength: Infinity,
                    validateStatus: () => true,
                    responseType: 'arraybuffer',
                    decompress: true // Ensure we get decompressed data for patching
                };

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
                            const realDefaultView = document.defaultView;
                            Object.defineProperty(document, 'defaultView', {
                                get: () => new Proxy(realDefaultView, {
                                    get: (target, prop) => {
                                        if (prop === 'location') return hubLocation;
                                        let val = target[prop];
                                        return typeof val === 'function' ? val.bind(target) : val;
                                    }
                                }),
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

                    // 2. JS Redirection in HTML
                    html = html.replace(/window\.location/g, 'globalThis.__HL__');
                    html = html.replace(/document\.location/g, 'globalThis.__HL__');

                    // 3. Domain Rewriting
                    const ownDomainRegex = new RegExp(`(https?:)?//([a-zA-Z0-9.-]*\\.)?${targetDomain.replace(/\./g, '\\.')}`, 'g');
                    html = html.replace(ownDomainRegex, `/proxy/${service.slug}`);

                    processedBuffer = Buffer.from(html);
                } 
                else if (contentType.includes('javascript') || req.url.includes('.js') || (contentType.includes('octet-stream') && req.url.includes('.js'))) {
                    let js = bodyBuffer.toString('utf8');
                    
                    // MINIMAL SURGERY: Only replace the two unambiguous browser location accessors.
                    js = js.replace(/window\.location/g, 'globalThis.__HL__');
                    js = js.replace(/document\.location/g, 'globalThis.__HL__');
                    
                    // Domain Rewriting in JS (CRITICAL: catches hardcoded absolute API URLs)
                    const targetDomain = targetUrlObj.host;
                    const ownDomainRegex = new RegExp(`(https?:)?//([a-zA-Z0-9.-]*\\.)?${targetDomain.replace(/\./g, '\\.')}`, 'g');
                    js = js.replace(ownDomainRegex, `/proxy/${service.slug}`);
                    
                    // Force relative paths for dynamic imports
                    const cleanBase = proxyBase.endsWith('/') ? proxyBase.slice(0, -1) : proxyBase;
                    js = js.replace(/from\s*["'](\/cdn\/[^"']+)["']/g, `from "${cleanBase}$1"`);
                    js = js.replace(/import\s*\(["'](\/cdn\/[^"']+)["']\)/g, `import("${cleanBase}$1")`);
                    
                    // Force Cache Busting
                    res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');
                    res.setHeader('Pragma', 'no-cache');
                    res.setHeader('Expires', '0');
                    
                    processedBuffer = Buffer.from(js);
                }

                // --- SEND RESPONSE ---
                Object.keys(response.headers).forEach(key => {
                    const lowKey = key.toLowerCase();
                    if (!['content-length', 'content-security-policy', 'x-frame-options', 'transfer-encoding'].includes(lowKey)) {
                        res.setHeader(key, response.headers[key]);
                    }
                });

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

            res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');


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
                    return origFetch.apply(this, arguments);
                };

                console.log('[Hub] Pathname cloaking active. Prefix:', proxyPrefix);
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
                            const cookie = await get('SELECT id FROM cookies WHERE service_id = ? LIMIT 1', [service.id]);
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
            proxyReq.setHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8');
            proxyReq.setHeader('Accept-Language', 'en-US,en;q=0.9');
            proxyReq.setHeader('Sec-Ch-Ua', '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"');
            proxyReq.setHeader('Sec-Ch-Ua-Mobile', '?0');
            proxyReq.setHeader('Sec-Ch-Ua-Platform', '"Windows"');
            proxyReq.setHeader('Sec-Fetch-Dest', 'document');
            proxyReq.setHeader('Sec-Fetch-Mode', 'navigate');
            proxyReq.setHeader('Sec-Fetch-Site', 'none');
            proxyReq.setHeader('Sec-Fetch-User', '?1');
            proxyReq.setHeader('Upgrade-Insecure-Requests', '1');

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
