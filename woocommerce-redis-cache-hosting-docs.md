# WooCommerce Redis Cache - Hosting Configuration Guide

This guide provides configuration details for setting up WooCommerce Redis Cache on various hosting environments. Different hosting providers handle Redis in different ways, so follow the guidelines that match your hosting environment.

## General Requirements

For all hosting environments, you need:

1. PHP 7.4 or higher
2. WordPress 5.8 or higher
3. WooCommerce 5.0 or higher
4. Redis server 6.0 or higher
5. PHP Redis extension installed and enabled

## Self-Hosted Environments

### Installing Redis Server

If you're managing your own server, you'll need to install Redis:

#### Ubuntu/Debian

```bash
sudo apt update
sudo apt install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

#### CentOS/RHEL

```bash
sudo yum install redis
sudo systemctl enable redis
sudo systemctl start redis
```

### Installing PHP Redis Extension

#### Ubuntu/Debian

```bash
sudo apt install php-redis
sudo service php-fpm restart  # or apache2 restart
```

#### CentOS/RHEL

```bash
sudo yum install php-redis
sudo systemctl restart php-fpm  # or httpd restart
```

### Configuration

Default configuration (localhost):

- Host: 127.0.0.1
- Port: 6379
- Password: (none)
- Database: 0

To secure your Redis installation, edit the Redis configuration file (`/etc/redis/redis.conf`):

```
# Set a password
requirepass your_strong_password

# Bind to localhost only
bind 127.0.0.1

# Disable dangerous commands
rename-command FLUSHALL ""
rename-command FLUSHDB ""
rename-command CONFIG ""
```

## Managed WordPress Hosting

### Kinsta

Kinsta provides Redis as an add-on service:

1. Enable Redis from the Kinsta dashboard under "Tools" > "Redis" for your site
2. Kinsta will provide you with the connection details
3. Use these details in the WooCommerce Redis Cache settings:
   - Host: `localhost` (typically)
   - Port: 6379
   - Password: (provided by Kinsta)
   - Database: 0

### WP Engine

WP Engine offers Redis as part of their higher-tier plans:

1. Contact WP Engine support to enable Redis for your site
2. They will provide you with connection details
3. Use these details in the plugin settings:
   - Host: (provided by WP Engine)
   - Port: (provided by WP Engine, usually 6379)
   - Password: (provided by WP Engine)
   - Database: 0

Note: WP Engine might have specific Redis configuration requirements or limitations. Follow their documentation for best practices.

### SiteGround

SiteGround offers Redis on their GoGeek plans and higher:

1. Log in to your SiteGround dashboard
2. Go to "Site Tools" > "Speed" > "Caching"
3. Enable Redis
4. Use these connection details:
   - Host: 127.0.0.1
   - Port: 6379
   - Password: (usually not required)
   - Database: 0

### Cloudways

Cloudways has built-in Redis support:

1. Log in to your Cloudways account
2. Navigate to your server
3. Go to "Manage Services" > "Redis"
4. Enable Redis and install the Redis extension
5. Use these connection details:
   - Host: 127.0.0.1
   - Port: 6379
   - Password: (usually not required)
   - Database: 0

## Cloud Providers

### AWS ElastiCache

To use AWS ElastiCache for Redis:

1. Create an ElastiCache Redis cluster in the same VPC as your web server
2. Enable encryption in transit if needed
3. Set up authentication if needed
4. Use these connection details in the plugin:
   - Host: (ElastiCache endpoint URL)
   - Port: 6379
   - Password: (if authentication is enabled)
   - Database: 0

Note: Make sure your EC2 security groups allow traffic from your web server to ElastiCache.

### DigitalOcean Managed Databases

For DigitalOcean Redis:

1. Create a managed Redis database
2. Ensure your Droplet and Redis database are in the same region
3. Add your Droplet to the database's trusted sources
4. Use these connection details:
   - Host: (Database connection endpoint)
   - Port: (usually 25061 for DigitalOcean Redis)
   - Password: (provided by DigitalOcean)
   - Database: 0

### Google Cloud Memorystore

For Google Cloud Memorystore for Redis:

1. Create a Memorystore Redis instance
2. Ensure it's in the same region as your web server
3. Use these connection details:
   - Host: (Memorystore IP address)
   - Port: 6379
   - Password: (if authentication is enabled)
   - Database: 0

## Docker Environments

If you're using Docker:

1. Add Redis to your docker-compose file:

```yaml
services:
  redis:
    image: redis:6
    command: redis-server --requirepass your_password
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data

volumes:
  redis_data:
```

2. Configure the WordPress container to have access to Redis
3. Use these connection details:
   - Host: redis (service name in docker-compose)
   - Port: 6379
   - Password: your_password
   - Database: 0

## Troubleshooting Connections

If you're having trouble connecting to Redis:

1. Verify Redis is running:
   ```bash
   redis-cli ping
   ```

2. Check connectivity from PHP:
   ```php
   <?php
   $redis = new Redis();
   try {
       $redis->connect('127.0.0.1', 6379);
       // If password is set
       $redis->auth('your_password');
       echo $redis->ping();
   } catch (Exception $e) {
       echo $e->getMessage();
   }
   ```

3. Ensure the PHP Redis extension is installed:
   ```bash
   php -m | grep redis
   ```

4. Check for firewall issues:
   ```bash
   telnet your_redis_host 6379
   ```

5. Enable debug mode in the plugin settings and check the WordPress debug log for more details.

## Performance Recommendations

For optimal performance with large WooCommerce stores:

1. Use a dedicated Redis instance for the WooCommerce cache
2. Allocate sufficient memory (at least 1GB for stores with 10,000+ products)
3. Consider Redis cluster for horizontal scaling with very large catalogs
4. Set appropriate TTL values based on how frequently your data changes
5. For full-page caching, consider combining with a CDN for best results
