<?php
/**
 * Plugin Name: WooCommerce Redis full Cache
 * Plugin URI: https://kevin.com/woocommerce-redis-cache
 * Description: High-performance Redis caching solution for WooCommerce stores
 * Version: 1.0.0
 * Author: Kevin Ng.
 * Author URI: https://kevin.com
 * Text Domain: wc-redis-cache
 * Domain Path: /languages
 * WC requires at least: 5.0.0
 * WC tested up to: 8.0.0
 * Requires PHP: 7.4
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_REDIS_CACHE_VERSION', '1.0.0');
define('WC_REDIS_CACHE_PATH', plugin_dir_path(__FILE__));
define('WC_REDIS_CACHE_URL', plugin_dir_url(__FILE__));
define('WC_REDIS_CACHE_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
final class WC_Redis_Cache {

    /**
     * Single instance of the plugin
     *
     * @var WC_Redis_Cache
     */
    protected static $instance = null;

    /**
     * Redis client instance
     *
     * @var Redis
     */
    protected $redis = null;

    /**
     * Plugin settings
     *
     * @var array
     */
    public $settings = [];

    /**
     * Stats counter
     *
     * @var array
     */
    protected $stats = [
        'hits' => 0,
        'misses' => 0,
        'time' => 0,
    ];

    /**
     * Debug mode
     *
     * @var bool
     */
    protected $debug = false;

    /**
     * Main plugin instance
     *
     * @return WC_Redis_Cache
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->includes();
        $this->init_hooks();
        $this->load_settings();
        $this->connect();
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-object.php';
        require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-session.php';
        require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-transient.php';
        require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-full-page.php';
        require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-admin.php';
        require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-invalidation.php';
        
        // CLI support
        if (defined('WP_CLI') && WP_CLI) {
            require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-cli.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check for WooCommerce
        add_action('plugins_loaded', [$this, 'check_dependencies']);
        
        // Initialize components
        add_action('init', [$this, 'init_components'], 0);
        
        // Plugin activation/deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    /**
     * Check plugin dependencies
     */
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                ?>
                <div class="error">
                    <p><?php _e('WooCommerce Redis Cache requires WooCommerce to be installed and active.', 'wc-redis-cache'); ?></p>
                </div>
                <?php
            });
            return;
        }
        
        if (!extension_loaded('redis')) {
            add_action('admin_notices', function() {
                ?>
                <div class="error">
                    <p><?php _e('WooCommerce Redis Cache requires the PHP Redis extension to be installed.', 'wc-redis-cache'); ?></p>
                </div>
                <?php
            });
            return;
        }
    }

    /**
     * Initialize components
     */
    public function init_components() {
        if (!$this->is_connected()) {
            return;
        }

        // Initialize components based on settings
        if ($this->get_setting('enable_object_cache', true)) {
            new WC_Redis_Cache_Object($this);
        }
        
        if ($this->get_setting('enable_session_cache', true)) {
            new WC_Redis_Cache_Session($this);
        }
        
        if ($this->get_setting('enable_transient_cache', true)) {
            new WC_Redis_Cache_Transient($this);
        }
        
        if ($this->get_setting('enable_full_page_cache', false)) {
            new WC_Redis_Cache_Full_Page($this);
        }
        
        new WC_Redis_Cache_Invalidation($this);
        
        // Admin interface
        if (is_admin()) {
            new WC_Redis_Cache_Admin($this);
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create default settings
        $default_settings = [
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'redis_password' => '',
            'redis_database' => 0,
            'redis_timeout' => 5,
            'enable_object_cache' => true,
            'enable_session_cache' => true,
            'enable_transient_cache' => true,
            'enable_full_page_cache' => false,
            'product_ttl' => 86400, // 24 hours
            'category_ttl' => 86400, // 24 hours
            'cart_ttl' => 3600,     // 1 hour
            'session_ttl' => 86400, // 24 hours
            'debug_mode' => false,
        ];
        
        if (!get_option('wc_redis_cache_settings')) {
            update_option('wc_redis_cache_settings', $default_settings);
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush cache on deactivation
        if ($this->is_connected()) {
            $this->flush_cache();
        }
    }

    /**
     * Load plugin settings
     */
    public function load_settings() {
        $this->settings = get_option('wc_redis_cache_settings', []);
        $this->debug = !empty($this->settings['debug_mode']);
    }

    /**
     * Connect to Redis
     */
    public function connect() {
        if (!extension_loaded('redis')) {
            $this->log('Redis extension not installed');
            return false;
        }
        
        try {
            $this->redis = new Redis();
            
            $host = $this->get_setting('redis_host', '127.0.0.1');
            $port = $this->get_setting('redis_port', 6379);
            $timeout = $this->get_setting('redis_timeout', 5);

            // Connect to Redis
            if (!$this->redis->connect($host, $port, $timeout)) {
                throw new Exception('Could not connect to Redis server');
            }
            
            // Authenticate if password is set
            $password = $this->get_setting('redis_password', '');
            if (!empty($password) && !$this->redis->auth($password)) {
                throw new Exception('Redis authentication failed');
            }
            
            // Select database
            $database = $this->get_setting('redis_database', 0);
            if (!$this->redis->select($database)) {
                throw new Exception('Redis database selection failed');
            }
            
            $this->log('Connected to Redis server');
            return true;
            
        } catch (Exception $e) {
            $this->log('Redis connection error: ' . $e->getMessage());
            $this->redis = null;
            return false;
        }
    }

    /**
     * Check if connected to Redis
     * 
     * @return bool
     */
    public function is_connected() {
        return $this->redis !== null && $this->redis->ping() === '+PONG';
    }

    /**
     * Get Redis instance
     * 
     * @return Redis|null
     */
    public function get_redis() {
        return $this->redis;
    }

    /**
     * Get plugin setting
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get_setting($key, $default = null) {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    /**
     * Update plugin setting
     * 
     * @param string $key
     * @param mixed $value
     */
    public function update_setting($key, $value) {
        $this->settings[$key] = $value;
        update_option('wc_redis_cache_settings', $this->settings);
    }

    /**
     * Get cache key
     * 
     * @param string $type Cache type
     * @param mixed $id Identifier
     * @return string
     */
    public function get_cache_key($type, $id) {
        $blog_id = get_current_blog_id();
        return "wc:{$blog_id}:{$type}:{$id}";
    }

    /**
     * Get cache TTL for specific type
     * 
     * @param string $type Cache type
     * @return int TTL in seconds
     */
    public function get_ttl($type) {
        $ttl_map = [
            'product' => $this->get_setting('product_ttl', 86400),
            'category' => $this->get_setting('category_ttl', 86400),
            'cart' => $this->get_setting('cart_ttl', 3600),
            'session' => $this->get_setting('session_ttl', 86400),
            'transient' => $this->get_setting('transient_ttl', 86400),
        ];

        return isset($ttl_map[$type]) ? $ttl_map[$type] : 3600;
    }

    /**
     * Set cache value
     * 
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return bool
     */
    public function set($key, $value, $ttl = 0) {
        if (!$this->is_connected()) {
            return false;
        }

        $serialized = serialize($value);
        $start = microtime(true);
        
        if ($ttl > 0) {
            $result = $this->redis->setex($key, $ttl, $serialized);
        } else {
            $result = $this->redis->set($key, $serialized);
        }
        
        $this->stats['time'] += microtime(true) - $start;
        
        $this->log("SET {$key}" . ($ttl > 0 ? " (TTL: {$ttl}s)" : ""));
        return $result;
    }

    /**
     * Get cache value
     * 
     * @param string $key
     * @return mixed|false
     */
    public function get($key) {
        if (!$this->is_connected()) {
            return false;
        }

        $start = microtime(true);
        $result = $this->redis->get($key);
        $this->stats['time'] += microtime(true) - $start;
        
        if ($result === false) {
            $this->stats['misses']++;
            $this->log("MISS {$key}");
            return false;
        }
        
        $this->stats['hits']++;
        $this->log("HIT {$key}");
        
        return unserialize($result);
    }

    /**
     * Delete cache value
     * 
     * @param string $key
     * @return bool
     */
    public function delete($key) {
        if (!$this->is_connected()) {
            return false;
        }

        $this->log("DEL {$key}");
        return $this->redis->del($key) > 0;
    }

    /**
     * Delete cache keys by pattern
     * 
     * @param string $pattern
     * @return int Number of deleted keys
     */
    public function delete_by_pattern($pattern) {
        if (!$this->is_connected()) {
            return 0;
        }

        $keys = $this->redis->keys($pattern);
        if (empty($keys)) {
            return 0;
        }

        $deleted = $this->redis->del($keys);
        $this->log("DEL by pattern {$pattern}: {$deleted} keys");
        
        return $deleted;
    }

    /**
     * Flush all cache
     * 
     * @return bool
     */
    public function flush_cache() {
        if (!$this->is_connected()) {
            return false;
        }

        // Only flush our keys, not the entire Redis database
        $blog_id = get_current_blog_id();
        $pattern = "wc:{$blog_id}:*";
        
        $deleted = $this->delete_by_pattern($pattern);
        $this->log("Flushed cache: {$deleted} keys");
        
        return true;
    }

    /**
     * Get cache statistics
     * 
     * @return array
     */
    public function get_stats() {
        $stats = $this->stats;
        
        if ($this->is_connected()) {
            $info = $this->redis->info();
            $stats['memory_used'] = isset($info['used_memory_human']) ? $info['used_memory_human'] : 'N/A';
            $stats['uptime'] = isset($info['uptime_in_seconds']) ? $info['uptime_in_seconds'] : 'N/A';
            $stats['connected_clients'] = isset($info['connected_clients']) ? $info['connected_clients'] : 'N/A';
            $stats['total_keys'] = count($this->redis->keys('wc:*'));
        }
        
        $stats['hit_ratio'] = ($stats['hits'] + $stats['misses'] > 0) 
            ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2) 
            : 0;
            
        return $stats;
    }

    /**
     * Log message if debug is enabled
     * 
     * @param string $message
     */
    public function log($message) {
        if ($this->debug) {
            error_log('[WC Redis Cache] ' . $message);
        }
    }
}

/**
 * Return the main instance of the plugin
 * 
 * @return WC_Redis_Cache
 */
function WC_Redis_Cache() {
    return WC_Redis_Cache::instance();
}

// Initialize the plugin
WC_Redis_Cache();
