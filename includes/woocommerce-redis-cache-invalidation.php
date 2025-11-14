<?php
/**
 * WooCommerce Redis Cache Invalidation
 *
 * Handles automatic cache invalidation when WooCommerce content changes
 * Compatible with WooCommerce 10.x HPOS (High-Performance Order Storage)
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Redis Cache Invalidation
 */
class WC_Redis_Cache_Invalidation {

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
        // Product changes
        add_action('woocommerce_update_product', [$this, 'invalidate_product'], 10);
        add_action('woocommerce_delete_product', [$this, 'invalidate_product'], 10);
        add_action('woocommerce_new_product', [$this, 'invalidate_product_collection'], 10);
        add_action('woocommerce_update_product_variation', [$this, 'invalidate_product_parent'], 10);
        
        // Price changes
        add_action('woocommerce_product_set_stock', [$this, 'invalidate_product'], 10);
        add_action('woocommerce_product_set_stock_status', [$this, 'invalidate_product'], 10);
        add_action('woocommerce_product_set_sale_price', [$this, 'invalidate_product'], 10);
        add_action('woocommerce_product_set_regular_price', [$this, 'invalidate_product'], 10);
        
        // Category changes
        add_action('edited_product_cat', [$this, 'invalidate_category'], 10);
        add_action('delete_product_cat', [$this, 'invalidate_category'], 10);
        add_action('create_product_cat', [$this, 'invalidate_category_collection'], 10);
        
        // Tag changes
        add_action('edited_product_tag', [$this, 'invalidate_tag'], 10);
        add_action('delete_product_tag', [$this, 'invalidate_tag'], 10);
        add_action('create_product_tag', [$this, 'invalidate_tag_collection'], 10);
        
        // Order changes - Traditional and HPOS
        add_action('woocommerce_order_status_changed', [$this, 'invalidate_order'], 10, 3);
        add_action('woocommerce_order_refunded', [$this, 'invalidate_order'], 10);
        
        // WooCommerce 10.x HPOS-specific hooks
        add_action('woocommerce_new_order', [$this, 'invalidate_order'], 10);
        add_action('woocommerce_update_order', [$this, 'invalidate_order'], 10);
        add_action('woocommerce_before_delete_order', [$this, 'invalidate_order'], 10);
        
        // Settings changes
        add_action('woocommerce_settings_saved', [$this, 'invalidate_shop_settings'], 10);
        add_action('woocommerce_attribute_added', [$this, 'invalidate_shop_settings'], 10);
        add_action('woocommerce_attribute_updated', [$this, 'invalidate_shop_settings'], 10);
        add_action('woocommerce_attribute_deleted', [$this, 'invalidate_shop_settings'], 10);
        
        // Bulk operations
        add_action('woocommerce_product_import_inserted_product_object', [$this, 'invalidate_product'], 10);
        add_action('woocommerce_product_import_updated_product_object', [$this, 'invalidate_product'], 10);
        add_action('woocommerce_variable_product_sync', [$this, 'invalidate_product'], 10);
        
        // Sale price schedule
        add_action('woocommerce_before_product_object_save', [$this, 'maybe_schedule_sale_price_cache_flush'], 10);
        
        // Coupon changes
        add_action('woocommerce_coupon_object_updated_props', [$this, 'invalidate_coupon'], 10);
        add_action('woocommerce_delete_coupon', [$this, 'invalidate_coupon'], 10);
        
