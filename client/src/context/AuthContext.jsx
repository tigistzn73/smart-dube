import React, { createContext, useContext, useState, useEffect } from 'react';

const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(localStorage.getItem('smart_dube_token') || null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (token) {
      fetch('/api/auth/me', {
        headers: { Authorization: `Bearer ${token}` }
      })
        .then(res => res.json())
        .then(data => {
          if (data.user) {
            setUser(data.user);
          } else {
            logout();
          }
        })
        .catch(() => logout())
        .finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, [token]);

  const loginWithToken = (newToken, userData) => {
    localStorage.setItem('smart_dube_token', newToken);
    setToken(newToken);
    setUser(userData);
  };

  const logout = () => {
    localStorage.removeItem('smart_dube_token');
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
