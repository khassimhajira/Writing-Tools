require('dotenv').config();
const express = require('express');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const { get, run } = require('../database');
const { checkAmemberAuth } = require('../amember');

const router = express.Router();
const JWT_SECRET = process.env.JWT_SECRET || 'stealth_secret_key_123';
const JWT_EXPIRY = process.env.JWT_EXPIRY || '7d';

// Register
router.post('/register', async (req, res) => {
    const { username, email, password } = req.body;
    try {
        const existing = await get('SELECT id FROM users WHERE email = ?', [email]);
        if (existing) return res.status(400).json({ error: 'Email already registered' });

        const salt = await bcrypt.genSalt(10);
        const hash = await bcrypt.hash(password, salt);
        
        await run('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)', [username, email, hash]);
        res.status(201).json({ message: 'Registration successful' });
    } catch(e) {
        res.status(500).json({ error: 'Database error' });
    }
});

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
                    await run('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)', 
                        [amUser.username, amUser.email, amUser.password_hash, 'user']);
                    user = await get('SELECT * FROM users WHERE email = ?', [amUser.email]);
                    
                    // Automatically assign them to the StealthWriter service if they are new
                    const service = await get("SELECT id FROM services WHERE slug = 'stealth'");
                    if (service) {
                        await run('INSERT OR IGNORE INTO user_assignments (user_id, service_id) VALUES (?, ?)', [user.id, service.id]);
                    }
                } else {
                    // Update existing local user's hash to match aMember
                    await run('UPDATE users SET password_hash = ? WHERE id = ?', [amUser.password_hash, user.id]);
                    user.password_hash = amUser.password_hash;
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


module.exports = { router, JWT_SECRET };
