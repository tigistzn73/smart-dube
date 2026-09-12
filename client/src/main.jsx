import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.jsx';
import './index.css';

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
