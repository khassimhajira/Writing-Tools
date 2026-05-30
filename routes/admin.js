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
        } catch (e) {
            // If the DB check itself errors, fail closed — safer than letting a stale JWT through.
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
    const { name, target_url, icon_svg, text_svg, injection_js, amember_product_id, daily_limit, billable_path } = req.body;
    const slug = (req.body.slug || '').trim();
    const dl = (daily_limit === '' || daily_limit == null) ? null : parseInt(daily_limit, 10);
    const bp = typeof billable_path === 'string' ? billable_path.trim() : '';
    try {
        await run(`INSERT INTO services (name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id, daily_limit, billable_path) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`, 
                   [name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id || null, Number.isFinite(dl) && dl > 0 ? dl : null, bp || null]);
        res.status(201).json({ message: 'Service added' });
    } catch(e) { res.status(500).json({ error: 'Database error: ' + e.message }); }
});

// Update service
router.put('/services/:id', async (req, res) => {
    const { name, target_url, icon_svg, text_svg, injection_js, amember_product_id, daily_limit, billable_path } = req.body;
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

    try {
        const params = [name, slug, target_url, icon_svg, text_svg, injection_js, amember_product_id];
        let sql = `UPDATE services SET name = ?, slug = ?, target_url = ?, icon_svg = ?, text_svg = ?, injection_js = ?, amember_product_id = ?${dlSql}${bpSql} WHERE id = ?`;
        if (dlHasParam) params.push(dlParam);
        if (bpHasParam) params.push(bpParam);
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


module.exports = router;

