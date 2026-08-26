const fs = require('fs');
const path = require('path');
const { Pool, types } = require('pg');
const sqlite3 = require('sqlite3').verbose();

// Parse PostgreSQL NUMERIC/DECIMAL as float
types.setTypeParser(1700, function(val) {
  return val === null ? null : parseFloat(val);
});
// Parse PostgreSQL BIGINT as integer
types.setTypeParser(20, function(val) {
  return val === null ? null : parseInt(val, 10);
});

require('dotenv').config({ path: path.resolve(__dirname, '../../.env') });

let pgPool = null;
let sqliteDb = null;
let useSqlite = false;

// Initialize SQLite database
function getSqliteDb() {
  if (!sqliteDb) {
    const dbPath = path.resolve(__dirname, '../../smart_dube.sqlite');
    sqliteDb = new sqlite3.Database(dbPath);
    initSqliteSchema(sqliteDb);
  }
  return sqliteDb;
}

function initSqliteSchema(db) {
  const schema = `
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      full_name TEXT NOT NULL,
      phone TEXT UNIQUE NOT NULL,
      email TEXT,
      role TEXT NOT NULL,
      password_hash TEXT NOT NULL,
      fayda_id TEXT,
      photo_url TEXT,
      reset_token TEXT,
      reset_token_expires TEXT,
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS merchants (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      store_name TEXT NOT NULL,
      business_license_no TEXT NOT NULL,
      address TEXT NOT NULL,
      kyc_status TEXT NOT NULL DEFAULT 'PENDING',
      verified_at TEXT,
      kyc_notes TEXT,
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS customer_profiles (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      merchant_id INTEGER NOT NULL,
      user_id INTEGER,
      full_name TEXT NOT NULL,
      phone TEXT NOT NULL,
      fayda_id TEXT NOT NULL,
      photo_url TEXT,
      credit_limit REAL NOT NULL DEFAULT 5000.00,
      current_balance REAL NOT NULL DEFAULT 0.00,
      status TEXT NOT NULL DEFAULT 'ACTIVE',
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS credit_transactions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      transaction_ref TEXT UNIQUE NOT NULL,
      customer_id INTEGER NOT NULL,
      merchant_id INTEGER NOT NULL,
      items_json TEXT NOT NULL,
      total_amount REAL NOT NULL,
      due_date TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'PENDING',
      notes TEXT,
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS repayments (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      repayment_ref TEXT UNIQUE NOT NULL,
      transaction_id INTEGER,
      customer_id INTEGER NOT NULL,
      merchant_id INTEGER NOT NULL,
      amount REAL NOT NULL,
      payment_gateway TEXT NOT NULL,
      reference_code TEXT NOT NULL,
      receipt_url TEXT,
      status TEXT NOT NULL DEFAULT 'PENDING',
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS sms_notifications (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      customer_id INTEGER,
      phone TEXT NOT NULL,
      message TEXT NOT NULL,
      type TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'SIMULATED',
      sent_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS payment_gateway_logs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      gateway_name TEXT NOT NULL,
      event_type TEXT NOT NULL,
      payload_json TEXT NOT NULL,
      response_status TEXT NOT NULL,
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS audit_logs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      actor_name TEXT NOT NULL,
      action TEXT NOT NULL,
      resource TEXT NOT NULL,
      details_json TEXT NOT NULL,
      ip_address TEXT NOT NULL,
      created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS installment_schedules (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      transaction_id INTEGER,
      customer_id INTEGER NOT NULL,
      merchant_id INTEGER NOT NULL,
      installment_number INTEGER NOT NULL,
      due_date TEXT NOT NULL,
      amount REAL NOT NULL,
      paid_amount REAL DEFAULT 0,
      status TEXT DEFAULT 'PENDING',
      created_at TEXT DEFAULT (datetime('now'))
    );
  `;
  db.exec(schema, (err) => {
    if (err) console.error('[SQLite] Schema init error:', err);
    else console.log('[SQLite] Embedded database tables initialized.');
  });
}

// Convert PostgreSQL query $1, $2 to SQLite ?
function translatePgToSqlite(sql) {
  let sqliteSql = sql.replace(/\$(\d+)/g, '?');
  sqliteSql = sqliteSql.replace(/\bNOW\(\)/gi, "datetime('now')");
  sqliteSql = sqliteSql.replace(/\bTIMESTAMPTZ\b/gi, 'TEXT');
  sqliteSql = sqliteSql.replace(/\bSERIAL PRIMARY KEY\b/gi, 'INTEGER PRIMARY KEY AUTOINCREMENT');
  return sqliteSql;
}

