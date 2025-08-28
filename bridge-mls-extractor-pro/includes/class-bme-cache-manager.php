<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Intelligent cache manager for API responses and database queries
 * Version: 2.2.0 (Refactored filter logic)
 */
class BME_Cache_Manager {
    
    private $cache_group;
    private $default_ttl;
    
    public function __construct() {
        $this->cache_group = BME_CACHE_GROUP;
        $this->default_ttl = BME_CACHE_DURATION;
    }
    
    /**
     * Get cached data with fallback
     */
    public function get($key, $callback = null, $ttl = null) {
        $ttl = $ttl ?: $this->default_ttl;
        $cache_key = $this->build_cache_key($key);
        
        $cached_data = wp_cache_get($cache_key, $this->cache_group);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        if ($callback && is_callable($callback)) {
            $data = $callback();
            $this->set($key, $data, $ttl);
            return $data;
        }
        
        return false; // Return false if no data and no callback
    }
    
    /**
     * Set cache data
     */
    public function set($key, $data, $ttl = null) {
        $ttl = $ttl ?: $this->default_ttl;
        $cache_key = $this->build_cache_key($key);
        
        return wp_cache_set($cache_key, $data, $this->cache_group, $ttl);
    }
    
    /**
     * Delete cached data
     */
    public function delete($key) {
        $cache_key = $this->build_cache_key($key);
        return wp_cache_delete($cache_key, $this->cache_group);
    }
    
    /**
     * Clear all cache for the plugin
     */
    public function flush() {
        if (function_exists('wp_cache_flush_group')) {
            return wp_cache_flush_group($this->cache_group);
        } else {
            // Fallback for older WordPress versions or non-object cache setups
            return wp_cache_flush();
        }
    }
    
    /**
     * Build standardized cache key
     */
    private function build_cache_key($key) {
        if (is_array($key)) {
            return md5(serialize($key));
        }
        return sanitize_key($key);
    }
    
    /**
     * Cache search results with smart invalidation
     */
    public function cache_search_results($filters, $results, $count) {
        $cache_key = 'search_' . md5(serialize($filters));
        
        $cache_data = [
            'results' => $results,
            'count' => $count,
            'timestamp' => time(),
            'filters' => $filters
        ];
        
        return $this->set($cache_key, $cache_data, 300); // 5 minutes TTL
    }
    
    /**
     * Get cached search results
     */
    public function get_cached_search($filters) {
        $cache_key = 'search_' . md5(serialize($filters));
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && is_array($cached_data)) {
            // Check if cache is still fresh
            if ((time() - ($cached_data['timestamp'] ?? 0)) < 300) {
                return $cached_data;
            }
        }
        
