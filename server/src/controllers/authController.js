const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const db = require('../config/database');
const { JWT_SECRET } = require('../middleware/auth');
const { logAudit } = require('../services/auditService');

// Account Lockout Protection (3 failed attempts -> 5 minutes lockout)
const loginAttemptTracker = new Map();
const MAX_FAILED_ATTEMPTS = 3;
const LOCKOUT_DURATION_MS = 5 * 60 * 1000; // 5 minutes

async function registerUser(req, res) {
  const { fullName, phone, email, role, password, faydaId, storeName, businessLicenseNo, address } = req.body;

  try {
    const existing = await db.get('SELECT id FROM users WHERE phone = $1 OR (email IS NOT NULL AND email = $2)', [phone, email || '']);
    if (existing) {
      return res.status(400).json({ error: 'A user with this phone number or email already exists.' });
    }

    const { photoUrl } = req.body;
    const userPhoto = (photoUrl && photoUrl.trim())
      ? photoUrl
      : `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(fullName)}`;

    const salt = bcrypt.genSaltSync(10);
    const passwordHash = bcrypt.hashSync(password, salt);

    const result = await db.get(`
      INSERT INTO users (full_name, phone, email, role, password_hash, fayda_id, photo_url)
      VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id
    `, [fullName, phone, email || null, role, passwordHash, faydaId || null, userPhoto]);
    const userId = result.id;

    let merchantInfo = null;
    if (role === 'MERCHANT') {
      const mResult = await db.get(`
        INSERT INTO merchants (user_id, store_name, business_license_no, address, kyc_status)
        VALUES ($1, $2, $3, $4, 'PENDING') RETURNING id
      `, [userId, storeName || `${fullName}'s Shop`, businessLicenseNo || 'LIC-PENDING', address || 'Addis Ababa']);
      merchantInfo = { id: mResult.id, kycStatus: 'PENDING' };
    } else if (role === 'CUSTOMER') {
      const existingCp = await db.get('SELECT id FROM customer_profiles WHERE phone = $1', [phone]);
      if (existingCp) {
        await db.run('UPDATE customer_profiles SET user_id = $1, photo_url = $2 WHERE id = $3', 
          [userId, userPhoto, existingCp.id]);
      }
    }

    // Fire and forget audit log (audit log function itself is async but we don't need to block response)
    logAudit({
      userId,
      actorName: fullName,
      action: 'USER_REGISTER',
      resource: `User #${userId} (${role})`,
      details: { role, phone, storeName }
    });

    const token = jwt.sign({ id: userId, fullName, phone, role }, JWT_SECRET, { expiresIn: '7d' });

    res.status(201).json({
      message: 'User registered successfully',
      token,
      user: { id: userId, fullName, phone, role, merchant: merchantInfo }
    });
  } catch (err) {
    console.error('Register Error:', err);
    res.status(500).json({ error: 'Server error during user registration.' });
  }
}

