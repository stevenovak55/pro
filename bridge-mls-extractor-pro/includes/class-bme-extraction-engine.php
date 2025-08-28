<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main extraction engine orchestrating the entire data extraction process
 * Version: 1.4 (Pass Correct Lookback Key)
 */
class BME_Extraction_Engine {

    private $api_client;
    private $data_processor;
    private $cache_manager;

    public function __construct(BME_API_Client $api_client, BME_Data_Processor $data_processor, BME_Cache_Manager $cache_manager) {
        $this->api_client = $api_client;
        $this->data_processor = $data_processor;
        $this->cache_manager = $cache_manager;
    }

    /**
     * Execute a single extraction profile
     */
    public function run_extraction($extraction_id, $is_resync = false) {
        $start_time = microtime(true);
        $memory_start = memory_get_usage();

        // Initialize live progress tracking
        $this->init_live_progress($extraction_id, $start_time);

        try {
            // Validate API credentials first
            $this->api_client->validate_credentials();

            // Get extraction configuration
            $config = $this->get_extraction_config($extraction_id, $is_resync);

            // Clear existing data if resync
            if ($is_resync) {
                $cleared = $this->data_processor->clear_extraction_data($extraction_id);
                $this->log_extraction_step($extraction_id, 'info', "Cleared {$cleared} existing listings for resync");
                $this->update_live_progress($extraction_id, ['last_message' => sprintf('Cleared %d existing listings for resync.', $cleared)]);
            }

            // Build API filter query
            $filter_query = $this->api_client->build_filter_query($config);
            // Log the filter query explicitly here
            $this->log_extraction_step($extraction_id, 'info', "API Filter Query built: " . $filter_query);
            $this->update_live_progress($extraction_id, ['last_message' => 'Fetching data from MLS API with filter: ' . $filter_query]);


            // Initialize extraction metrics
            $metrics = [
                'total_listings' => 0,
                'total_batches' => 0,
                'api_requests' => 0,
                'errors' => [],
                'last_modified' => $config['last_modified']
            ];

            // Create extraction callback
            $extraction_callback = function($batch_listings, $total_processed) use ($extraction_id, &$metrics) {
                $result = $this->process_listings_batch($extraction_id, $batch_listings, $metrics);

                // Update live progress after each batch
                $this->update_live_progress($extraction_id, [
                    'total_processed_current_run' => $metrics['total_listings'],
                    'last_message' => sprintf('Processed batch: %d listings. Total: %d.', $result['processed'], $metrics['total_listings']),
                    'property_subtype_counts' => $metrics['property_subtype_counts'] ?? [],
                    'current_listing_mls_id' => $batch_listings[count($batch_listings) - 1]['ListingId'] ?? '',
                    'current_listing_address' => $batch_listings[count($batch_listings) - 1]['UnparsedAddress'] ?? '',
                ]);

                return $result;
            };

            // Execute main extraction
            $total_processed = $this->api_client->fetch_listings($filter_query, $extraction_callback);

            // Update last modified timestamp
            if ($total_processed > 0 && !empty($metrics['last_modified'])) {
                update_post_meta($extraction_id, '_bme_last_modified', $metrics['last_modified']);
            }

            // Invalidate cache on successful run to refresh browser data
            if ($total_processed > 0 || $is_resync) {
                $this->cache_manager->invalidate_listing_caches();
                $this->log_extraction_step($extraction_id, 'info', "Plugin caches invalidated successfully.");
            }

            // Calculate final metrics
            $duration = microtime(true) - $start_time;
            $memory_peak = memory_get_peak_usage() - $memory_start;

            // Log completion
            $this->log_extraction_completion($extraction_id, [
                'status' => empty($metrics['errors']) ? 'Success' : 'Completed with errors',
                'total_listings' => $total_processed,
                'duration' => $duration,
                'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2),
                'api_requests' => $metrics['api_requests'],
                'errors' => $metrics['errors'],
                'is_resync' => $is_resync
            ]);

            // Finalize live progress
            $this->finalize_live_progress($extraction_id, 'completed', $total_processed);

            return true;

        } catch (Exception $e) {
            $duration = microtime(true) - $start_time;

            $this->log_extraction_completion($extraction_id, [
                'status' => 'Failure',
                'error_message' => $e->getMessage(),
                'duration' => $duration,
                'memory_peak_mb' => round((memory_get_peak_usage() - $memory_start) / 1024 / 1024, 2),
                'is_resync' => $is_resync
            ]);

            // Finalize live progress with failure
            $this->finalize_live_progress($extraction_id, 'failed', 0, $e->getMessage());

            return false;
        }
    }

