<?php
/**
 * Smart Dube WordPress Database Class
 *
 * @package SmartDube
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Smart_Dube_Database')) {
class Smart_Dube_Database {

    public static function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . 'smart_dube_' . $table;
    }

    public static function maybe_init_tables() {
        if (get_option('smart_dube_db_version') !== '1.0.3') {
            self::init_tables();
        }
    }

    public static function init_tables() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        $t_users        = self::get_table_name('users');
        $t_merchants    = self::get_table_name('merchants');
        $t_customers    = self::get_table_name('customer_profiles');
        $t_transactions = self::get_table_name('credit_transactions');
        $t_repayments   = self::get_table_name('repayments');
        $t_sms          = self::get_table_name('sms_notifications');

        // 1. Users table
        $sql_users = "CREATE TABLE $t_users (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(200) NOT NULL,
            phone VARCHAR(30) NOT NULL UNIQUE,
            email VARCHAR(200) DEFAULT '',
            role VARCHAR(30) NOT NULL DEFAULT 'CUSTOMER',
            password_hash VARCHAR(255) NOT NULL,
            fayda_id VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY phone_idx (phone)
        ) $charset_collate;";

        // 2. Merchants table
        $sql_merchants = "CREATE TABLE $t_merchants (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) NOT NULL,
            store_name VARCHAR(200) NOT NULL,
            business_license_no VARCHAR(100) NOT NULL,
            address TEXT NOT NULL,
            kyc_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id_idx (user_id)
        ) $charset_collate;";

        // 3. Customer profiles
        $sql_customers = "CREATE TABLE $t_customers (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            merchant_id BIGINT(20) NOT NULL,
            user_id BIGINT(20) DEFAULT NULL,
            full_name VARCHAR(200) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            fayda_id VARCHAR(50) NOT NULL,
            photo_url TEXT DEFAULT NULL,
            credit_limit DECIMAL(12,2) NOT NULL DEFAULT 5000.00,
            current_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY merchant_phone_idx (merchant_id, phone)
        ) $charset_collate;";

        // 4. Credit transactions
        $sql_transactions = "CREATE TABLE $t_transactions (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            transaction_ref VARCHAR(100) NOT NULL UNIQUE,
            customer_id BIGINT(20) NOT NULL,
            merchant_id BIGINT(20) NOT NULL,
            items_json TEXT NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            due_date DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY customer_idx (customer_id),
            KEY merchant_idx (merchant_id)
        ) $charset_collate;";

        // 5. Repayments
        $sql_repayments = "CREATE TABLE $t_repayments (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            repayment_ref VARCHAR(100) NOT NULL UNIQUE,
            transaction_id BIGINT(20) DEFAULT NULL,
            customer_id BIGINT(20) NOT NULL,
            merchant_id BIGINT(20) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_gateway VARCHAR(50) NOT NULL DEFAULT 'CASH',
            reference_code VARCHAR(100) NOT NULL,
            receipt_url TEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'COMPLETED',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY customer_repay_idx (customer_id)
        ) $charset_collate;";

        // 6. SMS notifications
        $sql_sms = "CREATE TABLE $t_sms (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            customer_id BIGINT(20) DEFAULT NULL,
            phone VARCHAR(30) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'SIMULATED',
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        dbDelta($sql_users);
        dbDelta($sql_merchants);
        dbDelta($sql_customers);
        dbDelta($sql_transactions);
        dbDelta($sql_repayments);
        dbDelta($sql_sms);

        self::seed_demo_data();

        update_option('smart_dube_db_version', '1.0.3');
    }

    public static function seed_demo_data() {
        global $wpdb;
        $t_users     = self::get_table_name('users');
        $t_merchants = self::get_table_name('merchants');
        $t_customers = self::get_table_name('customer_profiles');
        $t_trans     = self::get_table_name('credit_transactions');

        // Check if users already seeded
        $existing_users = $wpdb->get_var("SELECT COUNT(*) FROM $t_users");
        if ((int)$existing_users > 0) {
            return;
        }

        // Passwords
        $pass_m1 = wp_hash_password('merchant123');
        $pass_m2 = wp_hash_password('merchant1212');
        $pass_c1 = wp_hash_password('customer123');
        $pass_ad = wp_hash_password('admin123');

        // Insert Users
        $wpdb->insert($t_users, [
            'full_name' => 'Arada Supermarket',
            'phone'     => '+251911223344',
            'email'     => 'arada@smartdube.et',
            'role'      => 'MERCHANT',
            'password_hash' => $pass_m1,
            'fayda_id'  => 'FIN-9901-2024-M1'
        ]);
        $u_m1_id = $wpdb->insert_id;

        $wpdb->insert($t_users, [
            'full_name' => 'Zemero Supermarket',
            'phone'     => '+251932167208',
            'email'     => 'zemero@smartdube.et',
            'role'      => 'MERCHANT',
            'password_hash' => $pass_m2,
            'fayda_id'  => 'FIN-8812-2024-M2'
        ]);
        $u_m2_id = $wpdb->insert_id;

        $wpdb->insert($t_users, [
            'full_name' => 'Dawit Yohannes',
            'phone'     => '+251933445566',
            'email'     => 'dawit@smartdube.et',
            'role'      => 'CUSTOMER',
            'password_hash' => $pass_c1,
            'fayda_id'  => 'FIN-4455-2024-C1'
        ]);
        $u_c1_id = $wpdb->insert_id;

        $wpdb->insert($t_users, [
            'full_name' => 'System Administrator',
            'phone'     => '+251987005355',
            'email'     => 'admin@smartdube.et',
            'role'      => 'ADMIN',
            'password_hash' => $pass_ad,
            'fayda_id'  => 'FIN-0001-2024-AD'
        ]);

        // Insert Merchants
        $wpdb->insert($t_merchants, [
            'user_id' => $u_m1_id,
            'store_name' => 'Arada Supermarket (Piazza)',
            'business_license_no' => 'BL-ADD-2024-0091',
            'address' => 'Piazza Church Street, Addis Ababa',
            'kyc_status' => 'VERIFIED'
        ]);
        $m1_id = $wpdb->insert_id;

        $wpdb->insert($t_merchants, [
            'user_id' => $u_m2_id,
            'store_name' => 'Zemero Supermarket (Bole)',
            'business_license_no' => 'BL-ADD-2024-0182',
            'address' => 'Bole Road, Near Friendship Mall, Addis Ababa',
            'kyc_status' => 'VERIFIED'
        ]);

        // Insert Customer Profiles for Merchant 1
        $wpdb->insert($t_customers, [
            'merchant_id' => $m1_id,
            'user_id' => $u_c1_id,
            'full_name' => 'Dawit Yohannes',
            'phone' => '+251933445566',
            'fayda_id' => 'FIN-4455-2024-C1',
            'credit_limit' => 5000.00,
            'current_balance' => 1200.00,
            'status' => 'ACTIVE'
        ]);
        $c1_profile_id = $wpdb->insert_id;

        $wpdb->insert($t_customers, [
            'merchant_id' => $m1_id,
            'user_id' => null,
            'full_name' => 'Bethlehem Tadesse',
            'phone' => '+251912345678',
            'fayda_id' => 'FIN-7788-2024-C2',
            'credit_limit' => 4000.00,
            'current_balance' => 450.00,
            'status' => 'ACTIVE'
        ]);

        // Insert Demo Credit Transaction
        $items = [
            ['name' => 'Teff (25kg)', 'qty' => 1, 'price' => 1000.00],
            ['name' => 'Cooking Oil (2L)', 'qty' => 1, 'price' => 200.00]
        ];
        $wpdb->insert($t_trans, [
            'transaction_ref' => 'REF-' . time() . '-001',
            'customer_id' => $c1_profile_id,
            'merchant_id' => $m1_id,
            'items_json' => json_encode($items),
            'total_amount' => 1200.00,
            'due_date' => date('Y-m-d', strtotime('+15 days')),
            'status' => 'PENDING',
            'notes' => 'Initial grocery credit purchase'
        ]);
    }
}
}
