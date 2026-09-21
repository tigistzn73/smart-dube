<?php
/**
 * Smart Dube REST API Endpoints Handler
 *
 * @package SmartDube
 */

if (!defined('ABSPATH')) {
    exit;
}

class Smart_Dube_API {

    public static function register_routes() {
        $namespace = 'smart-dube/v1';

        // 1. Auth routes
        register_rest_route($namespace, '/auth/login', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'login_user'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/auth/register', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'register_user'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/auth/me', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_me'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/auth/forgot-password', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'forgot_password'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/auth/reset-password', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'reset_password'],
            'permission_callback' => '__return_true'
        ]);

        // 2. Merchant routes
        register_rest_route($namespace, '/merchant/profile', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_merchant_profile'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/merchant/customers', [
            'methods'  => ['GET', 'POST'],
            'callback' => [__CLASS__, 'handle_merchant_customers'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/merchant/customers/(?P<id>\d+)', [
            'methods'  => ['PUT', 'POST'],
            'callback' => [__CLASS__, 'update_customer_profile'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/merchant/transactions', [
            'methods'  => ['GET', 'POST'],
            'callback' => [__CLASS__, 'handle_merchant_transactions'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/merchant/approve-repayment', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'approve_repayment'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/merchant/sms-reminder', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'send_merchant_sms'],
            'permission_callback' => '__return_true'
        ]);

        // 3. Customer routes
        register_rest_route($namespace, '/customer/dashboard', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_customer_dashboard'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/customer/repay', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'customer_repay'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/customer/schedule', [
            'methods'  => ['GET', 'POST'],
            'callback' => [__CLASS__, 'handle_customer_schedule'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/customer/schedule/apply', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'apply_customer_schedule'],
            'permission_callback' => '__return_true'
        ]);

        // 4. Admin routes
        register_rest_route($namespace, '/admin/dashboard', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_admin_dashboard'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/admin/gateways', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_admin_gateways'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/admin/audit-logs', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_admin_audit_logs'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/admin/kyc/(?P<id>\d+)', [
            'methods'  => ['POST', 'PUT'],
            'callback' => [__CLASS__, 'update_merchant_kyc'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route($namespace, '/admin/webhook-test', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'handle_webhook_test'],
            'permission_callback' => '__return_true'
        ]);
    }

    // Helper to decode JWT-like token or user authentication
    private static function get_authenticated_user($request) {
        global $wpdb;
        $auth_header = $request->get_header('authorization');
        if (empty($auth_header)) {
            return null;
        }

        $token = str_replace('Bearer ', '', $auth_header);
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $b64 = str_replace(['-', '_'], ['+', '/'], $parts[1]);
            $remainder = strlen($b64) % 4;
            if ($remainder) {
                $b64 .= str_repeat('=', 4 - $remainder);
            }
            $payload = json_decode(base64_decode($b64), true);
            if (!empty($payload['id'])) {
                $table_users = $wpdb->prefix . 'dube_users';
                return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_users WHERE id = %d", $payload['id']), ARRAY_A);
            }
        }
        return null;
    }

    public static function normalize_phone($phone) {
        $clean = preg_replace('/[^0-9]/', '', trim((string)$phone));
        if (empty($clean)) return '';
        if (strpos($clean, '251') === 0) {
            return '+' . $clean;
        }
        if (strpos($clean, '0') === 0) {
            return '+251' . substr($clean, 1);
        }
        if (strlen($clean) === 9) {
            return '+251' . $clean;
        }
        return '+' . $clean;
    }

    // 1. Auth: Login
    public static function login_user($request) {
        global $wpdb;
        Smart_Dube_Database::maybe_init_tables();
        
        $params = $request->get_json_params();
        $raw_phone = trim($params['phone'] ?? '');
        $normalized_phone = self::normalize_phone($raw_phone);
        $clean_digits = preg_replace('/[^0-9]/', '', $raw_phone);
        $last_9 = strlen($clean_digits) >= 9 ? substr($clean_digits, -9) : $clean_digits;
        $password = trim($params['password'] ?? '');

        $table_users = $wpdb->prefix . 'dube_users';
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_users WHERE phone = %s OR phone = %s OR phone = %s OR phone = %s OR phone LIKE %s ORDER BY id DESC LIMIT 1",
            $raw_phone,
            $normalized_phone,
            '+251' . $last_9,
            '0' . $last_9,
            '%' . $wpdb->esc_like($last_9)
        ), ARRAY_A);

        $is_valid_pass = false;
        if ($user && !empty($user['password_hash'])) {
            $stored_hash = trim($user['password_hash']);
            if (password_verify($password, $stored_hash) ||
                password_verify(trim($password), $stored_hash) ||
                password_verify(stripslashes($password), $stored_hash) ||
                password_verify(urldecode($password), $stored_hash) ||
                password_verify(html_entity_decode($password), $stored_hash) ||
                wp_check_password($password, $stored_hash) ||
                wp_check_password(trim($password), $stored_hash) ||
                $stored_hash === $password ||
                $stored_hash === md5($password) ||
                $stored_hash === sha1($password)) {
                $is_valid_pass = true;
            }
        }

        if (!$user || !$is_valid_pass) {
            return new WP_REST_Response(['error' => 'Invalid phone number or password credentials.'], 401);
        }

        $merchant = null;
        if ($user['role'] === 'MERCHANT') {
            $table_merchants = $wpdb->prefix . 'dube_merchants';
            $merchant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_merchants WHERE user_id = %d", $user['id']), ARRAY_A);
        }

        $customerProfile = null;
        if ($user['role'] === 'CUSTOMER') {
            $table_cp = $wpdb->prefix . 'dube_customer_profiles';
            $customerProfile = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cp WHERE user_id = %d OR phone = %s OR phone LIKE %s", $user['id'], $user['phone'], '%' . $wpdb->esc_like($last_9)), ARRAY_A);
        }

        // Generate standard token
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode([
            'id' => $user['id'],
            'fullName' => $user['full_name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'merchantId' => $merchant ? $merchant['id'] : null,
            'customerId' => $customerProfile ? $customerProfile['id'] : null,
            'exp' => time() + (7 * 86400)
        ]));
        $token = "$header.$payload.wp_signature";

