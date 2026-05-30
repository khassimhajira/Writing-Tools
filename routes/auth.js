require('dotenv').config();
// Belt-and-braces override. Under Hostinger Passenger, the parent process
// can pre-seed env vars (e.g. JWT_SECRET from an old hpanel config) which
// silently win over our .env file. We explicitly read .env and OVERWRITE
// any matching keys in process.env so the file is the source of truth.
try {
    const fs = require('fs');
    const envPath = require('path').join(__dirname, '..', '.env');
    const raw = fs.readFileSync(envPath, 'utf8');
    raw.split(/\r?\n/).forEach(line => {
        const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/i);
        if (!m) return;
        let v = m[2];
        if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
            v = v.slice(1, -1);
        }
        // OVERRIDE — file wins over inherited env. Only touches keys that
        // appear in the file, so unrelated process env stays untouched.
        process.env[m[1]] = v;
    });
} catch (e) {
    console.error('[auth.js] manual .env load failed:', e.message);
}
const express = require('express');
const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const { get, run, pickLeastLoadedCookie } = require('../database');
const { checkAmemberAuth } = require('../amember');

const router = express.Router();

// JWT_SECRET MUST be set in env. Without it, anyone with the public source
// can mint admin tokens. We refuse to operate with a weak fallback.
const JWT_SECRET = process.env.JWT_SECRET;
if (!JWT_SECRET || JWT_SECRET.length < 32) {
    // Server.js also checks this and refuses to boot, but auth-side guard
    // catches a hot-reload of just this file.
    console.error('FATAL: JWT_SECRET is missing or too short (<32 chars). Refusing to operate.');
    throw new Error('JWT_SECRET unset or too weak.');
}
const JWT_EXPIRY = process.env.JWT_EXPIRY || '7d';

// Cloudflare Turnstile (optional). Set TURNSTILE_SITE_KEY and
// TURNSTILE_SECRET_KEY in env to enable the captcha on /login. If unset,
// the check is silently skipped so existing setups still work.
const TURNSTILE_SECRET = process.env.TURNSTILE_SECRET_KEY || '';
const TURNSTILE_SITE_KEY = process.env.TURNSTILE_SITE_KEY || '';

// --- helpers ---
function getClientIp(req) {
    const xff = (req.headers['x-forwarded-for'] || '').split(',')[0].trim();
    return xff || req.ip || (req.socket && req.socket.remoteAddress) || '';
}

// Tiny user-agent parser. Returns { device, browser } in the same vibe as
// the modal mockup (e.g. "Windows (Chrome)", "iPhone (Safari)"). No dep.
function parseUA(uaRaw) {
    const ua = String(uaRaw || '');
    let device = 'Unknown';
    if (/iPhone/i.test(ua))            device = 'iPhone';
    else if (/iPad/i.test(ua))         device = 'iPad';
    else if (/Android/i.test(ua) && /Mobile/i.test(ua)) device = 'Android Phone';
    else if (/Android/i.test(ua))      device = 'Android Tablet';
    else if (/Windows/i.test(ua))      device = 'Windows PC';
    else if (/Mac OS X/i.test(ua))     device = 'Mac';
    else if (/Linux/i.test(ua))        device = 'Linux';

    let browser = 'Unknown';
    if (/Edg\//i.test(ua))             browser = 'Edge';
    else if (/OPR\//i.test(ua))        browser = 'Opera';
    else if (/Firefox/i.test(ua))      browser = 'Firefox';
    else if (/Chrome/i.test(ua))       browser = 'Chrome';
    else if (/Safari/i.test(ua))       browser = 'Safari';

    return { device, browser };
}

// Resolve country from IP using ipwho.is (free, no key, no signup).
// Cached in-process for 1h to keep things cheap.
const ipCache = new Map(); // ip -> { ts, country, country_code }
async function lookupCountry(ip) {
    if (!ip) return { country: 'Unknown Location', country_code: '' };
    // Skip private IPs (localhost, 127.x, 10.x, 192.168.x, etc.)
    if (/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|::1$|fc00:|fd00:|fe80:)/.test(ip)) {
        return { country: 'Local Network', country_code: '' };
    }
    const now = Date.now();
    const cached = ipCache.get(ip);
    if (cached && (now - cached.ts) < 3600 * 1000) {
        return { country: cached.country, country_code: cached.country_code };
    }
    try {
        const r = await fetch('https://ipwho.is/' + encodeURIComponent(ip), { signal: AbortSignal.timeout(4000) });
        if (!r.ok) throw new Error('http ' + r.status);
        const j = await r.json();
        const out = {
            country: j && j.success && j.country ? j.country : 'Unknown Location',
            country_code: j && j.country_code ? String(j.country_code).toLowerCase() : ''
        };
        ipCache.set(ip, { ts: now, ...out });
        return out;
    } catch (e) {
        return { country: 'Unknown Location', country_code: '' };
    }
}

// Verify a Cloudflare Turnstile token. Returns true if valid OR if
// Turnstile is disabled (no secret in env).
async function verifyTurnstile(token, ip) {
    if (!TURNSTILE_SECRET) return true; // disabled
    if (!token) return false;
    try {
        const body = new URLSearchParams();
        body.set('secret', TURNSTILE_SECRET);
        body.set('response', token);
        if (ip) body.set('remoteip', ip);
        const r = await fetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
            method: 'POST', body, signal: AbortSignal.timeout(6000)
        });
        if (!r.ok) return false;
        const j = await r.json();
        return j && j.success === true;
    } catch (e) { return false; }
}

