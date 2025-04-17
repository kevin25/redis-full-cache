<?php
/**
 * WooCommerce Redis Session Handler
 *
 * Class that extends the WC_Session_Handler to store session data in Redis
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redis-based WooCommerce session handler
 */
class WC_Session_Handler_Redis extends WC_Session_Handler {

    /**
     * Redis connection
     *
     * @var Redis
     */
    protected $redis;

    /**
     * Session TTL in seconds
     *
     * @var int
     */
    protected $ttl;

    /**
     * Constructor
     */
    public function __construct() {
        // Call parent constructor
        parent::__construct();
        
        // Get plugin instance
        $plugin = WC_Redis_Cache();
        
        // Store Redis connection
        $this->redis = $plugin->get_redis();
        
        // Get session TTL
        $this->ttl = $plugin->get_ttl('session');
        
        // Set cookie if it doesn't exist already
        if ($this->redis && !$this->has_session()) {
            $this->set_customer_session_cookie(true);
        }
    }

    /**
     * Get session from Redis
     *
     * @param string $customer_id
     * @param bool $force_refresh
     * @return bool
     */
    public function get_session_data() {
        if (!$this->redis || !$this->_customer_id) {
            return parent::get_session_data();
        }

        $cache_key = $this->get_cache_key();
        $value = $this->redis->get($cache_key);
        
        if (!empty($value)) {
            $this->_data = maybe_unserialize($value);
            // Extend session expiration
            $this->redis->expire($cache_key, $this->ttl);
        }
        
        return $this->has_session() ? (array) $this->_data : [];
    }

    /**
     * Save data to Redis
     */
    public function save_data() {
        if (!$this->redis || !$this->_customer_id) {
            parent::save_data();
            return;
        }

        // Only update if data has changed
        if ($this->_dirty && $this->has_session()) {
            $cache_key = $this->get_cache_key();
            $value = maybe_serialize($this->_data);
            
            // Save to Redis with TTL
            $this->redis->setex($cache_key, $this->ttl, $value);
            
            // Mark as clean after saving
            $this->_dirty = false;
        }
    }

    /**
     * Destroy session in Redis
     */
    public function destroy_session() {
        if (!$this->redis || !$this->_customer_id) {
            parent::destroy_session();
            return;
        }

        $cache_key = $this->get_cache_key();
        $this->redis->del($cache_key);
        
        // Reset session data
        $this->_data = [];
        $this->_dirty = false;
        
        // Clear cookies
        if (isset($_COOKIE[apply_filters('woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH)])) {
            $this->delete_customer_session_cookie();
        }
    }

    /**
     * Update session expiration in Redis
     */
    public function update_session_timestamp() {
        if (!$this->redis || !$this->_customer_id) {
            parent::update_session_timestamp();
            return;
        }
        
        $cache_key = $this->get_cache_key();
        
        // Check if key exists
        if ($this->redis->exists($cache_key)) {
            // Update expiration time
            $this->redis->expire($cache_key, $this->ttl);
        }
    }

    /**
     * Get current session status
     *
     * @return string 'active' if session exists and not expired, 'expired' if expired, 'not_active' if not started
     */
    public function get_session_status() {
        if (!$this->redis || !$this->_customer_id) {
            return parent::get_session_status();
        }
        
        $cache_key = $this->get_cache_key();
        
        // Check if key exists
        if ($this->redis->exists($cache_key)) {
            // Get TTL (time-to-live)
            $ttl = $this->redis->ttl($cache_key);
            
            // Session exists and not expired
            if ($ttl > 0) {
                return 'active';
            }
            
            // Session expired
            return 'expired';
        }
        
        return 'not_active';
    }

    /**
     * Session has expired
     *
     * @return bool
     */
    public function is_session_expired() {
        if (!$this->redis || !$this->_customer_id) {
            return parent::is_session_expired();
        }
        
        return $this->get_session_status() === 'expired';
    }

    /**
     * Generate Redis cache key for current session
     *
     * @return string
     */
    protected function get_cache_key() {
        $blog_id = get_current_blog_id();
        return "wc:{$blog_id}:session:{$this->_customer_id}";
    }
}
