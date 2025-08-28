<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * High-performance API client with robust, sequential fetching for related data.
 * Version: 2.1.2 (Refined Filter Logic for Closed Listings)
 */
class BME_API_Client {

    private $api_token;
    private $base_url;
    private $timeout;
    private $rate_limit_delay = 1; // seconds

    public function __construct() {
        $credentials = get_option('bme_pro_api_credentials', []);
        $this->api_token = $credentials['server_token'] ?? null;
        $this->base_url = $credentials['endpoint_url'] ?? null;
        $this->timeout = BME_API_TIMEOUT;
    }

    /**
     * Validate API credentials
     */
    public function validate_credentials() {
        if (empty($this->api_token) || empty($this->base_url)) {
            throw new Exception('API credentials not configured');
        }

        $test_url = add_query_arg(['access_token' => $this->api_token, '$top' => 1], $this->base_url);

        $response = wp_remote_get($test_url, ['timeout' => 30, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response)) {
            throw new Exception('API connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new Exception('API authentication failed. Response code: ' . $code);
        }

        return true;
    }

    /**
     * Fetch listings with pagination and filtering.
     */
    public function fetch_listings($filters, $callback = null) {
        $this->validate_credentials();

        $query_args = [
            'access_token' => $this->api_token,
            '$filter' => $filters,
            '$top' => BME_BATCH_SIZE,
            '$orderby' => 'ModificationTimestamp asc' // Always order by ModificationTimestamp
        ];

        $next_url = add_query_arg($query_args, $this->base_url);
        $total_processed = 0;

        do {
            $start_time = microtime(true);

            $response = $this->make_request($next_url);
            $data = $this->parse_response($response);

            if (!empty($data['value'])) {
                $batch_count = count($data['value']);
                $total_processed += $batch_count;

                if ($callback && is_callable($callback)) {
                    $callback($data['value'], $total_processed);
                }

                $duration = microtime(true) - $start_time;
                $this->log_performance_metric('listings_batch', $batch_count, $duration);
            }

            $next_url = $data['@odata.nextLink'] ?? null;

            if ($next_url) {
                sleep($this->rate_limit_delay);
            }

        } while ($next_url);

        return $total_processed;
    }

    /**
     * Fetch related data by making sequential, chunked requests to prevent URL length errors.
     */
    public function fetch_related_data($agents_ids, $offices_ids, $listing_keys) {
        $results = [
            'agents' => $this->fetch_resource_in_chunks('Member', 'MemberMlsId', $agents_ids),
            'offices' => $this->fetch_resource_in_chunks('Office', 'OfficeMlsId', $offices_ids),
            'open_houses' => $this->fetch_resource_in_chunks('OpenHouse', 'ListingKey', $listing_keys, true),
        ];
        return $results;
    }

    /**
     * Fetches data for a specific resource type in chunks to avoid overly long URLs.
     */
    private function fetch_resource_in_chunks($resource, $key_field, $ids, $group_results = false) {
        if (empty($ids)) {
            return [];
        }

        $resource_url = str_replace('/Property', '/' . $resource, $this->base_url);
        $results_map = [];
        $id_chunks = array_chunk(array_unique($ids), 50); // Process 50 IDs at a time

        foreach ($id_chunks as $chunk) {
            $filter_values = "'" . implode("','", array_map('esc_sql', $chunk)) . "'";
            $filter_string = "{$key_field} in ({$filter_values})";

            $query_args = [
                'access_token' => $this->api_token,
                '$filter'      => $filter_string,
                '$top'         => 200 // Get up to 200 results per chunk request
            ];

            $request_url = add_query_arg($query_args, $resource_url);

            try {
                $response = $this->make_request($request_url);
                $data = $this->parse_response($response);

                if (!empty($data['value'])) {
                    foreach ($data['value'] as $item) {
                        if (isset($item[$key_field])) {
                            $key = $item[$key_field];
                            if ($group_results) {
                                if (!isset($results_map[$key])) {
                                    $results_map[$key] = [];
                                }
                                $results_map[$key][] = $item;
                            } else {
                                $results_map[$key] = $item;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("BME API Error fetching {$resource}: " . $e->getMessage() . " URL: " . $request_url);
                continue;
            }
        }
        return $results_map;
    }

    /**
     * Make a single HTTP request
     */
    private function make_request($url) {
        $response = wp_remote_get($url, ['timeout' => $this->timeout, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new Exception("API request failed with code {$code}");
        }

        return $response;
    }

    /**
     * Parse API response
     */
    private function parse_response($response) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }

        if (isset($data['error'])) {
            throw new Exception('API Error: ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    /**
     * Build OData filter query
     */
    public function build_filter_query($extraction_config) {
        $filters = [];

        if (!empty($extraction_config['statuses'])) {
            $status_filters = array_map(fn($status) => "StandardStatus eq '{$status}'", $extraction_config['statuses']);
            $filters[] = count($status_filters) > 1 ? '(' . implode(' or ', $status_filters) . ')' : $status_filters[0];
        } else {
            throw new Exception('No statuses selected for extraction.');
        }

        if (!empty($extraction_config['cities'])) {
            $cities_raw = $extraction_config['cities'];
            if (is_string($cities_raw) && !empty(trim($cities_raw))) {
                $cities = array_filter(array_map('trim', explode(',', $cities_raw)));
                if (!empty($cities)) {
                    $city_filters = array_map(fn($city) => "City eq '" . str_replace("'", "''", $city) . "'", $cities);
                    $filters[] = count($city_filters) > 1 ? '(' . implode(' or ', $city_filters) . ')' : $city_filters[0];
                }
            }
        }

        if (!empty($extraction_config['states'])) {
            $state_filters = array_map(fn($state) => "StateOrProvince eq '{$state}'", $extraction_config['states']);
            $filters[] = count($state_filters) > 1 ? '(' . implode(' or ', $state_filters) . ')' : $state_filters[0];
        }

        if (!empty($extraction_config['list_agent_id'])) {
            $filters[] = "toupper(ListAgentMlsId) eq '" . strtoupper($extraction_config['list_agent_id']) . "'";
        }

        if (!empty($extraction_config['buyer_agent_id'])) {
            $filters[] = "toupper(BuyerAgentMlsId) eq '" . strtoupper($extraction_config['buyer_agent_id']) . "'";
        }

        // Determine if any selected status is in the archived group
        $archived_group_api_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled']; // These are the statuses that indicate an archived state
        $selected_archived_statuses = array_intersect($extraction_config['statuses'], $archived_group_api_statuses);
        $is_archived_extraction = !empty($selected_archived_statuses);

        if ($is_archived_extraction) {
            if (!empty($extraction_config['closed_lookback_months'])) { // Use closed_lookback_months from config
                $months = absint($extraction_config['closed_lookback_months']);
                $date = new DateTime('now', new DateTimeZone('UTC'));
                $date->modify("-{$months} months");
                $iso_date = $date->format('Y-m-d\TH:i:s\Z');
                // For archived listings, filter by CloseDate or StatusChangeTimestamp
                // Assuming CloseDate is more reliable for filtering historical sales
                $filters[] = "CloseDate ge {$iso_date}";
            } else {
                throw new Exception('Lookback period is required for archived extractions.');
            }
        } elseif (!$extraction_config['is_resync']) {
            // For active listings, only get records modified since the last run, unless it's a full resync
            $last_modified = $extraction_config['last_modified'] ?? '1970-01-01T00:00:00Z';
            $filters[] = "ModificationTimestamp gt {$last_modified}";
        }

        $final_filter_query = implode(' and ', $filters);

        // Log the final filter query for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BME API Client: Final OData Filter Query: ' . $final_filter_query);
        }

        return $final_filter_query;
    }

    /**
     * Log performance metrics
     */
    private function log_performance_metric($operation, $count, $duration) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('BME API Performance - %s: %d items in %.3f seconds (%.2f items/sec)', $operation, $count, $duration, $count / max($duration, 0.001)));
        }
    }
}