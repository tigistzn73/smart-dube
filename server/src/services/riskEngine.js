const db = require('../config/database');

/**
 * Evaluate customer credit risk and auto-enforce credit bounds
 * @param {number} customerId 
 * @param {number} requestedNewAmount 
 * @returns {Promise<object>} { allowed: boolean, reason: string|null, updatedStatus: string }
 */
async function evaluateCreditRisk(customerId, requestedNewAmount = 0) {
  const customer = await db.get('SELECT * FROM customer_profiles WHERE id = $1', [customerId]);
  if (!customer) {
    return { allowed: false, reason: 'Customer profile not found.' };
  }

  if (customer.status === 'BLOCKED') {
    return { allowed: false, reason: 'Customer account is explicitly BLOCKED by merchant due to non-repayment.' };
  }

  // Check for overdue transactions
  const currentDate = new Date().toISOString().split('T')[0];
  const overdueTx = await db.all(`
    SELECT * FROM credit_transactions 
    WHERE customer_id = $1 
    AND status IN ('PENDING', 'PARTIALLY_PAID') 
    AND due_date < $2
  `, [customerId, currentDate]);

  if (overdueTx.length > 0) {
    // Auto-update status to RESTRICTED if not already blocked
    if (customer.status === 'ACTIVE') {
      await db.run('UPDATE customer_profiles SET status = $1 WHERE id = $2', ['RESTRICTED', customerId]);
    }
    return { 
      allowed: false, 
      reason: `Credit restricted: Customer has ${overdueTx.length} overdue Dube ledger item(s) past repayment deadline.`,
      isOverdue: true,
      overdueCount: overdueTx.length
    };
  }

  // Check credit limit
  const projectedBalance = parseFloat(customer.current_balance) + requestedNewAmount;
  if (projectedBalance > parseFloat(customer.credit_limit)) {
    const availableCredit = parseFloat(customer.credit_limit) - parseFloat(customer.current_balance);
    return {
      allowed: false,
      reason: `Requested amount (${requestedNewAmount.toFixed(2)} ETB) exceeds available credit limit (${availableCredit.toFixed(2)} ETB remaining of ${parseFloat(customer.credit_limit).toFixed(2)} ETB limit).`,
      availableCredit,
      creditLimit: parseFloat(customer.credit_limit),
      currentBalance: parseFloat(customer.current_balance)
    };
  }

  return {
    allowed: true,
    reason: 'Credit check passed successfully.',
    availableCredit: parseFloat(customer.credit_limit) - parseFloat(customer.current_balance),
    projectedBalance
  };
}

module.exports = {
  evaluateCreditRisk
};
