<?php
/**
 * Smart Dube WordPress REST API Handler
 *
 * @package SmartDube
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Smart_Dube_API')) {
class Smart_Dube_API {

    public static function register_routes() {
        $namespace = 'smart-dube/v1';

        // Auth: Login
        register_rest_route($namespace, '/auth/login', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'login_handler'],
            'permission_callback' => '__return_true'
        ]);

        // Auth: Get Current User
        register_rest_route($namespace, '/auth/me', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_me_handler'],
            'permission_callback' => '__return_true'
        ]);

        // Customers: List & Create
        register_rest_route($namespace, '/customers', [
            [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_customers_handler'],
                'permission_callback' => '__return_true'
            ],
            [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'create_customer_handler'],
                'permission_callback' => '__return_true'
            ]
        ]);

        // Transactions: List & Issue Credit
        register_rest_route($namespace, '/transactions', [
            [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_transactions_handler'],
                'permission_callback' => '__return_true'
            ],
            [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'issue_credit_handler'],
                'permission_callback' => '__return_true'
            ]
        ]);

        // Repayments: List & Record Payment
        register_rest_route($namespace, '/repayments', [
            [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_repayments_handler'],
                'permission_callback' => '__return_true'
            ],
            [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'record_repayment_handler'],
                'permission_callback' => '__return_true'
            ]
        ]);

        // Receipt OCR Parse
        register_rest_route($namespace, '/receipt/ocr', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'ocr_parse_handler'],
            'permission_callback' => '__return_true'
        ]);

        // Stats & Dashboard
        register_rest_route($namespace, '/stats', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_stats_handler'],
            'permission_callback' => '__return_true'
        ]);
    }

    // Helper: Normalize Phone Number Format (+251...)
    public static function format_phone($phone) {
        $clean = preg_replace('/[^\d+]/', '', trim($phone));
        if (strpos($clean, '09') === 0 || strpos($clean, '07') === 0) {
            return '+251' . substr($clean, 1);
        }
        if (strpos($clean, '251') === 0) {
            return '+' . $clean;
        }
        return $clean;
    }

    // 1. Auth Login Handler
    public static function login_handler($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $phone = self::format_phone($params['phone'] ?? '');
        $password = $params['password'] ?? '';

        if (empty($phone) || empty($password)) {
            return new WP_Error('missing_params', 'Phone number and password are required', ['status' => 400]);
        }

        $t_users = $wpdb->prefix . 'smart_dube_users';
        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_users WHERE phone = %s", $phone));

        if (!$user) {
            return new WP_Error('invalid_credentials', 'Invalid phone number or password', ['status' => 401]);
        }

        // Demo password fallback or wp_check_password
        $pass_valid = wp_check_password($password, $user->password_hash);
        if (!$pass_valid) {
            // Allow demo simple passwords
            if (
                ($phone === '+251911223344' && $password === 'merchant123') ||
                ($phone === '+251932167208' && $password === 'merchant1212') ||
                ($phone === '+251933445566' && $password === 'customer123') ||
                ($phone === '+251987005355' && $password === 'admin123')
            ) {
                $pass_valid = true;
            }
        }

        if (!$pass_valid) {
            return new WP_Error('invalid_credentials', 'Invalid phone number or password', ['status' => 401]);
        }

        // Fetch merchant_id if merchant
        $merchant_id = null;
        if ($user->role === 'MERCHANT') {
            $t_merchants = $wpdb->prefix . 'smart_dube_merchants';
            $merchant_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_merchants WHERE user_id = %d", $user->id));
        }

        // Token simulation
        $token = 'sd_tok_' . md5($user->id . time() . 'wp_secret');

        return rest_ensure_response([
            'success' => true,
            'user' => [
                'id'         => (int)$user->id,
                'full_name'  => $user->full_name,
                'phone'      => $user->phone,
                'email'      => $user->email,
                'role'       => $user->role,
                'fayda_id'   => $user->fayda_id,
                'merchant_id'=> $merchant_id ? (int)$merchant_id : null
            ],
            'token' => $token
        ]);
    }

    // 2. Get Me Handler
    public static function get_me_handler($request) {
        return rest_ensure_response(['success' => true, 'status' => 'authenticated']);
    }

    // 3. Customers Handler
    public static function get_customers_handler($request) {
        global $wpdb;
        $t_customers = $wpdb->prefix . 'smart_dube_customer_profiles';
        $customers = $wpdb->get_results("SELECT * FROM $t_customers ORDER BY id DESC");

        foreach ($customers as &$c) {
            $c->id = (int)$c->id;
            $c->merchant_id = (int)$c->merchant_id;
            $c->credit_limit = (float)$c->credit_limit;
            $c->current_balance = (float)$c->current_balance;
        }

        return rest_ensure_response($customers);
    }

    public static function create_customer_handler($request) {
        global $wpdb;
        $params = $request->get_json_params();

        $merchant_id  = $params['merchant_id'] ?? 1;
        $full_name    = sanitize_text_field($params['full_name'] ?? '');
        $phone        = self::format_phone($params['phone'] ?? '');
        $fayda_id     = sanitize_text_field($params['fayda_id'] ?? ('FIN-' . rand(1000,9999) . '-2024'));
        $credit_limit = floatval($params['credit_limit'] ?? 5000.00);

        if (empty($full_name) || empty($phone)) {
            return new WP_Error('missing_params', 'Full name and phone number are required', ['status' => 400]);
        }

        $t_customers = $wpdb->prefix . 'smart_dube_customer_profiles';
        
        // Check duplicate
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_customers WHERE merchant_id = %d AND phone = %s",
            $merchant_id, $phone
        ));

        if ($existing) {
            return new WP_Error('duplicate_customer', 'Customer with this phone number already exists for your store.', ['status' => 400]);
        }

        $wpdb->insert($t_customers, [
            'merchant_id'     => $merchant_id,
            'full_name'       => $full_name,
            'phone'           => $phone,
            'fayda_id'        => $fayda_id,
            'credit_limit'    => $credit_limit,
            'current_balance' => 0.00,
            'status'          => 'ACTIVE',
            'created_at'      => current_time('mysql')
        ]);

        $customer_id = $wpdb->insert_id;

        // Send Welcome SMS
        $msg = "Dear $full_name, welcome to Smart Dube BNPL credit system! Your credit limit is set to " . number_format($credit_limit, 2) . " ETB.";
        Smart_Dube_SMS::send($phone, $msg, 'CREDIT_ISSUED', $customer_id);

        return rest_ensure_response([
            'success' => true,
            'id' => $customer_id,
            'full_name' => $full_name,
            'phone' => $phone,
            'credit_limit' => $credit_limit,
            'current_balance' => 0.00,
            'status' => 'ACTIVE'
        ]);
    }

    // 4. Transactions Handler
    public static function get_transactions_handler($request) {
        global $wpdb;
        $t_trans = $wpdb->prefix . 'smart_dube_credit_transactions';
        $t_cust  = $wpdb->prefix . 'smart_dube_customer_profiles';

        $sql = "SELECT t.*, c.full_name as customer_name, c.phone as customer_phone 
                FROM $t_trans t 
                LEFT JOIN $t_cust c ON t.customer_id = c.id 
                ORDER BY t.id DESC";

        $rows = $wpdb->get_results($sql);

        foreach ($rows as &$r) {
            $r->id = (int)$r->id;
            $r->customer_id = (int)$r->customer_id;
            $r->merchant_id = (int)$r->merchant_id;
            $r->total_amount = (float)$r->total_amount;
            $r->items = json_decode($r->items_json);
        }

        return rest_ensure_response($rows);
    }

    public static function issue_credit_handler($request) {
        global $wpdb;
        $params = $request->get_json_params();

        $customer_id  = (int)($params['customer_id'] ?? 0);
        $merchant_id  = (int)($params['merchant_id'] ?? 1);
        $total_amount = floatval($params['total_amount'] ?? 0);
        $due_days     = (int)($params['due_days'] ?? 15);
        $items        = $params['items'] ?? [];
        $notes        = sanitize_text_field($params['notes'] ?? '');

        if (!$customer_id || $total_amount <= 0) {
            return new WP_Error('invalid_amount', 'Valid customer and total amount required', ['status' => 400]);
        }

        $t_cust = $wpdb->prefix . 'smart_dube_customer_profiles';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_cust WHERE id = %d", $customer_id));

        if (!$customer) {
            return new WP_Error('customer_not_found', 'Customer profile not found', ['status' => 404]);
        }

        // Check credit limit
        $new_balance = floatval($customer->current_balance) + $total_amount;
        if ($new_balance > floatval($customer->credit_limit)) {
            return new WP_Error('limit_exceeded', 'Transaction exceeds customer available credit limit', ['status' => 400]);
        }

        $ref = 'REF-' . time() . '-' . rand(100, 999);
        $due_date = date('Y-m-d', strtotime("+$due_days days"));

        $t_trans = $wpdb->prefix . 'smart_dube_credit_transactions';
        $wpdb->insert($t_trans, [
            'transaction_ref' => $ref,
            'customer_id'     => $customer_id,
            'merchant_id'     => $merchant_id,
            'items_json'      => json_encode($items),
            'total_amount'    => $total_amount,
            'due_date'        => $due_date,
            'status'          => 'PENDING',
            'notes'           => $notes,
            'created_at'      => current_time('mysql')
        ]);

        // Update customer current balance
        $wpdb->update($t_cust, ['current_balance' => $new_balance], ['id' => $customer_id]);

        // Send SMS Notice
        $msg = "Smart Dube Alert: Credit purchase of ETB " . number_format($total_amount, 2) . " recorded. Due date: $due_date. Ref: $ref.";
        Smart_Dube_SMS::send($customer->phone, $msg, 'CREDIT_ISSUED', $customer_id);

        return rest_ensure_response([
            'success' => true,
            'transaction_ref' => $ref,
            'total_amount' => $total_amount,
            'due_date' => $due_date,
            'new_balance' => $new_balance
        ]);
    }

    // 5. Repayments Handler
    public static function get_repayments_handler($request) {
        global $wpdb;
        $t_repay = $wpdb->prefix . 'smart_dube_repayments';
        $t_cust  = $wpdb->prefix . 'smart_dube_customer_profiles';

        $sql = "SELECT r.*, c.full_name as customer_name 
                FROM $t_repay r 
                LEFT JOIN $t_cust c ON r.customer_id = c.id 
                ORDER BY r.id DESC";

        $rows = $wpdb->get_results($sql);

        foreach ($rows as &$r) {
            $r->id = (int)$r->id;
            $r->customer_id = (int)$r->customer_id;
            $r->amount = (float)$r->amount;
        }

        return rest_ensure_response($rows);
    }

    public static function record_repayment_handler($request) {
        global $wpdb;
        $params = $request->get_json_params();

        $customer_id = (int)($params['customer_id'] ?? 0);
        $merchant_id = (int)($params['merchant_id'] ?? 1);
        $amount      = floatval($params['amount'] ?? 0);
        $gateway     = sanitize_text_field($params['payment_gateway'] ?? 'TELEBIRR');
        $ref_code    = sanitize_text_field($params['reference_code'] ?? ('TXN-' . rand(100000, 999999)));

        if (!$customer_id || $amount <= 0) {
            return new WP_Error('invalid_amount', 'Valid customer and repayment amount required', ['status' => 400]);
        }

        $t_cust = $wpdb->prefix . 'smart_dube_customer_profiles';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_cust WHERE id = %d", $customer_id));

        if (!$customer) {
            return new WP_Error('customer_not_found', 'Customer profile not found', ['status' => 404]);
        }

        $repay_ref = 'PAY-' . time() . '-' . rand(100, 999);
        $t_repay = $wpdb->prefix . 'smart_dube_repayments';

        $wpdb->insert($t_repay, [
            'repayment_ref'   => $repay_ref,
            'customer_id'     => $customer_id,
            'merchant_id'     => $merchant_id,
            'amount'          => $amount,
            'payment_gateway' => $gateway,
            'reference_code'  => $ref_code,
            'status'          => 'COMPLETED',
            'created_at'      => current_time('mysql')
        ]);

        // Reduce balance
        $new_balance = max(0, floatval($customer->current_balance) - $amount);
        $wpdb->update($t_cust, ['current_balance' => $new_balance], ['id' => $customer_id]);

        // Send SMS Receipt
        $msg = "Smart Dube Receipt: Repayment of ETB " . number_format($amount, 2) . " received via $gateway. Ref: $ref_code. Remaining balance: ETB " . number_format($new_balance, 2) . ". Thank you!";
        Smart_Dube_SMS::send($customer->phone, $msg, 'PAYMENT_RECEIPT', $customer_id);

        return rest_ensure_response([
            'success' => true,
            'repayment_ref' => $repay_ref,
            'amount' => $amount,
            'gateway' => $gateway,
            'new_balance' => $new_balance
        ]);
    }

    // 6. OCR Parse Handler
    public static function ocr_parse_handler($request) {
        return rest_ensure_response([
            'success' => true,
            'parsed' => [
                'merchant' => 'Telebirr Transfer',
                'amount'   => 500.00,
                'reference_code' => 'TLB-' . rand(100000, 999999),
                'confidence' => 0.98
            ]
        ]);
    }

    // 7. Stats Handler
    public static function get_stats_handler($request) {
        global $wpdb;
        $t_cust  = $wpdb->prefix . 'smart_dube_customer_profiles';
        $t_trans = $wpdb->prefix . 'smart_dube_credit_transactions';
        $t_repay = $wpdb->prefix . 'smart_dube_repayments';

        $total_customers = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t_cust");
        $total_credit    = (float)$wpdb->get_var("SELECT SUM(current_balance) FROM $t_cust");
        $total_issued    = (float)$wpdb->get_var("SELECT SUM(total_amount) FROM $t_trans");
        $total_repaid    = (float)$wpdb->get_var("SELECT SUM(amount) FROM $t_repay");

        return rest_ensure_response([
            'total_customers' => $total_customers,
            'total_outstanding' => $total_credit,
            'total_issued' => $total_issued,
            'total_repaid' => $total_repaid
        ]);
    }
}
}
