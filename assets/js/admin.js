/**
 * WooCommerce Redis Cache - Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Test Redis connection
        $('#wc-redis-test-connection').on('click', function() {
            const $button = $(this);
            const $result = $('#wc-redis-test-connection-result');
            
            // Get form field values
            const host = $('input[name="wc_redis_cache_settings[redis_host]"]').val();
            const port = $('input[name="wc_redis_cache_settings[redis_port]"]').val();
            const password = $('input[name="wc_redis_cache_settings[redis_password]"]').val();
            const database = $('input[name="wc_redis_cache_settings[redis_database]"]').val();
            const timeout = $('input[name="wc_redis_cache_settings[redis_timeout]"]').val();
            
            $button.prop('disabled', true).text(wc_redis_cache.i18n.testing);
            $result.removeClass('success error').text('');
            
            $.ajax({
                url: wc_redis_cache.ajax_url,
                type: 'POST',
                data: {
                    action: 'wc_redis_cache_test_connection',
                    nonce: wc_redis_cache.nonce,
                    host: host,
                    port: port,
                    password: password,
                    database: database,
                    timeout: timeout
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Test Connection');
                    
                    if (response.success) {
                        $result.addClass('success').text(response.data.message);
                        if (response.data.version) {
                            $result.append(' Redis v' + response.data.version);
                        }
                    } else {
                        $result.addClass('error').text(wc_redis_cache.i18n.error + ' ' + response.data);
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Test Connection');
                    $result.addClass('error').text('Connection test failed. Please check your settings.');
                }
            });
        });
        
        // Flush cache
        $('#wc-redis-flush-cache').on('click', function() {
            const $button = $(this);
            const $result = $('#wc-redis-cache-action-result');
            
            $button.prop('disabled', true).text(wc_redis_cache.i18n.flushing);
            $result.removeClass('success error').text('');
            
            $.ajax({
                url: wc_redis_cache.ajax_url,
                type: 'POST',
                data: {
                    action: 'wc_redis_cache_flush',
                    nonce: wc_redis_cache.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Flush Cache');
                    
                    if (response.success) {
                        $result.addClass('success').text(response.data);
                    } else {
                        $result.addClass('error').text(response.data);
                    }
                    
                    // Auto-reload after 2 seconds to update stats
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                },
                error: function() {
                    $button.prop('disabled', false).text('Flush Cache');
                    $result.addClass('error').text('Failed to flush cache. Please try again.');
                }
            });
        });
        
        // Reindex products
        $('#wc-redis-reindex-products').on('click', function() {
            const $button = $(this);
            const $result = $('#wc-redis-cache-action-result');
            
            $button.prop('disabled', true).text(wc_redis_cache.i18n.reindexing);
            $result.removeClass('success error').text('');
            
            $.ajax({
                url: wc_redis_cache.ajax_url,
                type: 'POST',
                data: {
                    action: 'wc_redis_cache_reindex',
                    nonce: wc_redis_cache.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Reindex Products');
                    
                    if (response.success) {
                        $result.addClass('success').text(response.data);
                    } else {
                        $result.addClass('error').text(response.data);
                    }
                    
                    // Auto-reload after 2 seconds to update stats
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                },
                error: function() {
                    $button.prop('disabled', false).text('Reindex Products');
                    $result.addClass('error').text('Failed to reindex products. Please try again.');
                }
            });
        });
    });
})(jQuery);
