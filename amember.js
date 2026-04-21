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
    if (process.env.AMEMBER_ENABLE !== 'true' || !pool) return null;

    try {
        const prefix = amemberConfig.prefix;
        // Search by email or username (login)
        const [users] = await pool.execute(
            `SELECT user_id, login, email, pass, name_f, name_l FROM ${prefix}user WHERE email = ? OR login = ? LIMIT 1`, 
            [loginOrEmail, loginOrEmail]
        );

        if (users.length === 0) return null;

        const amUser = users[0];
        
        // aMember 6+ uses PHP password_hash (bcrypt compatible)
        // Note: PHP's $2y$ is identical to $2a$ for bcryptjs
        let amPass = amUser.pass;
        if (amPass.startsWith('$2y$')) {
            amPass = '$2a$' + amPass.substring(4);
        }

        const valid = await bcrypt.compare(password, amPass);
        if (!valid) return null;

        // Check for active access
        // We look for any active record in the access table
        const [access] = await pool.execute(
            `SELECT access_id FROM ${prefix}access 
             WHERE user_id = ? AND begin_date <= CURDATE() AND expire_date >= CURDATE() 
             LIMIT 1`,
            [amUser.user_id]
        );

        if (access.length === 0) {
            console.log(`aMember user ${amUser.login} found but has no active subscription.`);
            return { error: 'No active subscription found in aMember Pro.' };
        }

        return {
            username: amUser.login || amUser.name_f,
            email: amUser.email,
            password_hash: amPass // We can use this to sync to local DB
        };
    } catch (e) {
        console.error('aMember Auth Bridge Error:', e);
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
