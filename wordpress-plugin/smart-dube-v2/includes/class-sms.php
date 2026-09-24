<?php
/**
 * Smart Dube SMS Gateway (Africa's Talking Integration)
 *
 * @package SmartDube
 */

if (!defined('ABSPATH')) {
    exit;
}

class Smart_Dube_SMS {

    public static function send_sms($phone, $message, $customer_id = null, $type = 'REMINDER') {
        global $wpdb;

        $username = get_option('smart_dube_at_username', 'Dbusms');
        $api_key = get_option('smart_dube_at_apikey', 'atsk_1af4ca589d97fae69fa09fa845f2ad3e77864f19e7a836d396996d9326e5e0ba235e1657');
        $sender_id = get_option('smart_dube_at_sender', '');

        // Format phone number (+251...)
        $formatted_phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strpos($formatted_phone, '0') === 0) {
            $formatted_phone = '+251' . substr($formatted_phone, 1);
        } elseif (strpos($formatted_phone, '251') === 0) {
            $formatted_phone = '+' . $formatted_phone;
        }

        $url = 'https://api.africastalking.com/version1/messaging';
        if (strpos($username, 'sandbox') !== false || empty($username)) {
            $url = 'https://api.sandbox.africastalking.com/version1/messaging';
        }

        $body = [
            'username' => $username,
            'to'       => $formatted_phone,
            'message'  => $message
        ];

        if (!empty($sender_id)) {
            $body['from'] = $sender_id;
        }

        $response = wp_remote_post($url, [
            'method'  => 'POST',
            'headers' => [
                'apiKey'       => $api_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json'
            ],
            'body'    => http_build_query($body),
            'timeout' => 15
        ]);

        $status = 'DELIVERED';
        if (is_wp_error($response)) {
            $status = 'FAILED';
        }

        // Log into database
        $table_sms = $wpdb->prefix . 'dube_sms_notifications';
        $wpdb->insert($table_sms, [
            'customer_id' => $customer_id,
            'phone'       => $formatted_phone,
            'message'     => $message,
            'type'        => $type,
            'status'      => $status,
            'sent_at'     => current_time('mysql')
        ]);

        return [
            'status' => $status,
            'phone'  => $formatted_phone
        ];
    }
}
