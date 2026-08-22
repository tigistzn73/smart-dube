const db = require('../config/database');
const { processRepayment, processMultiMerchantRepayment } = require('../services/paymentGatewayService');

// Get Customer Balance and Dube Ledger Accounts
async function getCustomerDashboard(req, res) {
  try {
    const userPhone = req.user.phone;
    
    // Find all customer credit profiles linked to this phone across different merchant ledgers
    const profiles = await db.all(`
      SELECT cp.*, m.store_name, m.address as store_address, m.business_license_no
      FROM customer_profiles cp
      JOIN merchants m ON cp.merchant_id = m.id
      WHERE cp.phone = $1 OR cp.user_id = $2
    `, [userPhone, req.user.id]);

    if (profiles.length === 0) {
      return res.json({
        totalBalance: 0.00,
        totalCreditLimit: 0.00,
        availableCredit: 0.00,
        profiles: [],
        pendingTransactions: [],
        repayments: []
      });
    }

    const profileIds = profiles.map(p => p.id);

    // Pending Credit Ledger Items across all merchants
    const pendingTransactions = await db.all(`
      SELECT ct.*, cp.full_name as customer_name, m.store_name
      FROM credit_transactions ct
      JOIN customer_profiles cp ON ct.customer_id = cp.id
      JOIN merchants m ON ct.merchant_id = m.id
      WHERE ct.customer_id = ANY($1::int[])
      ORDER BY ct.created_at DESC
    `, [profileIds]);

    // Calculate sum of completed repayments for each transaction to ensure status accuracy
    const formattedTx = await Promise.all(pendingTransactions.map(async t => {
      const sumRes = await db.get(`SELECT SUM(amount) as total FROM repayments WHERE transaction_id = $1 AND status = 'COMPLETED'`, [t.id]);
      const paidSum = parseFloat(sumRes?.total || 0);
      const remaining = Math.max(0, parseFloat(t.total_amount) - paidSum);
      const isSettled = remaining === 0 || t.status === 'SETTLED';
      if (isSettled && t.status !== 'SETTLED') {
        await db.run(`UPDATE credit_transactions SET status = 'SETTLED' WHERE id = $1`, [t.id]);
      }
      return {
        ...t,
        status: isSettled ? 'SETTLED' : t.status,
        remaining_amount: remaining,
        due_date: t.due_date ? (t.due_date instanceof Date ? t.due_date.toISOString().split('T')[0] : String(t.due_date).split('T')[0]) : null,
        items: JSON.parse(t.items_json || '[]')
      };
    }));

    // Repayments history
    const repayments = await db.all(`
      SELECT r.*, m.store_name
      FROM repayments r
      JOIN merchants m ON r.merchant_id = m.id
      WHERE r.customer_id = ANY($1::int[])
      ORDER BY r.created_at DESC
    `, [profileIds]);

    // Notifications / Alerts history
    const notifications = await db.all(`
      SELECT *
      FROM sms_notifications
      WHERE phone = $1 OR customer_id = ANY($2::int[])
      ORDER BY sent_at DESC
    `, [userPhone, profileIds]);

    const totalBalance = profiles.reduce((sum, p) => sum + parseFloat(p.current_balance), 0);
    const totalCreditLimit = profiles.reduce((sum, p) => sum + parseFloat(p.credit_limit), 0);

    // Active Salary Schedules for customer (per-merchant and combined)
    const scheduleRows = await db.all(`
      SELECT cs.*, m.store_name, m.id as merchant_id
      FROM customer_schedules cs
      LEFT JOIN customer_profiles cp ON cs.customer_id = cp.id
      LEFT JOIN merchants m ON cp.merchant_id = m.id
      WHERE cs.user_id = $1 AND cs.status = 'ACTIVE'
      ORDER BY cs.id DESC
    `, [req.user.id]);

    const activeSchedules = [];
    for (const sRow of scheduleRows) {
      const insts = JSON.parse(sRow.installments_json || '[]');
      const allPaid = insts.length > 0 && insts.every(i => i.status === 'PAID');

      if (allPaid) {
        await db.run(`UPDATE customer_schedules SET status = 'COMPLETED' WHERE id = $1`, [sRow.id]);
      } else {
        activeSchedules.push({
          ...sRow,
          installments: insts
        });
      }
    }
    const activeSchedule = activeSchedules.length > 0 ? activeSchedules[0] : null;

    res.json({
      summary: {
        totalBalance,
        totalCreditLimit,
        availableCredit: Math.max(0, totalCreditLimit - totalBalance),
        activeAccountsCount: profiles.length
      },
      profiles,
      transactions: formattedTx,
      repayments,
      notifications,
      activeSchedule,
      activeSchedules
    });
  } catch (err) {
    console.error('Customer Dashboard Error:', err);
    res.status(500).json({ error: err.message });
  }
}