// Public config endpoint so the login page can read whether Turnstile is on
// and which site key to render with.
router.get('/config', (req, res) => {
    res.json({
        turnstile_enabled: !!TURNSTILE_SECRET,
        turnstile_site_key: TURNSTILE_SITE_KEY || null
    });
});

// Snapshot the currently-active session for a user (for the takeover modal).
async function snapshotSession(userId, sessionId, req) {
    const ip = getClientIp(req);
    const ua = req.headers['user-agent'] || '';
    const { device, browser } = parseUA(ua);
    const { country, country_code } = await lookupCountry(ip);
    const now = Date.now();
    await run(
        `INSERT INTO user_sessions (user_id, session_id, ip, country, country_code, device, browser, user_agent, last_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(user_id) DO UPDATE SET
            session_id = excluded.session_id,
            ip = excluded.ip,
            country = excluded.country,
            country_code = excluded.country_code,
            device = excluded.device,
            browser = excluded.browser,
            user_agent = excluded.user_agent,
            last_active = excluded.last_active`,
        [userId, sessionId, ip, country, country_code, device, browser, ua, now]
    );
}

async function getSnapshot(userId) {
    return await get('SELECT * FROM user_sessions WHERE user_id = ?', [userId]);
}

// Issue a fresh JWT + cookie + DB session_id. This is the only place we
// hand out a session — keeps the takeover behavior consistent.
async function issueSession(req, res, user) {
    const sessionId = crypto.randomBytes(24).toString('hex');
    await run('UPDATE users SET session_id = ? WHERE id = ?', [sessionId, user.id]);
    await snapshotSession(user.id, sessionId, req);
    const token = jwt.sign(
        { id: user.id, role: user.role, status: user.status, sid: sessionId },
        JWT_SECRET,
        { expiresIn: JWT_EXPIRY }
    );
    res.cookie('stealth_hub_token', token, {
        httpOnly: true,
        sameSite: 'Lax',
        secure: process.env.NODE_ENV === 'production',
        path: '/',
        maxAge: 7 * 24 * 60 * 60 * 1000
    });
    return sessionId;
}

