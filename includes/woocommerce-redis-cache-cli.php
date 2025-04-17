<?php
/**
 * WooCommerce Redis Cache CLI Commands
 *
 * WP-CLI commands for managing the Redis cache
 *
 * @package WC_Redis_Cache
 */

// Exit if not CLI
if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manage WooCommerce Redis Cache.
 */
class WC_Redis_Cache_CLI extends WP_CLI_Command {

    /**
     * Flush the Redis cache.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Flush specific cache type (products, categories, sessions, transients). Default: all.
     *
     * ## EXAMPLES
     *
     *     # Flush all cache
     *     $ wp redis cache flush
     *
     *     # Flush only product cache
     *     $ wp redis cache flush --type=products
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function flush($args, $assoc_args) {
        $plugin = WC_Redis_Cache();
        
        if (!$plugin->is_connected()) {
            WP_CLI::error('Not connected to Redis. Please check connection settings.');
            return;
        }
        
        $type = isset($assoc_args['type']) ? $assoc_args['type'] : 'all';
        $blog_id = get_current_blog_id();
        
        switch ($type) {
            case 'products':
                $pattern = "wc:{$blog_id}:product:*";
                $count = $plugin->delete_by_pattern($pattern);
                WP_CLI::success("Flushed {$count} product cache keys.");
                break;
                
            case 'categories':
                $pattern = "wc:{$blog_id}:category:*";
                $count = $plugin->delete_by_pattern($pattern);
                $count += $plugin->delete_by_pattern("wc:{$blog_id}:terms:*");
                WP_CLI::success("Flushed {$count} category cache keys.");
                break;
                
            case 'sessions':
                $pattern = "wc:{$blog_id}:session:*";
                $count = $plugin->delete_by_pattern($pattern);
                WP_CLI::success("Flushed {$count} session cache keys.");
                break;
                
            case 'transients':
                $pattern = "wc:{$blog_id}:transient:*";
                $count = $plugin->delete_by_pattern($pattern);
                WP_CLI::success("Flushed {$count} transient cache keys.");
                break;
                
            case 'all':
            default:
                $pattern = "wc:{$blog_id}:*";
                $count = $plugin->delete_by_pattern($pattern);
                WP_CLI::success("Flushed {$count} cache keys.");
                break;
        }
    }

    /**
     * Display Redis cache statistics.
     *
     * ## EXAMPLES
     *
     *     # Show cache statistics
     *     $ wp redis cache stats
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function stats($args, $assoc_args) {
        $plugin = WC_Redis_Cache();
        
        if (!$plugin->is_connected()) {
            WP_CLI::error('Not connected to Redis. Please check connection settings.');
            return;
        }
        
        $stats = $plugin->get_stats();
        $redis = $plugin->get_redis();
        $info = $redis->info();
        
        // Format stats
        WP_CLI::line('WooCommerce Redis Cache Statistics:');
        WP_CLI::line('');
        
        // Redis Info
        $table_data = [
            ['Redis Version', $info['redis_version'] ?? 'Unknown'],
            ['Redis Mode', $info['redis_mode'] ?? 'Unknown'],
            ['Uptime', $this->format_uptime($info['uptime_in_seconds'] ?? 0)],
            ['Memory Usage', $info['used_memory_human'] ?? 'Unknown'],
            ['Peak Memory', $info['used_memory_peak_human'] ?? 'Unknown'],
            ['Connected Clients', $info['connected_clients'] ?? 'Unknown'],
            ['Total Commands', $info['total_commands_processed'] ?? 'Unknown'],
        ];
        
        WP_CLI\Utils\format_items('table', $table_data, ['Property', 'Value']);
        WP_CLI::line('');
        
        // Cache Stats
        $blog_id = get_current_blog_id();
        $counts = [
            ['Product Keys', count($redis->keys("wc:{$blog_id}:product:*"))],
            ['Category Keys', count($redis->keys("wc:{$blog_id}:category:*"))],
            ['Session Keys', count($redis->keys("wc:{$blog_id}:session:*"))],
            ['Transient Keys', count($redis->keys("wc:{$blog_id}:transient:*"))],
            ['Total Keys', $stats['total_keys'] ?? 0],
        ];
        
        WP_CLI\Utils\format_items('table', $counts, ['Cache Type', 'Count']);
        WP_CLI::line('');
        
        // Performance
        $performance = [
            ['Cache Hits', $stats['hits'] ?? 0],
            ['Cache Misses', $stats['misses'] ?? 0],
            ['Hit Ratio', ($stats['hit_ratio'] ?? 0) . '%'],
            ['Response Time', ($stats['time'] ?? 0) . ' seconds'],
        ];
        
        WP_CLI\Utils\format_items('table', $performance, ['Metric', 'Value']);
    }

    /**
     * Test Redis connection.
     *
     * ## EXAMPLES
     *
     *     # Test Redis connection
     *     $ wp redis cache test
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function test($args, $assoc_args) {
        $plugin = WC_Redis_Cache();
        
        WP_CLI::line('Testing Redis connection...');
        
        if ($plugin->is_connected()) {
            $redis = $plugin->get_redis();
            $info = $redis->info();
            
            WP_CLI::success("Connected to Redis server successfully!");
            WP_CLI::line("Redis version: " . ($info['redis_version'] ?? 'Unknown'));
            WP_CLI::line("Memory usage: " . ($info['used_memory_human'] ?? 'Unknown'));
            
            // Test write/read speed
            WP_CLI::line("\nPerforming read/write test...");
            
            $test_key = "wc_test_" . uniqid();
            $test_value = "Test value " . time();
            
            $start = microtime(true);
            $redis->set($test_key, $test_value);
            $write_time = microtime(true) - $start;
            
            $start = microtime(true);
            $read_value = $redis->get($test_key);
            $read_time = microtime(true) - $start;
            
            $redis->del($test_key);
            
            WP_CLI::line("Write time: " . round($write_time * 1000, 2) . " ms");
            WP_CLI::line("Read time: " . round($read_time * 1000, 2) . " ms");
            
            if ($read_value === $test_value) {
                WP_CLI::success("Data integrity verified.");
            } else {
                WP_CLI::warning("Data integrity check failed.");
            }
        } else {
            WP_CLI::error("Failed to connect to Redis server. Please check your settings.");
        }
    }

    /**
     * Reindex WooCommerce products in the cache.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Limit the number of products to reindex. Default: all.
     *
     * [--product-type=<type>]
     * : Only reindex specific product types (simple, variable, etc.). Default: all.
     *
     * ## EXAMPLES
     *
     *     # Reindex all products
     *     $ wp redis cache reindex
     *
     *     # Reindex only 100 products
     *     $ wp redis cache reindex --limit=100
     *
     *     # Reindex only simple products
     *     $ wp redis cache reindex --product-type=simple
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function reindex($args, $assoc_args) {
        $plugin = WC_Redis_Cache();
        
        if (!$plugin->is_connected()) {
            WP_CLI::error('Not connected to Redis. Please check connection settings.');
            return;
        }
        
        require_once WC_REDIS_CACHE_PATH . 'includes/class-wc-redis-cache-object.php';
        $object_cache = new WC_Redis_Cache_Object($plugin);
        
        $limit = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 0;
        $product_type = isset($assoc_args['product-type']) ? $assoc_args['product-type'] : '';
        
        // Build query args
        $query_args = [
            'posts_per_page' => ($limit > 0) ? min(100, $limit) : 100,
            'paged' => 1,
        ];
        
        if (!empty($product_type)) {
            $query_args['type'] = $product_type;
        }
        
        // Start progress bar
        $products_count = wp_count_posts('product');
        $total = $products_count->publish ?? 0;
        
        if ($limit > 0) {
            $total = min($total, $limit);
        }
        
        $progress = \WP_CLI\Utils\make_progress_bar('Reindexing products', $total);
        $count = 0;
        
        do {
            $products = wc_get_products($query_args);
            
            if (empty($products)) {
                break;
            }
            
            foreach ($products as $product) {
                $product_id = $product->get_id();
                $cache_key = $plugin->get_cache_key('product', $product_id);
                $ttl = $plugin->get_ttl('product');
                $plugin->set($cache_key, $product, $ttl);
                
                // Also cache price
                $price_key = $plugin->get_cache_key('product_price', $product_id);
                $plugin->set($price_key, $product->get_price(), $ttl);
                
                $count++;
                $progress->tick();
                
                if ($limit > 0 && $count >= $limit) {
                    break;
                }
            }
            
            $query_args['paged']++;
            
            if ($limit > 0 && $count >= $limit) {
                break;
            }
        } while (!empty($products));
        
        $progress->finish();
        
        WP_CLI::success(sprintf(
            '%d products have been reindexed in the Redis cache.',
            $count
        ));
    }

    /**
     * Format uptime in a human-readable format
     *
     * @param int $seconds Uptime in seconds
     * @return string Formatted uptime
     */
    protected function format_uptime($seconds) {
        $days = floor($seconds / 86400);
        $seconds %= 86400;
        
        $hours = floor($seconds / 3600);
        $seconds %= 3600;
        
        $minutes = floor($seconds / 60);
        $seconds %= 60;
        
        $parts = [];
        
        if ($days > 0) {
            $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
        }
        
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        
        if ($minutes > 0) {
            $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        
        if ($seconds > 0 || empty($parts)) {
            $parts[] = $seconds . ' second' . ($seconds != 1 ? 's' : '');
        }
        
        return implode(', ', $parts);
    }
}

// Register WP-CLI command
if (class_exists('WP_CLI')) {
    WP_CLI::add_command('redis cache', 'WC_Redis_Cache_CLI');
}