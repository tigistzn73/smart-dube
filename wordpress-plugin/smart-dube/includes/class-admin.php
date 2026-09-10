<?php
/**
 * Smart Dube WordPress Admin Settings Page
 *
 * @package SmartDube
 */

if (!defined('ABSPATH')) {
    exit;
}

class Smart_Dube_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_admin_menu() {
        add_menu_page(
            'Smart Dube BNPL',
            'Smart Dube',
            'manage_options',
            'smart-dube-settings',
            [__CLASS__, 'render_admin_page'],
            'dashicons-money-alt',
            30
        );
    }

    public static function register_settings() {
        register_setting('smart_dube_settings_group', 'smart_dube_at_username');
        register_setting('smart_dube_settings_group', 'smart_dube_at_apikey');
        register_setting('smart_dube_settings_group', 'smart_dube_at_sender');
    }

    public static function render_admin_page() {
        ?>
        <div class="wrap" style="max-width: 900px;">
            <h1 style="display: flex; align-items: center; gap: 10px;">
                <span style="background: linear-gradient(135deg, #009639, #FCDD09, #DA121A); color: #000; font-weight: 900; padding: 4px 10px; border-radius: 8px; font-size: 16px;">ET</span>
                Smart Dube (???? ??) — Settings & Setup
            </h1>
            <p>Ethiopian Digital BNPL Credit & Ledger System for WordPress.</p>

            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 8px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #009639;">?? How to Display on Your Website</h2>
                <p>Add the following Shortcode to any page (e.g. <code>/smart-dube</code>):</p>
                <div style="background: #f0f0f1; padding: 12px 18px; border-left: 4px solid #009639; font-family: monospace; font-size: 16px; font-weight: bold; border-radius: 4px;">
                    [smart_dube_app]
                </div>
            </div>

            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 8px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #1d2327;">?? Demo Accounts Included</h2>
                <table class="widefat striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Phone Number</th>
                            <th>Password</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Merchant</strong></td>
                            <td><code>+251911223344</code></td>
                            <td><code>merchant123</code></td>
                            <td>Arada Supermarket</td>
                        </tr>
                        <tr>
                            <td><strong>Merchant</strong></td>
                            <td><code>+251932167208</code></td>
                            <td><code>merchant1212</code></td>
                            <td>Zemero Supermarket</td>
                        </tr>
                        <tr>
                            <td><strong>Customer</strong></td>
                            <td><code>+251933445566</code></td>
                            <td><code>customer123</code></td>
                            <td>Dawit Yohannes</td>
                        </tr>
                        <tr>
                            <td><strong>Admin</strong></td>
                            <td><code>+251987005355</code></td>
                            <td><code>admin123</code></td>
                            <td>System Administrator</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 8px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #1d2327;">?? Africa's Talking SMS Gateway Settings</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('smart_dube_settings_group'); ?>
                    <?php do_settings_sections('smart_dube_settings_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Username</th>
                            <td>
                                <input type="text" name="smart_dube_at_username" value="<?php echo esc_attr(get_option('smart_dube_at_username', 'Dbusms')); ?>" class="regular-text" />
                                <p class="description">Your Africa's Talking username (or 'sandbox' for testing).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API Key</th>
                            <td>
                                <input type="password" name="smart_dube_at_apikey" value="<?php echo esc_attr(get_option('smart_dube_at_apikey', 'atsk_1af4ca589d97fae69fa09fa845f2ad3e77864f19e7a836d396996d9326e5e0ba235e1657')); ?>" class="regular-text" />
                                <p class="description">Your Africa's Talking API key.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Sender ID (Optional)</th>
                            <td>
                                <input type="text" name="smart_dube_at_sender" value="<?php echo esc_attr(get_option('smart_dube_at_sender', '')); ?>" class="regular-text" />
                                <p class="description">Optional custom alphanumeric Sender ID (e.g. SMARTDUBE).</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save SMS Settings'); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