// PostgreSQL Pool setup
if (process.env.DATABASE_URL) {
  pgPool = new Pool({
    connectionString: process.env.DATABASE_URL,
    ssl: { rejectUnauthorized: false }
  });
} else if (process.env.PG_HOST || process.env.PG_PORT) {
  pgPool = new Pool({
    host: process.env.PG_HOST || 'localhost',
    port: parseInt(process.env.PG_PORT || '5433'),
    database: process.env.PG_DATABASE || 'smart_dube_system',
    user: process.env.PG_USER || 'postgres',
    password: process.env.PG_PASSWORD || 'my1919',
    connectionTimeoutMillis: 3000
  });
}

if (pgPool) {
  pgPool.on('error', (err) => {
    console.warn('[DB] PostgreSQL pool notice:', err.message);
  });
}

module.exports = {
  pool: pgPool,

  async all(text, params = []) {
    if (!useSqlite && pgPool) {
      try {
        const res = await pgPool.query(text, params);
        return res.rows;
      } catch (err) {
        if (err.code === 'ECONNREFUSED' || err.code === '28P01' || err.message.includes('connect')) {
          console.warn('[DB] PostgreSQL unreachable, falling back to SQLite...');
          useSqlite = true;
        } else {
          throw err;
        }
      }
    }

    // SQLite fallback
    const sqliteSql = translatePgToSqlite(text);
    return new Promise((resolve, reject) => {
      getSqliteDb().all(sqliteSql, params, (err, rows) => {
        if (err) reject(err);
        else resolve(rows || []);
      });
    });
  },

  async get(text, params = []) {
    if (!useSqlite && pgPool) {
      try {
        const res = await pgPool.query(text, params);
        return res.rows[0];
      } catch (err) {
        if (err.code === 'ECONNREFUSED' || err.code === '28P01' || err.message.includes('connect')) {
          console.warn('[DB] PostgreSQL unreachable, falling back to SQLite...');
          useSqlite = true;
        } else {
          throw err;
        }
      }
    }

    // SQLite fallback
    const sqliteSql = translatePgToSqlite(text);
    const isInsert = /^\s*INSERT\s+INTO/i.test(sqliteSql);
    const hasReturning = /RETURNING\s+/i.test(sqliteSql);

    if (isInsert && hasReturning) {
      const cleanSql = sqliteSql.replace(/\s*RETURNING\s+.*$/i, '');
      return new Promise((resolve, reject) => {
        getSqliteDb().run(cleanSql, params, function (err) {
          if (err) return reject(err);
          resolve({ id: this.lastID });
        });
      });
    }

    return new Promise((resolve, reject) => {
      getSqliteDb().get(sqliteSql, params, (err, row) => {
        if (err) reject(err);
        else resolve(row || null);
      });
    });
  },

  async run(text, params = []) {
    if (!useSqlite && pgPool) {
      try {
        const res = await pgPool.query(text, params);
        return { rowCount: res.rowCount, rows: res.rows };
      } catch (err) {
        if (err.code === 'ECONNREFUSED' || err.code === '28P01' || err.message.includes('connect')) {
          console.warn('[DB] PostgreSQL unreachable, falling back to SQLite...');
          useSqlite = true;
        } else {
          throw err;
        }
      }
    }

    // SQLite fallback
    const sqliteSql = translatePgToSqlite(text);
    return new Promise((resolve, reject) => {
      getSqliteDb().run(sqliteSql, params, function (err) {
        if (err) reject(err);
        else resolve({ rowCount: this.changes, lastID: this.lastID });
      });
    });
  },

  async transaction(callback) {
    if (!useSqlite && pgPool) {
      try {
        const client = await pgPool.connect();
        try {
          await client.query('BEGIN');
          const result = await callback(client);
          await client.query('COMMIT');
          return result;
        } catch (e) {
          await client.query('ROLLBACK');
          throw e;
        } finally {
          client.release();
        }
      } catch (err) {
        if (err.code === 'ECONNREFUSED' || err.code === '28P01' || err.message.includes('connect')) {
          useSqlite = true;
        } else {
          throw err;
        }
      }
    }

    return callback(this);
  }
};
