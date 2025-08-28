<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced property history tracking with comprehensive event capture
 * Version: 1.0.0
 */
class BME_Property_History_Tracker {
    
    private $db_manager;
    private $history_table;
    
    public function __construct($db_manager) {
        $this->db_manager = $db_manager;
        $this->history_table = $this->db_manager->get_table('property_history');
    }
    
    /**
     * Track all property changes comprehensively
     */
    public function track_property_changes($listing_id, $new_data, $existing_data, $extraction_id) {
        global $wpdb;
        
        // Get address and normalize it
        $unparsed_address = $this->get_property_address($new_data, $existing_data);
        $normalized_address = BME_Address_Normalizer::normalize($unparsed_address);
        
        // Get additional property details for context
        $living_area = $new_data['living_area'] ?? $existing_data['living_area'] ?? 0;
        $agent_name = $this->get_agent_name($new_data['list_agent_mls_id'] ?? null);
        $office_name = $this->get_office_name($new_data['list_office_mls_id'] ?? null);
        
        // 1. Track Price Changes with more detail
        if ($this->has_changed($new_data, $existing_data, 'list_price')) {
            $price_per_sqft = $living_area > 0 ? round($new_data['list_price'] / $living_area, 2) : null;
            $price_change_percent = $existing_data['list_price'] > 0 
                ? round((($new_data['list_price'] - $existing_data['list_price']) / $existing_data['list_price']) * 100, 2)
                : 0;
            
            $additional_data = json_encode([
                'price_change_amount' => $new_data['list_price'] - $existing_data['list_price'],
                'price_change_percent' => $price_change_percent,
                'living_area' => $living_area
            ]);
            
            $wpdb->insert($this->history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'normalized_address' => $normalized_address,
                'event_type' => 'price_change',
                'event_date' => $new_data['modification_timestamp'] ?? current_time('mysql'),
                'field_name' => 'list_price',
                'old_value' => $existing_data['list_price'],
                'new_value' => $new_data['list_price'],
                'old_price' => $existing_data['list_price'],
                'new_price' => $new_data['list_price'],
                'price_per_sqft' => $price_per_sqft,
                'days_on_market' => $new_data['days_on_market'] ?? null,
                'agent_name' => $agent_name,
                'office_name' => $office_name,
                'additional_data' => $additional_data,
                'extraction_log_id' => $extraction_id
            ]);
        }
        
