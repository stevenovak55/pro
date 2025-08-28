<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles fetching, parsing, and updating virtual tour links from a supplementary file.
 * Version: 1.0.2 (Improved Error Reporting)
 */
class BME_Virtual_Tour_Importer {

    private $db_manager;
    private $virtual_tours_table;
    private $listings_table; // To check if MLS ID exists in our main listings data

    public function __construct(BME_Database_Manager $db_manager) {
        $this->db_manager = $db_manager;
        $this->virtual_tours_table = $this->db_manager->get_table('virtual_tours');
        $this->listings_table = $this->db_manager->get_table('listings'); // Use active listings table for lookup
    }

    /**
     * Imports virtual tour data from the configured URL.
     *
     * @return int The number of virtual tour entries processed (updated or inserted).
     * @throws Exception If the file URL is not configured or fetching fails.
     */
    public function import_virtual_tours() {
        global $wpdb;

        $file_url = get_option('bme_pro_vt_file_url');
        if (empty($file_url)) {
            $this->log('error', 'Virtual Tour file URL is not configured in plugin settings.');
            $this->set_admin_error_message('Virtual Tour file URL is not configured. Please set it in Settings.');
            return 0;
        }

        $this->log('info', "Attempting to fetch virtual tour file from: {$file_url}");

        try {
            $file_content = $this->fetch_file_content($file_url);
            if (empty($file_content)) {
                $this->log('warning', 'Fetched virtual tour file is empty.');
                $this->set_admin_error_message('Virtual Tour file content is empty. Check the URL and file content.');
                return 0;
            }
            $this->log('info', 'Virtual tour file fetched successfully. Parsing content...');

            $parsed_data = $this->parse_file_content($file_content);
            if (empty($parsed_data)) {
                $this->log('warning', 'Parsed virtual tour data is empty or invalid.');
                $this->set_admin_error_message('No valid data found in the virtual tour file. Check the file format.');
                return 0;
            }
            $this->log('info', sprintf('Parsed %d unique MLS IDs with virtual tour links.', count($parsed_data)));

            $processed_count = 0;
            foreach ($parsed_data as $mls_id => $tour_urls) {
                // Ensure the MLS ID exists in the main listings table (active or archive)
                $listing_exists_query = $wpdb->prepare(
                    "(SELECT COUNT(*) as count_col FROM {$this->listings_table} WHERE listing_id = %s)
                     UNION ALL
                     (SELECT COUNT(*) as count_col FROM {$this->db_manager->get_table('listings_archive')} WHERE listing_id = %s)",
                    $mls_id, $mls_id
                );
                $listing_exists = (int) $wpdb->get_var("SELECT SUM(count_col) FROM ({$listing_exists_query}) AS a");

                if ($listing_exists > 0) {
                    $this->update_virtual_tour_entry($mls_id, $tour_urls);
                    $processed_count++;
                } else {
                    $this->log('info', "Skipping virtual tour for MLS ID {$mls_id}: No corresponding listing found in main tables.");
                }
            }

            $this->log('info', sprintf('Virtual Tour import complete. %d entries processed.', $processed_count));
            // Set a success message if no prior errors occurred
            $this->set_admin_success_message(sprintf('Virtual Tour import completed successfully. %d entries processed.', $processed_count));
            return $processed_count;

        } catch (Exception $e) {
            $this->log('error', 'Failed to import virtual tours: ' . $e->getMessage());
            $this->set_admin_error_message('Virtual Tour import failed: ' . $e->getMessage());
            return 0; // Return 0 to indicate failure count
        }
    }

