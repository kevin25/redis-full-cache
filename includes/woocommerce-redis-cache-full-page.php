<?php
/**
 * WooCommerce Redis Full-Page Cache
 *
 * Handles caching entire WooCommerce pages for logged-out users
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Redis Full-Page Cache
 */
class WC_Redis_Cache_Full_Page {

    /**
     * Parent plugin instance
     *
     * @var WC_Redis_Cache
     */
    protected $plugin;

    /**
     * Cache TTL in seconds
     *
     * @var int
     */
    protected $ttl = 3600;

    /**
     * Whether we're currently buffering output
     *
     * @var bool
     */
    protected $is_buffering = false;

    /**
     * Pages that should never be cached
     *
     * @var array
     */
    protected $excluded_pages = [
        'cart',
        'checkout',
        'my-account',
    ];

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
        // Only enable for non-admin requests
        if (is_admin()) {
            return;
        }

        // Set TTL from settings
        $this->ttl = $this->plugin->get_setting('full_page_ttl', 3600);

        // Check cached page early (before WordPress loads)
        add_action('plugins_loaded', [$this, 'maybe_serve_cached_page'], 5);

        // Buffer page output for caching
        add_action('template_redirect', [$this, 'maybe_start_buffer'], 0);
        add_action('shutdown', [$this, 'maybe_cache_page'], 0);

