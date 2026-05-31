const express = require('express');
const { get, run, query, pickLeastLoadedCookie, db } = require('../database');
const jwt = require('jsonwebtoken');
const { JWT_SECRET } = require('./auth');

const router = express.Router();

// Middleware to check Admin role + enforce single-session.
// If a different device logged in as this admin, the JWT's session_id
// no longer matches the DB and we kick. Without this check, a kicked
// admin tab would keep working until the user manually navigated away.
const isAdmin = async (req, res, next) => {
    const token = req.cookies.stealth_hub_token;
    if (!token) return res.status(401).json({ error: 'Unauthorized' });

    try {
        const verified = jwt.verify(token, JWT_SECRET);
        if (verified.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });

        // Session enforcement: pull the user's current session_id from DB
        // and compare to the JWT claim. Mismatch = another device took over.
        try {
            const dbUser = await get('SELECT session_id, status FROM users WHERE id = ?', [verified.id]);
            if (!dbUser) return res.status(401).json({ error: 'User not found', code: 'NO_USER' });
            if (dbUser.status === 'blocked') return res.status(403).json({ error: 'Account suspended' });
            if (dbUser.session_id && verified.sid && dbUser.session_id !== verified.sid) {
                return res.status(401).json({ error: 'Session ended on another device.', code: 'KICKED' });
            }
            // Idle timeout enforcement.
            if (verified.sid) {
                const snap = await get('SELECT last_active FROM user_sessions WHERE user_id = ?', [verified.id]);
                const idleMin = parseInt(process.env.IDLE_TIMEOUT_MIN || '5', 10);
                const idleMs = idleMin * 60 * 1000;
                if (snap && snap.last_active && (Date.now() - Number(snap.last_active)) > idleMs) {
                    await run('UPDATE users SET session_id = NULL WHERE id = ?', [verified.id]);
                    await run('DELETE FROM user_sessions WHERE user_id = ?', [verified.id]);
                    res.clearCookie('stealth_hub_token', { domain: '.scholargenie.org', path: '/' });
                    return res.status(401).json({ error: 'Session timed out due to inactivity.', code: 'IDLE_TIMEOUT' });
                }
            }
        } catch (e) {
            console.error('isAdmin session check failed:', e.message);
            return res.status(500).json({ error: 'Auth check failed' });
        }

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
    const { name, target_url, icon_svg, text_svg, injection_js, amember_product_id, daily_limit, billable_path, cookie_file } = req.body;
    const slug = (req.body.slug || '').trim();
    const dl = (daily_limit === '' || daily_limit == null) ? null : parseInt(daily_limit, 10);
    const bp = typeof billable_path === 'string' ? billable_path.trim() : '';
    const cf = typeof cookie_file === 'string' ? cookie_file.trim() : '';
    try {
        await run(`INSERT INTO services (name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id, daily_limit, billable_path, cookie_file)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
                   [name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id || null, Number.isFinite(dl) && dl > 0 ? dl : null, bp || null, cf || null]);
        res.status(201).json({ message: 'Service added' });
    } catch(e) { res.status(500).json({ error: 'Database error: ' + e.message }); }
});

// Update service
router.put('/services/:id', async (req, res) => {
    const { name, target_url, icon_svg, text_svg, injection_js, amember_product_id, daily_limit, billable_path, cookie_file } = req.body;
    const slug = (req.body.slug || '').trim();

    // SAFETY: only overwrite daily_limit when the client explicitly sends it.
    // Treat "field omitted" and "field is empty string" differently:
    //   - omitted   (undefined): keep the existing value (so accidental edits
    //                            of other fields don't wipe the cap).
    //   - empty ""  (explicit clear): set to NULL (= unlimited).
    //   - number    (set/change):     parseInt, must be > 0.
    let dlSql = '';
    let dlParam = null;
    let dlHasParam = false;
    if (Object.prototype.hasOwnProperty.call(req.body, 'daily_limit')) {
        if (daily_limit === '' || daily_limit === null) {
            dlSql = ', daily_limit = NULL';
        } else {
            const n = parseInt(daily_limit, 10);
            if (Number.isFinite(n) && n > 0) {
                dlSql = ', daily_limit = ?';
                dlParam = n;
                dlHasParam = true;
            } else {
                dlSql = ', daily_limit = NULL';
            }
        }
    }

    // Same preserve-when-omitted policy for billable_path.
    let bpSql = '';
    let bpParam = null;
    let bpHasParam = false;
    if (Object.prototype.hasOwnProperty.call(req.body, 'billable_path')) {
        if (billable_path === '' || billable_path === null) {
            bpSql = ', billable_path = NULL';
        } else if (typeof billable_path === 'string') {
            bpSql = ', billable_path = ?';
            bpParam = billable_path.trim();
            bpHasParam = true;
        }
    }

    // Same preserve-when-omitted policy for cookie_file.
    let cfSql = '';
    let cfParam = null;
    let cfHasParam = false;
    if (Object.prototype.hasOwnProperty.call(req.body, 'cookie_file')) {
        if (cookie_file === '' || cookie_file === null) {
            cfSql = ', cookie_file = NULL';
        } else if (typeof cookie_file === 'string') {
            cfSql = ', cookie_file = ?';
            cfParam = cookie_file.trim();
            cfHasParam = true;
        }
    }

    try {
        const params = [name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id];
        let sql = `UPDATE services SET name = ?, slug = ?, target_url = ?, icon_svg = ?, text_svg = ?, injection_js = ?, amember_product_id = ?${dlSql}${bpSql}${cfSql} WHERE id = ?`;
        if (dlHasParam) params.push(dlParam);
        if (bpHasParam) params.push(bpParam);
        if (cfHasParam) params.push(cfParam);
        params.push(req.params.id);

        await run(sql, params);
        res.json({ message: 'Service updated' });
    } catch(e) { res.status(500).json({ error: 'Database error: ' + e.message }); }
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
            SELECT a.user_id, a.service_id, a.cookie_id, a.daily_limit_override, c.name as cookie_name, s.name as service_name, s.daily_limit as service_default_limit
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
                    const hasAccessInAmember = mappedProductId ? userProducts.includes(mappedProductId) : false; // Default to false for strict access control

                    if (hasAccessInAmember) {
                        const existingAssignment = await get(
                            'SELECT id FROM user_assignments WHERE user_id = ? AND service_id = ?',
                            [hubUser.id, service.id]
                        );
                        if (!existingAssignment) {
                            // Find least-loaded cookie slot for this service (load-balanced across slots)
                            const cookie = await pickLeastLoadedCookie(service.id);
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

// Per-user daily-limit override for a specific service.
//   PUT /users/:id/limit-override   body: { service_id, limit }
//     limit = null or empty:  clear override (user inherits service default)
//     limit = positive int:   user's personal cap on this service
//
// The user must already have an assignment row for this service. We update
// it in place rather than creating one, so revoking access stays a separate
// operation.
router.put('/users/:id/limit-override', async (req, res) => {
    const { service_id, limit } = req.body;
    if (!service_id) return res.status(400).json({ error: 'service_id required' });
    let normalized = null;
    if (limit !== '' && limit !== null && limit !== undefined) {
        const n = parseInt(limit, 10);
        if (Number.isFinite(n) && n > 0) normalized = n;
    }
    try {
        const result = await run(
            'UPDATE user_assignments SET daily_limit_override = ? WHERE user_id = ? AND service_id = ?',
            [normalized, req.params.id, service_id]
        );
        if (result.changes === 0) {
            return res.status(404).json({ error: 'User has no assignment for this service. Assign them first.' });
        }
        const io = req.app.get('io');
        if (io) io.to(`user_${req.params.id}`).emit('force_refresh');
        res.json({ message: 'Override updated', daily_limit_override: normalized });
    } catch(e) {
        console.error('Override Error:', e);
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


// --- LOAD-BALANCING / REBALANCE ---

// Get a per-slot user-count summary for a service (used by the admin UI to show
// how loaded each cookie slot is).
router.get('/services/:id/slot-load', async (req, res) => {
    try {
        const rows = await query(`
            SELECT c.id AS cookie_id, c.name AS cookie_name,
                   COUNT(a.id) AS user_count
            FROM cookies c
            LEFT JOIN user_assignments a ON a.cookie_id = c.id
            WHERE c.service_id = ?
            GROUP BY c.id
            ORDER BY c.id ASC
        `, [req.params.id]);
        res.json(rows);
    } catch(e) {
        console.error('Slot Load Error:', e);
        res.status(500).json({ error: 'Database error' });
    }
});

// Rebalance: redistribute every user assigned to this service evenly across
// the service's cookie slots.
//
// Safety:
//   - This only TOUCHES rows in user_assignments for the specified service.
//     Users on other services, cookies, services rows, and proxies are not
//     touched.
//   - If the service has zero cookie slots, nothing changes.
//   - We use a transaction so a failure mid-way leaves data unchanged.
router.post('/services/:id/rebalance', async (req, res) => {
    const serviceId = parseInt(req.params.id, 10);
    if (!serviceId) return res.status(400).json({ error: 'Invalid service id' });

    try {
        const service = await get('SELECT id, name FROM services WHERE id = ?', [serviceId]);
        if (!service) return res.status(404).json({ error: 'Service not found' });

        const slots = await query('SELECT id FROM cookies WHERE service_id = ? ORDER BY id ASC', [serviceId]);
        if (slots.length === 0) {
            return res.status(400).json({ error: 'No cookie slots exist for this service. Add at least one slot before rebalancing.' });
        }

        const assignments = await query(
            'SELECT id, user_id, cookie_id FROM user_assignments WHERE service_id = ? ORDER BY user_id ASC',
            [serviceId]
        );

        if (assignments.length === 0) {
            return res.json({ message: 'No assignments to rebalance for this service.', moved: 0, slot_count: slots.length, user_count: 0 });
        }

        // Round-robin: assignment[i] -> slots[i % slots.length]
        let moved = 0;
        await new Promise((resolve, reject) => {
            db.serialize(() => {
                db.run('BEGIN TRANSACTION', (err) => { if (err) reject(err); });
                const stmt = db.prepare('UPDATE user_assignments SET cookie_id = ? WHERE id = ?');
                assignments.forEach((a, i) => {
                    const target = slots[i % slots.length].id;
                    if (a.cookie_id !== target) {
                        stmt.run([target, a.id], (err) => { if (err) console.error('Rebalance row error:', err); });
                        moved++;
                    }
                });
                stmt.finalize((err) => {
                    if (err) {
                        db.run('ROLLBACK', () => reject(err));
                        return;
                    }
                    db.run('COMMIT', (err2) => err2 ? reject(err2) : resolve());
                });
            });
        });

        // Notify clients to refresh — assigned users' currently-injected cookie
        // may have changed, so they should reload.
        const io = req.app.get('io');
        if (io) io.emit('global_reload');

        res.json({
            message: `Rebalanced ${assignments.length} users across ${slots.length} slot(s). ${moved} reassignments applied.`,
            user_count: assignments.length,
            slot_count: slots.length,
            moved
        });
    } catch(e) {
        console.error('Rebalance Error:', e);
        res.status(500).json({ error: 'Rebalance failed: ' + e.message });
    }
});



// Quick Grant (Find/Create slot and assign in one click)
router.post('/users/:id/quick-grant', async (req, res) => {
    const { service_id } = req.body;
    try {
        // 1. Find service
        const service = await get('SELECT name FROM services WHERE id = ?', [service_id]);
        if (!service) return res.status(404).json({ error: 'Service not found' });

        // 2. Find an existing slot for this service (least-loaded one)
        let slot = await pickLeastLoadedCookie(service_id);
        
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


// --- PROXY MANAGEMENT ---

// Get all proxies
router.get('/proxies', async (req, res) => {
    try {
        const proxies = await query('SELECT * FROM proxies');
        res.json(proxies);
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Helper to parse messy proxy pastes
function parseProxyPaste(text) {
    if (!text) return [];
    
    // Split by lines or double newlines
    const lines = text.split(/\r?\n/).map(l => l.trim()).filter(l => l.length > 0);
    const results = [];
    
    // Attempt 1: Standard URL format (http://user:pass@ip:port)
    const urlRegex = /http[s]?:\/\/[^:]+:[^@]+@[^:]+:\d+/g;
    const matches = text.match(urlRegex);
    if (matches) return matches;

    // Attempt 2: IP:Port:User:Pass format
    lines.forEach(line => {
        const parts = line.split(/[:\s\t,|]+/);
        if (parts.length >= 4) {
            // Check if first part looks like IP
            if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(parts[0])) {
                results.push(`http://${parts[2]}:${parts[3]}@${parts[0]}:${parts[1]}`);
            }
        }
    });

    if (results.length > 0) return results;

    // Attempt 3: Multi-line chunks (IP \n Port \n User \n Pass)
    // We look for sequences of 4 lines
    for (let i = 0; i < lines.length - 3; i++) {
        const p1 = lines[i];   // IP?
        const p2 = lines[i+1]; // Port?
        const p3 = lines[i+2]; // User?
        const p4 = lines[i+3]; // Pass?
        
        if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(p1) && /^\d+$/.test(p2)) {
            results.push(`http://${p3}:${p4}@${p1}:${p2}`);
            i += 3; // Skip next 3 lines as they are used
        }
    }

    return results;
}

