const express = require('express');
const { body } = require('express-validator');
const {
  getMerchantProfile,
  getMerchantCustomers,
  registerCustomerProfile,
  updateCustomerProfile,
  createCreditTransaction,
  getMerchantTransactions,
  triggerSMSReminder,
  approveRepayment
} = require('../controllers/merchantController');
const { authenticateToken, authorizeRoles } = require('../middleware/auth');
const { validateResult } = require('../middleware/validate');

const router = express.Router();

// Merchant role restriction
router.use(authenticateToken);
router.use(authorizeRoles('MERCHANT', 'ADMIN'));

router.get('/profile', getMerchantProfile);
router.get('/customers', getMerchantCustomers);

router.post(
  '/customers',
  [
    body('fullName').notEmpty().withMessage('Customer full name is required'),
    body('phone').notEmpty().withMessage('Customer phone number is required'),
    body('faydaId').notEmpty().withMessage('Fayda ID number is required for KYC compliance'),
    validateResult
  ],
  registerCustomerProfile
);

router.put('/customers/:customerId', updateCustomerProfile);

router.post(
  '/transactions',
  [
    body('customerId').notEmpty().withMessage('Customer selection is required'),
    body('totalAmount').isNumeric().withMessage('Total credit amount must be a valid number'),
    body('dueDate').notEmpty().withMessage('Repayment due date is required'),
    validateResult
  ],
  createCreditTransaction
);

router.get('/transactions', getMerchantTransactions);

router.post(
  '/sms-reminder',
  [
    body('customerId').notEmpty().withMessage('Customer ID is required'),
    validateResult
  ],
  triggerSMSReminder
);

router.post(
  '/approve-repayment',
  [
    body('repaymentId').notEmpty().withMessage('Repayment ID is required'),
    body('action').isIn(['APPROVE', 'REJECT']).withMessage('Action must be APPROVE or REJECT'),
    validateResult
  ],
  approveRepayment
);

module.exports = router;
