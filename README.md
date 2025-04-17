# WooCommerce Redis Cache

High-performance Redis caching solution for WooCommerce stores, designed to handle large product catalogs efficiently.

## Features

- **Object Caching**: Cache WooCommerce products, categories, and other database queries in Redis
- **Session Caching**: Store user sessions in Redis instead of the database
- **Transient Caching**: Cache WordPress transients used by WooCommerce
- **Full-Page Caching**: Optional caching of entire pages for logged-out users
- **Automatic Cache Invalidation**: Clear relevant cache when content changes
- **Flexible TTL Settings**: Configure expiration times for different types of cached data
- **Admin Dashboard**: User-friendly interface for configuration and monitoring
- **WP-CLI Integration**: Command-line tools for managing cache
- **Performance Statistics**: Track cache hit/miss ratios and memory usage
- **Multisite Support**: Works with WordPress multisite setups

## Requirements

- PHP 7.4 or higher
- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- Redis server 6.0 or higher
- PHP Redis extension

## Installation

### Manual Installation

1. Download the plugin and extract it to your `/wp-content/plugins/` directory
2. Activate the plugin through the WordPress admin interface
3. Go to **WooCommerce > Redis Cache** to configure your Redis connection

### Via Composer

```
composer require your-vendor/woocommerce-redis-cache
```

## Configuration

1. Ensure you have Redis installed and running on your server or a remote host
2. Go to **WooCommerce > Redis Cache** in your WordPress admin
3. Enter your Redis connection details:
   - Host: Your Redis server host (default: 127.0.0.1)
   - Port: Your Redis server port (default: 6379)
   - Password: Redis authentication password (if required)
   - Database: Redis database index (0-15)
4. Configure caching options:
   - Enable/disable object caching, session caching, transient caching, or full-page caching
   - Set TTL (time-to-live) values for different types of cache
5. Click "Save Settings" and test your connection

## Usage

Once configured, the plugin works automatically to cache WooCommerce data and reduce database load. You can monitor performance and manage the cache through the admin dashboard or WP-CLI.

### Admin Dashboard

The dashboard shows:
- Connection status
- Cache statistics (hit ratio, memory usage)
- Action buttons for flushing cache or reindexing products

### WP-CLI Commands

```bash
# Flush all cache
wp redis cache flush

# Flush specific cache type
wp redis cache flush --type=products

# Show cache statistics
wp redis cache stats

# Test Redis connection
wp redis cache test

# Reindex products
wp redis cache reindex

# Reindex only specific product types
wp redis cache reindex --product-type=simple
```

## Advanced Configuration

### WooCommerce-Specific Settings

The plugin is optimized for WooCommerce with special handling for:
- Product data
- Category and taxonomy data
- User sessions and carts
- Transients used by WooCommerce extensions

### Performance Tuning

For large stores (300k+ products):
1. Increase `product_ttl` to reduce cache rebuilding frequency
2. Consider enabling the full-page cache for maximum performance
3. Use WP-CLI to reindex products during maintenance windows

### Working with Hosting Providers

The plugin is compatible with managed Redis services provided by:
- Kinsta
- WP Engine
- Cloudways
- DigitalOcean
- AWS ElastiCache

## Troubleshooting

### Connection Issues
1. Verify Redis is running with `redis-cli ping`
2. Check firewall settings if using a remote Redis server
3. Ensure PHP Redis extension is installed with `php -m | grep redis`

### Cache Invalidation Problems
If you notice stale content:
1. Check for errors in the WordPress debug log
2. Enable debug mode in the plugin settings
3. Manually flush the cache from the admin dashboard

### Compatibility Issues
The plugin is designed to work with major caching plugins, but conflicts may occur. If you encounter issues:
1. Temporarily disable other caching plugins
2. Check for error messages in the WordPress debug log
3. Contact support with details about your environment

## Support

For bug reports, feature requests or support, please use the GitHub issues tracker.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by Your Name/Company.
