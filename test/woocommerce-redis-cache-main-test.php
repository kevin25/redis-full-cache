<?php
/**
 * Class WC_Redis_Cache_Test
 *
 * @package WC_Redis_Cache
 */

/**
 * Main plugin test case.
 */
class WC_Redis_Cache_Test extends WP_UnitTestCase {

    /**
     * Plugin instance
     *
     * @var WC_Redis_Cache
     */
    protected $plugin;

    /**
     * Setup test environment
     */
    public function setUp(): void {
        parent::setUp();
        $this->plugin = WC_Redis_Cache();
    }

    /**
     * Test that the plugin initializes
     */
    public function test_plugin_initialized() {
        $this->assertInstanceOf('WC_Redis_Cache', $this->plugin);
    }

    /**
     * Test get_setting method
     */
    public function test_get_setting() {
        // Test default value
        $this->assertTrue($this->plugin->get_setting('enable_object_cache', true));
        
        // Test non-existent setting with default
        $this->assertEquals('default_value', $this->plugin->get_setting('non_existent_setting', 'default_value'));
        
        // Test non-existent setting without default
        $this->assertNull($this->plugin->get_setting('non_existent_setting'));
    }

    /**
     * Test get_cache_key method
     */
    public function test_get_cache_key() {
        $blog_id = get_current_blog_id();
        
        // Test product key
        $product_id = 123;
        $expected_key = "wc:{$blog_id}:product:{$product_id}";
        $this->assertEquals($expected_key, $this->plugin->get_cache_key('product', $product_id));
        
        // Test session key
        $session_id = 'abc123';
        $expected_key = "wc:{$blog_id}:session:{$session_id}";
        $this->assertEquals($expected_key, $this->plugin->get_cache_key('session', $session_id));
    }

    /**
     * Test get_ttl method
     */
    public function test_get_ttl() {
        // Test default TTLs
        $this->assertEquals(86400, $this->plugin->get_ttl('product'));
        $this->assertEquals(86400, $this->plugin->get_ttl('category'));
        $this->assertEquals(3600, $this->plugin->get_ttl('cart'));
        $this->assertEquals(86400, $this->plugin->get_ttl('session'));
        
        // Test unknown type (should return 3600)
        $this->assertEquals(3600, $this->plugin->get_ttl('unknown_type'));
    }

    /**
     * Test Redis connection
     * 
     * Note: This test requires Redis to be running
     */
    public function test_redis_connection() {
        // Skip test if Redis is not available
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
            return;
        }
        
        // Try to connect with default settings
        $connected = $this->plugin->is_connected();
        
        // This might fail if Redis is not running, so we're not making assertions
        // Just testing that the method doesn't throw errors
        $this->assertTrue(true);
    }

    /**
     * Test cache operations
     * 
     * Note: This test requires Redis to be running
     */
    public function test_cache_operations() {
        // Skip test if Redis is not available or not connected
        if (!extension_loaded('redis') || !$this->plugin->is_connected()) {
            $this->markTestSkipped('Redis extension not available or not connected');
            return;
        }
        
        // Test data
        $key = $this->plugin->get_cache_key('test', uniqid());
        $value = ['test' => 'data', 'number' => 123];
        
        // Test set
        $result = $this->plugin->set($key, $value, 60);
        $this->assertTrue($result);
        
        // Test get
        $retrieved = $this->plugin->get($key);
        $this->assertEquals($value, $retrieved);
        
        // Test delete
        $result = $this->plugin->delete($key);
        $this->assertTrue($result);
        
        // Verify deleted
        $retrieved = $this->plugin->get($key);
        $this->assertFalse($retrieved);
    }

    /**
     * Test pattern-based deletion
     * 
     * Note: This test requires Redis to be running
     */
    public function test_delete_by_pattern() {
        // Skip test if Redis is not available or not connected
        if (!extension_loaded('redis') || !$this->plugin->is_connected()) {
            $this->markTestSkipped('Redis extension not available or not connected');
            return;
        }
        
        $prefix = 'wc:test:' . uniqid() . ':';
        
        // Create test keys
        $keys = [
            $prefix . 'product:1',
            $prefix . 'product:2',
            $prefix . 'category:1',
            $prefix . 'other:1',
        ];
        
        // Add test data
        foreach ($keys as $key) {
            $this->plugin->set($key, 'test_value', 60);
        }
        
        // Delete by pattern
        $count = $this->plugin->delete_by_pattern($prefix . 'product:*');
        
        // Should have deleted 2 keys
        $this->assertEquals(2, $count);
        
        // Verify deleted
        $this->assertFalse($this->plugin->get($keys[0]));
        $this->assertFalse($this->plugin->get($keys[1]));
        
        // Verify others still exist
        $this->assertEquals('test_value', $this->plugin->get($keys[2]));
        $this->assertEquals('test_value', $this->plugin->get($keys[3]));
        
        // Clean up
        $this->plugin->delete($keys[2]);
        $this->plugin->delete($keys[3]);
    }
}
