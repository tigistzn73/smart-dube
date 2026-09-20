import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.jsx';
import './index.css';

// Transparent WordPress REST API fetch interceptor
if (typeof window !== 'undefined') {
  const originalFetch = window.fetch;
  window.fetch = function (resource, config) {
    if (typeof resource === 'string' && resource.startsWith('/api')) {
      if (window.smartDubeSettings && window.smartDubeSettings.apiUrl) {
        const base = window.smartDubeSettings.apiUrl.replace(/\/+$/, '');
        const path = resource.replace(/^\/api\//, '').replace(/^\//, '');
        resource = `${base}/${path}`;

        config = config || {};
        const existingHeaders = config.headers || {};
        const headers = (typeof existingHeaders.set === 'function')
          ? existingHeaders
          : { ...existingHeaders };

        if (window.smartDubeSettings.nonce) {
          if (typeof headers.set === 'function') {
            if (!headers.has('X-WP-Nonce')) headers.set('X-WP-Nonce', window.smartDubeSettings.nonce);
          } else {
            if (!headers['X-WP-Nonce']) headers['X-WP-Nonce'] = window.smartDubeSettings.nonce;
          }
        }
        config.headers = headers;
      }
    }
    return originalFetch.call(this, resource, config);
  };
}

function mountSmartDube() {
  const rootEl = document.getElementById('root') || document.querySelector('.smart-dube-app-wrapper');
  if (rootEl && !rootEl.__smartDubeMounted) {
    rootEl.__smartDubeMounted = true;
    ReactDOM.createRoot(rootEl).render(
      <React.StrictMode>
        <App />
      </React.StrictMode>
    );
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountSmartDube);
} else {
  mountSmartDube();
}
