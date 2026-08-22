const db = require('../config/database');

async function updateCustomerAvatars() {
  console.log('Updating customer profile photo URLs to generated character avatars...');
  try {
    const profiles = await db.all('SELECT id, full_name, photo_url FROM customer_profiles');
    for (const p of profiles) {
      if (!p.photo_url || p.photo_url.includes('ui-avatars.com')) {
        const newUrl = `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(p.full_name)}`;
        await db.run('UPDATE customer_profiles SET photo_url = $1 WHERE id = $2', [newUrl, p.id]);
        console.log(`✓ Updated customer #${p.id} (${p.full_name}) -> ${newUrl}`);
      }
    }
    console.log('All customer avatars updated successfully!');
  } catch (err) {
    console.error('Avatar update failed:', err);
  } finally {
    process.exit(0);
  }
}

updateCustomerAvatars();
