require('dotenv').config();
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const httpProxy = require('http-proxy');
const cookieParser = require('cookie-parser');
const path = require('path');
const jwt = require('jsonwebtoken');
const { HttpsProxyAgent } = require('https-proxy-agent');

// MVC Modules
const { get, db } = require('./database');
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
const proxyUrls = (process.env.PROXY_LIST || '').split(',').map(u => u.trim()).filter(u => u);
const proxyAgents = proxyUrls.map(url => new HttpsProxyAgent(url));
let globalProxyCounter = 0;

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
    // Redirect /hub to root to ensure landing page visibility
    if (req.url === '/hub' || req.url === '/hub/') {
        return res.redirect('/');
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

    // --- 1. Identify Service (Priority Match) ---
    const proxyMatch = req.url.match(/^\/proxy\/([^\/]+)(\/.*)?$/);
    const refererMatch = req.headers.referer?.match(/\/proxy\/([^\/]+)/);
    const sessionMatch = req.cookies?.stealth_proxy_last_slug;

    // Trailing Slash Normalization: /proxy/chatgpt -> /proxy/chatgpt/
    if (proxyMatch && !proxyMatch[2]) {
        return res.redirect(req.url + '/');
    }

    let serviceSlug = null;
    let targetPath = '/';

    if (proxyMatch) {
        serviceSlug = proxyMatch[1];
        targetPath = proxyMatch[2] || '/';
        // Stickiness: Remember this tool for assets
        res.cookie('stealth_proxy_last_slug', serviceSlug, { maxAge: 300000, path: '/' }); // 5 min stickiness
    } else if (refererMatch) {
        serviceSlug = refererMatch[1];
        targetPath = req.url;
    } else if (sessionMatch) {
        // Fallback for orphaned assets
        serviceSlug = sessionMatch;
        targetPath = req.url;
    }


    if (!serviceSlug) {
        // If it's not a Hub route AND not a Proxy route, it's a 404.
        // If the URL has an extension, return a real 404 (don't serve Hub HTML)
        if (req.url.includes('.') || req.url.startsWith('/cdn/')) {
            return res.status(404).send('Not Found');
        }
        return next(); 
    }


    // 2. Auth & Permission Check
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).send('<div style="color:black;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">Please Login via the Hub.</div>');

    try {
        const verified = jwt.verify(token, JWT_SECRET);
        
        // Fetch Service & Assignment
        const service = await get('SELECT * FROM services WHERE slug = ?', [serviceSlug]);
        if (!service) return res.status(404).send('Service not found.');

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

        // Prepare Proxy Request
        req.userCookieData = assignment.cookie_data;
        req.serviceInjectionJS = service.injection_js;
        req.targetUrl = service.target_url;
        
        // 3. Prepare Proxy Headers (Common for all requests)
        const sanitizedCookie = (assignment.cookie_data || '')
                                  .replace(/[\r\n]/gm, '')
                                  .replace(/[^\x20-\x7E]/g, '')
                                  .trim();
        
        req.headers['cookie'] = sanitizedCookie;
        req.headers['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        
        const targetUrlObj = new URL(service.target_url);
        req.headers['origin'] = targetUrlObj.origin;
        req.headers['host'] = targetUrlObj.host;
        req.url = targetPath;
        req.headers['referer'] = targetUrlObj.origin + targetPath;

        // Strip out security headers that leak the proxy domain
        delete req.headers['sec-fetch-dest'];
        delete req.headers['sec-fetch-mode'];
        delete req.headers['sec-fetch-site'];

        const isHtmlRequest = req.headers['accept']?.includes('text/html') || 
                              req.headers['accept']?.includes('application/xhtml+xml') ||
                              !targetPath.includes('.') || 
                              targetPath.endsWith('/');
        
        const currentAgent = getProxyAgent(verified.id);

        if (isHtmlRequest) {
            req.shouldBufferResponse = true; // Tag for proxyRes handler
            delete req.headers['accept-encoding']; // Strip only for HTML to allow injection
            proxy.web(req, res, { target: targetUrlObj.origin, selfHandleResponse: true, agent: currentAgent });
        } else {
            req.shouldBufferResponse = true; // We now buffer almost everything to strip CSP properly
            delete req.headers['accept-encoding']; 
            proxy.web(req, res, { target: targetUrlObj.origin, selfHandleResponse: true, agent: currentAgent });
        }


    } catch(e) {
        console.error('Proxy Middleware Error:', e);
        return res.status(500).send('<div style="color:black;text-align:center;margin-top:20%;font-size:2rem;font-family:sans-serif;">Internal Server Error.</div>');
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
    // ONLY handle response if we explicitly requested buffering (selfHandleResponse: true)
    // AND the headers haven't already been flushed.
    if (!req.shouldBufferResponse || res.headersSent) return;

    let body = [];


    proxyRes.on('data', chunk => body.push(chunk));
    proxyRes.on('end', () => {
        body = Buffer.concat(body);
        
        // Remove restrictive security headers that cause 'Partial Loading' or Blocks
        const headersToRemove = [
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

        Object.keys(proxyRes.headers).forEach(key => {
            const lowKey = key.toLowerCase();
            if (lowKey === 'set-cookie' || lowKey === 'cache-control' || lowKey === 'etag' || lowKey === 'content-length') {
                return; // Handled separately or preserved
            }
            if (headersToRemove.includes(lowKey)) {
                return; // Skip these headers
            }
            
            let val = proxyRes.headers[key];
            
            // Rewrite Redirects
            if (lowKey === 'location') {
                const slugMatch = req.originalUrl.match(/\/proxy\/([^\/]+)/);
                if (slugMatch) {
                    const slug = slugMatch[1];
                    if (val.startsWith('/')) {
                        val = `/proxy/${slug}${val}`;
                    } else if (val.startsWith('http')) {
                        try {
                            const locUrl = new URL(val);
                            const targetUrlObj = new URL(req.targetUrl);
                            // Relaxed host matching to handle subdomains (e.g. app.writehuman.ai)
                            if (locUrl.host === targetUrlObj.host || locUrl.host.endsWith('.' + targetUrlObj.host.replace('www.', ''))) {
                                val = `/proxy/${slug}${locUrl.pathname}${locUrl.search}${locUrl.hash}`;
                            }
                        } catch(e) {}
                    }
                }
            }
            res.setHeader(key, val);
        });

        res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');


        // Session Sync (Mobile-Optimized)
        if (req.userCookieData) {
            const cookieArray = req.userCookieData.split(';').map(c => c.trim());
            cookieArray.forEach(c => {
                res.append('Set-Cookie', `${c}; Path=/; SameSite=Lax`);
            });
        }


        const contentType = proxyRes.headers['content-type'] || '';
        delete res.removeHeader('X-Frame-Options');
        delete res.removeHeader('Content-Security-Policy');

        if (contentType.includes('text/html')) {
            let html = body.toString();
            
            // --- 4. Dynamic Link Rewriting ---
            // Rewrite absolute URLs for known complex domains to go through our proxy-static
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
                'ggpht.com',
                'writehuman.ai'
            ];

            domainsToProxy.forEach(domain => {
                // Catches https://, http://, and //
                const regex = new RegExp(`(https?:)?//([a-zA-Z0-9.-]+\\.)?${domain.replace('.', '\\.')}`, 'g');
                html = html.replace(regex, (match) => {
                    const cleanMatch = match.replace(/^(https?:)?\/\//, '');
                    return `/proxy-static/${cleanMatch}`;
                });
            });


            // Inject service-specific JS if available
            if (req.serviceInjectionJS) {
                html = html.replace('</body>', `${req.serviceInjectionJS}</body>`);
            }
            res.send(html);
        } else {
            res.end(body);
        }

    });
});


// Explicit WebSocket Upgrade Handling (Critical for Mobile Progress)
server.on('upgrade', async (req, socket, head) => {
    // Identify Service from URL Slug for WebSockets
    const proxyMatch = req.url.match(/^\/proxy\/([^\/]+)(\/.*)?$/);
    if (!proxyMatch) return socket.destroy();

    const serviceSlug = proxyMatch[1];
    const targetPath = proxyMatch[2] || '/';

    try {
        const service = await get('SELECT target_url FROM services WHERE slug = ?', [serviceSlug]);
        if (!service) return socket.destroy();

        const targetUrlObj = new URL(service.target_url);
        req.url = targetPath;
        
        // Dynamic Agent for WebSockets
        const currentAgent = getProxyAgent(null); // No userId easily accessible here for WS upgrade usually

        proxy.ws(req, socket, head, { 
            target: targetUrlObj.origin, 
            changeOrigin: true, 
            agent: currentAgent 
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
                    const hasAccessInAmember = mappedProductId ? userProducts.includes(mappedProductId) : true;

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
    } catch (e) {
        console.error('[Background Sync] Failed:', e.message);
    }
}

// Start background sync: Initial run after 10s, then every 10 minutes
setTimeout(autoSyncAmember, 10000);
setInterval(autoSyncAmember, 10 * 60 * 1000);

// Error Handling & Graceful Shutdown
process.on('unhandledRejection', (reason, promise) => {
    console.error('Unhandled Rejection at:', promise, 'reason:', reason);
});

proxy.on('error', (err, req, res) => {
    console.error('Proxy Engine Error:', err);
    if (!res.headersSent) {
        res.writeHead(502);
        res.end('Reverse Proxy Error: Could not reach target.');
    }
});

// --- AUTOMATIC AMEMBER SYNC ON STARTUP ---
async function autoSyncAmember() {
    if (process.env.AMEMBER_ENABLE === 'true') {
        console.log('[aMember Sync] Starting automatic startup sync...');
        try {
            // Internal call to the sync logic (reusing logic from admin router would be complex, 
            // so we'll just log that it's recommended to hit the sync button or wait for first login)
            // Actually, let's just trigger a small delay then run it if database is ready
            setTimeout(async () => {
                try {
                    const { syncAmemberUsers } = require('./amember');
                    const { get, run } = require('./database');
                    const amUsers = await syncAmemberUsers();
                    if (amUsers && amUsers.length > 0) {
                    const allServices = await query('SELECT id, amember_product_id FROM services');
                    let created = 0, existing = 0;
                    for (const amUser of amUsers) {
                        const email = amUser.email;
                        if (!email) continue;
                        let hubUser = await get('SELECT id FROM users WHERE email = ?', [email]);
                        if (!hubUser) {
                            const result = await run(
                                'INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)',
                                [amUser.login || amUser.name_f || `user_${amUser.user_id}`, email, amUser.pass, 'user', 'active']
                            );
                            hubUser = { id: result.lastID };
                            created++;
                        } else {
                            await run('UPDATE users SET password_hash = ? WHERE id = ?', [amUser.pass, hubUser.id]);
                            existing++;
                        }
                        
                        // Product-based assignment
                        const userProducts = (amUser.product_ids || '').split(',').map(id => id.trim());
                        for (const service of allServices) {
                            const mappedProductId = String(service.amember_product_id || '').trim();
                            const hasAccessInAmember = mappedProductId ? userProducts.includes(mappedProductId) : true;
                            
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
                        console.log(`[aMember Sync] Startup sync complete: ${created} created, ${existing} updated.`);
                    }
                } catch (e) {
                    console.error('[aMember Sync] Startup sync failed:', e.message);
                }
            }, 5000); // 5 second delay to ensure DB is fully initialized
        } catch (e) {
            console.error('[aMember Sync] Startup sync error:', e);
        }
    }
}

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
