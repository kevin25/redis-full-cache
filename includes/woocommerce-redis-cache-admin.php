<?php
/**
 * WooCommerce Redis Cache Admin
 *
 * Provides administration interface for the Redis cache
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Redis Cache Admin
 */
class WC_Redis_Cache_Admin {

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
        // Add admin menu
        add_action('admin_menu', [$this, 'add_menu_page']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Admin notices
        add_action('admin_notices', [$this, 'admin_notices']);
        
        // Add WooCommerce settings section
        add_filter('woocommerce_get_sections_products', [$this, 'add_section']);
        add_filter('woocommerce_get_settings_products', [$this, 'add_settings'], 10, 2);
        
        // AJAX actions
        add_action('wp_ajax_wc_redis_cache_flush', [$this, 'ajax_flush_cache']);
        add_action('wp_ajax_wc_redis_cache_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_wc_redis_cache_reindex', [$this, 'ajax_reindex']);
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Add admin menu page
     */
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('Redis Cache', 'wc-redis-cache'),
            __('Redis Cache', 'wc-redis-cache'),
            'manage_options',
            'wc-redis-cache',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('wc_redis_cache_settings', 'wc_redis_cache_settings');
    }

    /**
     * Add section to WooCommerce settings
     *
     * @param array $sections
     * @return array
     */
    public function add_section($sections) {
        $sections['redis_cache'] = __('Redis Cache', 'wc-redis-cache');
        return $sections;
    }

    /**
     * Add settings to WooCommerce products settings
     *
     * @param array $settings
     * @param string $current_section
     * @return array
     */
    public function add_settings($settings, $current_section) {
        if ($current_section !== 'redis_cache') {
            return $settings;
        }

        $redis_settings = [
            [
                'title' => __('Redis Cache Settings', 'wc-redis-cache'),
                'type'  => 'title',
                'desc'  => __('Configure Redis caching for WooCommerce to improve performance.', 'wc-redis-cache'),
                'id'    => 'wc_redis_cache_options',
            ],
            [
                'title'   => __('Connection', 'wc-redis-cache'),
                'desc'    => __('Configure Redis connection settings in the Redis Cache admin page.', 'wc-redis-cache'),
                'type'    => 'info',
            ],
            [
                'title'   => __('Redis Cache Admin', 'wc-redis-cache'),
                'desc'    => __('Go to Redis Cache admin page for full configuration.', 'wc-redis-cache'),
                'type'    => 'button',
                'css'     => 'margin-top: 10px;',
                'custom_attributes' => [
                    'onclick' => 'window.location.href="' . admin_url('admin.php?page=wc-redis-cache') . '";',
                    'class'   => 'button-primary',
                    'value'   => __('Redis Cache Admin', 'wc-redis-cache'),
                ],
            ],
            [
                'type' => 'sectionend',
                'id'   => 'wc_redis_cache_options',
            ],
        ];

        return $redis_settings;
    }

