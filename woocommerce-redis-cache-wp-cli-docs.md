# WooCommerce Redis Cache - WP-CLI Commands

This plugin provides several WP-CLI commands to manage the Redis cache from the command line. These commands are especially useful for automation, scheduled tasks, and managing large WooCommerce stores.

## Available Commands

### Flush Cache

Flush the Redis cache, either completely or by specific type.

```bash
# Flush all cache
wp redis cache flush

# Flush only product cache
wp redis cache flush --type=products

# Flush only category cache
wp redis cache flush --type=categories

# Flush only session cache
wp redis cache flush --type=sessions

# Flush only transient cache
wp redis cache flush --type=transients
```

### Display Cache Statistics

Show statistics about the Redis cache, including memory usage, hit/miss ratios, and key counts.

```bash
wp redis cache stats
```

Example output:

```
WooCommerce Redis Cache Statistics:

+------------------+--------------+
| Property         | Value        |
+------------------+--------------+
| Redis Version    | 6.2.6        |
| Redis Mode       | standalone   |
| Uptime           | 3 days, 5 h  |
| Memory Usage     | 1.25M        |
| Peak Memory      | 2.10M        |
| Connected Clients| 1            |
| Total Commands   | 1542387      |
+------------------+--------------+

+----------------+-------+
| Cache Type     | Count |
+----------------+-------+
| Product Keys   | 256   |
| Category Keys  | 18    |
| Session Keys   | 32    |
| Transient Keys | 46    |
| Total Keys     | 352   |
+----------------+-------+

+-------------+--------+
| Metric      | Value  |
+-------------+--------+
| Cache Hits  | 25487  |
| Cache Misses| 1209   |
| Hit Ratio   | 95.47% |
| Response Time| 0.021 s|
+-------------+--------+
```

### Test Redis Connection

Test the connection to the Redis server and display basic performance metrics.

```bash
wp redis cache test
```

Example output:

```
Testing Redis connection...
Connected to Redis server successfully!
Redis version: 6.2.6
Memory usage: 1.25M

Performing read/write test...
Write time: 0.35 ms
Read time: 0.28 ms
Data integrity verified.
Success: Connection test passed.
```

### Reindex Products

Reindex products in the Redis cache. This is useful after bulk imports or updates, or when setting up the cache initially for a large store.

```bash
# Reindex all products
wp redis cache reindex

# Reindex only 100 products
wp redis cache reindex --limit=100

# Reindex only simple products
wp redis cache reindex --product-type=simple

# Reindex only variable products
wp redis cache reindex --product-type=variable
```

## Usage Examples

### Scheduled Cache Maintenance

You can set up a cron job to periodically flush transients and refresh product cache:

```bash
# In your crontab:
# Every day at 3 AM, flush transients and reindex products
0 3 * * * cd /path/to/wordpress && wp redis cache flush --type=transients && wp redis cache reindex
```

### After Large Imports

After importing a large number of products, you can reindex the cache:

```bash
# Import products first
wp wc product import /path/to/products.csv

# Then reindex the Redis cache
wp redis cache reindex
```

### Performance Monitoring

You can use the stats command to regularly check cache performance:

```bash
# Save stats to a log file
wp redis cache stats >> /path/to/redis-stats.log
```

## Troubleshooting

If you encounter issues with the WP-CLI commands:

1. Ensure Redis server is running: `redis-cli ping`
2. Check if WordPress can connect to Redis: `wp redis cache test`
3. Verify plugin settings: `wp option get wc_redis_cache_settings --format=json`
4. Make sure WooCommerce is active: `wp plugin status woocommerce`

For more advanced troubleshooting, enable debug mode in the plugin settings and check the WordPress debug log.
