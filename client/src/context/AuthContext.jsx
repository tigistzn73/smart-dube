import React, { createContext, useContext, useState, useEffect } from 'react';

const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [token, setToken] = useState(localStorage.getItem('smart_dube_token') || null);
  const [user, setUser] = useState(() => {
    try {
      const saved = localStorage.getItem('smart_dube_user');
      return saved ? JSON.parse(saved) : null;
    } catch {
      return null;
    }
  });
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (token) {
      fetch('/api/auth/me', {
        headers: { Authorization: `Bearer ${token}` }
      })
        .then(res => res.json())
        .then(data => {
          if (data && data.user) {
            setUser(data.user);
            localStorage.setItem('smart_dube_user', JSON.stringify(data.user));
          } else if (data && data.error && (data.error.includes('expired') || data.error.includes('denied'))) {
            logout();
          }
        })
        .catch(err => {
          console.warn('[Auth] Session check network note:', err.message);
        });
    }
  }, [token]);

  const loginWithToken = (newToken, userData) => {
    localStorage.setItem('smart_dube_token', newToken);
    if (userData) {
      localStorage.setItem('smart_dube_user', JSON.stringify(userData));
    }
    setToken(newToken);
    setUser(userData);
  };

  const logout = () => {
    localStorage.removeItem('smart_dube_token');
    localStorage.removeItem('smart_dube_user');
    setToken(null);
    setUser(null);
  };

  // Quick Demo Login Switcher for evaluators
  const switchDemoRole = async (roleType) => {
    let phone = '+251911223344';
    let password = 'merchant123';

    if (roleType === 'ADMIN') {
      phone = '+251911000111';
      password = 'admin123';
    } else if (roleType === 'CUSTOMER') {
      phone = '+251933445566';
      password = 'customer123';
    }

    try {
      const res = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone, password })
      });
      const data = await res.json();
      if (data.token) {
        loginWithToken(data.token, data.user);
      }
    } catch (err) {
      console.error('Demo switch error:', err);
    }
  };

  // Register new user account
  const register = async ({ fullName, phone, email, role, password, faydaId, storeName, businessLicenseNo, address, photoUrl }) => {
    const res = await fetch('/api/auth/register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ fullName, phone, email, role, password, faydaId, storeName, businessLicenseNo, address, photoUrl })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Registration failed.');
    if (data.token && data.user) {
      loginWithToken(data.token, data.user);
    }
    return data;
  };

  // Forgot password - request OTP reset PIN
  const forgotPassword = async (phone) => {
    const res = await fetch('/api/auth/forgot-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ phone })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Failed to send reset PIN.');
    return data;
  };

  // Reset password with OTP code
  const resetPassword = async (phone, otpCode, newPassword) => {
    const res = await fetch('/api/auth/reset-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ phone, otpCode, newPassword })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Password reset failed.');
    loginWithToken(data.token, data.user);
    return data;
  };

  return (
    <AuthContext.Provider value={{ user, token, loading, loginWithToken, logout, switchDemoRole, register, forgotPassword, resetPassword }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);
