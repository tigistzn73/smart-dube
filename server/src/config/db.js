const { Pool, types } = require('pg');

// Parse PostgreSQL NUMERIC/DECIMAL (OID 1700) as float instead of string
types.setTypeParser(1700, function(val) {
  return val === null ? null : parseFloat(val);
});

// Parse PostgreSQL BIGINT (OID 20) as integer instead of string
types.setTypeParser(20, function(val) {
  return val === null ? null : parseInt(val, 10);
});

require('dotenv').config({ path: require('path').resolve(__dirname, '../../.env') });

const pool = new Pool({
  host: process.env.PG_HOST || 'localhost',
  port: parseInt(process.env.PG_PORT || '5433'),
  database: process.env.PG_DATABASE || 'smart_dube_system',
  user: process.env.PG_USER || 'postgres',
  password: process.env.PG_PASSWORD || 'my1919',
});

pool.on('error', (err) => {
  console.error('Unexpected error on idle pg client', err);
});

module.exports = {
  pool,
  
  // Helper to query multiple rows (corresponds to better-sqlite3 .all())
  async all(text, params) {
    const res = await pool.query(text, params);
    return res.rows;
  },

  // Helper to query a single row (corresponds to better-sqlite3 .get())
  async get(text, params) {
    const res = await pool.query(text, params);
    return res.rows[0];
  },

  // Helper to run a command (corresponds to better-sqlite3 .run())
  async run(text, params) {
    const res = await pool.query(text, params);
    return {
      rowCount: res.rowCount,
      rows: res.rows
    };
  },

  // Database transaction wrapper
  async transaction(callback) {
    const client = await pool.connect();
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
  }
};
