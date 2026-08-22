const { sendSMS } = require('../src/services/smsService');
require('dotenv').config({ path: '../.env' });

// Get arguments from command line
const testPhone = process.argv[2];
const testMessage = process.argv[3] || "Hello! This is a test SMS from your Smart Dube system via Africa's Talking Gateway.";

if (!testPhone) {
  console.error('\n❌ Error: Please specify a recipient phone number.');
  console.log('Usage: node test_sms.js <EthiopianPhoneNumber>');
  console.log('Example: node test_sms.js 0911223344\n');
  process.exit(1);
}

(async () => {
  console.log('==================================================');
  console.log('📬 SMART DUBE — SMS GATEWAY DIAGNOSTICS');
  console.log('==================================================');
  console.log(`Configured Username:  ${process.env.AT_USERNAME || 'Not configured (fallback to sandbox)'}`);
  console.log(`Sender ID / Shortcode: ${process.env.AT_SENDER_ID || 'None'}`);
  console.log(`Environment Type:     ${process.env.AT_ENV || 'sandbox'}`);
  console.log(`Target Phone Number:  ${testPhone}`);
  console.log('==================================================\n');

  console.log('Initiating test SMS dispatch...');
  try {
    const result = await sendSMS({
      customerId: null,
      phone: testPhone,
      message: testMessage,
      type: 'DIAGNOSTICS'
    });

    if (result.simulated) {
      console.log('\n⚠️ Running in SIMULATION MODE.');
      console.log('No real SMS was dispatched. Please set your real "AT_API_KEY" inside "server/.env" first.');
    } else if (result.success) {
      console.log('\n✅ Real SMS dispatched successfully!');
      console.log('--------------------------------------------------');
      console.log(`Status:      ${result.status}`);
      console.log(`Cost:        ${result.cost}`);
      console.log(`Message ID:  ${result.messageId}`);
      console.log(`Gateway:     ${result.gateway}`);
      console.log('--------------------------------------------------');
    } else {
      console.log('\n❌ Dispatch Failed.');
      console.log('Error Details:', result.error || 'Unknown gateway error');
    }
  } catch (err) {
    console.error('\n❌ Fatal Test Error:', err.message);
  }
})();
