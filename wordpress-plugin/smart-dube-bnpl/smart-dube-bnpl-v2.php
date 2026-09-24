<?php
/**
 * Plugin Name: Smart Dube - Ethiopian BNPL Digital Ledger
 * Plugin URI: https://github.com/tigistzn73/smart-dube
 * Description: Complete Buy-Now-Pay-Later (BNPL), neighborhood credit ledger, receipt OCR scanner, and SMS reminder system for Ethiopian merchants and customers.
 * Version: 1.0.3
 * Author: Tigist Zinabu & Smart Dube Team
 * Author URI: https://github.com/tigistzn73
 * License: GPL-2.0+
 * Text Domain: smart-dube
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SMART_DUBE_VERSION')) {
    define('SMART_DUBE_VERSION', '1.0.3.' . time());
}
if (!defined('SMART_DUBE_PLUGIN_DIR')) {
    define('SMART_DUBE_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('SMART_DUBE_PLUGIN_URL')) {
    define('SMART_DUBE_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// Require Core Modules
require_once SMART_DUBE_PLUGIN_DIR . 'includes/class-database.php';
require_once SMART_DUBE_PLUGIN_DIR . 'includes/class-sms.php';
require_once SMART_DUBE_PLUGIN_DIR . 'includes/class-api.php';
require_once SMART_DUBE_PLUGIN_DIR . 'includes/class-admin.php';

// Plugin Activation: Create database tables and seed demo data
register_activation_hook(__FILE__, function() {
    if (class_exists('Smart_Dube_Database')) {
        Smart_Dube_Database::init_tables();
    }
});

// Auto-ensure database schema is ready on every REST API & page request
add_action('init', function() {
    if (class_exists('Smart_Dube_Database')) {
        Smart_Dube_Database::maybe_init_tables();
    }
});

// Register WordPress REST API Routes
add_action('rest_api_init', function() {
    if (class_exists('Smart_Dube_Database')) {
        Smart_Dube_Database::maybe_init_tables();
    }
    if (class_exists('Smart_Dube_API')) {
        Smart_Dube_API::register_routes();
    }
});

// Initialize Admin Settings Page
if (class_exists('Smart_Dube_Admin')) {
    Smart_Dube_Admin::init();
}

// Main App Render Callback
if (!function_exists('smart_dube_render_app_shortcode')) {
    function smart_dube_render_app_shortcode($atts = []) {
        $js_files = glob(SMART_DUBE_PLUGIN_DIR . 'assets/*.js');
        $css_files = glob(SMART_DUBE_PLUGIN_DIR . 'assets/*.css');
        $js_file = !empty($js_files) ? basename(end($js_files)) : 'index.js';
        $css_file = !empty($css_files) ? basename(end($css_files)) : 'index.css';

        // 1. Enqueue React CSS
        wp_enqueue_style(
            'smart-dube-css',
            SMART_DUBE_PLUGIN_URL . 'assets/' . $css_file,
            [],
            SMART_DUBE_VERSION
        );

        // 2. Enqueue React Bundle JS
        wp_enqueue_script(
            'smart-dube-js',
            SMART_DUBE_PLUGIN_URL . 'assets/' . $js_file,
            [],
            SMART_DUBE_VERSION,
            true
        );

        // 3. Pass WordPress REST API settings to React frontend
        wp_localize_script('smart-dube-js', 'smartDubeSettings', [
            'apiUrl' => rest_url('smart-dube/v1'),
            'nonce'  => wp_create_nonce('wp_rest')
        ]);

        // 4. Output Root Mount Point for React with Full-Width Auto-Override
        $output = '<style>
        .ast-container, .site-content, .entry-content, .ast-article-single, .ast-separate-container {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
        h1.entry-title, .entry-title, .entry-header, .page-header, .ast-single-post-order, .ast-archive-description {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .smart-dube-app-wrapper {
            width: 100% !important;
            max-width: 100% !important;
            min-height: 95vh !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        </style>';
        $output .= '<div id="root" class="smart-dube-app-wrapper" style="min-height: 90vh; width: 100%;"></div>';
        return $output;
    }
}

// Register all shortcode variations with brackets [smart_dube_app], [smart-dube-app], [smart_dube], [smart-dube], [smartdube]
$shortcodes = ['smart_dube_app', 'smart-dube-app', 'smart_dube', 'smart-dube', 'smartdube'];
foreach ($shortcodes as $sc) {
    add_shortcode($sc, 'smart_dube_render_app_shortcode');
}
