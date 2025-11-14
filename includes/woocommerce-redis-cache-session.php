<?php
/**
 * WooCommerce Redis Session Handler
 *
 * Handles storing WooCommerce session data in Redis
 * Compatible with WooCommerce 10.x
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
        return 'WC_Session_Handler_Redis';
    }
}

/**
 * Redis-based WooCommerce session handler
 * 
 * FIXED for WooCommerce 10.x compatibility
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
     * Plugin instance
     *
     * @var WC_Redis_Cache
     */
    protected $plugin;

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
        
        // Get plugin instance
        $this->plugin = WC_Redis_Cache();
        
        // Store Redis connection
        $this->redis = $this->plugin->get_redis();
        
        // Get session TTL
        $this->ttl = $this->plugin->get_ttl('session');
        
        // Set cookie if it doesn't exist already
        if ($this->redis && !$this->has_session()) {
            $this->set_customer_session_cookie(true);
        }
    }

    /**
     * Get session data from Redis
     *
     * @return array
     */
    public function get_session_data() {
        if (!$this->redis || !$this->_customer_id) {
            return parent::get_session_data();
        }

        $cache_key = $this->get_cache_key();
        
        try {
            $value = $this->redis->get($cache_key);
            
            if (!empty($value)) {
                $data = maybe_unserialize($value);
                
                if (is_array($data)) {
                    $this->_data = $data;
                    
                    // Extend session expiration
                    $this->redis->expire($cache_key, $this->ttl);
                    
                    $this->plugin->log("Session loaded: {$this->_customer_id}");
                }
            }
        } catch (Exception $e) {
            $this->plugin->log("Session get error: " . $e->getMessage());
            return parent::get_session_data();
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
            try {
                $cache_key = $this->get_cache_key();
                $value = maybe_serialize($this->_data);
                
                // Save to Redis with TTL
                $result = $this->redis->setex($cache_key, $this->ttl, $value);
                
                if ($result) {
                    // Mark as clean after saving
                    $this->_dirty = false;
                    $this->plugin->log("Session saved: {$this->_customer_id}");
                }
            } catch (Exception $e) {
                $this->plugin->log("Session save error: " . $e->getMessage());
                // Fallback to parent implementation
                parent::save_data();
            }
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

        try {
            $cache_key = $this->get_cache_key();
            $this->redis->del($cache_key);
            
            $this->plugin->log("Session destroyed: {$this->_customer_id}");
        } catch (Exception $e) {
            $this->plugin->log("Session destroy error: " . $e->getMessage());
        }
        
        // Call parent to handle WordPress session cleanup
        parent::destroy_session();
    }
    
    /**
     * Update session timestamp if close to expiring
     * 
     * This prevents active sessions from expiring
     */
    public function update_session_timestamp() {
        if (!$this->redis || !$this->_customer_id) {
            parent::update_session_timestamp();
            return;
        }
        
        try {
            $cache_key = $this->get_cache_key();
            
            // Get the remaining TTL
            $ttl = $this->redis->ttl($cache_key);
            
            // If TTL is less than half the session lifetime, update it
            if ($ttl > 0 && $ttl < ($this->ttl / 2)) {
                $this->redis->expire($cache_key, $this->ttl);
                $this->plugin->log("Session TTL extended: {$this->_customer_id}");
            }
        } catch (Exception $e) {
            $this->plugin->log("Session timestamp update error: " . $e->getMessage());
            parent::update_session_timestamp();
        }
    }
    
    /**
     * Cleanup expired sessions
     * 
     * This is called by WooCommerce's session cleanup cron
     * With Redis, we don't need to do anything as TTL handles cleanup
     */
    public function cleanup_sessions() {
        // Redis handles cleanup automatically via TTL
        // No action needed
        $this->plugin->log("Session cleanup called (handled by Redis TTL)");
    }
    
    /**
     * Get cache key for current customer session
     *
     * @return string
     */
    protected function get_cache_key() {
        $blog_id = get_current_blog_id();
        return "wc:v" . explode('.', WC_VERSION)[0] . ":{$blog_id}:session:{$this->_customer_id}";
    }
    
    /**
     * Check if session exists in Redis
     * 
     * @return bool
     */
    public function session_exists() {
        if (!$this->redis || !$this->_customer_id) {
            return false;
        }
        
        try {
            $cache_key = $this->get_cache_key();
            return $this->redis->exists($cache_key) > 0;
        } catch (Exception $e) {
            $this->plugin->log("Session exists check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get session expiry time
     * 
     * @return int|false Time remaining in seconds, or false if no session
     */
    public function get_session_expiry() {
        if (!$this->redis || !$this->_customer_id) {
            return false;
        }
        
        try {
            $cache_key = $this->get_cache_key();
            $ttl = $this->redis->ttl($cache_key);
            
            return $ttl > 0 ? $ttl : false;
        } catch (Exception $e) {
            $this->plugin->log("Session expiry check error: " . $e->getMessage());
            return false;
        }
    }
}
