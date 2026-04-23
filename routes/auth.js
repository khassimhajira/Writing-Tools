require('dotenv').config();
const express = require('express');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const { get, run } = require('../database');
const { checkAmemberAuth, getAmemberExpiry } = require('../amember');

const router = express.Router();
const JWT_SECRET = process.env.JWT_SECRET || 'stealth_secret_key_123';
const JWT_EXPIRY = process.env.JWT_EXPIRY || '7d';

/* 
router.post('/register', async (req, res) => {
    // Disabled to enforce aMember registration
    return res.status(403).json({ error: 'Registration is handled via aMember Pro.' });
});
*/

// Login
router.post('/login', async (req, res) => {
    const { email, password } = req.body;
    try {
        // 1. Try Local Hub Database first (Admins & Manually added users)
        let user = await get('SELECT * FROM users WHERE email = ? OR username = ?', [email, email]);
        
        let validPassword = false;
        if (user) {
            validPassword = await bcrypt.compare(password, user.password_hash);
        }

        // 2. If not found locally or password wrong, try aMember Bridge
        if (!user || !validPassword) {
            const amUser = await checkAmemberAuth(email, password);
            
            if (amUser && amUser.error) {
                return res.status(403).json({ error: amUser.error });
            }

            if (amUser) {
                // User found in aMember! Shadow them into our local DB
                if (!user) {
                    const result = await run('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)', 
                        [amUser.username, amUser.email, amUser.password_hash, 'user']);
                    user = await get('SELECT * FROM users WHERE id = ?', [result.lastID]);
                } else {
                    await run('UPDATE users SET password_hash = ? WHERE id = ?', [amUser.password_hash, user.id]);
                    user.password_hash = amUser.password_hash;
                }

                // --- AUTO-ASSIGNMENT LOGIC ---
                // Ensure they have a slot for StealthWriter
                const service = await get("SELECT id FROM services WHERE slug = 'stealth'");
                if (service) {
                    // Check if they already have an assignment
                    const existingAssignment = await get('SELECT id, cookie_id FROM user_assignments WHERE user_id = ? AND service_id = ?', [user.id, service.id]);
                    
                    if (!existingAssignment || !existingAssignment.cookie_id) {
                        // Find an available cookie for this service
                        const cookie = await get('SELECT id FROM cookies WHERE service_id = ? LIMIT 1', [service.id]);
                        if (cookie) {
                            await run(`INSERT INTO user_assignments (user_id, service_id, cookie_id) 
                                       VALUES (?, ?, ?) 
                                       ON CONFLICT(user_id, service_id) DO UPDATE SET cookie_id = EXCLUDED.cookie_id`, 
                                       [user.id, service.id, cookie.id]);
                        } else {
                            // Even if no cookie exists yet, at least give them the service entry
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

        // Create token
        const token = jwt.sign({ id: user.id, role: user.role, status: user.status }, JWT_SECRET, { expiresIn: JWT_EXPIRY });
        
        res.cookie('stealth_hub_token', token, { httpOnly: true, sameSite: 'Lax', path: '/' });
        res.json({ message: 'Logged in', user: { id: user.id, username: user.username, role: user.role } });
    } catch(e) {
        console.error('Login Error:', e);
        res.status(500).json({ error: 'Authentication error' });
    }
});

// Logout
router.get('/logout', (req, res) => {
    res.clearCookie('stealth_hub_token');
    
    // Redirect to aMember logout so they are logged out of everything at once
    res.redirect('https://app.scholargenie.org/login/logout');
});

// Me (Get current session)
router.get('/me', async (req, res) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Not authenticated' });

    try {
        const verified = jwt.verify(token, JWT_SECRET);
        const user = await get('SELECT id, username, email, role, status FROM users WHERE id = ?', [verified.id]);
        if (!user) return res.status(401).json({ error: 'User not found' });

        
        if (user.status === 'blocked') {
            return res.status(403).json({ error: 'Account suspended' });
        }

        res.json(user);
    } catch(e) {
        res.status(401).json({ error: 'Invalid token' });
    }
});

// List User Services
router.get('/services', async (req, res) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Not authenticated' });
    try {
        const verified = jwt.verify(token, JWT_SECRET);
        const user = await get('SELECT email, role FROM users WHERE id = ?', [verified.id]);
        if (!user) return res.status(404).json({ error: 'User not found' });

        let services = [];
        if (user.role === 'admin') {
            services = await query('SELECT * FROM services');
        } else {
            services = await query(`
                SELECT s.* FROM services s
                JOIN user_assignments ua ON s.id = ua.service_id
                WHERE ua.user_id = ?`, [verified.id]);
        }

        // Add expiry date to each service
        const servicesWithExpiry = await Promise.all(services.map(async (s) => {
            try {
                const expiry = await getAmemberExpiry(user.email, s.product_id);
                return { ...s, expiry_date: expiry };
            } catch (err) {
                return { ...s, expiry_date: null };
            }
        }));

        res.json(servicesWithExpiry);
    } catch(e) {
        console.error('Service fetch error:', e);
        res.status(500).json({ error: 'Internal server error' });
    }
});


// Subscription Expiry Date
router.get('/expiry', async (req, res) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Not authenticated' });
    try {
        const verified = jwt.verify(token, JWT_SECRET);
        const user = await get('SELECT email FROM users WHERE id = ?', [verified.id]);
        if (!user) return res.status(404).json({ expiry_date: null });
        const expiryDate = await getAmemberExpiry(user.email);
        res.json({ expiry_date: expiryDate });
    } catch(e) {
        console.error('Expiry fetch error:', e);
        res.json({ expiry_date: null });
    }
});

// Silent Login from aMember Pro (SSO)
router.get('/am-login', async (req, res) => {
    const { email, username } = req.query;
    const amemberSessionId = req.cookies.amember_nr; 
    
    let identifier = email || username;
    let amUser = null;

    try {
        const { verifyAmemberSession, verifyAmemberUser } = require('../amember');

        // 1. Try Session-based Login
        if (amemberSessionId) {
            amUser = await verifyAmemberSession(amemberSessionId);
            if (amUser) {
                identifier = amUser.email;
            }
        }

        // 2. Fallback to Email/Username
        if (!amUser && !identifier) {
            return res.status(401).send('Access Denied: Please log in to aMember first.');
        }

        // 3. Find user in Hub DB
        let user = await get('SELECT id, role, status FROM users WHERE email = ? OR username = ?', [identifier, identifier]);
        
        // 4. Verify access in aMember
        if (!amUser) {
            const hasAccess = await verifyAmemberUser(identifier);
            if (!hasAccess) {
                return res.status(403).send(`Access Denied: No subscription found for "${identifier}".`);
            }
        }

        // 5. AUTO-CREATE THEM IF MISSING
        if (!user) {
            console.log(`[SSO] Auto-creating Hub account for: ${identifier}`);
            const { getAmemberUsers } = require('../amember');
            const amUsers = await getAmemberUsers();
            const amUserFull = amUsers.find(u => u.email === identifier || u.username === identifier || u.login === identifier);

            if (amUserFull) {
                const { run } = require('../database');
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

        // 6. Log In
        const token = jwt.sign({ id: user.id, role: user.role }, JWT_SECRET, { expiresIn: '7d' });
        res.cookie('stealth_hub_token', token, { 
            httpOnly: true, 
            secure: process.env.NODE_ENV === 'production',
            maxAge: 7 * 24 * 60 * 60 * 1000 
        });

        res.redirect('/dashboard');
    } catch(e) {
        console.error('[SSO] Error:', e);
        res.status(500).send('Login Error. Please try again.');
    }
});

module.exports = { router, JWT_SECRET };
