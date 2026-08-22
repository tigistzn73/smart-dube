const db = require('../config/database');

async function resetAlayuPhoto() {
  const avatar = 'https://api.dicebear.com/7.x/avataaars/svg?seed=alayu%20alaye';
  await db.run('UPDATE users SET photo_url = $1 WHERE phone = $2', [avatar, '+251900998877']);
  await db.run('UPDATE customer_profiles SET photo_url = $1 WHERE phone = $2', [avatar, '+251900998877']);
  console.log('Reset alayu alaye photo to avatar successfully!');
  process.exit(0);
}

resetAlayuPhoto();
