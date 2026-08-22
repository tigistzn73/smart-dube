const app = require('./app');
const os = require('os');

const PORT = process.env.PORT || 5000;

// Get local network IP for display
function getLocalIP() {
  const interfaces = os.networkInterfaces();
  for (const name of Object.keys(interfaces)) {
    for (const iface of interfaces[name]) {
      if (iface.family === 'IPv4' && !iface.internal) {
        return iface.address;
      }
    }
  }
  return 'localhost';
}

app.listen(PORT, '0.0.0.0', () => {
  const localIP = getLocalIP();
  console.log(`================================================`);
  console.log(`🚀 Smart Dube API + Frontend running on port ${PORT}`);
  console.log(`📍 Local:   http://localhost:${PORT}/`);
  console.log(`📍 Network: http://${localIP}:${PORT}/`);
  console.log(`================================================`);
});