// Login
router.post('/login', async (req, res) => {
    const { email, password, confirm_takeover, turnstile_token } = req.body || {};

    // Captcha first (skipped if Turnstile is disabled in env).
    const captchaOk = await verifyTurnstile(turnstile_token, getClientIp(req));
    if (!captchaOk) {
        return res.status(400).json({ error: 'Captcha verification failed. Please reload and try again.' });
    }

    try {
        // 1. Try Local Hub Database first (Admins & Manually added users)
        let user = await get('SELECT * FROM users WHERE email = ? OR username = ?', [email, email]);

        let validPassword = false;
        if (user && user.password_hash) {
            validPassword = await bcrypt.compare(password, user.password_hash);
        }

        // 2. If not found locally or password wrong, try aMember Bridge
        if (!user || !validPassword) {
            const amUser = await checkAmemberAuth(email, password);

            if (amUser && amUser.error) {
                return res.status(403).json({ error: amUser.error });
            }

            if (amUser) {
                if (!user) {
                    const result = await run('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)',
                        [amUser.username, amUser.email, amUser.password_hash, 'user']);
                    user = await get('SELECT * FROM users WHERE id = ?', [result.lastID]);
                } else {
                    await run('UPDATE users SET password_hash = ? WHERE id = ?', [amUser.password_hash, user.id]);
                    user.password_hash = amUser.password_hash;
                }

                // --- AUTO-ASSIGNMENT LOGIC ---
                const service = await get("SELECT id FROM services WHERE slug = 'stealth'");
                if (service) {
                    const existingAssignment = await get('SELECT id, cookie_id FROM user_assignments WHERE user_id = ? AND service_id = ?', [user.id, service.id]);
                    if (!existingAssignment || !existingAssignment.cookie_id) {
                        const cookie = await pickLeastLoadedCookie(service.id);
                        if (cookie) {
                            await run(`INSERT INTO user_assignments (user_id, service_id, cookie_id)
                                       VALUES (?, ?, ?)
                                       ON CONFLICT(user_id, service_id) DO UPDATE SET cookie_id = EXCLUDED.cookie_id`,
                                       [user.id, service.id, cookie.id]);
                        } else {
                            await run('INSERT OR IGNORE INTO user_assignments (user_id, service_id) VALUES (?, ?)', [user.id, service.id]);
                        }
                    }
                }
                validPassword = true;
            }
        }

        if (!user || !validPassword) {
            return res.status(400).json({ error: 'Invalid credentials' });
        }

        if (user.status === 'blocked') {
            return res.status(403).json({ error: 'Your account has been suspended. Please contact the administrator.' });
        }

        // --- SINGLE-SESSION ENFORCEMENT ---
        // If a session already exists and the client hasn't confirmed takeover,
        // surface the existing session metadata so the modal can render.
        if (user.session_id && !confirm_takeover) {
            const snap = await getSnapshot(user.id);
            // Build "this device" context for the modal.
            const ip = getClientIp(req);
            const ua = req.headers['user-agent'] || '';
            const { device: thisDevice, browser: thisBrowser } = parseUA(ua);
            const { country: thisCountry, country_code: thisCC } = await lookupCountry(ip);
            return res.status(409).json({
                code: 'SESSION_EXISTS',
                message: 'An active session already exists. Continue to log this device in and end the existing session.',
                existing: snap ? {
                    device: snap.device || 'Unknown',
                    browser: snap.browser || '',
                    ip: snap.ip || '',
                    country: snap.country || 'Unknown Location',
                    country_code: snap.country_code || '',
                    last_active: snap.last_active || null
                } : null,
                this_device: {
                    device: thisDevice,
                    browser: thisBrowser,
                    ip,
                    country: thisCountry,
                    country_code: thisCC
                }
            });
        }

        // Mint a new session (kicks any previous one).
        await issueSession(req, res, user);
        res.json({ message: 'Logged in', user: { id: user.id, username: user.username, role: user.role } });
    } catch(e) {
        console.error('Login Error:', e);
        res.status(500).json({ error: 'Authentication error' });
    }
});

// Logout
router.get('/logout', async (req, res) => {
    // Best-effort: invalidate this user's DB session_id so any other tab
    // running the same token also gets kicked.
    try {
        const token = req.cookies.stealth_hub_token;
        if (token) {
            const verified = jwt.verify(token, JWT_SECRET);
            if (verified && verified.id) {
                await run('UPDATE users SET session_id = NULL WHERE id = ?', [verified.id]);
                await run('DELETE FROM user_sessions WHERE user_id = ?', [verified.id]);
            }
        }
    } catch (_) {}
    res.clearCookie('stealth_hub_token');
    res.redirect('https://app.scholargenie.org/login/logout');
});