        return null;
    }
    
    /**
     * Cache agent data with smart expiration
     */
    public function cache_agent_data($agent_mls_id, $agent_data) {
        $cache_key = 'agent_' . $agent_mls_id;
        
        $cache_data = [
            'data' => $agent_data,
            'cached_at' => time(),
            'expires_at' => time() + (24 * HOUR_IN_SECONDS) // 24 hours
        ];
        
        return $this->set($cache_key, $cache_data, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Get cached agent data
     */
    public function get_cached_agent($agent_mls_id) {
        $cache_key = 'agent_' . $agent_mls_id;
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && isset($cached_data['data'])) {
            return $cached_data['data'];
        }
        
        return null;
    }
    
    /**
     * Cache office data with smart expiration
     */
    public function cache_office_data($office_mls_id, $office_data) {
        $cache_key = 'office_' . $office_mls_id;
        
        $cache_data = [
            'data' => $office_data,
            'cached_at' => time(),
            'expires_at' => time() + (24 * HOUR_IN_SECONDS) // 24 hours
        ];
        
        return $this->set($cache_key, $cache_data, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Get cached office data
     */
    public function get_cached_office($office_mls_id) {
        $cache_key = 'office_' . $office_mls_id;
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && isset($cached_data['data'])) {
            return $cached_data['data'];
        }
        
        return null;
    }

    /**
     * Cache extraction statistics
     */
    public function cache_extraction_stats($extraction_id, $stats) {
        $cache_key = 'extraction_stats_' . $extraction_id;
        return $this->set($cache_key, $stats, HOUR_IN_SECONDS);
    }
    
    /**
     * Get cached extraction statistics
     */
    public function get_extraction_stats($extraction_id) {
        $cache_key = 'extraction_stats_' . $extraction_id;
        return $this->get($cache_key);
    }
    
    /**
     * Get cached filter values, or generate them if they don't exist.
     */
    public function get_filter_values($field) {
        $cache_key = 'filter_values_' . $field;

        // Use the generic get() method with a callback to fetch data if cache is missed.
        $values = $this->get($cache_key, function() use ($field) {
            global $wpdb;
            
            // Get the DB manager instance from the global plugin function.
            $db_manager = bme_pro()->get('db');

            $allowed_fields = [
                'standard_status' => 'listings',
                'property_type' => 'listings',
                'city' => 'listing_location',
                'state_or_province' => 'listing_location'
            ];

            if (!array_key_exists($field, $allowed_fields)) {
                return []; // Return empty array if field is not allowed
            }

            $table_key = $allowed_fields[$field];
            $table_active_name = $db_manager->get_table($table_key);
            $table_archive_name = $db_manager->get_table($table_key . '_archive');

            $sql = "
                (SELECT DISTINCT `{$field}` FROM `{$table_active_name}` WHERE `{$field}` IS NOT NULL AND `{$field}` != '')
                UNION
                (SELECT DISTINCT `{$field}` FROM `{$table_archive_name}` WHERE `{$field}` IS NOT NULL AND `{$field}` != '')
                ORDER BY 1 ASC
            ";

            return $wpdb->get_col($sql);
        }, HOUR_IN_SECONDS); // Cache for 1 hour

        return $values;
    }
    
    /**
     * Invalidate related caches when data changes.
     */
    public function invalidate_listing_caches($listing_id = null) {
        $this->flush();
    }
    
    /**
     * Warm up frequently accessed caches, such as filter dropdown options and extraction stats.
     */
    public function warm_up_caches() {
        $this->warm_up_filter_caches();
        $this->warm_up_extraction_stats();
    }
    
    /**
     * Warm up filter value caches by querying distinct values from the database.
     */
    private function warm_up_filter_caches() {
        $filter_fields = ['standard_status', 'property_type', 'city', 'state_or_province'];
        
        foreach ($filter_fields as $field) {
            // This will trigger the callback in get_filter_values if the cache is empty
            $this->get_filter_values($field);
        }
    }
    
    /**
     * Warm up extraction statistics for recently updated or frequently viewed extractions.
     */
    private function warm_up_extraction_stats() {
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => 10,
            'post_status' => 'publish',
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids'
        ]);
        
        if (empty($extractions)) return;

        $data_processor = bme_pro()->get('processor');
        
        foreach ($extractions as $extraction_id) {
            $cache_key = 'extraction_stats_' . $extraction_id;
            
            if (!$this->get($cache_key)) { // Only warm up if not already cached
                $stats = $data_processor->get_extraction_stats($extraction_id);
                $this->cache_extraction_stats($extraction_id, $stats);
            }
        }
    }
    
    /**
     * Get cache statistics and information about the caching backend.
     */
    public function get_cache_stats() {
        $stats = [
            'cache_backend' => $this->get_cache_backend(),
            'group' => $this->cache_group,
            'default_ttl' => $this->default_ttl
        ];
        
        if (function_exists('wp_cache_get_stats')) {
            $cache_stats = wp_cache_get_stats();
            if ($cache_stats) {
                $stats['cache_stats'] = $cache_stats;
            }
        }
        
        return $stats;
    }
    
    /**
     * Detects the active WordPress object cache backend.
     */
    private function get_cache_backend() {
        global $wp_object_cache;
        if (is_object($wp_object_cache)) {
            $backend = get_class($wp_object_cache);
            if (strpos(strtolower($backend), 'redis') !== false) return 'Redis';
            if (strpos(strtolower($backend), 'memcached') !== false) return 'Memcached';
            return $backend;
        }
        return 'Database (Default)';
    }
    
    /**
     * Schedules a daily cron job to clean up expired cache entries from database tables.
     */
    public function schedule_cache_cleanup() {
        if (!wp_next_scheduled('bme_pro_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'bme_pro_cache_cleanup');
        }
    }
    
    /**
     * Cleans up expired cache entries from the agents and offices database tables.
     */
    public function cleanup_expired_cache() {
        global $wpdb;
        
        $db_manager = bme_pro()->get('db');
        
        $agents_table = $db_manager->get_table('agents');
        $deleted_agents = $wpdb->query(
            "DELETE FROM {$agents_table} WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );
        
        $offices_table = $db_manager->get_table('offices');
        $deleted_offices = $wpdb->query(
            "DELETE FROM {$offices_table} WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );
        
        if ($deleted_agents > 0 || $deleted_offices > 0) {
            error_log("BME Cache Cleanup: Removed {$deleted_agents} expired agents and {$deleted_offices} expired offices");
        }
        
        return [
            'deleted_agents' => $deleted_agents,
            'deleted_offices' => $deleted_offices
        ];
    }
    
    /**
     * Caches large datasets, with a check against memory limits to prevent crashes.
     */
    public function cache_large_dataset($key, $data, $ttl = null) {
        $data_size = strlen(serialize($data));
        $memory_limit = $this->get_memory_limit_bytes();
        
        if ($data_size > ($memory_limit * 0.1)) {
            error_log("BME Cache: Dataset too large to cache ({$data_size} bytes). Memory limit: {$memory_limit} bytes.");
            return false;
        }
        
        return $this->set($key, $data, $ttl);
    }
    
    /**
     * Gets the PHP memory limit in bytes.
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            $number = (int) $matches[1];
            $suffix = strtoupper($matches[2]);
            
            switch ($suffix) {
                case 'G':
                    return $number * 1024 * 1024 * 1024;
                case 'M':
                    return $number * 1024 * 1024;
                case 'K':
                    return $number * 1024;
                default:
                    return $number;
            }
        }
        
        return 128 * 1024 * 1024;
    }
}