    /**
     * Display admin notices
     */
    public function admin_notices() {
        // Show connection error notice
        if (isset($_GET['page']) && $_GET['page'] === 'wc-redis-cache') {
            if (!$this->plugin->is_connected()) {
                ?>
                <div class="notice notice-error">
                    <p><?php _e('Could not connect to Redis server. Please check your connection settings.', 'wc-redis-cache'); ?></p>
                </div>
                <?php
            }
        }
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook
     */
    public function enqueue_scripts($hook) {
        if ($hook === 'woocommerce_page_wc-redis-cache') {
            // Admin styles
            wp_enqueue_style(
                'wc-redis-cache-admin',
                WC_REDIS_CACHE_URL . 'assets/css/admin.css',
                [],
                WC_REDIS_CACHE_VERSION
            );
            
            // Admin scripts
            wp_enqueue_script(
                'wc-redis-cache-admin',
                WC_REDIS_CACHE_URL . 'assets/js/admin.js',
                ['jquery'],
                WC_REDIS_CACHE_VERSION,
                true
            );
            
            // Localize script
            wp_localize_script('wc-redis-cache-admin', 'wc_redis_cache', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_redis_cache_nonce'),
                'i18n' => [
                    'flushing' => __('Flushing cache...', 'wc-redis-cache'),
                    'flushed' => __('Cache flushed successfully!', 'wc-redis-cache'),
                    'testing' => __('Testing connection...', 'wc-redis-cache'),
                    'success' => __('Connection successful!', 'wc-redis-cache'),
                    'error' => __('Connection failed:', 'wc-redis-cache'),
                    'reindexing' => __('Reindexing products...', 'wc-redis-cache'),
                    'reindexed' => __('Products reindexed successfully!', 'wc-redis-cache'),
                ]
            ]);
        }
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Save settings
        if (isset($_POST['wc_redis_cache_settings']) && current_user_can('manage_options')) {
            check_admin_referer('wc_redis_cache_update_settings');
            
            $settings = array_map('sanitize_text_field', $_POST['wc_redis_cache_settings']);
            
            // Handle checkboxes (they don't get submitted when unchecked)
            $checkboxes = [
                'enable_object_cache',
                'enable_session_cache',
                'enable_transient_cache',
                'enable_full_page_cache',
                'debug_mode',
            ];
            
            foreach ($checkboxes as $checkbox) {
                $settings[$checkbox] = isset($settings[$checkbox]) ? true : false;
            }
            
            // Convert numeric values
            $numeric_fields = [
                'redis_port', 
                'redis_database', 
                'redis_timeout',
                'product_ttl',
                'category_ttl',
                'cart_ttl',
                'session_ttl',
            ];
            
            foreach ($numeric_fields as $field) {
                if (isset($settings[$field])) {
                    $settings[$field] = absint($settings[$field]);
                }
            }
            
            update_option('wc_redis_cache_settings', $settings);
            $this->plugin->load_settings();
            
            // Try to connect with new settings
            $this->plugin->connect();
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'wc-redis-cache') . '</p></div>';
        }
        
        // Get current settings
        $settings = $this->plugin->settings;
        $is_connected = $this->plugin->is_connected();
        $stats = $is_connected ? $this->plugin->get_stats() : [];
        
        // Render admin page
        ?>
        <div class="wrap wc-redis-cache-admin">
            <h1><?php _e('WooCommerce Redis Cache', 'wc-redis-cache'); ?></h1>
            
            <div class="wc-redis-cache-admin-content">
                <div class="wc-redis-cache-main">
                    <div class="wc-redis-cache-box">
                        <h2><?php _e('Connection Settings', 'wc-redis-cache'); ?></h2>
                        
