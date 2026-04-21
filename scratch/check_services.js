const sqlite3 = require('sqlite3').verbose();
const path = require('path');
// Path corrected to look in the root d:\stealthwriter directory
const dbPath = path.resolve(__dirname, '..', 'stealth.db');
console.log('Checking database at:', dbPath);
const db = new sqlite3.Database(dbPath);

db.all("SELECT id, name, slug FROM services", [], (err, rows) => {
    if (err) {
        console.error(err);
    } else {
        console.log(JSON.stringify(rows, null, 2));
    }
    db.close();
});