// Initiate Digital Settlement with Telebirr, Chapa, CBE Birr
async function initiateRepayment(req, res) {
  const { transactionId, customerId, amount, paymentGateway, referenceCode, receiptUrl, installmentNo, isMultiMerchant } = req.body;

  try {
    const payAmount = parseFloat(amount);
    if (!payAmount || payAmount <= 0) {
      return res.status(400).json({ error: 'Valid repayment amount is required.' });
    }

    if (isMultiMerchant || (!transactionId && !customerId)) {
      const multiResult = await processMultiMerchantRepayment({
        userId: req.user.id,
        userPhone: req.user.phone,
        amount: payAmount,
        gateway: paymentGateway || 'TELEBIRR',
        referenceCode,
        receiptUrl,
        actorName: req.user.fullName,
        installmentNo
      });

      return res.json({
        message: `Multi-merchant repayment of ${payAmount.toFixed(2)} ETB via ${paymentGateway} successfully processed across ${multiResult.allocations.length} merchants.`,
        receipt: multiResult
      });
    }

    const result = await processRepayment({
      transactionId,
      customerId,
      amount: payAmount,
      gateway: paymentGateway || 'TELEBIRR',
      referenceCode,
      receiptUrl,
      userId: req.user.id,
      actorName: req.user.fullName
    });

    // If an installment repayment was specified, update installment status in active schedule
    if (installmentNo) {
      const scheduleRow = await db.get(`SELECT * FROM customer_schedules WHERE user_id = $1 AND status = 'ACTIVE' ORDER BY id DESC LIMIT 1`, [req.user.id]);
      if (scheduleRow) {
        let installments = JSON.parse(scheduleRow.installments_json || '[]');
        installments = installments.map(inst => {
          if (inst.installmentNo === parseInt(installmentNo)) {
            return { ...inst, status: 'PAID' };
          }
          return inst;
        });
        await db.run(`UPDATE customer_schedules SET installments_json = $1 WHERE id = $2`, [JSON.stringify(installments), scheduleRow.id]);
      }
    }

    res.json({
      message: `Repayment of ${payAmount.toFixed(2)} ETB via ${paymentGateway} successfully processed.`,
      receipt: result
    });
  } catch (err) {
    console.error('Repayment Error:', err);
    res.status(400).json({ error: err.message });
  }
}

// Flexible Repayment Installment Builder Calculator:
// Monthly frequency: Spaced exactly 1 month apart on the same day-of-month (e.g. Aug 24 -> Sept 24).
// Weekly frequency: Spaced exactly 1 week (7 days) apart (e.g. Sept 17 -> Sept 24).
// Filters out any past dates relative to Today (2026-08-17).
async function calculateFlexibleInstallments(userId, totalAmount, frequency, _unused, numInstallments, merchantId) {
  const amount = parseFloat(totalAmount);
  let numInst = parseInt(numInstallments || 2);

  // Today's date at local midnight (e.g. 2026-08-17)
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  // Fetch customer profile & actual repayment deadline from pending transactions
  let cp = null;
  if (merchantId) {
    cp = await db.get(`SELECT id FROM customer_profiles WHERE (user_id = $1 OR phone = (SELECT phone FROM users WHERE id = $1)) AND merchant_id = $2 LIMIT 1`, [userId, merchantId]);
  }
  if (!cp) {
    cp = await db.get(`
      SELECT cp.id FROM customer_profiles cp
      JOIN credit_transactions ct ON ct.customer_id = cp.id
      WHERE (cp.user_id = $1 OR cp.phone = (SELECT phone FROM users WHERE id = $1)) AND ct.status IN ('PENDING', 'PARTIALLY_PAID')
      ORDER BY cp.current_balance DESC LIMIT 1
    `, [userId]);
  }
  if (!cp) {
    cp = await db.get(`SELECT id FROM customer_profiles WHERE user_id = $1 OR phone = (SELECT phone FROM users WHERE id = $1) LIMIT 1`, [userId]);
  }

  let deadlineDate = null;
  if (cp) {
    const tx = await db.get(`
      SELECT due_date FROM credit_transactions
      WHERE customer_id = $1 AND status IN ('PENDING', 'PARTIALLY_PAID') AND due_date IS NOT NULL
      ORDER BY due_date ASC LIMIT 1
    `, [cp.id]);
    if (tx && tx.due_date) {
      const isoStr = tx.due_date instanceof Date ? tx.due_date.toISOString().split('T')[0] : String(tx.due_date).split('T')[0];
      const parts = isoStr.split('-');
      deadlineDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
    }
  }

  // Fallback: if no deadline found, default to end of current month
  if (!deadlineDate || isNaN(deadlineDate.getTime())) {
    deadlineDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
  }
  deadlineDate.setHours(0, 0, 0, 0);

  // If deadline is in the past relative to today, clamp to today
  if (deadlineDate < today) {
    deadlineDate = new Date(today);
  }

  const deadlineDay = deadlineDate.getDate();
  const deadlineMonth = deadlineDate.getMonth();
  const deadlineYear = deadlineDate.getFullYear();

  const rawDates = [];

  for (let i = numInst - 1; i >= 0; i--) {
    let d;
    if (frequency === 'WEEKLY') {
      // Weekly: go back i * 7 days from deadline
      d = new Date(deadlineDate);
      d.setDate(deadlineDay - i * 7);
    } else {
      // Monthly: go back i months from deadline, pinning to the SAME day-of-month
      const targetMonth = deadlineMonth - i;
      const targetYear = deadlineYear + Math.floor(targetMonth / 12);
      const normalizedMonth = ((targetMonth % 12) + 12) % 12;
      const lastDayOfMonth = new Date(targetYear, normalizedMonth + 1, 0).getDate();
      const targetDay = Math.min(deadlineDay, lastDayOfMonth);
      d = new Date(targetYear, normalizedMonth, targetDay);
    }
    rawDates.push(d);
  }

  // Filter out any dates that are strictly in the past relative to today (before 2026-08-17)
  let validDates = rawDates.filter(d => d >= today);

  // Fallback: if all dates were in past, use deadlineDate
  if (validDates.length === 0) {
    validDates = [new Date(deadlineDate)];
  }

  const actualNumInst = validDates.length;
  const perInstallment = amount / actualNumInst;
  const installments = [];

  for (let i = 0; i < validDates.length; i++) {
    const dueDate = validDates[i];
    const y = dueDate.getFullYear();
    const m = String(dueDate.getMonth() + 1).padStart(2, '0');
    const dayStr = String(dueDate.getDate()).padStart(2, '0');
    installments.push({
      installmentNo: i + 1,
      dueDate: `${y}-${m}-${dayStr}`,
      amount: parseFloat(perInstallment.toFixed(2)),
      status: 'SCHEDULED'
    });
  }

  const dl = deadlineDate;
  const deadlineDateStr = `${dl.getFullYear()}-${String(dl.getMonth() + 1).padStart(2, '0')}-${String(dl.getDate()).padStart(2, '0')}`;

  return {
    amount,
    deadlineDateStr,
    installments,
    cpId: cp ? cp.id : null
  };
}

