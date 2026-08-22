const AfricasTalking = require('africastalking');

const testCombo = async (username, apiKey) => {
  try {
    const at = AfricasTalking({ username, apiKey });
    const result = await at.SMS.send({
      to: ['+251948310748'],
      message: 'Diagnostics test'
    });
    console.log(`SUCCESS for ${username}:`, JSON.stringify(result));
  } catch (e) {
    console.log(`FAIL for ${username}:`, e.message);
  }
};

(async () => {
  const apiKey = 'atsk_1af4c7e9839becbbbfe53f7051c10a4861837597075ff2a9494e4162a217c8be870e6fe8';
  console.log('Testing username "Dbusms"...');
  await testCombo('Dbusms', apiKey);
  console.log('Testing username "sandbox"...');
  await testCombo('sandbox', apiKey);
})();
