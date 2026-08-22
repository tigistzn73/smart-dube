const db = require('../config/database');
require('dotenv').config();

// ============================================================
//  Africa's Talking SMS Gateway — Real SMS to Ethiopian phones
// ============================================================
const AfricasTalking = require('africastalking');

const AT_USERNAME = process.env.AT_USERNAME || 'sandbox';
const AT_API_KEY = process.env.AT_API_KEY || '';
const AT_SENDER_ID = process.env.AT_SENDER_ID || '';
const AT_ENV = process.env.AT_ENV || 'sandbox';

// Only initialize if API key is configured
let smsClient = null;
if (AT_API_KEY && AT_API_KEY !== 'YOUR_AFRICASTALKING_API_KEY_HERE') {
  try {
    const at = AfricasTalking({
      apiKey: AT_API_KEY,
      username: AT_USERNAME
    });
    smsClient = at.SMS;
    console.log(`[SMS GATEWAY] Africa's Talking initialized — Mode: ${AT_ENV.toUpperCase()} | Username: ${AT_USERNAME} | Key: ${AT_API_KEY.substring(0, 10)}... | Sender: ${AT_SENDER_ID || 'None'}`);
  } catch (err) {
    console.error('[SMS GATEWAY] Failed to initialize Africa\'s Talking:', err.message);
  }
} else {
  console.log('[SMS GATEWAY] No API key configured — running in SIMULATION mode.');
}

/**
 * Send a real SMS via Africa's Talking to an Ethiopian phone number.
 * Falls back to simulation/logging if API key is not set.
 */
async function sendSMS({ customerId, phone, message, type }) {
  // 1. Always persist to database regardless of gateway status
  let dbNotificationId = null;
  const allowedTypes = ['CREDIT_ISSUED', 'REMINDER', 'OVERDUE_ALERT', 'PAYMENT_RECEIPT'];
  const dbType = allowedTypes.includes(type) ? type : 'REMINDER';

  try {
    const result = await db.get(`
      INSERT INTO sms_notifications (customer_id, phone, message, type, status)
      VALUES ($1, $2, $3, $4, $5) RETURNING id
    `, [customerId, phone, message, dbType, smsClient ? 'DELIVERED' : 'SIMULATED']);
    dbNotificationId = result.id;
  } catch (dbErr) {
    console.error('[SMS DB Error]:', dbErr.message);
  }

  // 2. Normalize phone to international format for Africa's Talking
  // Ethiopian numbers: 09XXXXXXXX → +2519XXXXXXXX | 0912... → +251912...
  const normalizePhone = (p) => {
    const cleaned = p.replace(/\s+/g, '').replace(/-/g, '');
    if (cleaned.startsWith('+')) return cleaned;
    if (cleaned.startsWith('251')) return `+${cleaned}`;
    if (cleaned.startsWith('0')) return `+251${cleaned.slice(1)}`;
    return `+251${cleaned}`;
  };

  const intlPhone = normalizePhone(phone);
  console.log(`[SMS GATEWAY OUTBOUND] Phone: ${intlPhone} | Type: ${type} | Message: ${message}`);

  // 3. If real SMS client is available, send it
  if (smsClient) {
    try {
      const options = {
        to: [intlPhone],
        message,
        ...(AT_SENDER_ID ? { from: AT_SENDER_ID } : {})
      };

      const response = await smsClient.send(options);
      const recipient = response.SMSMessageData?.Recipients?.[0];
      const status = recipient?.status || 'Unknown';
      const cost = recipient?.cost || 'N/A';
      const messageId = recipient?.messageId || null;

      console.log(`[SMS GATEWAY] Sent ✓ | Status: ${status} | Cost: ${cost} | MsgId: ${messageId}`);

      // Update DB record with real delivery status
      if (dbNotificationId) {
        await db.run(`UPDATE sms_notifications SET status = $1 WHERE id = $2`, 
          [status === 'Success' ? 'DELIVERED' : 'FAILED', dbNotificationId]);
      }

      return {
        success: status === 'Success',
        notificationId: dbNotificationId,
        phone: intlPhone,
        gateway: 'AFRICA_TALKING',
        messageId,
        status,
        cost,
        sentAt: new Date().toISOString()
      };
    } catch (err) {
      console.error('[SMS GATEWAY] Africa\'s Talking send error:', err.message);
      if (dbNotificationId) {
        await db.run(`UPDATE sms_notifications SET status = 'FAILED' WHERE id = $1`, [dbNotificationId]);
      }
      return { success: false, error: err.message, phone: intlPhone };
    }
  }

  // 4. Simulation fallback (no API key configured)
  return {
    success: true,
    simulated: true,
    notificationId: dbNotificationId,
    phone: intlPhone,
    gateway: 'SIMULATION',
    sentAt: new Date().toISOString()
  };
}

/**
 * Generate standard SMS templates
 */
function getTemplate(type, data) {
  switch (type) {
    case 'REMINDER':
      return `[Smart Dube Alert] Dear ${data.customerName}, your Dube credit repayment of ${data.amount} ETB to ${data.storeName} is due on ${data.dueDate}. Pay via Telebirr / CBE Birr to maintain active credit limit.`;
    case 'OVERDUE_ALERT':
      return `[Smart Dube URGENT] ${data.customerName}, your Dube debt of ${data.amount} ETB at ${data.storeName} is OVERDUE! New credit purchases are currently RESTRICTED. Please settle immediately.`;
    case 'PAYMENT_RECEIPT':
      return `[Smart Dube Receipt] Thank you ${data.customerName}! Payment of ${data.amount} ETB via ${data.gateway} received for ${data.storeName}. Ref: ${data.refCode}. Remaining balance: ${data.remainingBalance} ETB.`;
    case 'CREDIT_ISSUED':
      return `[Smart Dube Notification] New credit transaction logged at ${data.storeName}: ${data.amount} ETB on ${data.date}. Total Balance: ${data.totalBalance} ETB. Due: ${data.dueDate}.`;
    default:
      return data.customMessage || 'Smart Dube ledger notification.';
  }
}

async function getSMSHistory(customerId = null) {
  if (customerId) {
    return await db.all('SELECT * FROM sms_notifications WHERE customer_id = $1 ORDER BY sent_at DESC', [customerId]);
  }
  return await db.all('SELECT * FROM sms_notifications ORDER BY sent_at DESC LIMIT 100');
}

module.exports = {
  sendSMS,
  getTemplate,
  getSMSHistory
};
