const { validationResult } = require('express-validator');

function validateResult(req, res, next) {
  const errors = validationResult(req);
  if (!errors.isEmpty()) {
    return res.status(400).json({
      error: 'Validation failed for request payload',
      details: errors.array().map(err => ({ field: err.path, message: err.msg }))
    });
  }
  next();
}

module.exports = {
  validateResult
};