// Verify a JWT AND that its session_id still matches the DB. Returns
// the user row on success, throws on failure (caller handles the response).
async function verifyAndCheckSession(token) {
    const verified = jwt.verify(token, JWT_SECRET);
    const user = await get('SELECT id, username, email, role, status, session_id FROM users WHERE id = ?', [verified.id]);
    if (!user) { const e = new Error('User not found'); e.code = 'NO_USER'; throw e; }
    if (user.status === 'blocked') { const e = new Error('Account suspended'); e.code = 'BLOCKED'; throw e; }
    // Session enforcement: if the user has a session_id in DB and it doesn't
    // match the JWT, this token belongs to a kicked device. Reject.
    if (user.session_id && verified.sid && user.session_id !== verified.sid) {
        const e = new Error('Session ended on another device.');
        e.code = 'KICKED';
        throw e;
    }
    // Tokens issued before the session_id field was added (no `sid` claim)
    // are tolerated for backward compat — they'll be replaced on the user's
    // next login. New issuances always carry sid.
    return { verified, user };
}

// Me (Get current session)
router.get('/me', async (req, res) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Not authenticated' });
    try {
        const { user } = await verifyAndCheckSession(token);
        res.json({ id: user.id, username: user.username, email: user.email, role: user.role, status: user.status });
    } catch(e) {
        if (e.code === 'KICKED') return res.status(401).json({ error: 'Session ended on another device.', code: 'KICKED' });
        if (e.code === 'BLOCKED') return res.status(403).json({ error: 'Account suspended' });
        res.status(401).json({ error: 'Invalid token' });
    }
});

// Get User's Services (for Dashboard)
router.get('/services', async (req, res) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Not authenticated' });

    try {
        const { user: tokenUser } = await verifyAndCheckSession(token);
        const verified = { id: tokenUser.id };
        const { query, get, run, pickLeastLoadedCookie } = require('../database');
        const { getAmemberUserProducts } = require('../amember');

        // --- SILENT SYNC FOR THIS USER ---
        const user = await get('SELECT email, username FROM users WHERE id = ?', [verified.id]);
        if (user) {
            const amProducts = await getAmemberUserProducts(user.email || user.username);
            const allServices = await query('SELECT id, amember_product_id FROM services');

            for (const service of allServices) {
                const mappedProductId = String(service.amember_product_id || '').trim();
                const hasAccess = mappedProductId ? amProducts.includes(mappedProductId) : false;

                if (hasAccess) {
                    const existing = await get('SELECT id FROM user_assignments WHERE user_id = ? AND service_id = ?', [verified.id, service.id]);
                    if (!existing) {
                        const cookie = await pickLeastLoadedCookie(service.id);
                        await run('INSERT OR IGNORE INTO user_assignments (user_id, service_id, cookie_id) VALUES (?, ?, ?)', [verified.id, service.id, cookie ? cookie.id : null]);
                    }
                } else {
                    await run('DELETE FROM user_assignments WHERE user_id = ? AND service_id = ?', [verified.id, service.id]);
                }
            }
        }

        const services = await query(`
            SELECT s.id, s.name, s.slug, s.icon_svg, s.text_svg,
                   COALESCE(a.daily_limit_override, s.daily_limit) AS daily_limit
            FROM user_assignments a
            JOIN services s ON a.service_id = s.id
            WHERE a.user_id = ?
        `, [verified.id]);

        const since = Date.now() - 24 * 60 * 60 * 1000;
        for (const svc of services) {
            const usageRow = await get(
                'SELECT COUNT(*) AS used, MIN(used_at) AS oldest FROM service_usage WHERE user_id = ? AND service_id = ? AND used_at >= ?',
                [verified.id, svc.id, since]
            );
            svc.used_today = (usageRow && usageRow.used) || 0;
            if (svc.daily_limit && usageRow && usageRow.oldest) {
                svc.reset_at = usageRow.oldest + 24 * 60 * 60 * 1000;
                svc.reset_in_minutes = Math.max(0, Math.ceil((svc.reset_at - Date.now()) / 60000));
            } else {
                svc.reset_at = null;
                svc.reset_in_minutes = null;
            }
        }

        res.json(services);
    } catch(e) {
        if (e.code === 'KICKED') return res.status(401).json({ error: 'Session ended on another device.', code: 'KICKED' });
        console.error('Error fetching user services:', e);
        res.status(500).json({ error: 'Database error' });
    }
});


