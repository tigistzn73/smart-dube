<?php
/**
 * Smart Dube Database Schema & Initializer
 *
 * @package SmartDube
 */

if (!defined('ABSPATH')) {
    exit;
}

class Smart_Dube_Database {

    public static function init_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // 1. Users table
        $table_users = $wpdb->prefix . 'dube_users';
        $sql_users = "CREATE TABLE $table_users (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            full_name varchar(200) NOT NULL,
            phone varchar(20) NOT NULL,
            email varchar(200) DEFAULT NULL,
            role varchar(20) NOT NULL,
            password_hash text NOT NULL,
            fayda_id varchar(50) DEFAULT NULL,
            photo_url text DEFAULT NULL,
            reset_token varchar(10) DEFAULT NULL,
            reset_token_expires datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY phone (phone)
        ) $charset_collate;";
        dbDelta($sql_users);

        // 2. Merchants table
        $table_merchants = $wpdb->prefix . 'dube_merchants';
        $sql_merchants = "CREATE TABLE $table_merchants (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            store_name varchar(200) NOT NULL,
            business_license_no varchar(100) NOT NULL,
            address text NOT NULL,
            kyc_status varchar(20) DEFAULT 'PENDING' NOT NULL,
            verified_at datetime DEFAULT NULL,
            kyc_notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta($sql_merchants);

        // 3. Customer Profiles table
        $table_customer_profiles = $wpdb->prefix . 'dube_customer_profiles';
        $sql_customer_profiles = "CREATE TABLE $table_customer_profiles (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            merchant_id bigint(20) NOT NULL,
            user_id bigint(20) DEFAULT NULL,
            full_name varchar(200) NOT NULL,
            phone varchar(20) NOT NULL,
            fayda_id varchar(50) NOT NULL,
            photo_url text DEFAULT NULL,
            credit_limit decimal(12,2) DEFAULT '5000.00' NOT NULL,
            current_balance decimal(12,2) DEFAULT '0.00' NOT NULL,
            status varchar(20) DEFAULT 'ACTIVE' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY merchant_id (merchant_id),
            KEY user_id (user_id),
            KEY phone (phone)
        ) $charset_collate;";
        dbDelta($sql_customer_profiles);

        // 4. Credit Transactions table
        $table_credit_transactions = $wpdb->prefix . 'dube_credit_transactions';
        $sql_credit_transactions = "CREATE TABLE $table_credit_transactions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            transaction_ref varchar(100) NOT NULL,
            customer_id bigint(20) NOT NULL,
            merchant_id bigint(20) NOT NULL,
            items_json longtext NOT NULL,
            total_amount decimal(12,2) NOT NULL,
            due_date date NOT NULL,
            status varchar(20) DEFAULT 'PENDING' NOT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY transaction_ref (transaction_ref),
            KEY customer_id (customer_id),
            KEY merchant_id (merchant_id)
        ) $charset_collate;";
        dbDelta($sql_credit_transactions);

        // 5. Repayments table
        $table_repayments = $wpdb->prefix . 'dube_repayments';
        $sql_repayments = "CREATE TABLE $table_repayments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            repayment_ref varchar(100) NOT NULL,
            transaction_id bigint(20) DEFAULT NULL,
            customer_id bigint(20) NOT NULL,
            merchant_id bigint(20) NOT NULL,
            amount decimal(12,2) NOT NULL,
            payment_gateway varchar(30) NOT NULL,
            reference_code varchar(100) NOT NULL,
            receipt_url text DEFAULT NULL,
            status varchar(20) DEFAULT 'PENDING' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY repayment_ref (repayment_ref),
            KEY customer_id (customer_id),
            KEY merchant_id (merchant_id)
        ) $charset_collate;";
        dbDelta($sql_repayments);

        // 6. SMS Notifications table
        $table_sms = $wpdb->prefix . 'dube_sms_notifications';
        $sql_sms = "CREATE TABLE $table_sms (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) DEFAULT NULL,
            phone varchar(20) NOT NULL,
            message text NOT NULL,
            type varchar(30) NOT NULL,
            status varchar(20) DEFAULT 'SIMULATED' NOT NULL,
            sent_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY customer_id (customer_id)
        ) $charset_collate;";
        dbDelta($sql_sms);

        // 7. Payment Gateway Logs table
        $table_gateway_logs = $wpdb->prefix . 'dube_payment_gateway_logs';
        $sql_gateway_logs = "CREATE TABLE $table_gateway_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            gateway_name varchar(30) NOT NULL,
            event_type varchar(50) NOT NULL,
            payload_json longtext NOT NULL,
            response_status varchar(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta($sql_gateway_logs);

        // 8. Audit Logs table
        $table_audit_logs = $wpdb->prefix . 'dube_audit_logs';
        $sql_audit_logs = "CREATE TABLE $table_audit_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            actor_name varchar(200) NOT NULL,
            action varchar(100) NOT NULL,
            resource varchar(200) NOT NULL,
            details_json longtext NOT NULL,
            ip_address varchar(45) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta($sql_audit_logs);

        // 9. Installment Schedules table
        $table_schedules = $wpdb->prefix . 'dube_installment_schedules';
        $sql_schedules = "CREATE TABLE $table_schedules (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            transaction_id bigint(20) DEFAULT NULL,
            customer_id bigint(20) NOT NULL,
            merchant_id bigint(20) NOT NULL,
            installment_number int(11) NOT NULL,
            due_date date NOT NULL,
            amount decimal(12,2) NOT NULL,
            paid_amount decimal(12,2) DEFAULT '0.00' NOT NULL,
            status varchar(20) DEFAULT 'PENDING' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY transaction_id (transaction_id),
            KEY customer_id (customer_id),
            KEY merchant_id (merchant_id)
        ) $charset_collate;";
        dbDelta($sql_schedules);

        // Seed initial demo records if empty
        self::seed_initial_data();
    }

    public static function seed_initial_data() {
        global $wpdb;
        $table_users = $wpdb->prefix . 'dube_users';
        $user_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_users");

        if (intval($user_count) === 0) {
            $admin_hash = password_hash('admin123', PASSWORD_BCRYPT);
            $merchant_hash = password_hash('merchant123', PASSWORD_BCRYPT);
            $merchant1212_hash = password_hash('merchant1212', PASSWORD_BCRYPT);
            $customer_hash = password_hash('customer123', PASSWORD_BCRYPT);

            // 1. Admin User
            $wpdb->insert($table_users, [
                'full_name' => 'Solomon Kebede (Admin)',
                'phone' => '+251987005355',
                'email' => 'admin@smartdube.et',
                'role' => 'ADMIN',
                'password_hash' => $admin_hash,
                'fayda_id' => 'FYD-8890-1122-33',
                'photo_url' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=Solomon'
            ]);
            $admin_id = $wpdb->insert_id;

            // 2. Merchant User 1
            $wpdb->insert($table_users, [
                'full_name' => 'Abebe Bikila',
                'phone' => '+251911223344',
                'email' => 'abebe@bikalastore.et',
                'role' => 'MERCHANT',
                'password_hash' => $merchant_hash,
                'fayda_id' => 'FYD-4455-6677-88',
                'photo_url' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=Abebe'
            ]);
            $merchant_user1_id = $wpdb->insert_id;

            // 3. Merchant User 2 (Zemero)
            $wpdb->insert($table_users, [
                'full_name' => 'Zemero Supermarket',
                'phone' => '+251932167208',
                'email' => 'zemero@gmail.com',
                'role' => 'MERCHANT',
                'password_hash' => $merchant1212_hash,
                'fayda_id' => 'FYD-3322-1144-55',
                'photo_url' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=Zemero'
            ]);
            $merchant_user2_id = $wpdb->insert_id;

            // 4. Customer User 1
            $wpdb->insert($table_users, [
                'full_name' => 'Dawit Yohannes',
                'phone' => '+251933445566',
                'email' => 'dawit@gmail.com',
                'role' => 'CUSTOMER',
                'password_hash' => $customer_hash,
                'fayda_id' => 'FYD-9988-7766-55',
                'photo_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150'
            ]);
            $customer_user1_id = $wpdb->insert_id;

            // 5. Merchants table
            $table_merchants = $wpdb->prefix . 'dube_merchants';
            $wpdb->insert($table_merchants, [
                'user_id' => $merchant_user1_id,
                'store_name' => 'Arada Neighborhood Supermarket',
                'business_license_no' => 'BL-ADDIS-2024-9981',
                'address' => 'Arada Sub-city, Woreda 03, Addis Ababa',
                'kyc_status' => 'VERIFIED',
                'verified_at' => current_time('mysql'),
                'kyc_notes' => 'Verified on plugin activation'
            ]);
            $merchant1_id = $wpdb->insert_id;

            $wpdb->insert($table_merchants, [
                'user_id' => $merchant_user2_id,
                'store_name' => 'Zemero Supermarket & Dube',
                'business_license_no' => 'BL-KIRKOS-2026-1192',
                'address' => 'Kirkos, Addis Ababa',
                'kyc_status' => 'VERIFIED',
                'verified_at' => current_time('mysql'),
                'kyc_notes' => 'Verified on plugin activation'
            ]);

            // 6. Customer Profile
            $table_customer_profiles = $wpdb->prefix . 'dube_customer_profiles';
            $wpdb->insert($table_customer_profiles, [
                'merchant_id' => $merchant1_id,
                'user_id' => $customer_user1_id,
                'full_name' => 'Dawit Yohannes',
                'phone' => '+251933445566',
                'fayda_id' => 'FYD-9988-7766-55',
                'photo_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150',
                'credit_limit' => 8000.00,
                'current_balance' => 3250.00,
                'status' => 'ACTIVE'
            ]);
            $cp1_id = $wpdb->insert_id;

            // 7. Credit Transaction
            $table_credit_transactions = $wpdb->prefix . 'dube_credit_transactions';
            $items_sample = json_encode([
                ['name' => 'Teff Flour (25kg)', 'quantity' => 1, 'unitPrice' => 2200.00, 'total' => 2200.00],
                ['name' => 'Sunflower Cooking Oil (5L)', 'quantity' => 1, 'unitPrice' => 1050.00, 'total' => 1050.00]
            ]);
            $wpdb->insert($table_credit_transactions, [
                'transaction_ref' => 'DUBE-1001',
                'customer_id' => $cp1_id,
                'merchant_id' => $merchant1_id,
                'items_json' => $items_sample,
                'total_amount' => 3250.00,
                'due_date' => date('Y-m-d', strtotime('+14 days')),
                'status' => 'PENDING',
                'notes' => 'Monthly grocery credit Dube'
            ]);
        }
    }
}
