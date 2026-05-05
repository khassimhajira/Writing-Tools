require('dotenv').config();
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

// --- MYSQL DATABASE CONNECTION ---
// We use the same credentials already in your .env for aMember
const pool = mysql.createPool({
    host: process.env.AMEMBER_DB_HOST || 'localhost',
    user: process.env.AMEMBER_DB_USER,
    password: process.env.AMEMBER_DB_PASS,
    database: process.env.AMEMBER_DB_NAME,
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
});

console.log('[Database] Connected to MySQL:', process.env.AMEMBER_DB_NAME);

async function initializeDB() {
    try {
        // Services Table
        await pool.query(`CREATE TABLE IF NOT EXISTS services (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name TEXT NOT NULL,
            slug VARCHAR(191) UNIQUE NOT NULL,
            target_url TEXT NOT NULL,
            icon_svg LONGTEXT,
            text_svg LONGTEXT,
            injection_js LONGTEXT,
            amember_product_id VARCHAR(191)
        )`);

        // Cookies Table
        await pool.query(`CREATE TABLE IF NOT EXISTS cookies (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name TEXT NOT NULL,
            data LONGTEXT NOT NULL,
            service_id INT,
            FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE
        )`);

        // Users Table
        await pool.query(`CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(191) UNIQUE NOT NULL,
            email VARCHAR(191) UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role VARCHAR(50) DEFAULT 'student',
            status VARCHAR(50) DEFAULT 'active',
            assigned_cookie_id INT,
            FOREIGN KEY(assigned_cookie_id) REFERENCES cookies(id) ON DELETE SET NULL
        )`);

        // User Assignments Table
        await pool.query(`CREATE TABLE IF NOT EXISTS user_assignments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            service_id INT NOT NULL,
            cookie_id INT,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
            FOREIGN KEY(cookie_id) REFERENCES cookies(id) ON DELETE SET NULL,
            UNIQUE(user_id, service_id)
        )`);

        console.log('[Database] MySQL Tables Verified.');
    } catch (err) {
        console.error('[Database] Initialization Error:', err);
    }
}

// Ensure tables exist on start
initializeDB();

// --- HELPER FUNCTIONS ---
const query = async (sql, params = []) => {
    // Convert SQLite "?" placeholders to MySQL "?" (they are the same in mysql2)
    const [rows] = await pool.execute(sql, params);
    return rows;
};

const run = async (sql, params = []) => {
    const [result] = await pool.execute(sql, params);
    return result;
};

const get = async (sql, params = []) => {
    const [rows] = await pool.execute(sql, params);
    return rows[0] || null;
};

module.exports = { pool, query, run, get };
