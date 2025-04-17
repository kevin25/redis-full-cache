<?php
/**
 * WooCommerce Redis Cache Initialization
 *
 * Handles plugin initialization and dependencies check
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Redis Cache Initialization
 */
class WC_Redis_Cache_Init {

    /**
     * Initialize plugin
     */
    public static function init() {
        // Check if WooCommerce is active
        if (self::is_woocommerce_active()) {
            // Load the main plugin class
            require_once WC_REDIS_CACHE_PATH . 'woocommerce-redis-cache.php';
        } else {
            // Show admin notice if WooCommerce is not active
            add_action('admin_notices', [__CLASS__, 'woocommerce_not_active_notice']);
            
            // Deactivate plugin
            add_action('admin_init', [__CLASS__, 'deactivate_plugin']);
        }
    }

    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    public static function is_woocommerce_active() {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        return is_plugin_active('woocommerce/woocommerce.php');
    }

    /**
     * Show admin notice if WooCommerce is not active
     */
    public static function woocommerce_not_active_notice() {
        ?>
        <div class="error">
            <p><?php _e('WooCommerce Redis Cache requires WooCommerce to be installed and active.', 'wc-redis-cache'); ?></p>
        </div>
        <?php
    }

    /**
     * Deactivate plugin
     */
    public static function deactivate_plugin() {
        deactivate_plugins(WC_REDIS_CACHE_BASENAME);
    }

    /**
     * Check if Redis extension is installed
     *
     * @return bool
     */
    public static function is_redis_available() {
        return extension_loaded('redis');
    }

    /**
     * Show admin notice if Redis extension is not installed
     */
    public static function redis_not_installed_notice() {
        ?>
        <div class="error">
            <p><?php _e('WooCommerce Redis Cache requires the PHP Redis extension to be installed. Please install the extension or contact your hosting provider.', 'wc-redis-cache'); ?></p>
        </div>
        <?php
    }
}