async function loginUser(req, res) {
  const { phone, password } = req.body;
  const loginKey = String(phone || '').trim().toLowerCase();
  const now = Date.now();

  try {
    // 1. Check if identifier is currently locked out
    const attemptRecord = loginAttemptTracker.get(loginKey) || { attempts: 0, lockUntil: null };
    if (attemptRecord.lockUntil && now < attemptRecord.lockUntil) {
      const remainingMs = attemptRecord.lockUntil - now;
      const remainingMinutes = Math.ceil(remainingMs / (60 * 1000));
      const remainingSeconds = Math.ceil(remainingMs / 1000);

      logAudit({
        userId: null,
        actorName: 'Locked Account User',
        action: 'LOGIN_BLOCKED_ACCOUNT_LOCKED',
        resource: 'AUTH',
        details: { phone, remainingMinutes, remainingSeconds }
      });

      return res.status(429).json({
        error: `Account is temporarily locked due to 3 consecutive failed password attempts. Please try again in ${remainingMinutes} minute${remainingMinutes > 1 ? 's' : ''} (${remainingSeconds}s).`,
        locked: true,
        remainingSeconds,
        remainingMinutes
      });
    }

    // Reset expired lockout
    if (attemptRecord.lockUntil && now >= attemptRecord.lockUntil) {
      attemptRecord.attempts = 0;
      attemptRecord.lockUntil = null;
      loginAttemptTracker.delete(loginKey);
    }

    const user = await db.get('SELECT * FROM users WHERE phone = $1', [phone]);
    const isMatch = user ? bcrypt.compareSync(password, user.password_hash) : false;

    if (!user || !isMatch) {
      attemptRecord.attempts = (attemptRecord.attempts || 0) + 1;

      if (attemptRecord.attempts >= MAX_FAILED_ATTEMPTS) {
        attemptRecord.lockUntil = now + LOCKOUT_DURATION_MS;
        loginAttemptTracker.set(loginKey, attemptRecord);

        logAudit({
          userId: user ? user.id : null,
          actorName: user ? user.full_name : 'Unknown User',
          action: 'ACCOUNT_LOCKED_5_MINUTES',
          resource: 'AUTH',
          details: { phone, failedAttempts: attemptRecord.attempts, lockoutMinutes: 5 }
        });

        return res.status(429).json({
          error: 'Too many failed password attempts (3 times). Your account is locked for 5 minutes for security.',
          locked: true,
          remainingSeconds: 300,
          remainingMinutes: 5
        });
      } else {
        loginAttemptTracker.set(loginKey, attemptRecord);
        const attemptsLeft = MAX_FAILED_ATTEMPTS - attemptRecord.attempts;

        if (user) {
          logAudit({
            userId: user.id,
            actorName: user.full_name,
            action: 'FAILED_LOGIN',
            resource: 'AUTH',
            details: { phone, attemptsLeft }
          });
        }

        return res.status(401).json({
          error: `Invalid phone number or password credentials. (${attemptsLeft} attempt${attemptsLeft > 1 ? 's' : ''} remaining before 5-minute lockout)`
        });
      }
    }

    // Reset attempt tracker on successful login
    loginAttemptTracker.delete(loginKey);

    let merchant = null;
    if (user.role === 'MERCHANT') {
      merchant = await db.get('SELECT * FROM merchants WHERE user_id = $1', [user.id]);
    }

    let customerProfile = null;
    if (user.role === 'CUSTOMER') {
      customerProfile = await db.get('SELECT * FROM customer_profiles WHERE user_id = $1 OR phone = $2', [user.id, user.phone]);
    }

    const token = jwt.sign(
      { 
        id: user.id, 
        fullName: user.full_name, 
        phone: user.phone, 
        role: user.role,
        merchantId: merchant ? merchant.id : null,
        customerId: customerProfile ? customerProfile.id : null
      }, 
      JWT_SECRET, 
      { expiresIn: '7d' }
    );

    logAudit({
      userId: user.id,
      actorName: user.full_name,
      action: 'LOGIN_SUCCESS',
      resource: 'AUTH',
      details: { role: user.role }
    });

    res.json({
      message: 'Login successful',
      token,
      user: {
        id: user.id,
        fullName: user.full_name,
        phone: user.phone,
        role: user.role,
        faydaId: user.fayda_id,
        photo_url: user.photo_url,
        photoUrl: user.photo_url,
        merchant,
        customerProfile
      }
    });
  } catch (err) {
    console.error('Login Error:', err);
    res.status(500).json({ error: 'Server error during user login.' });
  }
}