                        <form method="post" action="">
                            <?php wp_nonce_field('wc_redis_cache_update_settings'); ?>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Redis Host', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="text" name="wc_redis_cache_settings[redis_host]" value="<?php echo esc_attr($settings['redis_host'] ?? '127.0.0.1'); ?>" class="regular-text" required>
                                        <p class="description"><?php _e('Redis server hostname or IP address.', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Redis Port', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="number" name="wc_redis_cache_settings[redis_port]" value="<?php echo esc_attr($settings['redis_port'] ?? 6379); ?>" class="small-text" required min="1" max="65535">
                                        <p class="description"><?php _e('Redis server port (default: 6379).', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Redis Password', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="password" name="wc_redis_cache_settings[redis_password]" value="<?php echo esc_attr($settings['redis_password'] ?? ''); ?>" class="regular-text">
                                        <p class="description"><?php _e('Redis server password (leave empty if no password is required).', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Redis Database', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="number" name="wc_redis_cache_settings[redis_database]" value="<?php echo esc_attr($settings['redis_database'] ?? 0); ?>" class="small-text" min="0" max="15">
                                        <p class="description"><?php _e('Redis database index (0-15).', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Connection Timeout', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="number" name="wc_redis_cache_settings[redis_timeout]" value="<?php echo esc_attr($settings['redis_timeout'] ?? 5); ?>" class="small-text" min="1" max="60">
                                        <p class="description"><?php _e('Connection timeout in seconds.', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Test Connection', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <button type="button" id="wc-redis-test-connection" class="button button-secondary">
                                            <?php _e('Test Connection', 'wc-redis-cache'); ?>
                                        </button>
                                        <span id="wc-redis-test-connection-result" class="<?php echo $is_connected ? 'success' : 'error'; ?>">
                                            <?php echo $is_connected ? __('Connected', 'wc-redis-cache') : __('Not connected', 'wc-redis-cache'); ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                            
                            <h2><?php _e('Cache Settings', 'wc-redis-cache'); ?></h2>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Object Caching', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="wc_redis_cache_settings[enable_object_cache]" value="1" <?php checked($settings['enable_object_cache'] ?? true); ?>>
                                            <?php _e('Enable object caching (products, categories, etc.)', 'wc-redis-cache'); ?>
                                        </label>
                                        <p class="description"><?php _e('Caches database query results in Redis.', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Session Caching', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="wc_redis_cache_settings[enable_session_cache]" value="1" <?php checked($settings['enable_session_cache'] ?? true); ?>>
                                            <?php _e('Enable session caching (carts, user sessions)', 'wc-redis-cache'); ?>
                                        </label>
                                        <p class="description"><?php _e('Stores WooCommerce session data in Redis instead of the database.', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Transient Caching', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="wc_redis_cache_settings[enable_transient_cache]" value="1" <?php checked($settings['enable_transient_cache'] ?? true); ?>>
                                            <?php _e('Enable transient caching', 'wc-redis-cache'); ?>
                                        </label>
                                        <p class="description"><?php _e('Stores WordPress transients used by WooCommerce in Redis.', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Full-Page Caching', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="wc_redis_cache_settings[enable_full_page_cache]" value="1" <?php checked($settings['enable_full_page_cache'] ?? false); ?>>
                                            <?php _e('Enable full-page caching (for logged-out users)', 'wc-redis-cache'); ?>
                                        </label>
                                        <p class="description"><?php _e('Caches entire WooCommerce pages for logged-out users.', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            
                            <h2><?php _e('TTL Settings (in seconds)', 'wc-redis-cache'); ?></h2>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Product TTL', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="number" name="wc_redis_cache_settings[product_ttl]" value="<?php echo esc_attr($settings['product_ttl'] ?? 86400); ?>" class="medium-text" min="60">
                                        <p class="description"><?php _e('Time-to-live for product cache in seconds (default: 86400 = 24 hours).', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Category TTL', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="number" name="wc_redis_cache_settings[category_ttl]" value="<?php echo esc_attr($settings['category_ttl'] ?? 86400); ?>" class="medium-text" min="60">
                                        <p class="description"><?php _e('Time-to-live for category cache in seconds (default: 86400 = 24 hours).', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Cart TTL', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="number" name="wc_redis_cache_settings[cart_ttl]" value="<?php echo esc_attr($settings['cart_ttl'] ?? 3600); ?>" class="medium-text" min="60">
                                        <p class="description"><?php _e('Time-to-live for cart cache in seconds (default: 3600 = 1 hour).', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e('Session TTL', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <input type="number" name="wc_redis_cache_settings[session_ttl]" value="<?php echo esc_attr($settings['session_ttl'] ?? 86400); ?>" class="medium-text" min="60">
                                        <p class="description"><?php _e('Time-to-live for user session cache in seconds (default: 86400 = 24 hours).', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            
                            <h2><?php _e('Advanced Settings', 'wc-redis-cache'); ?></h2>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Debug Mode', 'wc-redis-cache'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="wc_redis_cache_settings[debug_mode]" value="1" <?php checked($settings['debug_mode'] ?? false); ?>>
                                            <?php _e('Enable debug mode', 'wc-redis-cache'); ?>
                                        </label>
                                        <p class="description"><?php _e('Logs cache operations to WordPress debug log.', 'wc-redis-cache'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary"><?php _e('Save Settings', 'wc-redis-cache'); ?></button>
                            </p>
                        </form>
                    </div>
                </div>
                
                <div class="wc-redis-cache-sidebar">
                    <!-- Status Box -->
                    <div class="wc-redis-cache-box">
                        <h2><?php _e('Cache Status', 'wc-redis-cache'); ?></h2>
                        
                        <?php if ($is_connected): ?>
                            <div class="wc-redis-cache-status-connected">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Connected to Redis', 'wc-redis-cache'); ?>
                            </div>
                            
                            <table class="wc-redis-cache-stats">
                                <tr>
                                    <th><?php _e('Memory Usage:', 'wc-redis-cache'); ?></th>
                                    <td><?php echo esc_html($stats['memory_used'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Cache Hit Ratio:', 'wc-redis-cache'); ?></th>
                                    <td><?php echo esc_html($stats['hit_ratio'] ?? 0); ?>%</td>
                                </tr>
                                <tr>
                                    <th><?php _e('Total Keys:', 'wc-redis-cache'); ?></th>
                                    <td><?php echo esc_html($stats['total_keys'] ?? 0); ?></td>
                                </tr>
                            </table>
                        <?php else: ?>
                            <div class="wc-redis-cache-status-disconnected">
                                <span class="dashicons dashicons-no-alt"></span>
                                <?php _e('Not connected to Redis', 'wc-redis-cache'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Actions Box -->
                    <div class="wc-redis-cache-box">
                        <h2><?php _e('Cache Actions', 'wc-redis-cache'); ?></h2>
                        
                        <div class="wc-redis-cache-actions">
                            <button id="wc-redis-flush-cache" class="button button-secondary">
                                <span class="dashicons dashicons-trash"></span>
                                <?php _e('Flush Cache', 'wc-redis-cache'); ?>
                            </button>
                            
                            <button id="wc-redis-reindex-products" class="button button-secondary">
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Reindex Products', 'wc-redis-cache'); ?>
                            </button>
                        </div>
                        
                        <div id="wc-redis-cache-action-result"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for flushing cache
     */
    public function ajax_flush_cache() {
        check_ajax_referer('wc_redis_cache_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'wc-redis-cache'));
        }
        
        $result = $this->plugin->flush_cache();
        
        if ($result) {
            wp_send_json_success(__('Cache flushed successfully!', 'wc-redis-cache'));
        } else {
            wp_send_json_error(__('Failed to flush cache. Please check Redis connection.', 'wc-redis-cache'));
        }
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('wc_redis_cache_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'wc-redis-cache'));
        }
        
        $host = sanitize_text_field($_POST['host'] ?? '127.0.0.1');
        $port = absint($_POST['port'] ?? 6379);
        $password = sanitize_text_field($_POST['password'] ?? '');
        $database = absint($_POST['database'] ?? 0);
        $timeout = absint($_POST['timeout'] ?? 5);
        
        try {
            $redis = new Redis();
            
            if (!$redis->connect($host, $port, $timeout)) {
                throw new Exception(__('Could not connect to Redis server', 'wc-redis-cache'));
            }
            
            if (!empty($password) && !$redis->auth($password)) {
                throw new Exception(__('Redis authentication failed', 'wc-redis-cache'));
            }
            
            if (!$redis->select($database)) {
                throw new Exception(__('Redis database selection failed', 'wc-redis-cache'));
            }
            
            $info = $redis->info();
            
            wp_send_json_success([
                'message' => __('Connection successful!', 'wc-redis-cache'),
                'version' => $info['redis_version'] ?? 'Unknown',
                'memory' => $info['used_memory_human'] ?? 'Unknown',
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX handler for reindexing products
     */
    public function ajax_reindex() {
        check_ajax_referer('wc_redis_cache_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'wc-redis-cache'));
        }
        
        if (!$this->plugin->is_connected()) {
            wp_send_json_error(__('Not connected to Redis. Please check connection settings.', 'wc-redis-cache'));
        }
        
        require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-object.php';
        $object_cache = new WC_Redis_Cache_Object($this->plugin);
        
        $count = $object_cache->reindex_products();
        
        wp_send_json_success(sprintf(
            __('%d products reindexed successfully!', 'wc-redis-cache'),
            $count
        ));
    }