// Bulk add proxies
router.post('/proxies/bulk', async (req, res) => {
    const { text } = req.body;
    const urls = parseProxyPaste(text);
    
    if (urls.length === 0) {
        return res.status(400).json({ error: 'No valid proxies found in paste.' });
    }

    try {
        let added = 0;
        for (const url of urls) {
            try {
                await run('INSERT OR IGNORE INTO proxies (url) VALUES (?)', [url]);
                added++;
            } catch(e) {}
        }
        
        // Notify server to reload proxies
        const io = req.app.get('io');
        if (io) io.emit('proxies_updated');

        res.json({ message: `Successfully processed ${urls.length} proxies. Added ${added} new ones.`, count: urls.length });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Update proxy status
router.put('/proxies/:id', async (req, res) => {
    const { status, url } = req.body;
    try {
        await run('UPDATE proxies SET status = ?, url = ? WHERE id = ?', [status, url, req.params.id]);
        
        const io = req.app.get('io');
        if (io) io.emit('proxies_updated');

        res.json({ message: 'Proxy updated' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});

// Delete proxy
router.delete('/proxies/:id', async (req, res) => {
    try {
        await run('DELETE FROM proxies WHERE id = ?', [req.params.id]);
        
        const io = req.app.get('io');
        if (io) io.emit('proxies_updated');

        res.json({ message: 'Proxy deleted' });
    } catch(e) { res.status(500).json({ error: 'Database error' }); }
});


// --- USAGE LIMITS ---

// Per-service usage view: every user who used this service in the last 24h
// with their count and the configured cap. Drives the admin UI.
router.get('/services/:id/usage', async (req, res) => {
    try {
        const service = await get('SELECT id, name, daily_limit FROM services WHERE id = ?', [req.params.id]);
        if (!service) return res.status(404).json({ error: 'Service not found' });

        const since = Date.now() - 24 * 60 * 60 * 1000;
        const rows = await query(`
            SELECT u.id AS user_id, u.username, u.email,
                   COUNT(g.id) AS used,
                   MIN(g.used_at) AS oldest_use,
                   MAX(g.used_at) AS latest_use
            FROM users u
            JOIN service_usage g ON g.user_id = u.id
            WHERE g.service_id = ? AND g.used_at >= ?
            GROUP BY u.id
            ORDER BY used DESC, latest_use DESC
        `, [req.params.id, since]);

        res.json({
            service: { id: service.id, name: service.name, daily_limit: service.daily_limit || null },
            window_hours: 24,
            now: Date.now(),
            users: rows
        });
    } catch(e) {
        console.error('Usage view error:', e);
        res.status(500).json({ error: 'Database error' });
    }
});

// Reset a single user's usage on a service (admin override).
router.delete('/services/:id/usage/:user_id', async (req, res) => {
    try {
        const result = await run(
            'DELETE FROM service_usage WHERE service_id = ? AND user_id = ?',
            [req.params.id, req.params.user_id]
        );
        const io = req.app.get('io');
        if (io) io.emit('usage_reset', { service_id: parseInt(req.params.id, 10), user_id: parseInt(req.params.user_id, 10) });
        res.json({ message: 'Usage reset', removed: result.changes });
    } catch(e) {
        res.status(500).json({ error: 'Database error' });
    }
});

// Clear all usage rows for a service (full reset).
router.delete('/services/:id/usage', async (req, res) => {
    try {
        const result = await run('DELETE FROM service_usage WHERE service_id = ?', [req.params.id]);
        const io = req.app.get('io');
        if (io) io.emit('usage_reset', { service_id: parseInt(req.params.id, 10) });
        res.json({ message: 'All usage cleared for this service', removed: result.changes });
    } catch(e) {
        res.status(500).json({ error: 'Database error' });
    }
});


// --- COOKIE FILES ---
//
// For services that proxy via a shared upstream account (WriteHuman is
// the first), the upstream session lives in a file on disk. Admins
// rotate it from this UI by pasting whatever the browser shows them.
//
// We accept two shapes of paste:
//   1. The full cookie line:
//        sb-...-auth-token=base64-eyJ...; sb-session-token=...; ...
//   2. Just the long auth-token value (`base64-eyJ...` or `eyJ...`)
//      and we wrap it with the canonical name. Helps non-technical
//      admins who copy from DevTools' "Value" cell.
//
// On read, we decode the access-token JWT and surface its expiry so the
// UI can show "active until 17:45 UTC" or "expired".

const fs = require('fs');
const path = require('path');

// Defensive: refuse paths that aren\'t under our known data dir. This
// prevents a misconfigured service row from letting an admin overwrite
// /etc/passwd. The data dir is wherever the persistent .env lives.
function isSafeCookiePath(p) {
    if (!p || typeof p !== 'string') return false;
    const norm = path.resolve(p);
    // Allow only files under stealth_data/ (production) or our local
    // workspace tools/ folder for dev. Both conventions end with that
    // path segment.
    return /(?:[\\/]stealth_data[\\/])|(?:[\\/]tools[\\/])/.test(norm)
        && norm.endsWith('.txt');
}

function decodeSupabaseAuthCookie(cookieLine) {
    // Pull the supabase auth-token cookie out of the line and decode its
    // wrapped session blob to extract expires_at + email.
    if (!cookieLine) return {};
    const m = /sb-[a-z0-9]+-auth-token=([^;]+)/i.exec(cookieLine);
    if (!m) return {};
    let value = decodeURIComponent(m[1].trim());
    if (value.toLowerCase().startsWith('base64-')) {
        try {
            let v = value.slice(7).replace(/-/g, '+').replace(/_/g, '/');
            while (v.length % 4) v += '=';
            value = Buffer.from(v, 'base64').toString('utf8');
        } catch (_) { return {}; }
    }
    // Trim anything after the last `}` so accidental trailing junk
    // doesn\'t break JSON.parse.
    const close = value.lastIndexOf('}');
    if (close > 0) value = value.substring(0, close + 1);
    try {
        const blob = JSON.parse(value);
        return {
            expires_at: blob.expires_at || null,
            email: (blob.user && blob.user.email) || null,
            full_name: blob.user && blob.user.user_metadata && blob.user.user_metadata.full_name || null,
            has_refresh_token: !!blob.refresh_token,
        };
    } catch (_) { return {}; }
}

// Get cookie file status for a service (without leaking the raw token).
router.get('/services/:id/cookie-file', async (req, res) => {
    try {
        const svc = await get('SELECT id, name, cookie_file FROM services WHERE id = ?', [req.params.id]);
        if (!svc) return res.status(404).json({ error: 'Service not found' });
        if (!svc.cookie_file) return res.json({ configured: false });

        const out = { configured: true, path: svc.cookie_file };
        if (!isSafeCookiePath(svc.cookie_file)) {
            return res.json({ ...out, error: 'Configured path is outside the allowed data directory.' });
        }
        if (!fs.existsSync(svc.cookie_file)) {
            return res.json({ ...out, exists: false });
        }
        const stat = fs.statSync(svc.cookie_file);
        const raw  = fs.readFileSync(svc.cookie_file, 'utf8').trim();
        const meta = decodeSupabaseAuthCookie(raw);
        // Surface the cookie names present in the file so admins can
        // verify they pasted a complete blob.
        const cookieNames = raw.split(';').map(s => {
            const eq = s.indexOf('=');
            return eq > 0 ? s.slice(0, eq).trim() : null;
        }).filter(Boolean);
        return res.json({
            ...out,
            exists: true,
            size_bytes: stat.size,
            modified_at: stat.mtimeMs,
            cookie_names: cookieNames,
            ...meta,
        });
    } catch (e) {
        console.error('cookie-file read error:', e);
        return res.status(500).json({ error: 'Read failed: ' + e.message });
    }
});

// Update cookie file content. Accepts either the full cookie line or
// just the auth-token value.
router.put('/services/:id/cookie-file', async (req, res) => {
    try {
        const svc = await get('SELECT id, name, slug, cookie_file FROM services WHERE id = ?', [req.params.id]);
        if (!svc) return res.status(404).json({ error: 'Service not found' });
        if (!svc.cookie_file) return res.status(400).json({ error: 'This service has no cookie file configured.' });
        if (!isSafeCookiePath(svc.cookie_file)) {
            return res.status(400).json({ error: 'Configured cookie file path is unsafe.' });
        }

        let pasted = (req.body && req.body.cookie || '').toString().trim();
        if (!pasted) return res.status(400).json({ error: 'Empty paste.' });

        // Strip surrounding quotes and a leading "Cookie:" header, since
        // admins sometimes copy that whole line from devtools' Network tab.
        pasted = pasted.replace(/^['"]/, '').replace(/['"]$/, '').trim();
        pasted = pasted.replace(/^cookie:\s*/i, '');

        // Detect whether this service uses Supabase chunked cookies.
        // We default to "yes" if the existing file already has them, OR
        // the slug is in our known-Supabase set, OR the paste itself
        // contains the marker. Otherwise we use the simpler generic
        // parser.
        const isSupabase = (
            /writehuman/i.test(svc.slug || '')
            || /sb-[a-z0-9]+-auth-token/i.test(pasted)
            || (fs.existsSync(svc.cookie_file) && /sb-[a-z0-9]+-auth-token/i.test(fs.readFileSync(svc.cookie_file, 'utf8')))
        );

        let cookieLine;
        let orderedKeys;
        let meta;

        if (isSupabase) {
        // ---- Chunked Supabase cookies ----
        // Modern Supabase splits long auth blobs across multiple cookies:
        //   sb-{ref}-auth-token.0=base64-...
        //   sb-{ref}-auth-token.1=...
        //   sb-{ref}-auth-token.2=...
        // The browser's "Application" tab shows them as separate rows.
        // Admins frequently copy/paste all of them at once and the rows
        // arrive concatenated with various separators (newline, no
        // separator at all, weird "or these?" tokens between blocks).
        //
        // Strategy: be aggressively tolerant. We use a multi-pass parser:
        //   1. Find every `sb-...-auth-token(.N)?` marker and grab the
        //      following base64 blob, regardless of what separator is
        //      between them.
        //   2. Find any standalone UUIDs (xxxxxxxx-xxxx-xxxx-xxxx-...)
        //      and treat them as candidate session-token / anon_id values.
        //   3. Find any explicit `name=value` pairs the admin pasted.
        const parsed = {};
        const chunked = {};

        // (1) Auth-token chunks. We split the paste on every occurrence
        // of the cookie-name marker (`sb-..-auth-token` with optional
        // `.N`) and take everything between markers as candidate value.
        // Then trim non-base64 trailing junk.
        //
        // This handles all the messy paste shapes admins produce:
        //   - "sb-x-auth-token.0base64-eyJ...sb-x-auth-token.1abc..."
        //   - "sb-x-auth-token.0=base64-eyJ...; sb-x-auth-token.1=abc..."
        //   - the literal junk text users type between chunks
        const MARKER_RE = /sb-([a-z0-9]+)-auth-token(?:\.(\d+))?/gi;
        const markers = [];
        let mm;
        while ((mm = MARKER_RE.exec(pasted)) !== null) {
            markers.push({ ref: mm[1], idx: mm[2] != null ? parseInt(mm[2], 10) : 0, start: mm.index, end: mm.index + mm[0].length });
        }

        let detectedRef = null;
        for (let i = 0; i < markers.length; i++) {
            const mk = markers[i];
            detectedRef = detectedRef || mk.ref;
            const valStart = mk.end;
            const valEnd = (i + 1 < markers.length) ? markers[i + 1].start : pasted.length;
            let raw = pasted.substring(valStart, valEnd);

            raw = raw.replace(/^\s*=\s*/, '');

            let val = '';
            const prefix = raw.match(/^base64-/i);
            if (prefix) {
                val = prefix[0];
                raw = raw.substring(prefix[0].length);
            }
            const m2 = raw.match(/^[A-Za-z0-9_+\/=\-]+/);
            if (m2) val += m2[0];
            if (!val) continue;

            const baseName = `sb-${mk.ref}-auth-token`;
            if (!chunked[baseName]) chunked[baseName] = {};
            chunked[baseName][mk.idx] = val;
        }

        // Stitch + decode with retry. We try trimming up to 8 chars off
        // the tail of each chunk to absorb whatever junk admins typed
        // between rows. JSON.parse on the decoded payload is our oracle:
        // when it succeeds and yields a user object, we have the right
        // boundary.
        function tryDecode(stitched) {
            let v = stitched;
            if (v.toLowerCase().startsWith('base64-')) v = v.slice(7);
            v = v.replace(/-/g, '+').replace(/_/g, '/');
            while (v.length % 4) v += '=';
            try {
                const decoded = Buffer.from(v, 'base64').toString('utf8');
                const close = decoded.lastIndexOf('}');
                const candidate = close > 0 ? decoded.substring(0, close + 1) : decoded;
                const obj = JSON.parse(candidate);
                if (obj && (obj.access_token || obj.refresh_token || obj.user)) return obj;
            } catch (_) {}
            return null;
        }

        for (const base of Object.keys(chunked)) {
            const indices = Object.keys(chunked[base]).map(Number).sort((a, b) => a - b);
            // Build the stitched value. Try the obvious case first.
            const rawChunks = indices.map(i => chunked[base][i]);
            const naive = rawChunks.join('');
            let resolved = naive;
            if (!tryDecode(naive)) {
                // Brute force trim trailing chars from each chunk (max 8 each).
                let found = null;
                outer: for (let trim0 = 0; trim0 <= 8; trim0++) {
                    for (let trim1 = 0; trim1 <= 8; trim1++) {
                        const c0 = rawChunks[0] ? rawChunks[0].substring(0, rawChunks[0].length - trim0) : '';
                        const c1 = rawChunks[1] ? rawChunks[1].substring(0, rawChunks[1].length - trim1) : '';
                        const rest = rawChunks.slice(2).join('');
                        const candidate = c0 + c1 + rest;
                        if (tryDecode(candidate)) { found = candidate; break outer; }
                    }
                }
                if (found) resolved = found;
            }
            parsed[base] = resolved;
        }

        // (2) Find name=value pairs the admin pasted explicitly. This
        // catches sb-session-token, wh_anon_id, etc.
        const PAIR_RE = /([a-zA-Z][a-zA-Z0-9_.\-]{1,80})\s*=\s*([^;\n\r,\s][^;\n\r]*)/g;
        let pm;
        while ((pm = PAIR_RE.exec(pasted)) !== null) {
            const name = pm[1].trim();
            const value = pm[2].trim();
            if (!name || !value) continue;
            // Skip auth-token chunked names (we already handled them).
            if (/^sb-[a-z0-9]+-auth-token(?:\.\d+)?$/i.test(name)) continue;
            // Skip if this name is already set by chunk-stitching.
            if (parsed[name]) continue;
            parsed[name] = value;
        }

        // (3) Capture standalone UUIDs as candidate session tokens / anon ids.
        // We only do this if the admin pasted them un-named. We assign
        // the first UUID to sb-session-token, the second to wh_anon_id,
        // matching the typical Supabase + product pattern.
        const UUID_RE = /\b([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\b/gi;
        const seenUuids = new Set();
        const uuids = [];
        let um;
        while ((um = UUID_RE.exec(pasted)) !== null) {
            const u = um[1].toLowerCase();
            if (!seenUuids.has(u)) { seenUuids.add(u); uuids.push(u); }
        }
        if (uuids.length > 0 && !parsed['sb-session-token']) {
            parsed['sb-session-token'] = uuids[0];
        }
        if (uuids.length > 1 && !parsed['wh_anon_id']) {
            parsed['wh_anon_id'] = uuids[1];
        }

        // If we still have no auth-token detected, the paste may be a
        // bare auth-token value with no name at all.
        const authKey = Object.keys(parsed).find(k => /^sb-[a-z0-9]+-auth-token$/i.test(k));
        if (!authKey) {
            const bareJwt = pasted.match(/(?:base64-)?(eyJ[A-Za-z0-9_+\/=\-]{50,})/);
            if (bareJwt) {
                let ref = detectedRef || 'hicfsbrfkzsxbwayibfm';
                if (!detectedRef && fs.existsSync(svc.cookie_file)) {
                    const existing = fs.readFileSync(svc.cookie_file, 'utf8');
                    const m2 = /sb-([a-z0-9]+)-auth-token=/i.exec(existing);
                    if (m2) ref = m2[1];
                }
                parsed[`sb-${ref}-auth-token`] = 'base64-' + bareJwt[1];
            }
        }

        // Build the cookie line — auth-token first for readability.
        const finalAuthKey = Object.keys(parsed).find(k => /^sb-[a-z0-9]+-auth-token$/i.test(k));
        const orderedKeysSb = [];
        if (finalAuthKey) orderedKeysSb.push(finalAuthKey);
        for (const k of Object.keys(parsed)) {
            if (k !== finalAuthKey) orderedKeysSb.push(k);
        }
        cookieLine = orderedKeysSb.map(k => `${k}=${parsed[k]}`).join('; ');
        orderedKeys = orderedKeysSb;

        // Decode to extract expiry — both for validation and for the UI.
        meta = decodeSupabaseAuthCookie(cookieLine);
        if (!meta.expires_at && !meta.has_refresh_token) {
            return res.status(400).json({
                error: 'Could not find a valid Supabase auth-token in your paste. In DevTools → Cookies, click each row whose name starts with "sb-...-auth-token" (look for ".0" / ".1" suffixes too) and copy its full value into the box. The system will stitch them together automatically.'
            });
        }
        } else {
            // ---- Generic cookie parser ----
            // For non-Supabase services (Grok, future ones) we accept any
            // well-formed `name=value; name=value` paste. We do NOT try
            // to decode anything — we just preserve what the admin gave
            // us. Validation: at least one auth-looking pair must exist.
            const PAIR_RE = /([a-zA-Z][a-zA-Z0-9_.\-]{1,80})\s*=\s*([^;\n\r,\s][^;\n\r]*)/g;
            const generic = {};
            let pm;
            while ((pm = PAIR_RE.exec(pasted)) !== null) {
                const name = pm[1].trim();
                const value = pm[2].trim();
                if (!name || !value) continue;
                generic[name] = value;
            }
            const keys = Object.keys(generic);
            if (keys.length === 0) {
                return res.status(400).json({
                    error: 'No "name=value" cookie pairs found in your paste. In DevTools → Application → Cookies, copy each row\'s name and value, separated by ";" — for Grok the important ones are sso, sso-rw, x-anonuserid.'
                });
            }
            // Heuristic: at least one cookie name should look auth-related.
            // We allow any of these patterns to satisfy the check.
            const hasAuthLike = keys.some(k => /^(sso|sso-rw|sb-.*-auth-token|auth|session|token|access_token|refresh_token|x-anonuserid|jwt|.+_session|.+_token)$/i.test(k));
            if (!hasAuthLike) {
                return res.status(400).json({
                    error: 'None of the cookies you pasted look like an auth/session token. For Grok, include at least "sso=...". Cookie names found: ' + keys.join(', ')
                });
            }

            orderedKeys = keys;
            cookieLine = keys.map(k => `${k}=${generic[k]}`).join('; ');
            // Generic services have no Supabase metadata to surface.
            meta = {
                expires_at: null,
                email: null,
                full_name: null,
                has_refresh_token: keys.some(k => /refresh|rw/i.test(k)),
            };
        }

        // Atomic write: temp file + rename. Mode 0600 keeps it private.
        const dir = path.dirname(svc.cookie_file);
        if (!fs.existsSync(dir)) {
            return res.status(500).json({ error: 'Data directory does not exist on this host: ' + dir });
        }
        const tmp = svc.cookie_file + '.tmp';
        fs.writeFileSync(tmp, cookieLine + '\n', { mode: 0o600 });
        fs.renameSync(tmp, svc.cookie_file);
        try { fs.chmodSync(svc.cookie_file, 0o600); } catch (_) {}

        return res.json({
            message: 'Cookie file updated',
            ...meta,
            size_bytes: fs.statSync(svc.cookie_file).size,
            cookie_names: orderedKeys,
        });
    } catch (e) {
        console.error('cookie-file write error:', e);
        return res.status(500).json({ error: 'Write failed: ' + e.message });
    }
});


module.exports = router;

