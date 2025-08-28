<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Advanced listings list table with enhanced filtering and performance
 * Version: 2.1.1 (Updated for new DB Schema and Sorting Fix)
 */
class BME_Advanced_Listings_List_Table extends WP_List_Table {
    
    private $plugin;
    private $data_processor;
    
    public function __construct($plugin) {
        $this->plugin = $plugin;
        $this->data_processor = $plugin->get('processor');
        
        parent::__construct([
            'singular' => __('Listing', 'bridge-mls-extractor-pro'),
            'plural'   => __('Listings', 'bridge-mls-extractor-pro'),
            'ajax'     => false // Ajax is disabled to work better with POST filtering
        ]);
    }
    
    /**
     * Define the columns that are going to be used in the table
     * @return array an associative array of columns
     */
    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'listing_id' => __('MLS #', 'bridge-mls-extractor-pro'),
            'address' => __('Address', 'bridge-mls-extractor-pro'),
            'standard_status' => __('Status', 'bridge-mls-extractor-pro'),
            'property_type' => __('Type', 'bridge-mls-extractor-pro'),
            'list_price' => __('List Price', 'bridge-mls-extractor-pro'),
            'close_price' => __('Close Price', 'bridge-mls-extractor-pro'),
            'bedrooms_total' => __('Beds', 'bridge-mls-extractor-pro'),
            'bathrooms_total_integer' => __('Baths', 'bridge-mls-extractor-pro'),
            'living_area' => __('Sq Ft', 'bridge-mls-extractor-pro'),
            'mlspin_market_time_property' => __('DOM', 'bridge-mls-extractor-pro'),
            'modification_timestamp' => __('Updated', 'bridge-mls-extractor-pro')
        ];
    }

    /**
     * Render the checkbox column
     */
    function column_cb($item) {
        // Ensure item ID exists before creating checkbox. The 'id' column from the main listings table is used.
        $listing_id = $item['id'] ?? null;
        if (!$listing_id) {
            return '';
        }
        return sprintf(
            '<input type="checkbox" name="bme_listings[]" value="%s" />',
            esc_attr($listing_id)
        );
    }
    
    /**
     * Define the sortable columns.
     * The keys are the column slugs in the list table.
     * The values are the actual column names to pass to the data processor for ordering.
     * The data processor will handle mapping these to the correct database table aliases.
     * @return array
     */
    public function get_sortable_columns() {
        return [
            'listing_id' => ['listing_id', false], // 'listing_id' is the column name in the listings table
            'standard_status' => ['standard_status', false],
            'property_type' => ['property_type', false],
            'list_price' => ['list_price', true], // default ascending
            'close_price' => ['close_price', true],
            'bedrooms_total' => ['bedrooms_total', false], // This column is from listing_details
            'bathrooms_total_integer' => ['bathrooms_total_integer', false], // This column is from listing_details
            'living_area' => ['living_area', true], // This column is from listing_details
            'mlspin_market_time_property' => ['mlspin_market_time_property', true], // This column is from listing_details
            'modification_timestamp' => ['modification_timestamp', true]
        ];
    }

    /**
     * Define the bulk actions
     * @return array
     */
    public function get_bulk_actions() {
        return [
            'export_selected' => __('Export Selected', 'bridge-mls-extractor-pro'),
        ];
    }
    
    /**
     * Prepare the items for the table to process
     */
    public function prepare_items() {
        if (!empty($_REQUEST['bme_filter_nonce'])) {
            if (!wp_verify_nonce($_REQUEST['bme_filter_nonce'], 'bme_database_browser_filter')) {
                wp_die(esc_html__('Security check failed. Please refresh the page and try again.', 'bridge-mls-extractor-pro'));
            }
        }

        $per_page = 25;
        $current_page = $this->get_pagenum();
        
        $filters = $this->get_filters_from_request();
        
        // Sanitize orderby and order parameters
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'modification_timestamp';
        $order = !empty($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC']) ? strtoupper($_REQUEST['order']) : 'DESC';

        $total_items = $this->data_processor->get_search_count($filters);
        $offset = ($current_page - 1) * $per_page;
        
        $this->items = $this->data_processor->search_listings($filters, $per_page, $offset, $orderby, $order);
        
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
        
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            'listing_id' // Primary column
        ];
    }
    
    /**
     * Get filters from request (works for GET and POST)
     */
    public function get_filters_from_request() {
        $filters = [];
        
        $filters['dataset'] = isset($_REQUEST['filter_dataset']) && in_array($_REQUEST['filter_dataset'], ['active', 'closed', 'all']) 
            ? sanitize_key($_REQUEST['filter_dataset']) 
            : 'active';

        $filter_fields = [
            'standard_status', 'property_type', 'city', 'listing_id',
            'price_min', 'price_max', 'bedrooms_min', 'bathrooms_min',
            'year_built_min', 'year_built_max', 'days_on_market_max'
        ];
        
        foreach ($filter_fields as $field) {
            $request_key = 'filter_' . $field;
            if (isset($_REQUEST[$request_key]) && $_REQUEST[$request_key] !== '') {
                $filters[$field] = sanitize_text_field(wp_unslash($_REQUEST[$request_key]));
            }
        }

        if (isset($_REQUEST['s']) && !empty($_REQUEST['s'])) {
            $filters['search_query'] = sanitize_text_field(wp_unslash($_REQUEST['s']));
        }
        
        return $filters;
    }
    
    /**
     * Default column display
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'listing_id':
                $actions = [
                    'view' => sprintf('<a href="#" class="bme-view-details" data-listing-id="%d">View Details</a>', $item['id'])
                ];
                return '<strong>' . esc_html($item[$column_name] ?? '') . '</strong>' . $this->row_actions($actions);
                
            case 'address':
                // Use unparsed_address from listing_location table
                return esc_html($item['unparsed_address'] ?? 'N/A');
                
            case 'standard_status':
                $status = $item[$column_name] ?? '';
                $class = $this->get_status_class($status);
                return "<span class='bme-status {$class}'>" . esc_html($status) . "</span>";
                
            case 'list_price':
            case 'close_price':
                $price = floatval($item[$column_name] ?? 0);
                return $price > 0 ? '$' . number_format($price) : '—';
                
            case 'living_area':
                $area = floatval($item[$column_name] ?? 0);
                return $area > 0 ? number_format($area) : '—';
                
            case 'mlspin_market_time_property':
                return isset($item[$column_name]) ? esc_html($item[$column_name]) : '—';

            case 'modification_timestamp':
                $timestamp = $item[$column_name] ?? '';
                if ($timestamp) {
                    return '<abbr title="' . esc_attr($timestamp) . '">' . 
                           esc_html(human_time_diff(strtotime($timestamp))) . ' ago</abbr>';
                }
                return '—';
            
            case 'bedrooms_total':
            case 'bathrooms_total_integer':
                // These columns are directly selected from ld in search_listings
                return isset($item[$column_name]) && $item[$column_name] !== null ? esc_html($item[$column_name]) : '—';
                
            default:
                return esc_html($item[$column_name] ?? '—');
        }
    }
    
    /**
     * Get CSS class for status
     */
    private function get_status_class($status) {
        $status_slug = strtolower(str_replace(' ', '-', $status ?? ''));
        return 'status-' . sanitize_html_class($status_slug, 'unknown');
    }
    
    /**
     * Display when no items found
     */
    public function no_items() {
        _e('No listings found matching your criteria.', 'bridge-mls-extractor-pro');
    }
}
