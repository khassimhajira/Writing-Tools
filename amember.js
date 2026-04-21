const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

const amemberConfig = {
    host: process.env.AMEMBER_DB_HOST || 'localhost',
    user: process.env.AMEMBER_DB_USER,
    password: process.env.AMEMBER_DB_PASS,
    database: process.env.AMEMBER_DB_NAME,
    prefix: process.env.AMEMBER_DB_PREFIX || 'am_'
};

let pool;
if (process.env.AMEMBER_ENABLE === 'true') {
    pool = mysql.createPool({
        host: amemberConfig.host,
        user: amemberConfig.user,
        password: amemberConfig.password,
        database: amemberConfig.database,
        waitForConnections: true,
        connectionLimit: 10,
        queueLimit: 0
    });
}

async function checkAmemberAuth(loginOrEmail, password) {
    if (process.env.AMEMBER_ENABLE !== 'true' || !pool) {
        console.log('[aMember Bridge] Bridge is DISABLED or pool not ready.');
        return null;
    }

    try {
        const prefix = amemberConfig.prefix;
        console.log(`[aMember Bridge] Step 1: Searching for user: ${loginOrEmail}`);
        
        // Search by email or username (login)
        const [users] = await pool.execute(
            `SELECT user_id, login, email, pass, name_f, name_l FROM ${prefix}user WHERE email = ? OR login = ? LIMIT 1`, 
            [loginOrEmail, loginOrEmail]
        );

        if (users.length === 0) {
            console.log(`[aMember Bridge] Step 2: User NOT FOUND in aMember database.`);
            return null;
        }

        const amUser = users[0];
        const bcrypt = require('bcryptjs');
        
        console.log(`[aMember Bridge] Step 2: User found: ${amUser.login} (ID: ${amUser.user_id})`);

        // Handle different aMember password formats
        let amPass = amUser.pass;
        let valid = false;

        if (amPass.startsWith('$2y$') || amPass.startsWith('$2a$')) {
            const checkPass = amPass.startsWith('$2y$') ? '$2a$' + amPass.substring(4) : amPass;
            valid = await bcrypt.compare(password, checkPass);
        } else {
            console.log(`[aMember Bridge] Step 3: WARNING - Unknown password format: ${amPass.substring(0, 5)}...`);
        }

        if (!valid) {
            console.log(`[aMember Bridge] Step 3: Password MISMATCH for ${amUser.login}`);
            return null;
        }

        console.log(`[aMember Bridge] Step 3: Password VERIFIED.`);

        // Check for active access
        console.log(`[aMember Bridge] Step 4: Checking for active subscription...`);
        const [access] = await pool.execute(
            `SELECT access_id FROM ${prefix}access 
             WHERE user_id = ? AND (expire_date >= CURDATE() OR expire_date IS NULL)
             LIMIT 1`,
            [amUser.user_id]
        );

        if (access.length === 0) {
            console.log(`[aMember Bridge] Step 4: User has NO active subscription in aMember.`);
            return { error: 'No active subscription found in aMember Pro.' };
        }

        console.log(`[aMember Bridge] Step 5: Access CONFIRMED. Logging in...`);
        return {
            username: amUser.login || amUser.name_f,
            email: amUser.email,
            password_hash: amPass
        };
    } catch (e) {
        console.error('[aMember Bridge] CRITICAL ERROR:', e);
        return null;
    }
}

async function getAmemberUsers() {
    if (process.env.AMEMBER_ENABLE !== 'true' || !pool) return [];

    try {
        const prefix = amemberConfig.prefix;
        const [users] = await pool.execute(
            `SELECT u.user_id, u.login, u.email, u.name_f, u.name_l, 
             (SELECT COUNT(*) FROM ${prefix}access a WHERE a.user_id = u.user_id AND a.begin_date <= CURDATE() AND a.expire_date >= CURDATE()) as has_access
             FROM ${prefix}user u`
        );
        return users;
    } catch (e) {
        console.error('aMember List Error:', e);
        return [];
    }
}

module.exports = { checkAmemberAuth, getAmemberUsers };
