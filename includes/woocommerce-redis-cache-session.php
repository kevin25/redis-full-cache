<?php
/**
 * WooCommerce Redis Session Handler
 *
 * Handles storing WooCommerce session data in Redis
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Redis Session Handler
 */
class WC_Redis_Cache_Session {

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
        // Filter WooCommerce session handler
        add_filter('woocommerce_session_handler', [$this, 'register_session_handler']);
    }

    /**
     * Register custom session handler
     *
     * @return string Class name
     */
    public function register_session_handler() {
        // Include custom session handler
        include_once WC_REDIS_CACHE_PATH . 'includes/class-wc-session-handler-redis.php';
        return 'WC_Session_Handler_Redis';
    }
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
     * TTL for session
     *
     * @var int
     */
    protected $ttl;

    /**
     * Constructor
     */
    public function __construct() {
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
     * Get session data
     *
     * @return array
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
     * Save data to the session
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
     * Destroy session
     */
    public function destroy_session() {
        if (!$this->redis || !$this->_customer_id) {
            parent::destroy_session();
            return;
        }

        $cache_key = $this->get_cache_key();
        $this->redis->del($cache_key);
        
        // Reset cookie and customer ID
        $this->_customer_id = null;
        $this->_data = [];
        $this->_dirty = false;
        $this->cookie_deletion_time = time() - YEAR_IN_SECONDS;
        
        // Clear cookies
        wc_setcookie('wp_woocommerce_session_' . COOKIEHASH, '', $this->cookie_deletion_time, $this->use_secure_cookie, true);
    }
    
    /**
     * Update session if its close to expiring
     */
    public function update_session_timestamp() {
        if (!$this->redis || !$this->_customer_id) {
            parent::update_session_timestamp();
            return;
        }
        
        $cache_key = $this->get_cache_key();
        
        // Get the remaining TTL
        $ttl = $this->redis->ttl($cache_key);
        
        // If TTL is less than half the session lifetime, update it
        if ($ttl > 0 && $ttl < ($this->ttl / 2)) {
            $this->redis->expire($cache_key, $this->ttl);
        }
    }
    
    /**
     * Get cache key for current customer session
     *
     * @return string
     */
    protected function get_cache_key() {
        $blog_id = get_current_blog_id();
        return "wc:{$blog_id}:session:{$this->_customer_id}";
    }