        // Review changes
        add_action('comment_post', [$this, 'invalidate_product_on_review'], 10, 2);
        add_action('edit_comment', [$this, 'invalidate_product_on_review_edit'], 10);
        add_action('delete_comment', [$this, 'invalidate_product_on_review_delete'], 10);
    }

    /**
     * Invalidate product cache
     *
     * @param int|WC_Product $product_id Product ID or object
     */
    public function invalidate_product($product_id) {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        // Get the product ID from object if needed
        if (is_a($product_id, 'WC_Product')) {
            $product_id = $product_id->get_id();
        }
        
        if (!$product_id) {
            return;
        }
        
        // Get all WooCommerce major versions for cache key patterns
        $wc_version = defined('WC_VERSION') ? WC_VERSION : '0';
        $major_version = explode('.', $wc_version)[0];
        $blog_id = get_current_blog_id();
        
        // Delete product cache (current version)
        $cache_key = $this->plugin->get_cache_key('product', $product_id);
        $this->plugin->delete($cache_key);
        
        // Delete product price cache
        $price_key = $this->plugin->get_cache_key('product_price', $product_id);
        $this->plugin->delete($price_key);
        
        // Delete product categories cache
        $categories_key = $this->plugin->get_cache_key('product_categories', $product_id);
        $this->plugin->delete($categories_key);
        
        // Delete related product query caches
        $query_pattern = "wc:v{$major_version}:{$blog_id}:product_query:*";
        $this->plugin->delete_by_pattern($query_pattern);
        
        // Invalidate transients related to products
        $transients = [
            'wc_products_onsale',
            'wc_featured_products',
            'wc_term_counts',
            'wc_count_comments',
            'wc_product_loop',
        ];
        
        foreach ($transients as $transient) {
            delete_transient($transient);
        }
        
        $this->plugin->log("Invalidated cache for product ID: {$product_id}");
    }

    /**
     * Invalidate parent product when variation changes
     *
     * @param int $variation_id Variation ID
     */
    public function invalidate_product_parent($variation_id) {
        $variation = wc_get_product($variation_id);
        
        if ($variation && $variation->is_type('variation')) {
            $parent_id = $variation->get_parent_id();
            if ($parent_id) {
                $this->invalidate_product($parent_id);
            }
        }
        
        // Also invalidate the variation itself
        $this->invalidate_product($variation_id);
    }

    /**
     * Invalidate product collection cache
     *
     * @param int $product_id Product ID
     */
    public function invalidate_product_collection($product_id) {
        $this->invalidate_product($product_id);
        
        // Clear all product query results
        $wc_version = defined('WC_VERSION') ? WC_VERSION : '0';
        $major_version = explode('.', $wc_version)[0];
        $blog_id = get_current_blog_id();
        $query_pattern = "wc:v{$major_version}:{$blog_id}:product_query:*";
        $this->plugin->delete_by_pattern($query_pattern);
    }

    /**
     * Invalidate category cache
     *
     * @param int $term_id Term ID
     */
    public function invalidate_category($term_id) {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        // Delete category cache
        $cache_key = $this->plugin->get_cache_key('category', $term_id);
        $this->plugin->delete($cache_key);
        
        // Delete terms cache that might include this category
        $wc_version = defined('WC_VERSION') ? WC_VERSION : '0';
        $major_version = explode('.', $wc_version)[0];
        $blog_id = get_current_blog_id();
        $terms_pattern = "wc:v{$major_version}:{$blog_id}:terms:*";
        $this->plugin->delete_by_pattern($terms_pattern);
        
        // Delete product queries that might use this category
        $query_pattern = "wc:v{$major_version}:{$blog_id}:product_query:*";
        $this->plugin->delete_by_pattern($query_pattern);
        
        $this->plugin->log("Invalidated cache for category ID: {$term_id}");
    }

    /**
     * Invalidate category collection cache
     *
     * @param int $term_id Term ID
     */
    public function invalidate_category_collection($term_id) {
        $this->invalidate_category($term_id);
        
        // Clear all terms caches
        $wc_version = defined('WC_VERSION') ? WC_VERSION : '0';
        $major_version = explode('.', $wc_version)[0];
        $blog_id = get_current_blog_id();
        $terms_pattern = "wc:v{$major_version}:{$blog_id}:terms:*";
        $this->plugin->delete_by_pattern($terms_pattern);
    }

    /**
     * Invalidate tag cache
     *
     * @param int $term_id Term ID
     */
    public function invalidate_tag($term_id) {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        // Delete tag cache
        $cache_key = $this->plugin->get_cache_key('tag', $term_id);
        $this->plugin->delete($cache_key);
        
        // Delete terms cache that might include this tag
        $wc_version = defined('WC_VERSION') ? WC_VERSION : '0';
        $major_version = explode('.', $wc_version)[0];
        $blog_id = get_current_blog_id();
        $terms_pattern = "wc:v{$major_version}:{$blog_id}:terms:*";
        $this->plugin->delete_by_pattern($terms_pattern);
        
        // Delete product queries that might use this tag
        $query_pattern = "wc:v{$major_version}:{$blog_id}:product_query:*";
        $this->plugin->delete_by_pattern($query_pattern);
        
        $this->plugin->log("Invalidated cache for tag ID: {$term_id}");
    }

    /**
     * Invalidate tag collection cache
     *
     * @param int $term_id Term ID
     */
    public function invalidate_tag_collection($term_id) {
        $this->invalidate_tag($term_id);
        
        // Clear all terms caches
        $wc_version = defined('WC_VERSION') ? WC_VERSION : '0';
        $major_version = explode('.', $wc_version)[0];
        $blog_id = get_current_blog_id();
        $terms_pattern = "wc:v{$major_version}:{$blog_id}:terms:*";
        $this->plugin->delete_by_pattern($terms_pattern);
    }

    /**
     * Invalidate order cache
     * 
     * Handles both traditional and HPOS orders
     *
     * @param int|WC_Order $order_id Order ID or object
     * @param string $old_status Optional old status
     * @param string $new_status Optional new status
     */
    public function invalidate_order($order_id, $old_status = '', $new_status = '') {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        // Get order ID from object if needed
        if (is_a($order_id, 'WC_Order')) {
            $order_id = $order_id->get_id();
        }
        
        if (!$order_id) {
            return;
        }
        
        // Delete order cache
        $cache_key = $this->plugin->get_cache_key('order', $order_id);
        $this->plugin->delete($cache_key);
        
        // Invalidate reports cache when order status changes
        if (!empty($new_status)) {
            $this->invalidate_reports_cache();
        }
        
        $this->plugin->log("Invalidated cache for order ID: {$order_id}");
    }

    /**
     * Invalidate reports cache
     */
    protected function invalidate_reports_cache() {
        $transients = [
            'wc_report_sales_by_date',
            'wc_admin_report',
        ];
        
        foreach ($transients as $transient) {
            delete_transient($transient);
        }
        
        // Clear report query cache
        $wc_version = defined('WC_VERSION') ? WC_VERSION : '0';
        $major_version = explode('.', $wc_version)[0];
        $blog_id = get_current_blog_id();
        $pattern = "wc:v{$major_version}:{$blog_id}:report:*";
        $this->plugin->delete_by_pattern($pattern);
    }

    /**
     * Invalidate shop settings
     */
    public function invalidate_shop_settings() {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        // Clear transients related to shop settings
        $transients = [
            'wc_term_counts',
            'wc_shipping_method_count',
            'wc_attribute_taxonomies',
            'woocommerce_cache_excluded_uris',
        ];
        
        foreach ($transients as $transient) {
            delete_transient($transient);
        }
        
        $this->plugin->log("Invalidated shop settings cache");
    }

    /**
     * Invalidate coupon cache
     *
     * @param int|WC_Coupon $coupon Coupon ID or object
     */
    public function invalidate_coupon($coupon) {
        if (!$this->plugin->is_connected()) {
            return;
        }
        
        $coupon_id = is_a($coupon, 'WC_Coupon') ? $coupon->get_id() : $coupon;
        
        if (!$coupon_id) {
            return;
        }
        
        // Delete coupon cache
        $cache_key = $this->plugin->get_cache_key('coupon', $coupon_id);
        $this->plugin->delete($cache_key);
        
        $this->plugin->log("Invalidated cache for coupon ID: {$coupon_id}");
    }

    /**
     * Invalidate product cache when review is posted
     *
     * @param int $comment_id Comment ID
     * @param int|string $comment_approved Comment approval status
     */
    public function invalidate_product_on_review($comment_id, $comment_approved) {
        if ($comment_approved !== 1 && $comment_approved !== 'approve') {
            return;
        }
        
        $comment = get_comment($comment_id);
        if ($comment && $comment->comment_type === 'review') {
            $this->invalidate_product($comment->comment_post_ID);
        }
    }

    /**
     * Invalidate product cache when review is edited
     *
     * @param int $comment_id Comment ID
     */
    public function invalidate_product_on_review_edit($comment_id) {
        $comment = get_comment($comment_id);
        if ($comment && $comment->comment_type === 'review') {
            $this->invalidate_product($comment->comment_post_ID);
        }
    }

    /**
     * Invalidate product cache when review is deleted
     *
     * @param int $comment_id Comment ID
     */
    public function invalidate_product_on_review_delete($comment_id) {
        $comment = get_comment($comment_id);
        if ($comment && $comment->comment_type === 'review') {
            $this->invalidate_product($comment->comment_post_ID);
        }
    }

    /**
     * Schedule cache flush for sale price changes
     *
     * @param WC_Product $product Product object
     */
    public function maybe_schedule_sale_price_cache_flush($product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        $sale_from = $product->get_date_on_sale_from();
        $sale_to = $product->get_date_on_sale_to();
        
        // If we have future sale dates, schedule cache invalidation
        if ($sale_from && time() < $sale_from->getTimestamp()) {
            $this->schedule_sale_flush($sale_from->getTimestamp(), $product->get_id());
        }
        
        if ($sale_to && time() < $sale_to->getTimestamp()) {
            $this->schedule_sale_flush($sale_to->getTimestamp(), $product->get_id());
        }
    }

    /**
     * Schedule a cache flush for sale price changes
     *
     * @param int $timestamp Unix timestamp
     * @param int $product_id Product ID
     */
    protected function schedule_sale_flush($timestamp, $product_id) {
        // Use WP Cron to schedule cache flush
        $hook = 'wc_redis_cache_flush_sale_price';
        
        // Only schedule if not already scheduled
        if (!wp_next_scheduled($hook, [$product_id])) {
            wp_schedule_single_event($timestamp, $hook, [$product_id]);
            $this->plugin->log("Scheduled cache flush for product ID {$product_id} at " . date('Y-m-d H:i:s', $timestamp));
        }
    }
}

// Hook for scheduled sale price cache flush
add_action('wc_redis_cache_flush_sale_price', function($product_id) {
    $plugin = WC_Redis_Cache();
    if ($plugin && $plugin->is_connected()) {
        require_once WC_REDIS_CACHE_PATH . 'includes/woocommerce-redis-cache-invalidation.php';
        $invalidation = new WC_Redis_Cache_Invalidation($plugin);
        $invalidation->invalidate_product($product_id);
    }
});
