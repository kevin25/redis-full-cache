<?php
/**
 * Class WC_Redis_Cache_Object_Test
 *
 * @package WC_Redis_Cache
 */

/**
 * Object cache test case.
 */
class WC_Redis_Cache_Object_Test extends WP_UnitTestCase {

    /**
     * Plugin instance
     *
     * @var WC_Redis_Cache
     */
    protected $plugin;

    /**
     * Object cache instance
     *
     * @var WC_Redis_Cache_Object
     */
    protected $object_cache;

    /**
     * Test product ID
     *
     * @var int
     */
    protected $product_id;

    /**
     * Setup test environment
     */
    public function setUp(): void {
        parent::setUp();
        
        // Skip all tests if WooCommerce is not active
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active');
            return;
        }
        
        $this->plugin = WC_Redis_Cache();
        
        // Skip all tests if Redis is not available
        if (!extension_loaded('redis') || !$this->plugin->is_connected()) {
            $this->markTestSkipped('Redis extension not available or not connected');
            return;
        }
        
        $this->object_cache = new WC_Redis_Cache_Object($this->plugin);
        
        // Create a test product
        $this->product_id = $this->create_test_product();
    }

    /**
     * Teardown test environment
     */
    public function tearDown(): void {
        // Clean up test product
        if ($this->product_id) {
            wp_delete_post($this->product_id, true);
        }
        
        parent::tearDown();
    }

    /**
     * Create a test product
     *
     * @return int Product ID
     */
    protected function create_test_product() {
        $product = new WC_Product_Simple();
        $product->set_name('Test Product');
        $product->set_regular_price('10.99');
        $product->set_status('publish');
        $product->save();
        
        return $product->get_id();
    }

    /**
     * Test product caching
     */
    public function test_product_caching() {
        // Clear existing cache
        $cache_key = $this->plugin->get_cache_key('product', $this->product_id);
        $this->plugin->delete($cache_key);
        
        // Get product (should cache it)
        $product = wc_get_product($this->product_id);
        
        // Check that the product was cached
        $cached_product = $this->plugin->get($cache_key);
        $this->assertInstanceOf('WC_Product', $cached_product);
        $this->assertEquals($this->product_id, $cached_product->get_id());
        
        // Check cached data matches original
        $this->assertEquals($product->get_name(), $cached_product->get_name());
        $this->assertEquals($product->get_price(), $cached_product->get_price());
    }

    /**
     * Test price caching
     */
    public function test_price_caching() {
        // Clear existing cache
        $price_key = $this->plugin->get_cache_key('product_price', $this->product_id);
        $this->plugin->delete($price_key);
        
        // Get product
        $product = wc_get_product($this->product_id);
        
        // Access price to trigger caching
        $price = $product->get_price();
        
        // Check that the price was cached
        $cached_price = $this->plugin->get($price_key);
        $this->assertEquals($price, $cached_price);
        
        // Change price and verify cache is updated
        $product->set_regular_price('15.99');
        $product->save();
        
        // Get updated product
        $updated_product = wc_get_product($this->product_id);
        $updated_price = $updated_product->get_price();
        
        // Verify cache was invalidated and updated
        $new_cached_price = $this->plugin->get($price_key);
        $this->assertEquals($updated_price, $new_cached_price);
    }

    /**
     * Test product query caching
     */
    public function test_product_query_caching() {
        // This is harder to test directly as it depends on filters in WP_Query
        // But we can verify the method exists and doesn't cause errors
        
        $query = new WP_Query([
            'post_type' => 'product',
            'posts_per_page' => 10,
        ]);
        
        $this->assertNotEmpty($query->posts);
        
        // Method should exist
        $this->assertTrue(method_exists($this->object_cache, 'cache_product_queries'));
    }

    /**
     * Test reindexing function
     */
    public function test_reindex_products() {
        // Clear existing cache
        $cache_key = $this->plugin->get_cache_key('product', $this->product_id);
        $this->plugin->delete($cache_key);
        
        // Should not be in cache
        $cached_product = $this->plugin->get($cache_key);
        $this->assertFalse($cached_product);
        
        // Reindex products
        $count = $this->object_cache->reindex_products();
        
        // Should have indexed at least our test product
        $this->assertGreaterThanOrEqual(1, $count);
        
        // Should now be in cache
        $cached_product = $this->plugin->get($cache_key);
        $this->assertInstanceOf('WC_Product', $cached_product);
        $this->assertEquals($this->product_id, $cached_product->get_id());
    }
}
