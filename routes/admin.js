const express = require('express');
const { get, run, query } = require('../database');
const jwt = require('jsonwebtoken');
const { JWT_SECRET } = require('./auth');

const router = express.Router();

// Middleware to check Admin role
const isAdmin = async (req, res, next) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Unauthorized' });

    try {
        const verified = jwt.verify(token, JWT_SECRET);
        if (verified.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
        req.user = verified;
        next();
    } catch(e) {
        res.status(401).json({ error: 'Invalid token' });
    }
};

router.use(isAdmin);

// --- SERVICES MANAGEMENT ---

// Get all services
router.get('/services', async (req, res) => {
    try {
        const services = await query('SELECT * FROM services');
        res.json(services);
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Add new service
router.post('/services', async (req, res) => {
    const { name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id } = req.body;
    try {
        await run(`INSERT INTO services (name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id) 
                   VALUES (?, ?, ?, ?, ?, ?, ?)`, 
                   [name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id]);
        res.status(201).json({ message: 'Service added' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Update service
router.put('/services/:id', async (req, res) => {
    const { name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id } = req.body;
    try {
        await run(`UPDATE services SET name = ?, slug = ?, target_url = ?, icon_svg = ?, text_svg = ?, injection_js = ?, amember_product_id = ? 
                   WHERE id = ?`, 
                   [name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id, req.params.id]);
        res.json({ message: 'Service updated' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Delete service
router.delete('/services/:id', async (req, res) => {
    try {
        await run('DELETE FROM services WHERE id = ?', [req.params.id]);
        res.json({ message: 'Service deleted' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});


// --- COOKIES MANAGEMENT ---

// Helper to handle JSON cookie formats from browser extensions
function formatCookieData(input) {
    if (!input) return '';
    let cleaned = input.trim();
    if (cleaned.startsWith('[') && cleaned.endsWith(']')) {
        try {
            const arr = JSON.parse(cleaned);
            if (Array.isArray(arr)) {
                return arr.map(c => `${c.name || ''}=${c.value || ''}`).join('; ');
            }
        } catch(e) { /* Fallback to standard cleaning */ }
    }
    // Standard cleaning for raw strings
    return cleaned.replace(/[\r\n]/gm, '').replace(/[^\x20-\x7E]/g, '').trim();
}

// Get all cookies
router.get('/cookies', async (req, res) => {
    try {
        const cookies = await query(`
            SELECT c.*, s.name as service_name 
            FROM cookies c
            LEFT JOIN services s ON c.service_id = s.id
        `);
        res.json(cookies);
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});


// Update cookie
router.put('/cookies/:id', async (req, res) => {
    let { name, data, service_id } = req.body;
    data = formatCookieData(data);
    
    try {
        await run('UPDATE cookies SET name = ?, data = ?, service_id = ? WHERE id = ?', [name, data, service_id, req.params.id]);
        res.json({ message: 'Cookie updated' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});


// Add new cookie
router.post('/cookies', async (req, res) => {
    let { name, data, service_id } = req.body;
    data = formatCookieData(data);

    try {
        await run('INSERT INTO cookies (name, data, service_id) VALUES (?, ?, ?)', [name, data, service_id]);
        res.status(201).json({ message: 'Cookie added' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});


// Delete cookie
router.delete('/cookies/:id', async (req, res) => {
    try {
        const result = await run('DELETE FROM cookies WHERE id = ?', [req.params.id]);
        if (result.changes === 0) {
            return res.status(404).json({ error: 'Slot not found' });
        }
        res.json({ message: 'Cookie deleted' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});



const bcrypt = require('bcryptjs');

// --- USER MANAGEMENT ---

// Get all users with their assignments across all services
router.get('/users', async (req, res) => {
    try {
        const users = await query(`
            SELECT id, username, email, role, status
            FROM users
        `);
        
        const assignments = await query(`
            SELECT a.user_id, a.service_id, a.cookie_id, c.name as cookie_name, s.name as service_name
            FROM user_assignments a
            JOIN services s ON a.service_id = s.id
            LEFT JOIN cookies c ON a.cookie_id = c.id
        `);

        // Group assignments by user
        const usersWithAssignments = users.map(u => ({
            ...u,
            assignments: assignments.filter(a => a.user_id === u.id)
        }));

        res.json(usersWithAssignments);
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Get aMember Users (for Sync view)
router.get('/amember-users', async (req, res) => {
    try {
        const { getAmemberUsers } = require('../amember');
        const amUsers = await getAmemberUsers();
        res.json(amUsers);
    } catch(e) { res.status(500).json({ error: 'aMember database error' }); }
});

// Sync aMember users into Hub DB
router.post('/sync-amember', async (req, res) => {
    try {
        const { syncAmemberUsers } = require('../amember');
        const bcrypt = require('bcryptjs');
        const amUsers = await syncAmemberUsers();

        if (!amUsers || amUsers.length === 0) {
            return res.json({ message: 'No users found in aMember or aMember is disabled.', created: 0, existing: 0 });
        }

        const allServices = await query('SELECT id, amember_product_id FROM services');
        let created = 0, existing = 0, errors = 0;

        for (const amUser of amUsers) {
            try {
                const username = amUser.login || amUser.name_f || `user_${amUser.user_id}`;
                const email = amUser.email;
                if (!email) continue;

                // Check if user already exists in Hub DB
                let hubUser = await get('SELECT id FROM users WHERE email = ?', [email]);

                if (!hubUser) {
                    // New user — insert into Hub DB using aMember password hash directly
                    const result = await run(
                        'INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)',
                        [username, email, amUser.pass, 'user', 'active']
                    );
                    hubUser = { id: result.lastID };
                    created++;
                } else {
                    // Existing user — update password hash to stay in sync
                    await run('UPDATE users SET password_hash = ? WHERE id = ?', [amUser.pass, hubUser.id]);
                    existing++;
                }

                // --- PRODUCT-BASED ASSIGNMENT ---
                const userProducts = (amUser.product_ids || '').split(',').map(id => id.trim());

                for (const service of allServices) {
                    const mappedProductId = String(service.amember_product_id || '').trim();
                    const hasAccessInAmember = mappedProductId ? userProducts.includes(mappedProductId) : true; // If no mapping, default to has_access check or grant all

                    if (hasAccessInAmember) {
                        const existingAssignment = await get(
                            'SELECT id FROM user_assignments WHERE user_id = ? AND service_id = ?',
                            [hubUser.id, service.id]
                        );
                        if (!existingAssignment) {
                            // Find first available cookie slot
                            const cookie = await get('SELECT id FROM cookies WHERE service_id = ? LIMIT 1', [service.id]);
                            await run(
                                'INSERT OR IGNORE INTO user_assignments (user_id, service_id, cookie_id) VALUES (?, ?, ?)',
                                [hubUser.id, service.id, cookie ? cookie.id : null]
                            );
                        }
                    } else {
                        // User no longer has access to this specific product - REVOKE
                        await run('DELETE FROM user_assignments WHERE user_id = ? AND service_id = ?', [hubUser.id, service.id]);
                    }
                }
            } catch(userErr) {
                console.error(`[aMember Sync] Error processing user ${amUser.email}:`, userErr.message);
                errors++;
            }
        }

        // --- PURGE STEP: Remove users from Hub that no longer exist in aMember ---
        const amEmails = amUsers.map(u => u.email).filter(Boolean);
        const amLogins = amUsers.map(u => u.login).filter(Boolean);
        
        // Get all users from Hub who are NOT admins
        const hubUsers = await query('SELECT id, email, username FROM users WHERE role != "admin"');
        let deleted = 0;
        
        for (const hubUser of hubUsers) {
            // If the user's email AND username are both missing from the latest aMember list, they are deleted
            const existsInAm = amEmails.includes(hubUser.email) || amLogins.includes(hubUser.username);
            
            if (!existsInAm) {
                console.log(`[aMember Sync] Purging deleted user: ${hubUser.email || hubUser.username}`);
                await run('DELETE FROM users WHERE id = ?', [hubUser.id]);
                deleted++;
            }
        }

        console.log(`[aMember Sync] Complete: ${created} created, ${existing} updated, ${deleted} purged, ${errors} errors`);

        // Notify all admins in real-time
        const io = req.app.get('io');
        if (io) io.to('admins').emit('sync_complete', { created, existing, deleted });

        res.json({
            message: `Sync complete! ${created} new users, ${existing} updated, ${deleted} removed.`,
            created,
            existing,
            deleted,
            errors,
            total: amUsers.length
        });
    } catch(e) {
        console.error('[aMember Sync] Fatal Error:', e);
        res.status(500).json({ error: 'aMember sync failed: ' + e.message });
    }
});


// Create User (Manual)
router.post('/users', async (req, res) => {
    const { username, email, password, role } = req.body;
    try {
        const salt = await bcrypt.genSalt(10);
        const hash = await bcrypt.hash(password || 'password123', salt);
        await run('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)', 
            [username, email, hash, role || 'student']);
        res.status(201).json({ message: 'User created' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Update User (Edit / Block / Reset Password)
router.put('/users/:id', async (req, res) => {
    const { username, email, password, status, role } = req.body;
    try {
        const user = await get('SELECT * FROM users WHERE id = ?', [req.params.id]);
        if (!user) return res.status(404).json({ error: 'User not found' });

        let sql = 'UPDATE users SET username = ?, email = ?, status = ?, role = ?';
        let params = [username || user.username, email || user.email, status || user.status, role || user.role];

        if (password) {
            const salt = await bcrypt.genSalt(10);
            const hash = await bcrypt.hash(password, salt);
            sql += ', password_hash = ?';
            params.push(hash);
        }

        sql += ' WHERE id = ?';
        params.push(req.params.id);

        await run(sql, params);
        
        // Real-time Update Trigger
        const io = req.app.get('io');
        if (io) io.to(`user_${req.params.id}`).emit('force_refresh');

        res.json({ message: 'User updated successfully' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Assign cookie to user for a SPECIFIC service
router.post('/users/:id/assign', async (req, res) => {
    const { service_id, cookie_id } = req.body;
    try {
        if (!cookie_id) {
            // Revoke assignment
            await run('DELETE FROM user_assignments WHERE user_id = ? AND service_id = ?', [req.params.id, service_id]);
        } else {
            // Add or update assignment
            await run(`INSERT INTO user_assignments (user_id, service_id, cookie_id) 
                       VALUES (?, ?, ?) 
                       ON CONFLICT(user_id, service_id) DO UPDATE SET cookie_id = EXCLUDED.cookie_id`, 
                       [req.params.id, service_id, cookie_id]);
        }
        
        // Real-time Update Trigger
        const io = req.app.get('io');
        if (io) io.to(`user_${req.params.id}`).emit('force_refresh');

        res.json({ message: 'Assignment updated' });
    } catch(e) { 
        console.error('Assignment Error:', e);
        res.status(500).json({ error: 'Database error' }); 
    }
});


// Delete user
router.delete('/users/:id', async (req, res) => {
    console.log(`[ADMIN] Request to DELETE student ID: ${req.params.id}`);
    try {
        const result = await run('DELETE FROM users WHERE id = ? AND role != "admin"', [req.params.id]);
        console.log(`[ADMIN] Deletion result: ${result.changes} rows affected`);
        
        if (result.changes === 0) {
            return res.status(404).json({ error: 'User not found, already deleted, or protected' });
        }
        res.json({ message: 'User deleted' });
    } catch(e) { 
        console.error('Delete User Error:', e);
        res.status(500).json({ error: 'Database error' }); 
    }
});

// System Actions: Clear all assignments
router.post('/system/clear-assignments', async (req, res) => {
    try {
        await run('DELETE FROM user_assignments');
        
        // Real-time Global Update Trigger
        const io = req.app.get('io');
        if (io) io.emit('global_reload');

        res.json({ message: 'All student assignments cleared' });
    } catch(e) { 
        res.status(500).json({ error: 'Database error' }); 
    }
});



// Quick Grant (Find/Create slot and assign in one click)
router.post('/users/:id/quick-grant', async (req, res) => {
    const { service_id } = req.body;
    try {
        // 1. Find service
        const service = await get('SELECT name FROM services WHERE id = ?', [service_id]);
        if (!service) return res.status(404).json({ error: 'Service not found' });

        // 2. Find an existing slot for this service
        let slot = await get('SELECT id FROM cookies WHERE service_id = ? LIMIT 1', [service_id]);
        
        // 3. If no slot exists, create a default one
        if (!slot) {
            const result = await run('INSERT INTO cookies (name, data, service_id) VALUES (?, ?, ?)', 
                [`${service.name} Slot 1`, 'PLACEHOLDER_COOKIE', service_id]);
            slot = { id: result.lastID };
        }

        // 4. Assign user to this slot
        await run(`INSERT INTO user_assignments (user_id, service_id, cookie_id) 
                   VALUES (?, ?, ?) 
                   ON CONFLICT(user_id, service_id) DO UPDATE SET cookie_id = EXCLUDED.cookie_id`, 
                   [req.params.id, service_id, slot.id]);

        // Real-time Update Trigger
        const io = req.app.get('io');
        if (io) io.to(`user_${req.params.id}`).emit('force_refresh');

        res.json({ message: 'Access granted successfully', slot_id: slot.id });
    } catch(e) { 
        console.error('Quick Grant Error:', e);
        res.status(500).json({ error: 'Database error during quick grant' }); 
    }
});


module.exports = router;

