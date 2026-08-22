const express = require('express');
const { body } = require('express-validator');
const { registerUser, loginUser, getMe, forgotPassword, resetPassword } = require('../controllers/authController');
const { authenticateToken } = require('../middleware/auth');
const { validateResult } = require('../middleware/validate');

const router = express.Router();

router.post(
  '/register',
  [
    body('fullName').notEmpty().withMessage('Full name is required'),
    body('phone').notEmpty().withMessage('Phone number is required'),
    body('password').isLength({ min: 6 }).withMessage('Password must be at least 6 characters'),
    body('role').isIn(['ADMIN', 'MERCHANT', 'CUSTOMER']).withMessage('Role must be ADMIN, MERCHANT, or CUSTOMER'),
    validateResult
  ],
  registerUser
);

router.post(
  '/login',
  [
    body('phone').notEmpty().withMessage('Phone number is required'),
    body('password').notEmpty().withMessage('Password is required'),
    validateResult
  ],
  loginUser
);

router.post(
  '/forgot-password',
  [
    body('phone').notEmpty().withMessage('Phone number is required to receive OTP reset PIN'),
    validateResult
  ],
  forgotPassword
);

router.post(
  '/reset-password',
  [
    body('phone').notEmpty().withMessage('Phone number is required'),
    body('otpCode').notEmpty().isLength({ min: 6, max: 6 }).withMessage('6-digit OTP code is required'),
    body('newPassword').isLength({ min: 6 }).withMessage('New password must be at least 6 characters'),
    validateResult
  ],
  resetPassword
);

router.get('/me', authenticateToken, getMe);

module.exports = router;
