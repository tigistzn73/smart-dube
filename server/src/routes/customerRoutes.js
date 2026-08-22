const express = require('express');
const { body } = require('express-validator');
const {
  getCustomerDashboard,
  initiateRepayment,
  generateInstallmentSchedule,
  saveCustomerSchedule
} = require('../controllers/customerController');
const { authenticateToken, authorizeRoles } = require('../middleware/auth');
const { validateResult } = require('../middleware/validate');

const router = express.Router();

router.use(authenticateToken);
router.use(authorizeRoles('CUSTOMER', 'ADMIN', 'MERCHANT'));

router.get('/dashboard', getCustomerDashboard);

router.post(
  '/repay',
  [
    body('transactionId').notEmpty().withMessage('Transaction ID is required'),
    body('customerId').notEmpty().withMessage('Customer profile ID is required'),
    body('amount').isNumeric().withMessage('Repayment amount must be a number'),
    body('paymentGateway').isIn(['TELEBIRR', 'CHAPA', 'CBE_BIRR', 'CASH', 'RECEIPT_UPLOAD']).withMessage('Invalid payment gateway choice'),
    validateResult
  ],
  initiateRepayment
);

router.post('/schedule', generateInstallmentSchedule);
router.post('/schedule/apply', saveCustomerSchedule);

module.exports = router;