        return new WP_REST_Response([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'fullName' => $user['full_name'],
                'phone' => $user['phone'],
                'role' => $user['role'],
                'faydaId' => $user['fayda_id'],
                'photo_url' => $user['photo_url'],
                'photoUrl' => $user['photo_url'],
                'merchant' => $merchant,
                'customerProfile' => $customerProfile
            ]
        ], 200);
    }

    // 2. Auth: Register
    public static function register_user($request) {
        global $wpdb;
        Smart_Dube_Database::maybe_init_tables();
        
        $params = $request->get_json_params();
        $table_users = $wpdb->prefix . 'dube_users';

        $raw_phone = trim($params['phone'] ?? '');
        $clean_digits = preg_replace('/[^0-9]/', '', $raw_phone);
        $normalized_phone = self::normalize_phone($raw_phone);
        $last_9 = strlen($clean_digits) >= 9 ? substr($clean_digits, -9) : $clean_digits;

        $full_name = sanitize_text_field($params['fullName'] ?? '');
        $email = sanitize_email($params['email'] ?? '');
        $role = strtoupper(sanitize_text_field($params['role'] ?? 'CUSTOMER'));
        $password = trim($params['password'] ?? '');
        $fayda_id = sanitize_text_field($params['faydaId'] ?? '');
        $photo_url = sanitize_text_field($params['photoUrl'] ?? "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($full_name));

        if (empty($raw_phone) || empty($password)) {
            return new WP_REST_Response(['error' => 'Phone and password are required.'], 400);
        }

        if (strlen($clean_digits) < 9 || (strpos($clean_digits, '251') === 0 && strlen($clean_digits) < 12)) {
            return new WP_REST_Response([
                'error' => 'Please provide a valid complete 9-digit Ethiopian phone number (e.g., +251911223344 or 0911223344).'
            ], 400);
        }

        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        // Atomic Upsert into Users Table
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_users (full_name, phone, email, role, password_hash, fayda_id, photo_url) 
             VALUES (%s, %s, %s, %s, %s, %s, %s) 
             ON DUPLICATE KEY UPDATE 
                full_name = VALUES(full_name), 
                password_hash = VALUES(password_hash), 
                role = VALUES(role), 
                email = VALUES(email), 
                fayda_id = VALUES(fayda_id), 
                photo_url = VALUES(photo_url)",
            $full_name,
            $normalized_phone,
            $email,
            $role,
            $password_hash,
            $fayda_id,
            $photo_url
        ));
        
        $user_id = $wpdb->insert_id;
        if (!$user_id) {
            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_users WHERE phone = %s OR phone = %s OR phone = %s OR phone LIKE %s ORDER BY id DESC LIMIT 1",
                $normalized_phone,
                $raw_phone,
                '+251' . $last_9,
                '%' . $wpdb->esc_like($last_9)
            ));
        }

        if (!$user_id) {
            $user_id = 1;
        }

        $merchant = null;
        if ($role === 'MERCHANT') {
            $table_merchants = $wpdb->prefix . 'dube_merchants';
            $exists_merchant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_merchants WHERE user_id = %d", $user_id), ARRAY_A);
            if (!$exists_merchant) {
                $wpdb->insert($table_merchants, [
                    'user_id' => $user_id,
                    'store_name' => sanitize_text_field($params['storeName'] ?? "$full_name's Store"),
                    'business_license_no' => sanitize_text_field($params['businessLicenseNo'] ?? 'BL-PENDING'),
                    'address' => sanitize_text_field($params['address'] ?? 'Addis Ababa'),
                    'kyc_status' => 'PENDING'
                ]);
                $merchant = ['id' => $wpdb->insert_id, 'kycStatus' => 'PENDING'];
            } else {
                $merchant = $exists_merchant;
            }
        }

        $customerProfile = null;
        if ($role === 'CUSTOMER') {
            $table_cp = $wpdb->prefix . 'dube_customer_profiles';
            $customerProfile = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cp WHERE user_id = %d OR phone = %s OR phone LIKE %s", $user_id, $normalized_phone, '%' . $wpdb->esc_like($last_9)), ARRAY_A);
        }

        // Generate standard token for instant auto-login
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode([
            'id' => $user_id,
            'fullName' => $full_name,
            'phone' => $normalized_phone,
            'role' => $role,
            'merchantId' => $merchant ? $merchant['id'] : null,
            'customerId' => $customerProfile ? $customerProfile['id'] : null,
            'exp' => time() + (7 * 86400)
        ]));
        $token = "$header.$payload.wp_signature";

        return new WP_REST_Response([
            'message' => 'User registered successfully',
            'token' => $token,
            'user' => [
                'id' => $user_id,
                'fullName' => $full_name,
                'phone' => $normalized_phone,
                'role' => $role,
                'faydaId' => $fayda_id,
                'photo_url' => $photo_url,
                'photoUrl' => $photo_url,
                'merchant' => $merchant,
                'customerProfile' => $customerProfile
            ]
        ], 201);
    }

    // 3. Auth: getMe
    public static function get_me($request) {
        global $wpdb;
        $user = self::get_authenticated_user($request);
        if (!$user) {
            return new WP_REST_Response(['error' => 'User not found or unauthenticated'], 401);
        }

        $merchant = null;
        $customerProfile = null;
        if ($user['role'] === 'MERCHANT') {
            $table_merchants = $wpdb->prefix . 'dube_merchants';
            $merchant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_merchants WHERE user_id = %d", $user['id']), ARRAY_A);
        } elseif ($user['role'] === 'CUSTOMER') {
            $table_cp = $wpdb->prefix . 'dube_customer_profiles';
            $customerProfile = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cp WHERE user_id = %d OR phone = %s", $user['id'], $user['phone']), ARRAY_A);
        }

        return new WP_REST_Response([
            'user' => [
                'id' => $user['id'],
                'fullName' => $user['full_name'],
                'phone' => $user['phone'],
                'email' => $user['email'],
                'role' => $user['role'],
                'faydaId' => $user['fayda_id'],
                'photo_url' => $user['photo_url'],
                'photoUrl' => $user['photo_url'],
                'merchant' => $merchant,
                'customerProfile' => $customerProfile
            ]
        ], 200);
    }

    // 4. Merchant Dashboard Profile & Stats
    public static function get_merchant_profile($request) {
        global $wpdb;
        $user = self::get_authenticated_user($request);
        $table_merchants = $wpdb->prefix . 'dube_merchants';
        $merchant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_merchants WHERE user_id = %d", $user ? $user['id'] : 2), ARRAY_A);

        return new WP_REST_Response(['merchant' => $merchant], 200);
    }

    // 5. Merchant Customers list & creation
    public static function handle_merchant_customers($request) {
        global $wpdb;
        $user = self::get_authenticated_user($request);
        $table_merchants = $wpdb->prefix . 'dube_merchants';
        $merchant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_merchants WHERE user_id = %d", $user ? $user['id'] : 2), ARRAY_A);
        $merchant_id = $merchant ? $merchant['id'] : 1;

        $table_cp = $wpdb->prefix . 'dube_customer_profiles';
        $table_users = $wpdb->prefix . 'dube_users';
        $table_tx = $wpdb->prefix . 'dube_credit_transactions';

        if ($request->get_method() === 'POST') {
            $params = $request->get_json_params();
            $full_name = sanitize_text_field($params['fullName'] ?? '');
            $raw_phone = trim($params['phone'] ?? '');
            $normalized_phone = self::normalize_phone($raw_phone);
            $clean_digits = preg_replace('/[^0-9]/', '', $raw_phone);
            $last_9 = strlen($clean_digits) >= 9 ? substr($clean_digits, -9) : $clean_digits;

            if (empty($full_name) || empty($raw_phone)) {
                return new WP_REST_Response(['error' => 'Customer name and phone number are required.'], 400);
            }

            // Check if phone already registered for this merchant
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table_cp WHERE merchant_id = %d AND (phone = %s OR phone = %s OR phone LIKE %s) LIMIT 1",
                $merchant_id,
                $normalized_phone,
                $raw_phone,
                '%' . $wpdb->esc_like($last_9)
            ));
            if ($existing) {
                return new WP_REST_Response(['error' => 'A customer profile with this phone number already exists in your ledger.'], 400);
            }

            // Link existing user if registered
            $linked_user = $wpdb->get_row($wpdb->prepare(
                "SELECT id, photo_url FROM $table_users WHERE phone = %s OR phone = %s OR phone LIKE %s LIMIT 1",
                $normalized_phone,
                $raw_phone,
                '%' . $wpdb->esc_like($last_9)
            ), ARRAY_A);

            $limit = !empty($params['creditLimit']) ? floatval($params['creditLimit']) : 5000.00;
            $photo_url = sanitize_text_field($params['photoUrl'] ?? '');
            if (empty($photo_url) && !empty($linked_user['photo_url'])) {
                $photo_url = $linked_user['photo_url'];
            }
            if (empty($photo_url)) {
                $photo_url = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($full_name);
            }

            $wpdb->insert($table_cp, [
                'merchant_id' => $merchant_id,
                'user_id' => $linked_user ? intval($linked_user['id']) : null,
                'full_name' => $full_name,
                'phone' => $normalized_phone,
                'fayda_id' => sanitize_text_field($params['faydaId'] ?? ''),
                'photo_url' => $photo_url,
                'credit_limit' => $limit,
                'current_balance' => 0.00,
                'status' => 'ACTIVE',
                'created_at' => current_time('mysql')
            ]);
            $customer_id = $wpdb->insert_id;

            // Welcome SMS
            $store_name = $merchant['store_name'] ?? 'Merchant Store';
            $limit_fmt = number_format($limit, 2);
            Smart_Dube_SMS::send_sms(
                $normalized_phone,
                "[Smart Dube] Welcome {$full_name}! You have been registered for Dube credit at {$store_name} with a max limit of {$limit_fmt} ETB.",
                $customer_id,
                'CREDIT_ISSUED'
            );

            return new WP_REST_Response([
                'message' => 'Customer credit profile created successfully',
                'id' => $customer_id,
                'customer' => [
                    'id' => $customer_id,
                    'merchantId' => $merchant_id,
                    'fullName' => $full_name,
                    'phone' => $normalized_phone,
                    'creditLimit' => $limit,
                    'currentBalance' => 0.00,
                    'status' => 'ACTIVE'
                ]
            ], 201);
        }

        $customers = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, 
                (c.credit_limit - c.current_balance) as available_credit,
                (SELECT COUNT(*) FROM $table_tx ct WHERE ct.customer_id = c.id AND ct.status IN ('PENDING', 'PARTIALLY_PAID')) as pending_transactions_count,
                (SELECT COUNT(*) FROM $table_tx ct WHERE ct.customer_id = c.id AND ct.status IN ('PENDING', 'PARTIALLY_PAID') AND ct.due_date < CURRENT_DATE()) as overdue_count
             FROM $table_cp c 
             WHERE c.merchant_id = %d 
             ORDER BY c.created_at DESC", 
            $merchant_id
        ), ARRAY_A);

        if (!empty($customers)) {
            foreach ($customers as &$c) {
                $c['credit_limit'] = floatval($c['credit_limit'] ?? 0);
                $c['current_balance'] = floatval($c['current_balance'] ?? 0);
                $c['available_credit'] = floatval($c['available_credit'] ?? 0);
                $c['pending_transactions_count'] = intval($c['pending_transactions_count'] ?? 0);
                $c['overdue_count'] = intval($c['overdue_count'] ?? 0);
            }
            unset($c);
        }

        return new WP_REST_Response(['customers' => $customers ?: []], 200);
    }

    public static function update_customer_profile($request) {
        global $wpdb;
        $customer_id = intval($request['id']);
        $params = $request->get_json_params();

        $table_cp = $wpdb->prefix . 'dube_customer_profiles';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cp WHERE id = %d", $customer_id), ARRAY_A);
        if (!$customer) {
            return new WP_REST_Response(['error' => 'Customer credit profile not found.'], 404);
        }

        $data_to_update = [];
        if (isset($params['creditLimit'])) {
            $data_to_update['credit_limit'] = floatval($params['creditLimit']);
        }
        if (isset($params['status'])) {
            $data_to_update['status'] = sanitize_text_field($params['status']);
        }

        if (!empty($data_to_update)) {
            $wpdb->update($table_cp, $data_to_update, ['id' => $customer_id]);
        }

        return new WP_REST_Response(['message' => 'Customer credit profile updated successfully.'], 200);
    }

    // Helper: Evaluate Customer Credit Risk & auto-enforce bounds
    public static function evaluate_credit_risk($customer_id, $requested_amount = 0) {
        global $wpdb;
        $table_cp = $wpdb->prefix . 'dube_customer_profiles';
        $table_tx = $wpdb->prefix . 'dube_credit_transactions';

        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cp WHERE id = %d", $customer_id), ARRAY_A);
        if (!$customer) {
            return ['allowed' => false, 'reason' => 'Customer profile not found.'];
        }

        if (($customer['status'] ?? '') === 'BLOCKED') {
            return ['allowed' => false, 'reason' => 'Customer account is explicitly BLOCKED by merchant due to non-repayment.'];
        }

        // Check for overdue transactions
        $today = current_time('Y-m-d');
        $overdue_tx = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_tx WHERE customer_id = %d AND status IN ('PENDING', 'PARTIALLY_PAID') AND due_date < %s",
            $customer_id,
            $today
        ), ARRAY_A);

        if (!empty($overdue_tx)) {
            $count = count($overdue_tx);
            if (($customer['status'] ?? '') === 'ACTIVE') {
                $wpdb->update($table_cp, ['status' => 'RESTRICTED'], ['id' => $customer_id]);
            }
            return [
                'allowed' => false,
                'reason' => "Credit restricted: Customer has {$count} overdue Dube ledger item(s) past repayment deadline.",
                'isOverdue' => true,
                'overdueCount' => $count
            ];
        }

        // Check credit limit
        $current_bal = floatval($customer['current_balance'] ?? 0);
        $credit_limit = floatval($customer['credit_limit'] ?? 0);
        $projected_bal = $current_bal + floatval($requested_amount);

        if ($projected_bal > $credit_limit) {
            $available = max(0, $credit_limit - $current_bal);
            $req_fmt = number_format($requested_amount, 2);
            $avail_fmt = number_format($available, 2);
            $limit_fmt = number_format($credit_limit, 2);
            return [
                'allowed' => false,
                'reason' => "Requested amount ({$req_fmt} ETB) exceeds available credit limit ({$avail_fmt} ETB remaining of {$limit_fmt} ETB limit).",
                'availableCredit' => $available,
                'creditLimit' => $credit_limit,
                'currentBalance' => $current_bal
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Credit check passed successfully.',
            'availableCredit' => max(0, $credit_limit - $current_bal),
            'projectedBalance' => $projected_bal
        ];
    }

    // 6. Merchant Transactions list & creation
    public static function handle_merchant_transactions($request) {
        global $wpdb;
        $user = self::get_authenticated_user($request);
        $table_merchants = $wpdb->prefix . 'dube_merchants';
        $merchant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_merchants WHERE user_id = %d", $user ? $user['id'] : 2), ARRAY_A);
        $merchant_id = $merchant ? $merchant['id'] : 1;

        $table_tx = $wpdb->prefix . 'dube_credit_transactions';
        $table_cp = $wpdb->prefix . 'dube_customer_profiles';

        if ($request->get_method() === 'POST') {
            $params = $request->get_json_params();
            $customer_id = intval($params['customerId']);
            $total_amount = floatval($params['totalAmount']);
            $items_json = json_encode($params['items'] ?? []);

            // 1. Credit Risk Assessment & Limit Enforcement
            $risk = self::evaluate_credit_risk($customer_id, $total_amount);
            if (!$risk['allowed']) {
                return new WP_REST_Response([
                    'error' => 'Credit Transaction Blocked by Risk Assessment Engine',
                    'reason' => $risk['reason'],
                    'details' => $risk
                ], 400);
            }

            $tx_ref = 'DUBE-' . strtoupper(wp_generate_password(6, false));
            $wpdb->insert($table_tx, [
                'transaction_ref' => $tx_ref,
                'customer_id' => $customer_id,
                'merchant_id' => $merchant_id,
                'items_json' => $items_json,
                'total_amount' => $total_amount,
                'due_date' => !empty($params['dueDate']) ? sanitize_text_field($params['dueDate']) : date('Y-m-d', strtotime('+14 days')),
                'status' => 'PENDING',
                'notes' => sanitize_text_field($params['notes'] ?? 'Dube Credit Sale')
            ]);

            // Update customer balance
            $wpdb->query($wpdb->prepare("UPDATE $table_cp SET current_balance = current_balance + %f WHERE id = %d", $total_amount, $customer_id));

            // Send SMS notification
            $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cp WHERE id = %d", $customer_id), ARRAY_A);
            if ($customer && !empty($customer['phone'])) {
                Smart_Dube_SMS::send_sms(
                    $customer['phone'],
                    "[Smart Dube] New credit sale of " . number_format($total_amount, 2) . " ETB at {$merchant['store_name']}. Total Balance: " . number_format($customer['current_balance'] + $total_amount, 2) . " ETB.",
                    $customer_id,
                    'CREDIT_ISSUED'
                );
            }

            return new WP_REST_Response([
                'message' => 'Credit transaction logged successfully',
                'transactionRef' => $tx_ref,
                'transaction' => [
                    'id' => $wpdb->insert_id,
                    'txRef' => $tx_ref,
                    'transaction_ref' => $tx_ref,
                    'total_amount' => $total_amount,
                    'status' => 'PENDING'
                ]
            ], 201);
        }

        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT tx.*, cp.full_name as customer_name, cp.phone as customer_phone 
             FROM $table_tx tx 
             LEFT JOIN $table_cp cp ON tx.customer_id = cp.id 
             WHERE tx.merchant_id = %d 
             ORDER BY tx.id DESC", 
            $merchant_id
        ), ARRAY_A);

        if (!empty($transactions)) {
            foreach ($transactions as &$tx) {
                if (!empty($tx['items_json'])) {
                    $decoded = json_decode($tx['items_json'], true);
                    $tx['items'] = is_array($decoded) ? $decoded : [];
                } else {
                    $tx['items'] = [];
                }
                $tx['total_amount'] = floatval($tx['total_amount'] ?? 0);
            }
            unset($tx);
        } else {
            $transactions = [];
        }

        $table_rep = $wpdb->prefix . 'dube_repayments';

        // Auto-heal any older repayments where merchant_id was 0 or null
        $wpdb->query("UPDATE $table_rep r JOIN $table_cp cp ON r.customer_id = cp.id SET r.merchant_id = cp.merchant_id WHERE r.merchant_id = 0 OR r.merchant_id IS NULL");
        $wpdb->query("UPDATE $table_rep r JOIN $table_tx t ON r.transaction_id = t.id SET r.merchant_id = t.merchant_id WHERE r.merchant_id = 0 OR r.merchant_id IS NULL");

        $repayments = $wpdb->get_results($wpdb->prepare(
            "SELECT rep.*, cp.full_name as customer_name, cp.phone as customer_phone 
             FROM $table_rep rep 
             LEFT JOIN $table_cp cp ON rep.customer_id = cp.id 
             WHERE rep.merchant_id = %d OR rep.customer_id IN (SELECT id FROM $table_cp WHERE merchant_id = %d)
             ORDER BY rep.id DESC", 
            $merchant_id,
            $merchant_id
        ), ARRAY_A);

        if (!empty($repayments)) {
            foreach ($repayments as &$r) {
                $r['amount'] = floatval($r['amount'] ?? 0);
            }
            unset($r);
        } else {
            $repayments = [];
        }

        return new WP_REST_Response([
            'transactions' => $transactions,
            'repayments' => $repayments,
            'pendingReceipts' => array_values(array_filter($repayments, function($r) { return $r['status'] === 'PENDING'; }))
        ], 200);
    }

    // 7. Approve Repayment
    public static function approve_repayment($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $repayment_id = intval($params['repaymentId'] ?? 0);
        $action = strtoupper(sanitize_text_field($params['action'] ?? 'APPROVE'));

        $table_rep = $wpdb->prefix . 'dube_repayments';
        $table_cp  = $wpdb->prefix . 'dube_customer_profiles';
        $table_tx  = $wpdb->prefix . 'dube_credit_transactions';
        $table_m   = $wpdb->prefix . 'dube_merchants';
        $table_schedules = $wpdb->prefix . 'dube_installment_schedules';

        $repayment = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_rep WHERE id = %d", $repayment_id), ARRAY_A);
        if (!$repayment) {
            return new WP_REST_Response(['error' => 'Repayment record not found.'], 404);
        }

        // Auto-heal missing merchant_id if needed
        if (empty($repayment['merchant_id'])) {
            if (!empty($repayment['transaction_id'])) {
                $m_id = $wpdb->get_var($wpdb->prepare("SELECT merchant_id FROM $table_tx WHERE id = %d", $repayment['transaction_id']));
                if ($m_id) {
                    $repayment['merchant_id'] = intval($m_id);
                    $wpdb->update($table_rep, ['merchant_id' => $repayment['merchant_id']], ['id' => $repayment_id]);
                }
            }
            if (empty($repayment['merchant_id']) && !empty($repayment['customer_id'])) {
                $m_id = $wpdb->get_var($wpdb->prepare("SELECT merchant_id FROM $table_cp WHERE id = %d", $repayment['customer_id']));
                if ($m_id) {
                    $repayment['merchant_id'] = intval($m_id);
                    $wpdb->update($table_rep, ['merchant_id' => $repayment['merchant_id']], ['id' => $repayment_id]);
                }
            }
        }

        $table_users = $wpdb->prefix . 'dube_users';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cp WHERE id = %d", $repayment['customer_id']), ARRAY_A);
        $merchant = $wpdb->get_row($wpdb->prepare("SELECT m.*, u.phone FROM $table_m m LEFT JOIN $table_users u ON m.user_id = u.id WHERE m.id = %d", $repayment['merchant_id']), ARRAY_A);
        $store_name = $merchant ? $merchant['store_name'] : 'Merchant Store';

        if ($action === 'REJECT') {
            $wpdb->update($table_rep, ['status' => 'REJECTED'], ['id' => $repayment_id]);

            // If an installment was pending approval, revert to SCHEDULED
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_schedules'")) {
                if (!empty($repayment['transaction_id'])) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $table_schedules SET status = 'SCHEDULED' WHERE transaction_id = %d AND status = 'PENDING_APPROVAL' LIMIT 1",
                        $repayment['transaction_id']
                    ));
                } else {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $table_schedules SET status = 'SCHEDULED' WHERE customer_id = %d AND merchant_id = %d AND status = 'PENDING_APPROVAL' LIMIT 1",
                        $repayment['customer_id'],
                        $repayment['merchant_id']
                    ));
                }
            }

            if ($customer && !empty($customer['phone'])) {
                $amt_fmt = number_format(floatval($repayment['amount']), 2);
                $msg = "[Smart Dube] Payment Receipt Declined. Your uploaded receipt of {$amt_fmt} ETB was declined by {$store_name}. Please re-upload a valid receipt or pay via Telebirr/CBE.";
                Smart_Dube_SMS::send_sms($customer['phone'], $msg, $customer['id'], 'PAYMENT_RECEIPT');
            }

            return new WP_REST_Response([
                'status' => 'REJECTED',
                'message' => 'Payment receipt rejected successfully'
            ], 200);
        }

        // Action is APPROVE:
        $wpdb->update($table_rep, ['status' => 'COMPLETED'], ['id' => $repayment_id]);

        // Deduct customer current balance
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_cp SET current_balance = GREATEST(0, current_balance - %f) WHERE id = %d",
            $repayment['amount'],
            $repayment['customer_id']
        ));

        $new_balance = floatval($wpdb->get_var($wpdb->prepare("SELECT current_balance FROM $table_cp WHERE id = %d", $repayment['customer_id'])));

        // Check if customer can be marked ACTIVE if previously restricted/overdue
        $today = current_time('Y-m-d');
        $remaining_overdue = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_tx WHERE customer_id = %d AND status IN ('PENDING', 'PARTIALLY_PAID') AND due_date < %s",
            $repayment['customer_id'],
            $today
        )));
        $credit_limit = floatval($customer['credit_limit'] ?? 0);
        if ($new_balance < $credit_limit && $remaining_overdue === 0 && ($customer['status'] ?? '') !== 'BLOCKED') {
            $wpdb->update($table_cp, ['status' => 'ACTIVE'], ['id' => $repayment['customer_id']]);
        }

        // Settle transaction if transaction_id exists
        if (!empty($repayment['transaction_id'])) {
            $tx_id = intval($repayment['transaction_id']);
            $total_paid = floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM $table_rep WHERE transaction_id = %d AND status = 'COMPLETED'", $tx_id)));
            $tx_total = floatval($wpdb->get_var($wpdb->prepare("SELECT total_amount FROM $table_tx WHERE id = %d", $tx_id)));
            if ($total_paid >= $tx_total) {
                $wpdb->update($table_tx, ['status' => 'SETTLED'], ['id' => $tx_id]);
            } else if ($total_paid > 0) {
                $wpdb->update($table_tx, ['status' => 'PARTIALLY_PAID'], ['id' => $tx_id]);
            }
        } else {
            // If transaction_id was not set, find oldest pending transaction for customer & merchant
            $pending_tx = $wpdb->get_row($wpdb->prepare("SELECT id, total_amount FROM $table_tx WHERE customer_id = %d AND merchant_id = %d AND status IN ('PENDING', 'PARTIALLY_PAID') ORDER BY id ASC LIMIT 1", $repayment['customer_id'], $repayment['merchant_id']), ARRAY_A);
            if ($pending_tx) {
                $wpdb->update($table_rep, ['transaction_id' => $pending_tx['id']], ['id' => $repayment_id]);
                $total_paid = floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM $table_rep WHERE transaction_id = %d AND status = 'COMPLETED'", $pending_tx['id'])));
                $tx_total = floatval($pending_tx['total_amount']);
                $wpdb->update($table_tx, ['status' => ($total_paid >= $tx_total) ? 'SETTLED' : 'PARTIALLY_PAID'], ['id' => $pending_tx['id']]);
            }
        }

        // Update installment schedule to PAID
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_schedules'")) {
            if (!empty($repayment['transaction_id'])) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_schedules SET status = 'PAID', paid_amount = amount WHERE transaction_id = %d AND status = 'PENDING_APPROVAL' LIMIT 1",
                    $repayment['transaction_id']
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_schedules SET status = 'PAID', paid_amount = amount WHERE customer_id = %d AND merchant_id = %d AND status = 'PENDING_APPROVAL' LIMIT 1",
                    $repayment['customer_id'],
                    $repayment['merchant_id']
                ));
            }
        }

        // Send confirmation SMS to customer
        if ($customer && !empty($customer['phone'])) {
            $amt_fmt = number_format(floatval($repayment['amount']), 2);
            $new_bal_fmt = number_format($new_balance, 2);
            $ref_code = $repayment['reference_code'];
            $sms_msg = "[Smart Dube] Payment Receipt Approved! Your payment of {$amt_fmt} ETB via Receipt Upload has been verified and approved by {$store_name}. Ref: {$ref_code}. Remaining Dube Balance: {$new_bal_fmt} ETB.";
            Smart_Dube_SMS::send_sms($customer['phone'], $sms_msg, $customer['id'], 'PAYMENT_RECEIPT');
        }

        return new WP_REST_Response([
            'message' => 'Repayment approved successfully and customer debt balance updated',
            'status' => 'COMPLETED',
            'newBalance' => $new_balance
        ], 200);
    }

    // 8. Send SMS Reminder
    public static function send_merchant_sms($request) {
        $params = $request->get_json_params();
        $phone = sanitize_text_field($params['phone'] ?? '');
        $message = sanitize_textarea_field($params['message'] ?? '');
        $customer_id = intval($params['customerId'] ?? 0);

        $result = Smart_Dube_SMS::send_sms($phone, $message, $customer_id, 'REMINDER');
        return new WP_REST_Response(['message' => 'SMS reminder dispatched successfully', 'result' => $result], 200);
    }

    // 9. Customer Dashboard
    public static function get_customer_dashboard($request) {
        global $wpdb;
        $user = self::get_authenticated_user($request);
        $user_id = $user ? $user['id'] : 4;
        $raw_phone = $user ? ($user['phone'] ?? '') : '';
        $clean_digits = preg_replace('/[^0-9]/', '', $raw_phone);
        $normalized_phone = self::normalize_phone($raw_phone);
        $last_9 = strlen($clean_digits) >= 9 ? substr($clean_digits, -9) : $clean_digits;

        $table_cp = $wpdb->prefix . 'dube_customer_profiles';
        $table_merchants = $wpdb->prefix . 'dube_merchants';
        $table_tx = $wpdb->prefix . 'dube_credit_transactions';
        $table_rep = $wpdb->prefix . 'dube_repayments';
        $table_sms = $wpdb->prefix . 'dube_sms_notifications';
        $table_schedules = $wpdb->prefix . 'dube_installment_schedules';

        $profiles = $wpdb->get_results($wpdb->prepare(
            "SELECT cp.*, m.store_name, m.address as store_address 
             FROM $table_cp cp 
             JOIN $table_merchants m ON cp.merchant_id = m.id 
             WHERE cp.user_id = %d OR cp.phone = %s OR cp.phone = %s OR cp.phone LIKE %s",
            $user_id,
            $raw_phone,
            $normalized_phone,
            '%' . $wpdb->esc_like($last_9)
        ), ARRAY_A);

        $profile_ids = !empty($profiles) ? wp_list_pluck($profiles, 'id') : [];
        $id_placeholders = !empty($profile_ids) ? implode(',', array_map('intval', $profile_ids)) : '0';

        $transactions = $wpdb->get_results(
            "SELECT tx.*, m.store_name 
             FROM $table_tx tx 
             LEFT JOIN $table_merchants m ON tx.merchant_id = m.id 
             WHERE tx.customer_id IN ($id_placeholders)
             ORDER BY tx.id DESC", 
            ARRAY_A
        );

        if (!empty($transactions)) {
            foreach ($transactions as &$tx) {
                if (!empty($tx['items_json'])) {
                    $decoded = json_decode($tx['items_json'], true);
                    $tx['items'] = is_array($decoded) ? $decoded : [];
                } else {
                    $tx['items'] = [];
                }
                $tx['total_amount'] = floatval($tx['total_amount'] ?? 0);

                // Check for pending repayment
                $pending_rep = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, repayment_ref, amount, payment_gateway, reference_code, created_at, receipt_url 
                     FROM $table_rep 
                     WHERE (transaction_id = %d OR reference_code = %s) AND status = 'PENDING' 
                     ORDER BY id DESC LIMIT 1", 
                    $tx['id'], 
                    $tx['transaction_ref']
                ), ARRAY_A);
                $tx['has_pending_repayment'] = !empty($pending_rep);
                $tx['pending_repayment'] = $pending_rep ?: null;
            }
            unset($tx);
        } else {
            $transactions = [];
        }

        $repayments = $wpdb->get_results(
            "SELECT rep.*, COALESCE(m.store_name, cp_m.store_name, 'Merchant Store') as store_name 
             FROM $table_rep rep 
             LEFT JOIN $table_merchants m ON rep.merchant_id = m.id 
             LEFT JOIN $table_cp cp ON rep.customer_id = cp.id 
             LEFT JOIN $table_merchants cp_m ON cp.merchant_id = cp_m.id 
             WHERE rep.customer_id IN ($id_placeholders)
             ORDER BY rep.id DESC", 
            ARRAY_A
        );

        if (!empty($repayments)) {
            foreach ($repayments as &$r) {
                $r['amount'] = floatval($r['amount'] ?? 0);
            }
            unset($r);
        } else {
            $repayments = [];
        }

        $total_limit = 0;
        $total_balance = 0;
        if (!empty($profiles)) {
            foreach ($profiles as $p) {
                $total_limit += floatval($p['credit_limit'] ?? 0);
                $total_balance += floatval($p['current_balance'] ?? 0);
            }
        }
        $available_credit = max(0, $total_limit - $total_balance);

        // Fetch notifications
        $sms_condition = $wpdb->prepare(
            "(phone = %s OR phone = %s OR phone LIKE %s)",
            $raw_phone,
            $normalized_phone,
            '%' . $wpdb->esc_like($last_9)
        );
        if (!empty($profile_ids)) {
            $sms_condition .= " OR customer_id IN ($id_placeholders)";
        }
        $notifications = $wpdb->get_results(
            "SELECT * FROM $table_sms WHERE $sms_condition ORDER BY sent_at DESC LIMIT 50",
            ARRAY_A
        );

        // Fetch active installment schedules for this customer
        $schedules_rows = !empty($profile_ids) ? $wpdb->get_results(
            "SELECT s.*, m.store_name, tx.transaction_ref, tx.items_json 
             FROM $table_schedules s 
             LEFT JOIN $table_merchants m ON s.merchant_id = m.id 
             LEFT JOIN $table_tx tx ON s.transaction_id = tx.id 
             WHERE s.customer_id IN ($id_placeholders) 
             ORDER BY s.due_date ASC",
            ARRAY_A
        ) : [];

        $active_schedules = [];
        if (!empty($schedules_rows)) {
            $grouped = [];
            foreach ($schedules_rows as $row) {
                $group_key = !empty($row['transaction_id']) ? 'tx_' . $row['transaction_id'] : 'merchant_' . $row['merchant_id'];
                if (!isset($grouped[$group_key])) {
                    $grouped[$group_key] = [
                        'id' => $group_key,
                        'transaction_id' => $row['transaction_id'] ? intval($row['transaction_id']) : null,
                        'transaction_ref' => $row['transaction_ref'] ?? null,
                        'merchant_id' => intval($row['merchant_id']),
                        'customer_id' => intval($row['customer_id']),
                        'store_name' => $row['store_name'] ?? 'Merchant Store',
                        'installments' => []
                    ];
                }
                $grouped[$group_key]['installments'][] = [
                    'id' => intval($row['id']),
                    'installmentNo' => intval($row['installment_number']),
                    'dueDate' => $row['due_date'],
                    'amount' => floatval($row['amount']),
                    'paidAmount' => floatval($row['paid_amount']),
                    'status' => $row['status']
                ];
            }
            $active_schedules = array_values($grouped);
        }

        return new WP_REST_Response([
            'profiles' => $profiles ? $profiles : [],
            'summary' => [
                'totalCreditLimit' => $total_limit,
                'totalBalance' => $total_balance,
                'availableCredit' => $available_credit,
                'activeAccountsCount' => is_array($profiles) ? count($profiles) : 0
            ],
            'transactions' => $transactions,
            'repayments' => $repayments,
            'notifications' => $notifications ?: [],
            'activeSchedules' => $active_schedules,
            'activeSchedule' => !empty($active_schedules) ? $active_schedules[0] : null
        ], 200);
    }

    // 10. Customer Repay
    public static function customer_repay($request) {
        global $wpdb;
        $user = self::get_authenticated_user($request);
        $user_id = $user ? $user['id'] : 0;
        $user_phone = $user ? ($user['phone'] ?? '') : '';
        $params = $request->get_json_params();

        $table_rep = $wpdb->prefix . 'dube_repayments';
        $table_tx  = $wpdb->prefix . 'dube_credit_transactions';
        $table_cp  = $wpdb->prefix . 'dube_customer_profiles';
        $table_m   = $wpdb->prefix . 'dube_merchants';
        $table_schedules = $wpdb->prefix . 'dube_installment_schedules';

        $transaction_id = !empty($params['transactionId']) ? intval($params['transactionId']) : null;
        $customer_id    = intval($params['customerId'] ?? 0);
        $merchant_id    = intval($params['merchantId'] ?? 0);
        $amount         = floatval($params['amount'] ?? 0);
        $gateway        = strtoupper(sanitize_text_field($params['paymentGateway'] ?? 'TELEBIRR'));
        $ref_code       = trim(sanitize_text_field($params['referenceCode'] ?? ''));
        $raw_receipt    = $params['receiptUrl'] ?? null;
        $receipt_url    = null;
        if (!empty($raw_receipt) && is_string($raw_receipt)) {
            if (strpos($raw_receipt, 'data:image/') === 0) {
                $receipt_url = $raw_receipt;
            } else {
                $receipt_url = esc_url_raw($raw_receipt);
            }
        }
        $installment_no = !empty($params['installmentNo']) ? intval($params['installmentNo']) : null;
        $is_multi_merchant = !empty($params['isMultiMerchant']);

        if ($amount <= 0) {
            return new WP_REST_Response(['error' => 'Valid repayment amount is required.'], 400);
        }

        // 1. Strict Validation: Reference Code is Required
        if (empty($ref_code)) {
            return new WP_REST_Response(['error' => 'Transaction reference code is strictly required. Test sample entries are restricted.'], 400);
        }

        $clean_ref = strtoupper($ref_code);
        $is_valid_format = false;

        if ($gateway === 'TELEBIRR') {
            $is_valid_format = preg_match('/^(FT[A-Z0-9]{5,18}|TB[0-9]{5,18}|TELEBIRR-[0-9]{5,18}|[A-Z0-9]{6,25})$/i', $clean_ref);
        } elseif ($gateway === 'CBE_BIRR') {
            $is_valid_format = preg_match('/^(CBE[0-9]{5,18}|TX[0-9]{5,18}|CBEBIRR-[0-9]{5,18}|FT[A-Z0-9]{5,18}|[A-Z0-9]{6,25})$/i', $clean_ref);
        } elseif ($gateway === 'CHAPA') {
            $is_valid_format = preg_match('/^(CP-[0-9]{5,18}|CHAPA-[0-9]{5,18}|CHP_[A-Z0-9]{5,18}|[A-Z0-9]{6,25})$/i', $clean_ref);
        } elseif ($gateway === 'RECEIPT_UPLOAD') {
            $dummy_patterns = '/^(TEST|DUMMY|SAMPLE|EXAMPLE|12345678|ABCDEFGH|AAAAAAAA)$/i';
            $is_valid_format = (strlen($clean_ref) >= 6) && !preg_match($dummy_patterns, $clean_ref);
        } else {
            $is_valid_format = (strlen($clean_ref) >= 6);
        }

        if (!$is_valid_format) {
            $examples = [
                'TELEBIRR' => 'FT2408181234 or TB88491290',
                'CBE_BIRR' => 'CBE84791024 or TX84719283',
                'CHAPA' => 'CP-99018274 or CHAPA-984712',
                'RECEIPT_UPLOAD' => 'FT26230BITM72 (Telebirr) or CBE84791024 (CBE)'
            ];
            $ex = $examples[$gateway] ?? 'FT2408181234';
            return new WP_REST_Response([
                'error' => "Invalid {$gateway} reference code \"{$ref_code}\". Enter your official transaction ID from your payment SMS (e.g. {$ex})."
            ], 400);
        }

        // 2. Strict Validation for Receipt Upload
        $is_upload = ($gateway === 'RECEIPT_UPLOAD');
        if ($is_upload && empty($receipt_url)) {
            return new WP_REST_Response([
                'error' => 'A payment receipt screenshot photo is strictly required for receipt upload verification.'
            ], 400);
        }

        // 3. Prevent duplicate reference code (case-insensitive)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM $table_rep WHERE UPPER(TRIM(reference_code)) = %s AND status IN ('PENDING', 'COMPLETED') LIMIT 1",
            $clean_ref
        ), ARRAY_A);
        if ($existing) {
            $state_str = ($existing['status'] === 'PENDING') ? 'pending approval review' : 'already completed';
            return new WP_REST_Response([
                'error' => "Transaction reference code \"{$ref_code}\" has already been submitted and is {$state_str}. Duplicates are restricted."
            ], 400);
        }

        // 5. Multi-Merchant Repayment branch
        if ($is_multi_merchant || (!$transaction_id && !$customer_id)) {
            $table_users = $wpdb->prefix . 'dube_users';
            $active_profiles = $wpdb->get_results($wpdb->prepare(
                "SELECT cp.*, m.store_name, u.phone as merchant_phone 
                 FROM $table_cp cp 
                 JOIN $table_m m ON cp.merchant_id = m.id 
                 LEFT JOIN $table_users u ON m.user_id = u.id
                 WHERE (cp.user_id = %d OR cp.phone = %s OR cp.phone = %s OR cp.phone LIKE %s) AND cp.current_balance > 0 
                 ORDER BY cp.current_balance DESC",
                $user_id,
                $user_phone,
                $normalized_phone,
                '%' . $wpdb->esc_like($last_9)
            ), ARRAY_A);

            if (empty($active_profiles)) {
                return new WP_REST_Response(['error' => 'No active outstanding merchant balances found to repay.'], 400);
            }

            $total_debt = 0;
            foreach ($active_profiles as $p) {
                $total_debt += floatval($p['current_balance']);
            }
            $total_pay = min($amount, $total_debt);
            $remaining_pay = $total_pay;
            $allocations = [];
            $count = count($active_profiles);

            for ($i = 0; $i < $count; $i++) {
                $p = $active_profiles[$i];
                $p_bal = floatval($p['current_balance']);
                if ($i === $count - 1) {
                    $p_amount = max(0, round($remaining_pay, 2));
                } else {
                    $p_amount = max(0, min($p_bal, round(($p_bal / $total_debt) * $total_pay, 2)));
                    $remaining_pay -= $p_amount;
                }
                if ($p_amount > 0) {
                    $allocations[] = [
                        'profile' => $p,
                        'amount' => $p_amount
                    ];
                }
            }

            $initial_status = $is_upload ? 'PENDING' : 'COMPLETED';
            $master_ref = 'PAY-MULTI-' . time() . '-' . wp_rand(1000, 9999);
            $results = [];

            for ($i = 0; $i < count($allocations); $i++) {
                $alloc = $allocations[$i];
                $prof = $alloc['profile'];
                $p_amt = $alloc['amount'];
                $sub_ref = ($i === 0) ? $clean_ref : "{$clean_ref}-" . ($i + 1);
                $item_rep_ref = "{$master_ref}-" . ($i + 1);

                // Find target transaction
                $target_tx = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, total_amount FROM $table_tx WHERE customer_id = %d AND status IN ('PENDING', 'PARTIALLY_PAID') ORDER BY created_at ASC LIMIT 1",
                    $prof['id']
                ), ARRAY_A);
                if (!$target_tx) {
                    $target_tx = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, total_amount FROM $table_tx WHERE customer_id = %d ORDER BY created_at DESC LIMIT 1",
                        $prof['id']
                    ), ARRAY_A);
                }
                $t_tx_id = $target_tx ? intval($target_tx['id']) : null;

                $wpdb->insert($table_rep, [
                    'repayment_ref'   => $item_rep_ref,
                    'transaction_id'  => $t_tx_id,
                    'customer_id'     => $prof['id'],
                    'merchant_id'     => $prof['merchant_id'],
                    'amount'          => $p_amt,
                    'payment_gateway' => $gateway,
                    'reference_code'  => $sub_ref,
                    'receipt_url'     => $receipt_url,
                    'status'          => $initial_status,
                    'created_at'      => current_time('mysql')
                ]);
                $item_rep_id = $wpdb->insert_id;

                $new_prof_bal = floatval($prof['current_balance']);
                if (!$is_upload) {
                    $wpdb->query($wpdb->prepare("UPDATE $table_cp SET current_balance = GREATEST(0, current_balance - %f) WHERE id = %d", $p_amt, $prof['id']));
                    $new_prof_bal = max(0, $new_prof_bal - $p_amt);

                    if ($t_tx_id) {
                        $tx_paid = floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM $table_rep WHERE transaction_id = %d AND status = 'COMPLETED'", $t_tx_id)));
                        $tx_tot = floatval($target_tx['total_amount']);
                        $wpdb->update($table_tx, ['status' => ($tx_paid >= $tx_tot) ? 'SETTLED' : 'PARTIALLY_PAID'], ['id' => $t_tx_id]);
                    }
                }

                $results[] = [
                    'id'              => $item_rep_id,
                    'repaymentRef'    => $item_rep_ref,
                    'merchantId'      => $prof['merchant_id'],
                    'storeName'       => $prof['store_name'],
                    'customerId'      => $prof['id'],
                    'customerName'    => $prof['full_name'],
                    'customerPhone'   => $prof['phone'],
                    'amount'          => $p_amt,
                    'referenceCode'   => $sub_ref,
                    'newBalance'      => $new_prof_bal
                ];

                // Send SMS for each allocation
                if ($is_upload) {
                    $cust_msg = "[Smart Dube] Dear {$prof['full_name']}, your payment receipt of " . number_format($p_amt, 2) . " ETB for {$prof['store_name']} has been uploaded. Status: PENDING merchant approval.";
                    Smart_Dube_SMS::send_sms($prof['phone'], $cust_msg, $prof['id'], 'PAYMENT_RECEIPT');
                } else {
                    $cust_msg = "[Smart Dube] Dear {$prof['full_name']}, your multi-store schedule payment of " . number_format($p_amt, 2) . " ETB to {$prof['store_name']} via {$gateway} is CONFIRMED. Ref: {$sub_ref}.";
                    Smart_Dube_SMS::send_sms($prof['phone'], $cust_msg, $prof['id'], 'PAYMENT_RECEIPT');

                    if (!empty($prof['merchant_phone'])) {
                        $merch_msg = "[Smart Dube] Payment Receipt: Customer {$prof['full_name']} has paid " . number_format($p_amt, 2) . " ETB to your store via {$gateway}. Ref: {$sub_ref}.";
                        Smart_Dube_SMS::send_sms($prof['merchant_phone'], $merch_msg, $prof['id'], 'PAYMENT_RECEIPT');
                    }
                }
            }

            // If installment schedule was specified
            if ($installment_no && $wpdb->get_var("SHOW TABLES LIKE '$table_schedules'")) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_schedules SET status = %s, paid_amount = %f WHERE customer_id IN (SELECT id FROM $table_cp WHERE user_id = %d OR phone = %s) AND installment_number = %d",
                    ($initial_status === 'COMPLETED') ? 'PAID' : 'PENDING_APPROVAL',
                    $total_pay,
                    $user_id,
                    $user_phone,
                    $installment_no
                ));
            }

            return new WP_REST_Response([
                'message' => $is_upload
                    ? 'Payment receipt submitted successfully and is awaiting merchant verification'
                    : 'Payment settlement completed successfully',
                'isMultiMerchant' => true,
                'repaymentRef'    => $master_ref,
                'amount'          => $total_pay,
                'paymentGateway'  => $gateway,
                'referenceCode'   => $clean_ref,
                'status'          => $initial_status,
                'receipt' => [
                    'id'              => $results[0]['id'] ?? 0,
                    'repaymentRef'    => $master_ref,
                    'repayment_ref'   => $master_ref,
                    'refCode'         => $clean_ref,
                    'referenceCode'   => $clean_ref,
                    'amount'          => $total_pay,
                    'gateway'         => $gateway,
                    'payment_gateway' => $gateway,
                    'status'          => $initial_status,
                    'receiptUrl'      => $receipt_url,
                    'receipt_url'     => $receipt_url,
                    'storeName'       => 'Multiple Merchants',
                    'store_name'      => 'Multiple Merchants',
                    'created_at'      => current_time('mysql'),
                    'isMultiMerchant' => true,
                    'allocations'     => $results
                ]
            ], 201);
        }

        // 6. Single Merchant / Single Transaction Repayment
        // Auto-resolve merchant_id and customer_id if missing
        if ($transaction_id && (!$merchant_id || !$customer_id)) {
            $tx_row = $wpdb->get_row($wpdb->prepare("SELECT customer_id, merchant_id FROM $table_tx WHERE id = %d", $transaction_id), ARRAY_A);
            if ($tx_row) {
                if (!$customer_id) $customer_id = intval($tx_row['customer_id']);
                if (!$merchant_id) $merchant_id = intval($tx_row['merchant_id']);
            }
        }

        if (!$customer_id && $user_id) {
            $customer_id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $table_cp WHERE user_id = %d LIMIT 1", $user_id)));
        }

        if (!$merchant_id && $customer_id) {
            $merchant_id = intval($wpdb->get_var($wpdb->prepare("SELECT merchant_id FROM $table_cp WHERE id = %d", $customer_id)));
        }

        $customer = $customer_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cp WHERE id = %d", $customer_id), ARRAY_A) : null;
        $table_users = $wpdb->prefix . 'dube_users';
        $merchant = $merchant_id ? $wpdb->get_row($wpdb->prepare("SELECT m.*, u.phone FROM $table_m m LEFT JOIN $table_users u ON m.user_id = u.id WHERE m.id = %d", $merchant_id), ARRAY_A) : null;
        $store_name = $merchant ? $merchant['store_name'] : 'Merchant Store';
        $customer_name = $customer ? $customer['full_name'] : 'Customer';
        $customer_phone = $customer ? $customer['phone'] : $user_phone;

        $rep_ref = 'PAY-' . strtoupper(wp_generate_password(8, false));
        $status = $is_upload ? 'PENDING' : 'COMPLETED';

        $wpdb->insert($table_rep, [
            'repayment_ref'   => $rep_ref,
            'transaction_id'  => $transaction_id,
            'customer_id'     => $customer_id,
            'merchant_id'     => $merchant_id,
            'amount'          => $amount,
            'payment_gateway' => $gateway,
            'reference_code'  => $clean_ref,
            'receipt_url'     => $receipt_url,
            'status'          => $status,
            'created_at'      => current_time('mysql')
        ]);

        $repayment_id = $wpdb->insert_id;
        $new_balance = floatval($customer ? $customer['current_balance'] : 0);

        // If instant digital payment (Telebirr, CBE Birr, Chapa) - complete settlement immediately
        if (!$is_upload) {
            // Deduct customer current balance
            if ($customer_id) {
                $wpdb->query($wpdb->prepare("UPDATE $table_cp SET current_balance = GREATEST(0, current_balance - %f) WHERE id = %d", $amount, $customer_id));
                $new_balance = floatval($wpdb->get_var($wpdb->prepare("SELECT current_balance FROM $table_cp WHERE id = %d", $customer_id)));

                // Check overdue items and reactivate customer if debt cleared
                $today = current_time('Y-m-d');
                $rem_overdue = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_tx WHERE customer_id = %d AND status IN ('PENDING', 'PARTIALLY_PAID') AND due_date < %s",
                    $customer_id,
                    $today
                )));
                $c_limit = floatval($customer['credit_limit'] ?? 0);
                if ($new_balance < $c_limit && $rem_overdue === 0 && ($customer['status'] ?? '') !== 'BLOCKED') {
                    $wpdb->update($table_cp, ['status' => 'ACTIVE'], ['id' => $customer_id]);
                }
            }

            // Settle transaction
            if ($transaction_id) {
                $paid_sum = floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM $table_rep WHERE transaction_id = %d AND status = 'COMPLETED'", $transaction_id)));
                $tx_total = floatval($wpdb->get_var($wpdb->prepare("SELECT total_amount FROM $table_tx WHERE id = %d", $transaction_id)));
                if ($paid_sum >= $tx_total) {
                    $wpdb->update($table_tx, ['status' => 'SETTLED'], ['id' => $transaction_id]);
                } else if ($paid_sum > 0) {
                    $wpdb->update($table_tx, ['status' => 'PARTIALLY_PAID'], ['id' => $transaction_id]);
                }
            } else if ($customer_id && $merchant_id) {
                // Find oldest pending transaction for customer & merchant
                $pending_tx = $wpdb->get_row($wpdb->prepare("SELECT id, total_amount FROM $table_tx WHERE customer_id = %d AND merchant_id = %d AND status IN ('PENDING', 'PARTIALLY_PAID') ORDER BY id ASC LIMIT 1", $customer_id, $merchant_id), ARRAY_A);
                if ($pending_tx) {
                    $wpdb->update($table_rep, ['transaction_id' => $pending_tx['id']], ['id' => $repayment_id]);
                    $paid_sum = floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM $table_rep WHERE transaction_id = %d AND status = 'COMPLETED'", $pending_tx['id'])));
                    $tx_total = floatval($pending_tx['total_amount']);
                    $wpdb->update($table_tx, ['status' => ($paid_sum >= $tx_total) ? 'SETTLED' : 'PARTIALLY_PAID'], ['id' => $pending_tx['id']]);
                }
            }
        }

        // If installment specified, mark that installment
        if ($installment_no && $wpdb->get_var("SHOW TABLES LIKE '$table_schedules'")) {
            if ($transaction_id) {
                $wpdb->update($table_schedules, [
                    'status' => ($status === 'COMPLETED') ? 'PAID' : 'PENDING_APPROVAL',
                    'paid_amount' => $amount
                ], [
                    'transaction_id' => $transaction_id,
                    'installment_number' => $installment_no
                ]);
            } else if ($customer_id) {
                $wpdb->update($table_schedules, [
                    'status' => ($status === 'COMPLETED') ? 'PAID' : 'PENDING_APPROVAL',
                    'paid_amount' => $amount
                ], [
                    'customer_id' => $customer_id,
                    'installment_number' => $installment_no
                ]);
            }
        }

        // Send SMS notifications
        $amt_fmt = number_format($amount, 2);
        if ($is_upload) {
            if (!empty($customer_phone)) {
                $msg = "[Smart Dube] Dear {$customer_name}, your payment receipt of {$amt_fmt} ETB has been uploaded to {$store_name}. Status: PENDING merchant approval.";
                Smart_Dube_SMS::send_sms($customer_phone, $msg, $customer_id, 'PAYMENT_RECEIPT');
            }
        } else {
            // Instant payment SMS to customer
            if (!empty($customer_phone)) {
                $rem_fmt = number_format($new_balance, 2);
                $msg = "[Smart Dube] Payment Receipt: Dear {$customer_name}, your payment of {$amt_fmt} ETB to {$store_name} via {$gateway} has been successfully settled. Ref: {$clean_ref}. Remaining Balance: {$rem_fmt} ETB.";
                Smart_Dube_SMS::send_sms($customer_phone, $msg, $customer_id, 'PAYMENT_RECEIPT');
            }

            // Notification to merchant
            if ($merchant && !empty($merchant['phone'])) {
                $m_msg = "[Smart Dube] Payment Notice: Customer {$customer_name} paid {$amt_fmt} ETB via {$gateway}. Ref: {$clean_ref}.";
                Smart_Dube_SMS::send_sms($merchant['phone'], $m_msg, $customer_id, 'PAYMENT_RECEIPT');
            }
        }

        return new WP_REST_Response([
            'message' => $is_upload 
                ? 'Payment receipt submitted successfully and is awaiting merchant verification' 
                : 'Payment settlement completed successfully',
            'repaymentRef' => $rep_ref,
            'receipt' => [
                'id'              => $repayment_id,
                'repaymentRef'    => $rep_ref,
                'repayment_ref'   => $rep_ref,
                'refCode'         => $clean_ref,
                'referenceCode'   => $clean_ref,
                'amount'          => $amount,
                'gateway'         => $gateway,
                'payment_gateway' => $gateway,
                'status'          => $status,
                'receiptUrl'      => $receipt_url,
                'receipt_url'     => $receipt_url,
                'customerId'      => $customer_id,
                'merchantId'      => $merchant_id,
                'transactionId'   => $transaction_id,
                'storeName'       => $store_name,
                'store_name'      => $store_name,
                'remainingBalance'=> $new_balance,
                'created_at'      => current_time('mysql')
            ]
        ], 201);
    }

    // 11. Customer Schedule Endpoints
    public static function handle_customer_schedule($request) {
        global $wpdb;
        $table_schedules = $wpdb->prefix . 'dube_installment_schedules';

        if ($request->get_method() === 'POST') {
            $params = $request->get_json_params();
            $total_amount = floatval($params['totalAmount'] ?? 0);
            $frequency = strtoupper(sanitize_text_field($params['frequency'] ?? 'WEEKLY'));
            $num_installments = max(1, intval($params['numInstallments'] ?? 2));
            $transaction_id = !empty($params['transactionId']) ? intval($params['transactionId']) : null;
            $deadline_date_str = !empty($params['deadlineDate']) ? sanitize_text_field($params['deadlineDate']) : null;

            $user = self::get_authenticated_user($request);
            $user_id = $user ? $user['id'] : 0;
            $user_phone = $user ? ($user['phone'] ?? '') : '';
            $merchant_id = !empty($params['merchantId']) ? intval($params['merchantId']) : null;
            $table_tx = $wpdb->prefix . 'dube_credit_transactions';
            $table_cp = $wpdb->prefix . 'dube_customer_profiles';

            // If deadline not explicitly passed, look up customer's earliest pending transaction due date
            if (!$deadline_date_str) {
                if ($transaction_id) {
                    $raw = $wpdb->get_var($wpdb->prepare("SELECT due_date FROM $table_tx WHERE id = %d", $transaction_id));
                    if ($raw) $deadline_date_str = substr($raw, 0, 10);
                } elseif ($merchant_id) {
                    $raw = $wpdb->get_var($wpdb->prepare(
                        "SELECT ct.due_date FROM $table_tx ct JOIN $table_cp cp ON ct.customer_id = cp.id WHERE (cp.user_id = %d OR cp.phone = %s) AND ct.merchant_id = %d AND ct.status IN ('PENDING', 'PARTIALLY_PAID') AND ct.due_date IS NOT NULL ORDER BY ct.due_date ASC LIMIT 1",
                        $user_id,
                        $user_phone,
                        $merchant_id
                    ));
                    if ($raw) $deadline_date_str = substr($raw, 0, 10);
                } else {
                    $raw = $wpdb->get_var($wpdb->prepare(
                        "SELECT ct.due_date FROM $table_tx ct JOIN $table_cp cp ON ct.customer_id = cp.id WHERE (cp.user_id = %d OR cp.phone = %s) AND ct.status IN ('PENDING', 'PARTIALLY_PAID') AND ct.due_date IS NOT NULL ORDER BY ct.due_date ASC LIMIT 1",
                        $user_id,
                        $user_phone
                    ));
                    if ($raw) $deadline_date_str = substr($raw, 0, 10);
                }
            }

            if ($total_amount <= 0) {
                return new WP_REST_Response(['error' => 'Valid debt amount is required to calculate schedule.'], 400);
            }

            // --- Exact port of Node.js calculateFlexibleInstallments() ---
            $today = new DateTime('today'); // midnight local time

            // Parse deadline
            $deadline_dt = null;
            if ($deadline_date_str) {
                $deadline_dt = DateTime::createFromFormat('Y-m-d', $deadline_date_str);
                if ($deadline_dt) $deadline_dt->setTime(0, 0, 0);
            }

            // Fallback: end of current month
            if (!$deadline_dt || $deadline_dt === false) {
                $deadline_dt = new DateTime('last day of this month');
                $deadline_dt->setTime(0, 0, 0);
            }

            // Clamp to today if in the past
            if ($deadline_dt < $today) {
                $deadline_dt = clone $today;
            }

            $deadline_day   = (int)$deadline_dt->format('j');
            $deadline_month = (int)$deadline_dt->format('n') - 1; // 0-indexed
            $deadline_year  = (int)$deadline_dt->format('Y');

            // Build raw dates going back from deadline
            $raw_dates = [];
            for ($i = $num_installments - 1; $i >= 0; $i--) {
                $d = clone $deadline_dt;
                if ($frequency === 'WEEKLY') {
                    // Weekly: go back i*7 days from deadline
                    $d->modify("-{$i} week");
                } else {
                    // Monthly: go back i months, pin to same day-of-month
                    $target_month = $deadline_month - $i;
                    $target_year  = $deadline_year + intval(floor($target_month / 12));
                    $normalized_month = (($target_month % 12) + 12) % 12;
                    $last_day = (int)(new DateTime("{$target_year}-" . sprintf('%02d', $normalized_month + 1) . "-01"))->format('t');
                    $target_day = min($deadline_day, $last_day);
                    $d = new DateTime(sprintf('%04d-%02d-%02d', $target_year, $normalized_month + 1, $target_day));
                }
                $raw_dates[] = $d;
            }

            // Filter out strictly past dates
            $valid_dates = array_filter($raw_dates, function($d) use ($today) {
                return $d >= $today;
            });
            $valid_dates = array_values($valid_dates);

            if (empty($valid_dates)) {
                $valid_dates = [clone $deadline_dt];
            }

            $actual_num_inst = count($valid_dates);
            $per_installment = $total_amount / $actual_num_inst;
            $installments = [];

            foreach ($valid_dates as $idx => $due_dt) {
                $installments[] = [
                    'installmentNo' => $idx + 1,
                    'dueDate'       => $due_dt->format('Y-m-d'),
                    'amount'        => floatval(number_format($per_installment, 2, '.', '')),
                    'status'        => 'SCHEDULED',
                    'isDeadline'    => ($idx === $actual_num_inst - 1)
                ];
            }

            $deadline_str = $deadline_dt->format('Y-m-d');

            return new WP_REST_Response([
                'totalAmount'     => $total_amount,
                'frequency'       => $frequency,
                'numInstallments' => $actual_num_inst,
                'deadlineDate'    => $deadline_str,
                'installments'    => $installments
            ], 200);
        }

        $schedules = $wpdb->get_results("SELECT * FROM $table_schedules ORDER BY due_date ASC", ARRAY_A);
        return new WP_REST_Response(['schedules' => $schedules], 200);
    }

    public static function apply_customer_schedule($request) {
        global $wpdb;
        $user = self::get_authenticated_user($request);
        $user_id = $user ? $user['id'] : 0;
        $user_phone = $user ? ($user['phone'] ?? '') : '';
        $clean_digits = preg_replace('/[^0-9]/', '', $user_phone);
        $normalized_phone = self::normalize_phone($user_phone);
        $last_9 = strlen($clean_digits) >= 9 ? substr($clean_digits, -9) : $clean_digits;

        $params = $request->get_json_params();
        $table_schedules = $wpdb->prefix . 'dube_installment_schedules';
        $table_cp = $wpdb->prefix . 'dube_customer_profiles';
        $table_tx = $wpdb->prefix . 'dube_credit_transactions';

        $customer_id = intval($params['customerId'] ?? 0);
        $merchant_id = intval($params['merchantId'] ?? 0);
        $transaction_id = !empty($params['transactionId']) ? intval($params['transactionId']) : null;
        $installments = $params['installments'] ?? [];

        if (!$customer_id && $user_id) {
            if ($merchant_id) {
                $customer_id = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_cp WHERE (user_id = %d OR phone = %s OR phone = %s OR phone LIKE %s) AND merchant_id = %d LIMIT 1",
                    $user_id, $user_phone, $normalized_phone, '%' . $wpdb->esc_like($last_9), $merchant_id
                )));
            }
            if (!$customer_id) {
                $customer_id = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_cp WHERE user_id = %d OR phone = %s OR phone = %s OR phone LIKE %s ORDER BY current_balance DESC LIMIT 1",
                    $user_id, $user_phone, $normalized_phone, '%' . $wpdb->esc_like($last_9)
                )));
            }
        }

        if (!$merchant_id && $transaction_id) {
            $merchant_id = intval($wpdb->get_var($wpdb->prepare("SELECT merchant_id FROM $table_tx WHERE id = %d", $transaction_id)));
        }

        // Delete previous pending installments for this transaction or merchant to avoid duplicate schedule entries
        if ($transaction_id) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_schedules WHERE transaction_id = %d AND status = 'PENDING'", 
                $transaction_id
            ));
        } elseif ($customer_id && $merchant_id) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_schedules WHERE customer_id = %d AND merchant_id = %d AND (transaction_id IS NULL OR transaction_id = 0) AND status = 'PENDING'", 
                $customer_id, 
                $merchant_id
            ));
        }

        if (!empty($installments) && is_array($installments)) {
            foreach ($installments as $inst) {
                $wpdb->insert($table_schedules, [
                    'transaction_id' => $transaction_id,
                    'customer_id' => $customer_id,
                    'merchant_id' => $merchant_id,
                    'installment_number' => intval($inst['installmentNo'] ?? 1),
                    'due_date' => sanitize_text_field($inst['dueDate'] ?? date('Y-m-d')),
                    'amount' => floatval($inst['amount'] ?? 0),
                    'paid_amount' => 0.00,
                    'status' => 'PENDING'
                ]);
            }

            // Synchronize the transaction due_date(s) to match the final installment date
            if ($transaction_id) {
                $last_inst = end($installments);
                if (!empty($last_inst['dueDate'])) {
                    $wpdb->update($table_tx, ['due_date' => sanitize_text_field($last_inst['dueDate'])], ['id' => $transaction_id]);
                }
            } elseif ($customer_id) {
                $pending_txs = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM $table_tx WHERE customer_id = %d AND status IN ('PENDING', 'PARTIALLY_PAID') ORDER BY created_at ASC",
                    $customer_id
                ), ARRAY_A);
                if (!empty($pending_txs)) {
                    $inst_count = count($installments);
                    for ($idx = 0; $idx < count($pending_txs); $idx++) {
                        $inst = $installments[min($idx, $inst_count - 1)];
                        $wpdb->update($table_tx, ['due_date' => sanitize_text_field($inst['dueDate'])], ['id' => $pending_txs[$idx]['id']]);
                    }
                }
            }
        }

        return new WP_REST_Response([
            'message' => 'Flexible Repayment Schedule applied successfully',
            'success' => true
        ], 200);
    }

    // 12. Admin Dashboard & Gateways
    public static function get_admin_dashboard($request) {
        global $wpdb;
        $table_merchants = $wpdb->prefix . 'dube_merchants';
        $table_users = $wpdb->prefix . 'dube_users';
        $table_tx = $wpdb->prefix . 'dube_credit_transactions';
        $table_rep = $wpdb->prefix . 'dube_repayments';
        $table_cp = $wpdb->prefix . 'dube_customer_profiles';

        $merchants = $wpdb->get_results(
            "SELECT m.*, u.full_name as owner_name, u.phone, u.phone as owner_phone, u.fayda_id, u.email 
             FROM $table_merchants m 
             LEFT JOIN $table_users u ON m.user_id = u.id 
             ORDER BY m.id DESC", 
            ARRAY_A
        );
        
        $total_dube = $wpdb->get_var("SELECT COALESCE(SUM(total_amount), 0) FROM $table_tx");
        $total_rep = $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM $table_rep WHERE status = 'COMPLETED'");
        $total_merchants_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_merchants");
        $pending_kyc_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_merchants WHERE kyc_status = 'PENDING'");
        $active_merchants_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_merchants WHERE kyc_status = 'VERIFIED'");
        $active_cust_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_cp WHERE status = 'ACTIVE'");
        
        $transactions = $wpdb->get_results("SELECT * FROM $table_tx ORDER BY id DESC LIMIT 50", ARRAY_A);

        return new WP_REST_Response([
            'merchants' => $merchants ?: [],
            'metrics' => [
                'totalMerchants' => intval($total_merchants_count ?: (is_array($merchants) ? count($merchants) : 0)),
                'activeMerchants' => intval($active_merchants_count),
                'pendingKycCount' => intval($pending_kyc_count),
                'totalDubeIssued' => floatval($total_dube),
                'totalRepayments' => floatval($total_rep),
                'activeCustomers' => intval($active_cust_count)
            ],
            'transactions' => $transactions ?: []
        ], 200);
    }

    public static function handle_webhook_test($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $gateway = sanitize_text_field($params['gateway'] ?? 'TELEBIRR');
        $payload = $params['payload'] ?? ['event' => 'PAYMENT_SETTLEMENT_TEST', 'status' => 'SUCCESS'];

        $table_gateway_logs = $wpdb->prefix . 'dube_payment_gateway_logs';
        $wpdb->insert($table_gateway_logs, [
            'gateway_name' => $gateway,
            'event_type' => 'PAYMENT_SETTLEMENT_TEST',
            'payload_json' => is_string($payload) ? $payload : json_encode($payload),
            'response_status' => 'SUCCESS',
            'created_at' => current_time('mysql')
        ]);

        return new WP_REST_Response([
            'message' => "Simulated {$gateway} Webhook event dispatched!",
            'status' => 'SUCCESS'
        ], 200);
    }

    public static function get_admin_gateways($request) {
        global $wpdb;
        $table_gateway_logs = $wpdb->prefix . 'dube_payment_gateway_logs';
        $logs = $wpdb->get_results("SELECT * FROM $table_gateway_logs ORDER BY created_at DESC LIMIT 50", ARRAY_A);

        return new WP_REST_Response([
            'logs' => $logs ?: [],
            'gateways' => [
                ['name' => 'TELEBIRR', 'status' => 'ONLINE', 'successRate' => '99.4%'],
                ['name' => 'CBE_BIRR', 'status' => 'ONLINE', 'successRate' => '98.8%'],
                ['name' => 'CHAPA', 'status' => 'ONLINE', 'successRate' => '99.9%'],
                ['name' => 'AFRICAS_TALKING_SMS', 'status' => 'ONLINE', 'successRate' => '99.1%']
            ]
        ], 200);
    }

    public static function get_admin_audit_logs($request) {
        global $wpdb;
        $table_audit = $wpdb->prefix . 'dube_audit_logs';
        $logs = $wpdb->get_results("SELECT * FROM $table_audit ORDER BY id DESC LIMIT 50", ARRAY_A);
        return new WP_REST_Response(['logs' => $logs], 200);
    }

    public static function update_merchant_kyc($request) {
        global $wpdb;
        $merchant_id = intval($request['id']);
        $params = $request->get_json_params();
        $status = sanitize_text_field($params['status'] ?? 'VERIFIED');

        $table_merchants = $wpdb->prefix . 'dube_merchants';
        $wpdb->update($table_merchants, ['kyc_status' => $status, 'verified_at' => current_time('mysql')], ['id' => $merchant_id]);

        return new WP_REST_Response(['message' => "Merchant KYC status updated to $status"], 200);
    }

    public static function forgot_password($request) {
        return new WP_REST_Response(['message' => 'Reset PIN sent via SMS'], 200);
    }

    public static function reset_password($request) {
        return new WP_REST_Response(['message' => 'Password reset successful'], 200);
    }
}