    /**
     * Fetches file content using wp_remote_get, mimicking a browser.
     *
     * @param string $url The URL of the file to fetch.
     * @return string The raw file content.
     * @throws Exception If the request fails or returns a non-200 status.
     */
    private function fetch_file_content($url) {
        $args = array(
            'timeout' => 30, // seconds
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.88 Safari/537.36', // Mimic a common browser
                'Accept'     => 'text/plain, text/html, application/json, */*', // Accept typical web content types
            ),
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            throw new Exception('Failed to fetch file: ' . $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new Exception("Failed to fetch file. HTTP code: {$http_code}, Message: " . wp_remote_retrieve_response_message($response));
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Parses the raw text file content into an associative array of MLS IDs and their associated tour URLs.
     * Handles multiple URLs per MLS ID.
     *
     * @param string $content The raw content of the text file.
     * @return array An array where keys are MLS IDs and values are arrays of virtual tour URLs.
     */
    private function parse_file_content($content) {
        $lines = array_filter(array_map('trim', explode("\n", $content)));
        $parsed_data = [];

        foreach ($lines as $line) {
            // Skip header line if present
            if (strpos($line, 'LIST_NO|TOUR_URL') === 0) {
                continue;
            }

            $parts = explode('|', $line, 2); // Split only on the first '|'

            if (count($parts) === 2) {
                $mls_id = sanitize_text_field(trim($parts[0]));
                $tour_url = esc_url_raw(trim($parts[1]));

                if (!empty($mls_id) && !empty($tour_url)) {
                    if (!isset($parsed_data[$mls_id])) {
                        $parsed_data[$mls_id] = [];
                    }
                    $parsed_data[$mls_id][] = $tour_url;
                }
            }
        }
        return $parsed_data;
    }

    /**
     * Updates or inserts a virtual tour entry in the database.
     * It adds new links without deleting existing ones.
     *
     * @param string $mls_id The MLS ID of the listing.
     * @param array $new_tour_urls An array of virtual tour URLs from the fetched file.
     */
    private function update_virtual_tour_entry($mls_id, $new_tour_urls) {
        global $wpdb;

        $existing_entry = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->virtual_tours_table} WHERE mls_id = %s", $mls_id),
            ARRAY_A
        );

        $update_data = [];
        $inserted_links_count = 0;

        if ($existing_entry) {
            // Collect existing valid links into a temp array
            $current_links = [];
            for ($i = 1; $i <= 3; $i++) {
                if (!empty($existing_entry["virtual_tour_link_{$i}"])) {
                    $current_links[] = $existing_entry["virtual_tour_link_{$i}"];
                }
            }

            // Iterate through new links and add if not already present in current_links
            $new_links_to_add = array_diff($new_tour_urls, $current_links);
            $next_link_idx = count($current_links) + 1; // Start filling from the next available slot

            foreach ($new_links_to_add as $link) {
                if ($next_link_idx <= 3) {
                    $update_data["virtual_tour_link_{$next_link_idx}"] = $link;
                    $inserted_links_count++;
                    $next_link_idx++;
                } else {
                    $this->log('warning', "MLS ID {$mls_id} has more than 3 virtual tour links. Skipping: {$link}");
                    break; // Stop if all 3 slots are filled
                }
            }

            if (!empty($update_data)) {
                $wpdb->update($this->virtual_tours_table, $update_data, ['mls_id' => $mls_id]);
                $this->log('info', "Updated virtual tour entry for MLS ID {$mls_id}. Added {$inserted_links_count} new link(s).");
            } else {
                $this->log('info', "No new virtual tour links to add for MLS ID {$mls_id}.");
            }

        } else {
            // New entry: Insert up to 3 links
            $insert_data = [
                'mls_id' => $mls_id,
            ];
            for ($i = 0; $i < min(count($new_tour_urls), 3); $i++) {
                $insert_data["virtual_tour_link_" . ($i + 1)] = $new_tour_urls[$i];
                $inserted_links_count++;
            }
            if ($inserted_links_count > 0) {
                $wpdb->insert($this->virtual_tours_table, $insert_data);
                $this->log('info', "Inserted new virtual tour entry for MLS ID {$mls_id}. Added {$inserted_links_count} link(s).");
            }
        }
    }

    /**
     * Sets a transient for an admin success message.
     * @param string $message The message to display.
     */
    private function set_admin_success_message($message) {
        set_transient('bme_pro_vt_import_success_message', $message, 30); // Show for 30 seconds
    }

    /**
     * Sets a transient for an admin error message.
     * @param string $message The message to display.
     */
    private function set_admin_error_message($message) {
        set_transient('bme_pro_vt_import_error_message', $message, 30); // Show for 30 seconds
    }

    /**
     * Logs messages to error log if WP_DEBUG is enabled.
     * @param string $level The log level (info, warning, error).
     * @param string $message The message to log.
     */
    private function log($level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[BME Virtual Tour Importer] [{$level}] {$message}");
        }
    }
}