// Silent Login from aMember Pro (SSO).
// IMPORTANT: this route only accepts the aMember session cookie. The
// previous email/username query-string fallback was a master-key bypass
// (anyone who knew an email could mint a token as that user) — removed.
router.get('/am-login', async (req, res) => {
    const amemberSessionId = req.cookies.amember_nr;
    if (!amemberSessionId) {
        return res.status(401).send('Access Denied: please log in to aMember first.');
    }

    try {
        const { verifyAmemberSession } = require('../amember');
        const amUser = await verifyAmemberSession(amemberSessionId);
        if (!amUser) {
            return res.status(401).send('Access Denied: aMember session is invalid or expired.');
        }
        const identifier = amUser.email;

        let user = await get('SELECT id, role, status FROM users WHERE email = ? OR username = ?', [identifier, identifier]);

        if (!user) {
            console.log(`[SSO] Auto-creating Hub account for: ${identifier}`);
            const { getAmemberUsers } = require('../amember');
            const amUsers = await getAmemberUsers();
            const amUserFull = amUsers.find(u => u.email === identifier || u.username === identifier || u.login === identifier);

            if (amUserFull) {
                await run(
                    'INSERT INTO users (username, email, role, status) VALUES (?, ?, ?, ?)',
                    [amUserFull.username, amUserFull.email, 'user', 'active']
                );
                user = await get('SELECT id, role, status FROM users WHERE email = ?', [amUserFull.email]);
            }
        }

        if (!user || user.status === 'blocked') {
            return res.status(403).send('Access Denied or Account Suspended.');
        }

        // SSO logins always take over (mints a fresh session, kicks the old).
        // We considered surfacing the modal here but SSO is a "trusted"
        // entry-point already gated by aMember's own session — opening a
        // confirm modal would just confuse customers coming from the
        // aMember "Access Tools" button. Direct password logins go through
        // the modal flow.
        await issueSession(req, res, user);
        res.redirect('/dashboard');
    } catch(e) {
        console.error('[SSO] Error:', e);
        res.status(500).send('Login Error. Please try again.');
    }
});

// Lightweight usage endpoint used by the in-iframe usage card.
router.get('/my-usage/:slug', async (req, res) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Not authenticated' });

    try {
        const { user: tokenUser } = await verifyAndCheckSession(token);
        const service = await get(
            'SELECT id, name, slug, daily_limit FROM services WHERE slug = ?',
            [req.params.slug]
        );
        if (!service) return res.status(404).json({ error: 'Service not found' });

        const assignment = await get(
            'SELECT daily_limit_override FROM user_assignments WHERE user_id = ? AND service_id = ?',
            [tokenUser.id, service.id]
        );
        const override = assignment && assignment.daily_limit_override ? parseInt(assignment.daily_limit_override, 10) : null;
        const serviceDefault = service.daily_limit && service.daily_limit > 0 ? service.daily_limit : 0;
        const limit = (override !== null && Number.isFinite(override)) ? override : serviceDefault;

        if (!limit) {
            return res.json({
                service: { name: service.name, slug: service.slug, daily_limit: null },
                limited: false
            });
        }

        const since = Date.now() - 24 * 60 * 60 * 1000;
        const { db } = require('../database');
        const usage = await new Promise((resolve, reject) => {
            db.get(
                'SELECT COUNT(*) AS used, MIN(used_at) AS oldest FROM service_usage WHERE user_id = ? AND service_id = ? AND used_at >= ?',
                [tokenUser.id, service.id, since],
                (err, row) => err ? reject(err) : resolve(row || { used: 0, oldest: null })
            );
        });

        const used = usage.used || 0;
        const remaining = Math.max(0, limit - used);
        const resetAt = (used > 0 && usage.oldest) ? usage.oldest + 24 * 60 * 60 * 1000 : null;

        res.json({
            service: { name: service.name, slug: service.slug, daily_limit: limit },
            limited: true,
            used,
            remaining,
            reset_at: resetAt,
            now: Date.now()
        });
    } catch(e) {
        if (e.code === 'KICKED') return res.status(401).json({ error: 'Session ended on another device.', code: 'KICKED' });
        console.error('my-usage error:', e);
        res.status(401).json({ error: 'Invalid token' });
    }
});

module.exports = { router, JWT_SECRET, verifyAndCheckSession };
