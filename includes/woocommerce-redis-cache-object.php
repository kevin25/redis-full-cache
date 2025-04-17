<?php
/**
 * WooCommerce Redis Object Cache
 *
 * Handles caching of WooCommerce database query results
 *
 * @package WC_Redis_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Redis Object Cache
 */
class WC_Redis_Cache_Object {

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
        // Filter product data
        add_filter('woocommerce_get_product_from_the_post', [$this, 'get_product_from_cache'], 10, 3);
        add_filter('woocommerce_product_get_price', [$this, 'maybe_cache_price'], 99, 2);
        
        // Product query caching
        add_filter('posts_pre_query', [$this, 'cache_product_queries'], 10, 2);
        
        // Term and taxonomy caching
        add_filter('get_terms', [$this, 'cache_terms'], 10, 4);
        add_filter('get_product_categories', [$this, 'cache_product_categories'], 10, 2);
        
        // Customer caching
        add_filter('woocommerce_customer_get_customer_data', [$this, 'cache_customer_data'], 10, 2);
    }

    /**
     * Get product from cache
     *
     * @param WC_Product|false $product
     * @param int|WP_Post $the_product
     * @param bool $deprecated
     * @return WC_Product|false
     */
    public function get_product_from_cache($product, $the_product, $deprecated) {
        if (is_object($product)) {
            return $product;
        }

        $post_id = is_a($the_product, 'WP_Post') ? $the_product->ID : $the_product;
        if (!$post_id) {
            return $product;
        }

        $cache_key = $this->plugin->get_cache_key('product', $post_id);
        $cached_product = $this->plugin->get($cache_key);

        if ($cached_product !== false) {
            return $cached_product;
        }

        // If product is created, cache it for future use
        add_action('woocommerce_after_product_object_save', function($product) {
            if (!$product || !$product->get_id()) {
                return;
            }
            
            $cache_key = $this->plugin->get_cache_key('product', $product->get_id());
            $ttl = $this->plugin->get_ttl('product');
            $this->plugin->set($cache_key, $product, $ttl);
        });

        return $product;
    }

    /**
     * Cache product price
     *
     * @param string $price
     * @param WC_Product $product
     * @return string
     */
    public function maybe_cache_price($price, $product) {
        if (!$product || !$product->get_id()) {
            return $price;
        }

        $cache_key = $this->plugin->get_cache_key('product_price', $product->get_id());
        $cached_price = $this->plugin->get($cache_key);

        if ($cached_price !== false) {
            return $cached_price;
        }

        // Cache price for future use
        $ttl = $this->plugin->get_ttl('product');
        $this->plugin->set($cache_key, $price, $ttl);

        return $price;
    }

    /**
     * Cache product queries
     *
     * @param array|null $posts
     * @param WP_Query $query
     * @return array|null
     */
    public function cache_product_queries($posts, $query) {
        // Only cache product queries
        if (!$query->is_main_query() || $query->get('post_type') !== 'product') {
            return $posts;
        }

        // Generate cache key based on query vars
        $query_vars = $query->query_vars;
        // Remove dynamic vars that shouldn't affect caching
        unset($query_vars['cache_results'], $query_vars['update_post_meta_cache'], $query_vars['update_post_term_cache']);
        
        $cache_key = $this->plugin->get_cache_key('product_query', md5(serialize($query_vars)));
        $cached_posts = $this->plugin->get($cache_key);

        if ($cached_posts !== false) {
            // Set found_posts and max_num_pages
            if (isset($cached_posts['found_posts'])) {
                $query->found_posts = $cached_posts['found_posts'];
                $query->max_num_pages = $cached_posts['max_num_pages'];
                return $cached_posts['posts'];
            }
            return $cached_posts;
        }

        // If we get here, we need to let the query run and cache it after
        add_filter('the_posts', function($posts, $query) use ($cache_key) {
            if (!is_array($posts) || empty($posts)) {
                return $posts;
            }

            // Cache both posts and query results
            $cached_data = [
                'posts' => $posts,
                'found_posts' => $query->found_posts,
                'max_num_pages' => $query->max_num_pages
            ];

            $ttl = $this->plugin->get_ttl('product');
            $this->plugin->set($cache_key, $cached_data, $ttl);

            return $posts;
        }, 10, 2);

        return $posts;
    }

    /**
     * Cache terms
     *
     * @param array $terms
     * @param array $taxonomies
     * @param array $args
     * @param WP_Term_Query $term_query
     * @return array
     */
    public function cache_terms($terms, $taxonomies, $args, $term_query = null) {
        // Only cache product categories and tags
        $wc_taxonomies = ['product_cat', 'product_tag'];
        $should_cache = false;
        
        foreach ($taxonomies as $taxonomy) {
            if (in_array($taxonomy, $wc_taxonomies)) {
                $should_cache = true;
                break;
            }
        }
        
        if (!$should_cache) {
            return $terms;
        }

        // Generate cache key
        $cache_key = $this->plugin->get_cache_key('terms', md5(serialize([$taxonomies, $args])));
        $cached_terms = $this->plugin->get($cache_key);

        if ($cached_terms !== false) {
            return $cached_terms;
        }

        // Cache terms
        if (!empty($terms) && is_array($terms)) {
            $ttl = $this->plugin->get_ttl('category');
            $this->plugin->set($cache_key, $terms, $ttl);
        }

        return $terms;
    }

    /**
     * Cache product categories
     *
     * @param array $categories
     * @param int $product_id
     * @return array
     */
    public function cache_product_categories($categories, $product_id) {
        if (!$product_id || empty($categories)) {
            return $categories;
        }

        $cache_key = $this->plugin->get_cache_key('product_categories', $product_id);
        $cached_categories = $this->plugin->get($cache_key);

        if ($cached_categories !== false) {
            return $cached_categories;
        }

        // Cache categories
        $ttl = $this->plugin->get_ttl('category');
        $this->plugin->set($cache_key, $categories, $ttl);

        return $categories;
    }

    /**
     * Cache customer data
     *
     * @param array $data
     * @param WC_Customer $customer
     * @return array
     */
    public function cache_customer_data($data, $customer) {
        $customer_id = $customer->get_id();
        if (!$customer_id || empty($data)) {
            return $data;
        }

        $cache_key = $this->plugin->get_cache_key('customer', $customer_id);
        $cached_data = $this->plugin->get($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        // Cache customer data
        $ttl = $this->plugin->get_ttl('customer');
        $this->plugin->set($cache_key, $data, $ttl);

        return $data;
    }

    /**
     * Reindex products
     *
     * @param array $args Query args
     * @return int Number of products cached
     */
    public function reindex_products($args = []) {
        $default_args = [
            'post_type' => 'product',
            'posts_per_page' => 100,
            'paged' => 1,
            'post_status' => 'publish',
        ];
        
        $args = wp_parse_args($args, $default_args);
        $count = 0;
        $ttl = $this->plugin->get_ttl('product');
        
        do {
            $products = wc_get_products($args);
            
            if (empty($products)) {
                break;
            }
            
            foreach ($products as $product) {
                $product_id = $product->get_id();
                $cache_key = $this->plugin->get_cache_key('product', $product_id);
                $this->plugin->set($cache_key, $product, $ttl);
                
                // Also cache price
                $price_key = $this->plugin->get_cache_key('product_price', $product_id);
                $this->plugin->set($price_key, $product->get_price(), $ttl);
                
                $count++;
            }
            
            $args['paged']++;
            
        } while (!empty($products));
        
        return $count;
    }
}