async function getMe(req, res) {
  try {
    const user = await db.get('SELECT id, full_name, phone, email, role, fayda_id, photo_url FROM users WHERE id = $1', [req.user.id]);
    if (!user) return res.status(404).json({ error: 'User not found' });

    let merchant = null;
    let customerProfile = null;

    if (user.role === 'MERCHANT') {
      merchant = await db.get('SELECT * FROM merchants WHERE user_id = $1', [user.id]);
    } else if (user.role === 'CUSTOMER') {
      customerProfile = await db.get('SELECT * FROM customer_profiles WHERE user_id = $1 OR phone = $2', [user.id, user.phone]);
    }

    res.json({ user: { id: user.id, fullName: user.full_name, phone: user.phone, email: user.email, role: user.role, faydaId: user.fayda_id, photo_url: user.photo_url, photoUrl: user.photo_url, merchant, customerProfile } });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
}

async function forgotPassword(req, res) {
  const { phone } = req.body;

  try {
    const user = await db.get('SELECT * FROM users WHERE phone = $1', [phone]);
    if (!user) {
      return res.status(404).json({ error: 'No account found with this phone number.' });
    }

    // Generate 6-digit OTP PIN
    const resetToken = String(Math.floor(100000 + Math.random() * 900000));
    const expiresAt = new Date(Date.now() + 15 * 60 * 1000).toISOString(); // 15 minutes

    // Save to user record
    await db.run('UPDATE users SET reset_token = $1, reset_token_expires = $2 WHERE id = $3', [resetToken, expiresAt, user.id]);

    // Send SMS with OTP
    const { sendSMS } = require('../services/smsService');
    await sendSMS({
      customerId: user.id,
      phone: user.phone,
      message: `[Smart Dube Security] Your password reset OTP PIN is: ${resetToken}. This code expires in 15 minutes. Do not share this code with anyone.`,
      type: 'REMINDER'
    });

    logAudit({
      userId: user.id,
      actorName: user.full_name,
      action: 'PASSWORD_RESET_REQUESTED',
      resource: 'AUTH',
      details: { phone, otpSent: true }
    });

    res.json({
      message: 'A 6-digit OTP reset PIN has been sent to your phone number via SMS.',
      phone: user.phone,
      // Include OTP in response for demo/evaluation purposes only
      _demoOTP: resetToken
    });
  } catch (err) {
    console.error('Forgot Password Error:', err);
    res.status(500).json({ error: 'Server error during password reset request.' });
  }
}

async function resetPassword(req, res) {
  const { phone, otpCode, newPassword } = req.body;

  try {
    const user = await db.get('SELECT * FROM users WHERE phone = $1 AND reset_token = $2', [phone, otpCode]);
    if (!user) {
      return res.status(400).json({ error: 'Invalid OTP code or phone number. Please request a new reset PIN.' });
    }

    // Check expiration
    if (user.reset_token_expires && new Date(user.reset_token_expires) < new Date()) {
      return res.status(400).json({ error: 'OTP reset code has expired. Please request a new one (valid for 15 minutes).' });
    }

    // Hash new password
    const salt = bcrypt.genSaltSync(10);
    const newHash = bcrypt.hashSync(newPassword, salt);

    // Update password and clear reset token
    await db.run('UPDATE users SET password_hash = $1, reset_token = NULL, reset_token_expires = NULL WHERE id = $2', [newHash, user.id]);

    logAudit({
      userId: user.id,
      actorName: user.full_name,
      action: 'PASSWORD_RESET_SUCCESS',
      resource: 'AUTH',
      details: { phone }
    });

    // Issue new JWT token so user is instantly logged in
    const token = jwt.sign(
      { id: user.id, fullName: user.full_name, phone: user.phone, role: user.role },
      JWT_SECRET,
      { expiresIn: '7d' }
    );

    res.json({
      message: 'Password reset successful! You are now logged in.',
      token,
      user: {
        id: user.id,
        fullName: user.full_name,
        phone: user.phone,
        role: user.role
      }
    });
  } catch (err) {
    console.error('Reset Password Error:', err);
    res.status(500).json({ error: 'Server error during password reset.' });
  }
}

module.exports = {
  registerUser,
  loginUser,
  getMe,
  forgotPassword,
  resetPassword
};