// Generate Customized Repayment Installment Schedule (Preview)
async function generateInstallmentSchedule(req, res) {
  const { totalAmount, frequency, firstPaymentDate, numInstallments, merchantId } = req.body;

  try {
    const { amount, deadlineDateStr, installments } = await calculateFlexibleInstallments(
      req.user.id,
      totalAmount,
      frequency,
      firstPaymentDate,
      numInstallments,
      merchantId
    );

    res.json({
      totalAmount: amount,
      frequency,
      deadlineDate: deadlineDateStr,
      installments
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
}

// Save & Apply Active Salary Repayment Schedule
async function saveCustomerSchedule(req, res) {
  const { totalAmount, frequency, firstPaymentDate, numInstallments, merchantId } = req.body;

  try {
    const numInst = parseInt(numInstallments || 2);
    const { amount, deadlineDateStr, installments, cpId } = await calculateFlexibleInstallments(
      req.user.id,
      totalAmount,
      frequency,
      firstPaymentDate,
      numInstallments,
      merchantId
    );

    // Deactivate previous active schedule for this specific customer profile or combined schedule
    if (cpId) {
      await db.run(`UPDATE customer_schedules SET status = 'SUPERSEDED' WHERE user_id = $1 AND customer_id = $2 AND status = 'ACTIVE'`, [req.user.id, cpId]);
    } else {
      await db.run(`UPDATE customer_schedules SET status = 'SUPERSEDED' WHERE user_id = $1 AND (customer_id IS NULL OR status = 'ACTIVE')`, [req.user.id]);
    }

    const result = await db.get(`
      INSERT INTO customer_schedules (customer_id, user_id, total_amount, frequency, salary_day, duration_months, installments_json, status)
      VALUES ($1, $2, $3, $4, $5, $6, $7, 'ACTIVE')
      RETURNING *
    `, [
      cpId,
      req.user.id,
      amount,
      frequency,
      parseInt(firstPaymentDate ? firstPaymentDate.split('-')[2] : 30),
      numInst,
      JSON.stringify(installments)
    ]);

    // Update pending credit transactions' due_dates to align with the active schedule installment due dates
    if (cpId && installments.length > 0) {
      const pendingTxs = await db.all(`
        SELECT id FROM credit_transactions
        WHERE customer_id = $1 AND status IN ('PENDING', 'PARTIALLY_PAID')
        ORDER BY created_at ASC
      `, [cpId]);

      for (let idx = 0; idx < pendingTxs.length; idx++) {
        const inst = installments[Math.min(idx, installments.length - 1)];
        await db.run(`UPDATE credit_transactions SET due_date = $1 WHERE id = $2`, [inst.dueDate, pendingTxs[idx].id]);
      }
    }

    res.status(201).json({
      message: 'Flexible Repayment Schedule applied successfully! Repayment deadline updated.',
      schedule: {
        ...result,
        installments
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
}

module.exports = {
  getCustomerDashboard,
  initiateRepayment,
  generateInstallmentSchedule,
  saveCustomerSchedule
};