        // 2. Track Status Changes with timestamps
        if ($this->has_changed($new_data, $existing_data, 'standard_status')) {
            $event_date = $new_data['status_change_timestamp'] ?? $new_data['modification_timestamp'] ?? current_time('mysql');
            
            // Special handling for different status changes
            $event_type = 'status_change';
            if ($new_data['standard_status'] === 'Pending') {
                $event_type = 'pending';
                $event_date = $new_data['purchase_contract_date'] ?? $event_date;
            } elseif ($new_data['standard_status'] === 'Closed') {
                $event_type = 'sold';
                $event_date = $new_data['close_date'] ?? $event_date;
            } elseif (in_array($new_data['standard_status'], ['Expired', 'Withdrawn', 'Canceled'])) {
                $event_type = 'off_market';
                $event_date = $new_data['off_market_date'] ?? $event_date;
            }
            
            $wpdb->insert($this->history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'normalized_address' => $normalized_address,
                'event_type' => $event_type,
                'event_date' => $event_date,
                'field_name' => 'standard_status',
                'old_value' => $existing_data['standard_status'],
                'new_value' => $new_data['standard_status'],
                'old_status' => $existing_data['standard_status'],
                'new_status' => $new_data['standard_status'],
                'days_on_market' => $new_data['days_on_market'] ?? null,
                'agent_name' => $agent_name,
                'office_name' => $office_name,
                'extraction_log_id' => $extraction_id
            ]);
        }
        
        // 3. Track Contingency Changes
        if ($this->has_changed($new_data, $existing_data, 'contingency')) {
            $wpdb->insert($this->history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'normalized_address' => $normalized_address,
                'event_type' => 'contingency_change',
                'event_date' => $new_data['modification_timestamp'] ?? current_time('mysql'),
                'field_name' => 'contingency',
                'old_value' => $existing_data['contingency'],
                'new_value' => $new_data['contingency'],
                'agent_name' => $agent_name,
                'office_name' => $office_name,
                'extraction_log_id' => $extraction_id
            ]);
        }
        
        // 4. Track Agent Changes
        if ($this->has_changed($new_data, $existing_data, 'list_agent_mls_id')) {
            $old_agent = $this->get_agent_name($existing_data['list_agent_mls_id']);
            $new_agent = $this->get_agent_name($new_data['list_agent_mls_id']);
            
            $wpdb->insert($this->history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'normalized_address' => $normalized_address,
                'event_type' => 'agent_change',
                'event_date' => $new_data['modification_timestamp'] ?? current_time('mysql'),
                'field_name' => 'list_agent',
                'old_value' => $old_agent,
                'new_value' => $new_agent,
                'agent_name' => $new_agent,
                'office_name' => $office_name,
                'extraction_log_id' => $extraction_id
            ]);
        }
        
        // 5. Track Commission Changes
        if ($this->has_changed($new_data, $existing_data, 'buyer_agency_compensation')) {
            $wpdb->insert($this->history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'normalized_address' => $normalized_address,
                'event_type' => 'commission_change',
                'event_date' => $new_data['modification_timestamp'] ?? current_time('mysql'),
                'field_name' => 'buyer_agency_compensation',
                'old_value' => $existing_data['buyer_agency_compensation'],
                'new_value' => $new_data['buyer_agency_compensation'],
                'agent_name' => $agent_name,
                'office_name' => $office_name,
                'extraction_log_id' => $extraction_id
            ]);
        }
        
        // 6. Track Property Detail Changes
        $detail_fields = [
            'bedrooms_total' => 'Bedrooms',
            'bathrooms_full' => 'Full Bathrooms',
            'bathrooms_half' => 'Half Bathrooms',
            'living_area' => 'Square Footage',
            'lot_size_acres' => 'Lot Size',
            'property_sub_type' => 'Property Type'
        ];
        
        foreach ($detail_fields as $field => $label) {
            if ($this->has_changed($new_data, $existing_data, $field)) {
                $wpdb->insert($this->history_table, [
                    'listing_id' => $listing_id,
                    'unparsed_address' => $unparsed_address,
                    'normalized_address' => $normalized_address,
                    'event_type' => 'property_detail_change',
                    'event_date' => $new_data['modification_timestamp'] ?? current_time('mysql'),
                    'field_name' => $field,
                    'old_value' => $existing_data[$field],
                    'new_value' => $new_data[$field],
                    'agent_name' => $agent_name,
                    'office_name' => $office_name,
                    'additional_data' => json_encode(['label' => $label]),
                    'extraction_log_id' => $extraction_id
                ]);
            }
        }
        
        // 7. Track Showing Instructions Changes
        if ($this->has_changed($new_data, $existing_data, 'showing_instructions')) {
            $wpdb->insert($this->history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'normalized_address' => $normalized_address,
                'event_type' => 'showing_update',
                'event_date' => $new_data['modification_timestamp'] ?? current_time('mysql'),
                'field_name' => 'showing_instructions',
                'old_value' => substr($existing_data['showing_instructions'] ?? '', 0, 100) . '...',
                'new_value' => substr($new_data['showing_instructions'] ?? '', 0, 100) . '...',
                'agent_name' => $agent_name,
                'office_name' => $office_name,
                'extraction_log_id' => $extraction_id
            ]);
        }
    }
    
    /**
     * Track new listing with comprehensive initial data
     */
    public function track_new_listing($listing_id, $data, $extraction_id) {
        global $wpdb;
        
        // Get address and normalize it
        $unparsed_address = $data['unparsed_address'] ?? '';
        $normalized_address = BME_Address_Normalizer::normalize($unparsed_address);
        
        // Determine the actual event date
        $event_date = $this->determine_listing_date($data);
        
        // Get additional context
        $living_area = $data['living_area'] ?? 0;
        $price_per_sqft = $living_area > 0 ? round($data['list_price'] / $living_area, 2) : null;
        $agent_name = $this->get_agent_name($data['list_agent_mls_id'] ?? null);
        $office_name = $this->get_office_name($data['list_office_mls_id'] ?? null);
        
        // Track the initial listing
        $wpdb->insert($this->history_table, [
            'listing_id' => $listing_id,
            'unparsed_address' => $unparsed_address,
            'normalized_address' => $normalized_address,
            'event_type' => 'new_listing',
            'event_date' => $event_date,
            'field_name' => 'initial_listing',
            'new_value' => $data['list_price'] ?? 0,
            'new_price' => $data['list_price'] ?? 0,
            'new_status' => $data['standard_status'] ?? 'Active',
            'price_per_sqft' => $price_per_sqft,
            'days_on_market' => 0,
            'agent_name' => $agent_name,
            'office_name' => $office_name,
            'additional_data' => json_encode([
                'property_type' => $data['property_type'] ?? '',
                'property_sub_type' => $data['property_sub_type'] ?? '',
                'bedrooms' => $data['bedrooms_total'] ?? '',
                'bathrooms' => $data['bathrooms_full'] ?? '',
                'living_area' => $living_area
            ]),
            'extraction_log_id' => $extraction_id
        ]);
        
        // If this is a historical listing that's already sold, track that too
        if (!empty($data['close_date']) && $data['standard_status'] === 'Closed') {
            $wpdb->insert($this->history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'normalized_address' => $normalized_address,
                'event_type' => 'sold',
                'event_date' => $data['close_date'],
                'field_name' => 'close_price',
                'new_value' => $data['close_price'] ?? $data['list_price'],
                'new_price' => $data['close_price'] ?? $data['list_price'],
                'new_status' => 'Closed',
                'days_on_market' => $data['days_on_market'] ?? $data['mlspin_market_time_property'] ?? null,
                'agent_name' => $agent_name,
                'office_name' => $office_name,
                'extraction_log_id' => $extraction_id
            ]);
        }
    }
    
    /**
     * Helper: Check if a field has changed
     */
    private function has_changed($new_data, $existing_data, $field) {
        return isset($new_data[$field]) && 
               isset($existing_data[$field]) && 
               $new_data[$field] != $existing_data[$field];
    }
    
    /**
     * Helper: Get property address
     */
    private function get_property_address($new_data, $existing_data) {
        $unparsed_address = $new_data['unparsed_address'] ?? $existing_data['unparsed_address'] ?? '';
        
        if (empty($unparsed_address) && !empty($new_data['id'])) {
            global $wpdb;
            $location_table = $this->db_manager->get_table('listing_location');
            $unparsed_address = $wpdb->get_var($wpdb->prepare(
                "SELECT unparsed_address FROM {$location_table} WHERE listing_id = %d",
                $new_data['id']
            ));
        }
        
        return $unparsed_address;
    }
    
    /**
     * Helper: Determine the actual listing date
     */
    private function determine_listing_date($data) {
        // Priority: creation_timestamp > original_entry_timestamp > listing_contract_date > current time
        if (!empty($data['creation_timestamp'])) {
            return $data['creation_timestamp'];
        } elseif (!empty($data['original_entry_timestamp'])) {
            return $data['original_entry_timestamp'];
        } elseif (!empty($data['listing_contract_date'])) {
            return $data['listing_contract_date'] . ' 00:00:00';
        } else {
            return current_time('mysql');
        }
    }
    
    /**
     * Helper: Get agent name from MLS ID
     */
    private function get_agent_name($agent_mls_id) {
        if (empty($agent_mls_id)) return null;
        
        global $wpdb;
        $agents_table = $this->db_manager->get_table('agents');
        return $wpdb->get_var($wpdb->prepare(
            "SELECT agent_full_name FROM {$agents_table} WHERE agent_mls_id = %s",
            $agent_mls_id
        ));
    }
    
    /**
     * Helper: Get office name from MLS ID
     */
    private function get_office_name($office_mls_id) {
        if (empty($office_mls_id)) return null;
        
        global $wpdb;
        $offices_table = $this->db_manager->get_table('offices');
        return $wpdb->get_var($wpdb->prepare(
            "SELECT office_name FROM {$offices_table} WHERE office_mls_id = %s",
            $office_mls_id
        ));
    }
}