require('dotenv').config();
const express = require('express');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const { get, run } = require('../database');
const { checkAmemberAuth } = require('../amember');

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
router.post('/logout', (req, res) => {
    res.clearCookie('stealth_hub_token');
    res.json({ message: 'Logged out' });
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

// Get User's Services (for Dashboard)
router.get('/services', async (req, res) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Not authenticated' });

    try {
        const verified = jwt.verify(token, JWT_SECRET);
        const { query } = require('../database');
        
        const services = await query(`
            SELECT s.name, s.slug, s.icon_svg, s.text_svg
            FROM user_assignments a
            JOIN services s ON a.service_id = s.id
            WHERE a.user_id = ?
        `, [verified.id]);

        res.json(services);
    } catch(e) {
        console.error('Error fetching user services:', e);
        res.status(500).json({ error: 'Database error' });
    }
});


// Silent Login from aMember Pro (SSO)
router.get('/am-login', async (req, res) => {
    const { email } = req.query;
    if (!email) return res.redirect('https://app.scholargenie.org/login');

    try {
        // Find user by email in Hub DB
        const user = await get('SELECT id, role, status FROM users WHERE email = ?', [email]);
        
        if (!user) {
            console.log(`[SSO] User ${email} not found in Hub. Redirecting to login.`);
            return res.redirect('https://app.scholargenie.org/login');
        }

        // SECURITY CHECK: Verify they still have active access in aMember
        const { verifyAmemberUser } = require('../amember');
        const hasAccess = await verifyAmemberUser(email);
        if (!hasAccess) {
            console.log(`[SSO] User ${email} exists but HAS NO ACTIVE ACCESS in aMember.`);
            return res.status(403).send('Your subscription has expired. Please renew at app.scholargenie.org');
        }

        if (user.status === 'blocked') {
            return res.status(403).send('Your account is suspended.');
        }

        // Generate Hub Token
        const token = jwt.sign({ id: user.id, role: user.role }, JWT_SECRET, { expiresIn: '7d' });
        
        // Set Cookie
        res.cookie('stealth_hub_token', token, { 
            httpOnly: true, 
            secure: process.env.NODE_ENV === 'production',
            maxAge: 7 * 24 * 60 * 60 * 1000 
        });

        // Redirect to Dashboard
        console.log(`[SSO] User ${email} auto-logged in.`);
        res.redirect('/dashboard');
    } catch(e) {
        console.error('[SSO] Error during am-login:', e);
        res.redirect('https://app.scholargenie.org/login');
    }
});

module.exports = { router, JWT_SECRET };
