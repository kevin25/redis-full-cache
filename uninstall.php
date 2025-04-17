<?php
/**
 * WooCommerce Redis Cache Uninstall
 *
 * @package WC_Redis_Cache
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('wc_redis_cache_settings');

// For multisite installations
if (is_multisite()) {
    global $wpdb;
    
    // Get all blog IDs
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
    
    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        delete_option('wc_redis_cache_settings');
        restore_current_blog();
    }
}

// Note: We're not deleting Redis keys here because they're external to WordPress,
// and may be shared with other applications. Admins should manually clear Redis
// if needed via the Redis CLI or a Redis management tool.