        // Clear cache when content changes
        add_action('save_post_product', [$this, 'clear_product_page_cache']);
        add_action('woocommerce_update_product', [$this, 'clear_product_page_cache']);
        add_action('edit_term', [$this, 'clear_term_page_cache'], 10, 3);
        add_action('woocommerce_settings_saved', [$this, 'clear_all_cache']);
    }

    /**
     * Check if the current request can be cached
     *
     * @return bool
     */
    protected function can_cache_request() {
        // Don't cache for logged-in users
        if (is_user_logged_in()) {
            return false;
        }

        // Don't cache POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }

        // Don't cache search results
        if (is_search()) {
            return false;
        }

        // Don't cache cart, checkout, or account pages
        foreach ($this->excluded_pages as $page) {
            if (function_exists('is_' . str_replace('-', '_', $page)) && call_user_func('is_' . str_replace('-', '_', $page))) {
                return false;
            }
        }

        // Don't cache if WooCommerce query vars are present
        $wc_query_vars = ['add-to-cart', 'remove_item', 'apply_coupon', 'remove_coupon'];
        foreach ($wc_query_vars as $var) {
            if (isset($_GET[$var])) {
                return false;
            }
        }

        // Don't cache if specific WooCommerce cookies are set
        $excluded_cookies = ['woocommerce_items_in_cart', 'woocommerce_cart_hash'];
        foreach ($excluded_cookies as $cookie) {
            if (isset($_COOKIE[$cookie])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Serve cached page if available
     */
    public function maybe_serve_cached_page() {
        if (!$this->plugin->is_connected() || !$this->can_cache_request()) {
            return;
        }

        $cache_key = $this->get_page_cache_key();
        $cached_page = $this->plugin->get($cache_key);

        if (!empty($cached_page)) {
            // Set appropriate headers
            header('X-WC-Redis-Cache: HIT');
            header('Content-Type: text/html; charset=UTF-8');

            // Output cached content and exit
            echo $cached_page;
            exit;
        }

        // Set miss header
        header('X-WC-Redis-Cache: MISS');
    }

    /**
     * Start output buffering
     */
    public function maybe_start_buffer() {
        if (!$this->plugin->is_connected() || !$this->can_cache_request()) {
            return;
        }

        $this->is_buffering = true;
        ob_start([$this, 'process_output_buffer']);
    }

    /**
     * Process and cache the output buffer
     *
     * @param string $buffer Page output
     * @return string Original buffer
     */
    public function process_output_buffer($buffer) {
        if (!$this->is_buffering || empty($buffer)) {
            return $buffer;
        }

        // Only cache successful responses
        if (http_response_code() !== 200) {
            return $buffer;
        }

        $this->is_buffering = false;
        $cache_key = $this->get_page_cache_key();
        
        // Add cache signature
        $buffer = $this->add_cache_signature($buffer);
        
        // Store in Redis
        $this->plugin->set($cache_key, $buffer, $this->ttl);
        $this->plugin->log("Cached page: " . $_SERVER['REQUEST_URI']);

        return $buffer;
    }

    /**
     * Cache page on shutdown
     */
    public function maybe_cache_page() {
        if ($this->is_buffering) {
            $this->is_buffering = false;
            ob_end_flush();
        }
    }

    /**
     * Add cache signature to buffer
     *
     * @param string $buffer
     * @return string
     */
    protected function add_cache_signature($buffer) {
        $signature = "\n<!-- WooCommerce Redis Cache: Cached on " . date('Y-m-d H:i:s') . " -->";
        
        // Add before closing </body> or </html> tag
        $buffer = preg_replace('/<\/(body|html)>/', $signature . "\n</\\1>", $buffer, 1);
        
        return $buffer;
    }

    /**
     * Get cache key for the current page
     *
     * @return string
     */
    protected function get_page_cache_key() {
        $blog_id = get_current_blog_id();
        $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $params = $_GET;
        
        // Remove non-WooCommerce parameters to avoid cache fragmentation
        $ignore_params = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];
        foreach ($ignore_params as $param) {
            unset($params[$param]);
        }
        
        ksort($params);
        $param_str = !empty($params) ? md5(serialize($params)) : '';
        
        // Generate key based on URL and params
        return "wc:{$blog_id}:page:" . md5($url . $param_str);
    }

    /**
     * Clear cache for a specific product page
     *
     * @param int $product_id
     */
    public function clear_product_page_cache($product_id) {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        // Clear main product page
        $permalink = get_permalink($product_id);
        if ($permalink) {
            $url = parse_url($permalink, PHP_URL_PATH);
            $blog_id = get_current_blog_id();
            $pattern = "wc:{$blog_id}:page:" . md5($_SERVER['HTTP_HOST'] . $url . '*');
            
            $this->plugin->delete_by_pattern($pattern);
            $this->plugin->log("Cleared cache for product page: {$url}");
        }
        
        // Also clear shop and category pages
        $this->clear_shop_page_cache();
        
        // Clear category pages this product belongs to
        $terms = get_the_terms($product_id, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $this->clear_term_page_cache($term->term_id, $term->term_taxonomy_id, 'product_cat');
            }
        }
    }

    /**
     * Clear cache for a term/category page
     *
     * @param int $term_id
     * @param int $tt_id
     * @param string $taxonomy
     */
    public function clear_term_page_cache($term_id, $tt_id, $taxonomy) {
        if (!$this->plugin->is_connected() || !in_array($taxonomy, ['product_cat', 'product_tag'])) {
            return;
        }
        
        $term_link = get_term_link($term_id, $taxonomy);
        if (!is_wp_error($term_link)) {
            $url = parse_url($term_link, PHP_URL_PATH);
            $blog_id = get_current_blog_id();
            $pattern = "wc:{$blog_id}:page:" . md5($_SERVER['HTTP_HOST'] . $url . '*');
            
            $this->plugin->delete_by_pattern($pattern);
            $this->plugin->log("Cleared cache for term page: {$url}");
        }
        
        // Also clear shop page
        $this->clear_shop_page_cache();
    }

    /**
     * Clear shop page cache
     */
    public function clear_shop_page_cache() {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        $shop_page_id = wc_get_page_id('shop');
        if ($shop_page_id > 0) {
            $shop_url = get_permalink($shop_page_id);
            if ($shop_url) {
                $url = parse_url($shop_url, PHP_URL_PATH);
                $blog_id = get_current_blog_id();
                $pattern = "wc:{$blog_id}:page:" . md5($_SERVER['HTTP_HOST'] . $url . '*');
                
                $this->plugin->delete_by_pattern($pattern);
                $this->plugin->log("Cleared cache for shop page");
            }
        }
    }

    /**
     * Clear all cached pages
     */
    public function clear_all_cache() {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        $blog_id = get_current_blog_id();
        $pattern = "wc:{$blog_id}:page:*";
        
        $count = $this->plugin->delete_by_pattern($pattern);
        $this->plugin->log("Cleared all page cache: {$count} pages");
        
        return $count;
    }
}
