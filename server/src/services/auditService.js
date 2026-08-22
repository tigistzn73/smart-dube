const db = require('../config/database');

async function logAudit({ userId, actorName, action, resource, details = {}, ipAddress = '127.0.0.1' }) {
  try {
    await db.run(`
      INSERT INTO audit_logs (user_id, actor_name, action, resource, details_json, ip_address)
      VALUES ($1, $2, $3, $4, $5, $6)
    `, [userId || null, actorName || 'SYSTEM', action, resource, JSON.stringify(details), ipAddress]);
  } catch (err) {
    console.error('Audit Logging Error:', err.message);
  }
}

async function getAuditLogs(limit = 100) {
  try {
    const rows = await db.all(`
      SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT $1
    `, [limit]);
    
    return rows.map(row => ({
      ...row,
      details: row.details_json ? JSON.parse(row.details_json) : {}
    }));
  } catch (err) {
    console.error('Failed to retrieve audit logs:', err.message);
    return [];
  }
}

module.exports = {
  logAudit,
  getAuditLogs
};