    /**
     * Process a batch of listings with related data
     */
    private function process_listings_batch($extraction_id, $batch_listings, &$metrics) {
        try {
            // Extract IDs for related data fetching
            $agent_ids = [];
            $office_ids = [];
            $listing_keys_active = [];
            $property_subtypes = $metrics['property_subtype_counts'] ?? []; // Initialize or retrieve existing counts

            foreach ($batch_listings as $listing) {
                if (!empty($listing['ListAgentMlsId'])) $agent_ids[] = $listing['ListAgentMlsId'];
                if (!empty($listing['BuyerAgentMlsId'])) $agent_ids[] = $listing['BuyerAgentMlsId'];
                if (!empty($listing['ListOfficeMlsId'])) $office_ids[] = $listing['ListOfficeMlsId'];
                if (!empty($listing['BuyerOfficeMlsId'])) $office_ids[] = $listing['BuyerOfficeMlsId'];

                // Only check for open houses on active listings to save API calls
                if (isset($listing['StandardStatus']) && !$this->data_processor->is_archived_status($listing['StandardStatus'])) {
                    if (!empty($listing['ListingKey'])) {
                        $listing_keys_active[] = $listing['ListingKey'];
                    }
                }

                // Track PropertySubType counts
                if (!empty($listing['PropertySubType'])) {
                    $sub_type = sanitize_text_field($listing['PropertySubType']);
                    $property_subtypes[$sub_type] = ($property_subtypes[$sub_type] ?? 0) + 1;
                }

                // Track latest modification timestamp
                if (!empty($listing['ModificationTimestamp'])) {
                    $metrics['last_modified'] = $listing['ModificationTimestamp'];
                }
            }

            // Fetch related data. Open House data will only be fetched for active listings.
            $related_data = $this->api_client->fetch_related_data(
                $agent_ids,
                $office_ids,
                $listing_keys_active
            );

            // Increment API request counter
            $metrics['api_requests'] += 1 + ceil(count(array_unique($agent_ids)) / 50) +
                                       ceil(count(array_unique($office_ids)) / 50) +
                                       ceil(count(array_unique($listing_keys_active)) / 50);

            // Process the batch
            $result = $this->data_processor->process_listings_batch(
                $extraction_id,
                $batch_listings,
                $related_data
            );

            // Update metrics
            $metrics['total_listings'] += $result['processed'];
            $metrics['total_batches']++;
            $metrics['errors'] = array_merge($metrics['errors'], $result['errors']);
            $metrics['property_subtype_counts'] = $property_subtypes; // Update main metrics array

            // Log batch progress
            $this->log_extraction_step(
                $extraction_id,
                'info',
                sprintf(
                    'Processed batch: %d listings, %d errors, %.2f seconds',
                    $result['processed'],
                    count($result['errors']),
                    $result['duration']
                )
            );

            return $result;

        } catch (Exception $e) {
            $this->log_extraction_step($extraction_id, 'error', 'Batch processing failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get extraction configuration from post meta
     */
    private function get_extraction_config($extraction_id, $is_resync) {
        $config = [
            'extraction_id' => $extraction_id,
            'is_resync' => $is_resync,
            'statuses' => get_post_meta($extraction_id, '_bme_statuses', true) ?: [],
            'cities' => get_post_meta($extraction_id, '_bme_cities', true),
            'states' => get_post_meta($extraction_id, '_bme_states', true) ?: [],
            'list_agent_id' => get_post_meta($extraction_id, '_bme_list_agent_id', true),
            'buyer_agent_id' => get_post_meta($extraction_id, '_bme_buyer_agent_id', true),
            'closed_lookback_months' => get_post_meta($extraction_id, '_bme_lookback_months', true) ?: 12, // Ensure correct meta key
            'last_modified' => get_post_meta($extraction_id, '_bme_last_modified', true) ?: '1970-01-01T00:00:00Z'
        ];

        // Debug logging
        error_log('BME Debug - Extraction config for ID ' . $extraction_id . ': ' . print_r($config, true));

        // Validate required configuration
        if (empty($config['statuses'])) {
            throw new Exception('No listing statuses configured for extraction. Please edit the extraction and select at least one status.');
        }

        // Safety check - if no filters are set, require confirmation
        $has_filters = !empty($config['cities']) ||
                      !empty($config['states']) ||
                      !empty($config['list_agent_id']) ||
                      !empty($config['buyer_agent_id']);

        if (!$has_filters && !$is_resync) {
            // Log warning but don't prevent extraction
            error_log('BME Warning - Extraction ' . $extraction_id . ' has no geographic or agent filters. This may pull a large dataset.');
        }

        return $config;
    }

    /**
     * Initialize live progress transient for an extraction.
     * @param int $extraction_id The ID of the extraction.
     * @param float $start_time The microtime when extraction started.
     */
    private function init_live_progress($extraction_id, $start_time) {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        $initial_data = [
            'status' => 'running',
            'total_processed_current_run' => 0,
            'current_listing_mls_id' => '',
            'current_listing_address' => '',
            'property_subtype_counts' => [],
            'last_update_timestamp' => time(),
            'last_message' => 'Extraction started...',
            'extraction_start_time' => $start_time,
            'error_message' => ''
        ];
        set_transient($progress_key, $initial_data, HOUR_IN_SECONDS); // Keep for 1 hour
        $this->log_extraction_step($extraction_id, 'info', 'Live progress initialized.');
    }

    /**
     * Update live progress transient.
     * @param int $extraction_id The ID of the extraction.
     * @param array $data_to_update Associative array of data to merge into the transient.
     */
    private function update_live_progress($extraction_id, $data_to_update) {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        $current_data = get_transient($progress_key);
        if ($current_data === false) {
            // If transient somehow expired or was deleted, re-initialize minimally
            $current_data = [
                'status' => 'running',
                'total_processed_current_run' => 0,
                'property_subtype_counts' => [],
                'extraction_start_time' => microtime(true),
            ];
        }

        $merged_data = array_merge($current_data, $data_to_update);
        $merged_data['last_update_timestamp'] = time(); // Always update timestamp
        set_transient($progress_key, $merged_data, HOUR_IN_SECONDS);
    }

    /**
     * Finalize live progress transient (set status to completed/failed and remove live data).
     * @param int $extraction_id The ID of the extraction.
     * @param string $final_status 'completed' or 'failed'.
     * @param int $final_count The total number of listings processed.
     * @param string $error_message Optional error message.
     */
    private function finalize_live_progress($extraction_id, $final_status, $final_count, $error_message = '') {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        $final_data = get_transient($progress_key);
        if ($final_data === false) {
            $final_data = []; // Fallback if transient already gone
        }

        $final_data['status'] = $final_status;
        $final_data['total_processed_current_run'] = $final_count;
        $final_data['last_message'] = ($final_status === 'completed') ? 'Extraction completed.' : 'Extraction failed.';
        $final_data['error_message'] = $error_message;
        $final_data['last_update_timestamp'] = time();

        set_transient($progress_key, $final_data, MINUTE_IN_SECONDS * 5); // Keep final status for a short period
        $this->log_extraction_step($extraction_id, 'info', 'Live progress finalized. Status: ' . $final_status);
    }

    /**
     * Get live progress data for a specific extraction.
     * @param int $extraction_id The ID of the extraction.
     * @return array|false The live progress data, or false if not found.
     */
    public function get_live_progress($extraction_id) {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        return get_transient($progress_key);
    }

    /**
     * Log extraction step for debugging
     */
    private function log_extraction_step($extraction_id, $level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $prefix = sprintf('[BME Extraction %d]', $extraction_id);
            error_log("{$prefix} [{$level}] {$message}");
        }
    }

    /**
     * Log extraction completion with detailed metrics
     */
    private function log_extraction_completion($extraction_id, $metrics) {
        global $wpdb;

        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');

        $log_data = [
            'extraction_id' => $extraction_id,
            'status' => $metrics['status'],
            'listings_processed' => $metrics['total_listings'] ?? 0,
            'duration_seconds' => round($metrics['duration'], 3),
            'memory_peak_mb' => $metrics['memory_peak_mb'],
            'api_requests_count' => $metrics['api_requests'] ?? 0,
            'started_at' => date('Y-m-d H:i:s', time() - $metrics['duration']),
            'completed_at' => current_time('mysql')
        ];

        // Build message
        if ($metrics['status'] === 'Success') {
            $run_type = $metrics['is_resync'] ? 'Full Re-sync' : 'Standard Run';
            $log_data['message'] = sprintf(
                '%s completed successfully. Processed %d listings in %.2f seconds.',
                $run_type,
                $metrics['total_listings'] ?? 0,
                $metrics['duration']
            );
        } elseif ($metrics['status'] === 'Completed with errors') {
            $log_data['message'] = sprintf(
                'Extraction completed with %d errors. Processed %d listings.',
                count($metrics['errors']),
                $metrics['total_listings'] ?? 0
            );
            $log_data['error_details'] = json_encode($metrics['errors']);
        } else {
            $log_data['message'] = $metrics['error_message'] ?? 'Unknown error occurred';
            if (!empty($metrics['error_message'])) {
                $log_data['error_details'] = json_encode(['message' => $metrics['error_message']]);
            }
        }

        // Insert log record
        $wpdb->insert($table, $log_data);

        // Update extraction post meta
        update_post_meta($extraction_id, '_bme_last_run_status', $metrics['status']);
        update_post_meta($extraction_id, '_bme_last_run_time', time());

        // Update performance metrics
        if (!empty($metrics['duration'])) {
            update_post_meta($extraction_id, '_bme_last_run_duration', round($metrics['duration'], 2));
        }

        if (!empty($metrics['total_listings'])) {
            update_post_meta($extraction_id, '_bme_last_run_count', $metrics['total_listings']);
        }
    }

    /**
     * Get extraction statistics
     */
    public function get_extraction_stats($extraction_id) {
        return $this->data_processor->get_extraction_stats($extraction_id);
    }

    /**
     * Run multiple extractions (for cron)
     */
    public function run_scheduled_extractions() {
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_bme_schedule',
                    'value' => 'none',
                    'compare' => '!='
                ]
            ]
        ]);

        if (empty($extractions)) {
            return 0;
        }

        $executed = 0;
        $schedules = wp_get_schedules();

        foreach ($extractions as $extraction) {
            $schedule = get_post_meta($extraction->ID, '_bme_schedule', true);
            $last_run = get_post_meta($extraction->ID, '_bme_last_run_time', true) ?: 0;

            if (!isset($schedules[$schedule])) {
                continue;
            }

            $interval = $schedules[$schedule]['interval'];

            if (time() > ($last_run + $interval)) {
                try {
                    $this->run_extraction($extraction->ID, false);
                    $executed++;
                } catch (Exception $e) {
                    error_log('BME Scheduled extraction failed for ID ' . $extraction->ID . ': ' . $e->getMessage());
                }
            }
        }

        return $executed;
    }

    /**
     * Test extraction configuration without running full extraction
     */
    public function test_extraction_config($extraction_id) {
        try {
            // Validate API credentials
            $this->api_client->validate_credentials();

            // Get extraction configuration
            $config = $this->get_extraction_config($extraction_id, false);

            // Build filter query
            $filter_query = $this->api_client->build_filter_query($config);

            // Test with a small sample
            $test_config = $config;
            $test_config['limit'] = 5;

            // This would be a modified version of fetch_listings that returns just a sample
            // For now, we'll just validate the configuration and filter

            return [
                'success' => true,
                'config' => $config,
                'filter_query' => $filter_query,
                'message' => 'Configuration is valid and ready for extraction'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get recent extraction logs
     */
    public function get_recent_logs($extraction_id = null, $limit = 20) {
        global $wpdb;

        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');

        $sql = "SELECT * FROM {$table}";
        $params = [];

        if ($extraction_id) {
            $sql .= " WHERE extraction_id = %d";
            $params[] = $extraction_id;
        }

        $sql .= " ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    /**
     * Clear old extraction logs
     */
    public function cleanup_old_logs($days_to_keep = 30) {
        global $wpdb;

        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');

        $cutoff_date = date('Y-m-d H:i:s', time() - ($days_to_keep * DAY_IN_SECONDS));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff_date
        ));

        return $deleted;
    }

    /**
     * Get system performance metrics
     */
    public function get_performance_metrics() {
        global $wpdb;

        $db_manager = bme_pro()->get('db');
        $logs_table = $db_manager->get_table('extraction_logs');

        // Get metrics for the last 24 hours
        $metrics = $wpdb->get_row("
            SELECT
                COUNT(*) as total_runs,
                SUM(listings_processed) as total_listings,
                AVG(duration_seconds) as avg_duration,
                AVG(memory_peak_mb) as avg_memory,
                SUM(api_requests_count) as total_api_requests,
                SUM(CASE WHEN status = 'Success' THEN 1 ELSE 0 END) as successful_runs
            FROM {$logs_table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ", ARRAY_A);

        // Calculate success rate
        $metrics['success_rate'] = $metrics['total_runs'] > 0
            ? round(($metrics['successful_runs'] / $metrics['total_runs']) * 100, 2)
            : 0;

        // Get database statistics
        $db_stats = $db_manager->get_stats();

        return [
            'extraction_metrics' => $metrics,
            'database_stats' => $db_stats
        ];
    }
}