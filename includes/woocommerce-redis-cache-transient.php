<?php
/**
 * WooCommerce Redis Transient Cache
 *
 * Handles caching WordPress transients used by WooCommerce in Redis
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Redis Transient Cache
 */
class WC_Redis_Cache_Transient {

    /**
     * Parent plugin instance
     *
     * @var WC_Redis_Cache
     */
    protected $plugin;

    /**
     * Constructor
     *
     * @param WC_Redis_Cache $plugin
     */
    public function __construct($plugin) {
        $this->plugin = $plugin;
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    public function init_hooks() {
        // Override transient functions
        add_filter('pre_transient_wc_product_loop', [$this, 'get_transient'], 10, 2);
        add_filter('pre_transient_wc_products_onsale', [$this, 'get_transient'], 10, 2);
        add_filter('pre_transient_wc_featured_products', [$this, 'get_transient'], 10, 2);
        add_filter('pre_transient_wc_term_counts', [$this, 'get_transient'], 10, 2);
        add_filter('pre_transient_wc_shipping_method_count', [$this, 'get_transient'], 10, 2);
        add_filter('pre_transient_wc_attribute_taxonomies', [$this, 'get_transient'], 10, 2);
        
        // Wildcard for all WooCommerce transients
        add_filter('pre_transient_wc_', [$this, 'get_transient_wildcard'], 0, 2);
        
        // Set transient hooks
        add_filter('set_transient_wc_product_loop', [$this, 'set_transient'], 10, 3);
        add_filter('set_transient_wc_products_onsale', [$this, 'set_transient'], 10, 3);
        add_filter('set_transient_wc_featured_products', [$this, 'set_transient'], 10, 3);
        add_filter('set_transient_wc_term_counts', [$this, 'set_transient'], 10, 3);
        add_filter('set_transient_wc_shipping_method_count', [$this, 'set_transient'], 10, 3);
        add_filter('set_transient_wc_attribute_taxonomies', [$this, 'set_transient'], 10, 3);
        
        // Delete transient hooks
        add_action('delete_transient', [$this, 'delete_transient']);
    }

    /**
     * Get transient value from Redis
     *
     * @param mixed $value Default value
     * @param string $transient Transient name
     * @return mixed
     */
    public function get_transient($value, $transient) {
        if (!$this->plugin->is_connected()) {
            return $value;
        }

        $cache_key = $this->get_cache_key($transient);
        $cached_value = $this->plugin->get($cache_key);
        
        if ($cached_value !== false) {
            return $cached_value;
        }
        
        return $value;
    }

    /**
     * Get transient value from Redis using a wildcard match
     * Useful for handling dynamic WooCommerce transients
     *
     * @param mixed $value Default value
     * @param string $transient Transient name
     * @return mixed
     */
    public function get_transient_wildcard($value, $transient) {
        // Only match WooCommerce transients
        if (strpos($transient, 'wc_') !== 0) {
            return $value;
        }
        
        return $this->get_transient($value, $transient);
    }

    /**
     * Set transient value in Redis
     *
     * @param mixed $value Transient value
     * @param int $expiration Expiration time in seconds
     * @param string $transient Transient name
     * @return mixed
     */
    public function set_transient($value, $expiration, $transient) {
        if (!$this->plugin->is_connected() || $value === false) {
            return $value;
        }

        $cache_key = $this->get_cache_key($transient);
        
        // Use specified expiration or default TTL
        $ttl = $expiration > 0 ? $expiration : $this->plugin->get_ttl('transient');
        
        $this->plugin->set($cache_key, $value, $ttl);
        
        return $value;
    }

    /**
     * Delete transient from Redis
     *
     * @param string $transient Transient name
     * @return bool
     */
    public function delete_transient($transient) {
        // Only handle WooCommerce transients
        if (strpos($transient, 'wc_') !== 0) {
            return false;
        }
        
        if (!$this->plugin->is_connected()) {
            return false;
        }

        $cache_key = $this->get_cache_key($transient);
        return $this->plugin->delete($cache_key);
    }

    /**
     * Get cache key for a transient
     *
     * @param string $transient Transient name
     * @return string
     */
    protected function get_cache_key($transient) {
        $blog_id = get_current_blog_id();
        return "wc:{$blog_id}:transient:{$transient}";
    }

    /**
     * Clear all WooCommerce transients
     * 
     * @return int Number of deleted keys
     */
    public function clear_all_transients() {
        if (!$this->plugin->is_connected()) {
            return 0;
        }
        
        $blog_id = get_current_blog_id();
        $pattern = "wc:{$blog_id}:transient:*";
        
        return $this->plugin->delete_by_pattern($pattern);
    }
}
