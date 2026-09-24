<?php
/**
 * Smart Dube SMS Gateway Helper (Africa's Talking Integration)
 *
 * @package SmartDube
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Smart_Dube_SMS')) {
class Smart_Dube_SMS {

    public static function send($phone, $message, $type = 'NOTIFICATION', $customer_id = null) {
        global $wpdb;

        $username = get_option('smart_dube_at_username', 'sandbox');
        $api_key  = get_option('smart_dube_at_apikey', '');
        $sender   = get_option('smart_dube_at_sender', 'SmartDube');

        $status = 'SIMULATED';

        if (!empty($api_key) && !empty($username) && $username !== 'sandbox') {
            $url = 'https://api.africastalking.com/version1/messaging';
            $response = wp_remote_post($url, [
                'headers' => [
                    'Accept'  => 'application/json',
                    'apiKey'  => $api_key
                ],
                'body' => [
                    'username' => $username,
                    'to'       => $phone,
                    'message'  => $message,
                    'from'     => $sender
                ],
                'timeout' => 15
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201) {
                $status = 'DELIVERED';
            } else {
                $status = 'FAILED';
            }
        }

        // Save log to DB
        $t_sms = $wpdb->prefix . 'smart_dube_sms_notifications';
        $wpdb->insert($t_sms, [
            'customer_id' => $customer_id,
            'phone'       => $phone,
            'message'     => $message,
            'type'        => $type,
            'status'      => $status,
            'sent_at'     => current_time('mysql')
        ]);

        return [
            'success' => ($status !== 'FAILED'),
            'status'  => $status,
            'message' => 'SMS queued/sent successfully.'
        ];
    }
}
}
