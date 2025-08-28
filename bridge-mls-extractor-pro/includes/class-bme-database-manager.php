<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimized database manager with dbDelta-compliant schema and table archiving.
 * Version: 2.2.3 (Added Virtual Tours Table)
 */
class BME_Database_Manager {

    private $wpdb;
    private $charset_collate;
    private $tables = [];

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->charset_collate = $this->wpdb->get_charset_collate();
        $this->init_table_names();
    }

    /**
     * Initialize table names, including archive tables and new media/rooms tables.
     */
    private function init_table_names() {
        $this->tables = [
            // Active tables
            'listings' => $this->wpdb->prefix . 'bme_listings',
            'listing_details' => $this->wpdb->prefix . 'bme_listing_details',
            'listing_location' => $this->wpdb->prefix . 'bme_listing_location',
            'listing_financial' => $this->wpdb->prefix . 'bme_listing_financial',
            'listing_features' => $this->wpdb->prefix . 'bme_listing_features',

            // Archive (Closed/Off-market) tables
            'listings_archive' => $this->wpdb->prefix . 'bme_listings_archive',
            'listing_details_archive' => $this->wpdb->prefix . 'bme_listing_details_archive',
            'listing_location_archive' => $this->wpdb->prefix . 'bme_listing_location_archive',
            'listing_financial_archive' => $this->wpdb->prefix . 'bme_listing_financial_archive',
            'listing_features_archive' => $this->wpdb->prefix . 'bme_listing_features_archive',

            // Shared & New tables
            'agents' => $this->wpdb->prefix . 'bme_agents',
            'offices' => $this->wpdb->prefix . 'bme_offices',
            'open_houses' => $this->wpdb->prefix . 'bme_open_houses',
            'extraction_logs' => $this->wpdb->prefix . 'bme_extraction_logs',
            'media' => $this->wpdb->prefix . 'bme_media',
            'rooms' => $this->wpdb->prefix . 'bme_rooms',
            'virtual_tours' => $this->wpdb->prefix . 'bme_virtual_tours', // New: Virtual Tours table
            'property_history' => $this->wpdb->prefix . 'bme_property_history', // New: Property history tracking
        ];
    }

    /**
     * Create all database tables atomically.
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Create Active Tables
        $this->create_listings_table($this->tables['listings']);
        $this->create_listing_details_table($this->tables['listing_details']);
        $this->create_listing_location_table($this->tables['listing_location']);
        $this->create_listing_financial_table($this->tables['listing_financial']);
        $this->create_listing_features_table($this->tables['listing_features']);

        // Create Archive Tables
        $this->create_listings_table($this->tables['listings_archive']);
        $this->create_listing_details_table($this->tables['listing_details_archive']);
        $this->create_listing_location_table($this->tables['listing_location_archive']);
        $this->create_listing_financial_table($this->tables['listing_financial_archive']);
        $this->create_listing_features_table($this->tables['listing_features_archive']);

        // Create Shared & New Tables
        $this->create_agents_table();
        $this->create_offices_table();
        $this->create_open_houses_table();
        $this->create_extraction_logs_table();
        $this->create_media_table();
        $this->create_rooms_table();
        $this->create_virtual_tours_table(); // New: Create Virtual Tours table
    }

    /**
     * Core listings table - dbDelta compliant. Used for both active and archive.
     */
    private function create_listings_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            extraction_id BIGINT(20) UNSIGNED NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            modification_timestamp DATETIME,
            creation_timestamp DATETIME,
            status_change_timestamp DATETIME,
            close_date DATETIME,
            purchase_contract_date DATETIME,
            listing_contract_date DATE,
            original_entry_timestamp DATETIME,
            off_market_date DATETIME,
            standard_status VARCHAR(50),
            mls_status VARCHAR(50),
            property_type VARCHAR(50),
            property_sub_type VARCHAR(50),
            business_type VARCHAR(100),
            list_price DECIMAL(20,2),
            original_list_price DECIMAL(20,2),
            close_price DECIMAL(20,2),
            public_remarks LONGTEXT,
            private_remarks LONGTEXT,
            disclosures LONGTEXT,
            showing_instructions TEXT,
            photos_count INT DEFAULT 0,
            virtual_tour_url_unbranded VARCHAR(255),
            virtual_tour_url_branded VARCHAR(255),
            list_agent_mls_id VARCHAR(50),
            buyer_agent_mls_id VARCHAR(50),
            list_office_mls_id VARCHAR(50),
            buyer_office_mls_id VARCHAR(50),
            mlspin_main_so VARCHAR(50),
            mlspin_main_lo VARCHAR(50),
            mlspin_mse VARCHAR(50),
            mlspin_mgf VARCHAR(50),
            mlspin_deqe VARCHAR(50),
            mlspin_sold_vs_rent VARCHAR(20),
            mlspin_team_member VARCHAR(255),
            private_office_remarks LONGTEXT,
            buyer_agency_compensation VARCHAR(50),
            mlspin_buyer_comp_offered BOOLEAN,
            mlspin_showings_deferral_date DATE,
            mlspin_alert_comments LONGTEXT,
            mlspin_disclosure LONGTEXT,
            mlspin_comp_based_on VARCHAR(100),
            expiration_date DATE,
            -- New fields for listings
            contingency VARCHAR(255) NULL DEFAULT NULL,
            mlspin_ant_sold_date DATE NULL DEFAULT NULL,
            mlspin_market_time_property INT NULL DEFAULT NULL,
            -- Additional new fields (Jan 2025)
            buyer_agency_compensation_type VARCHAR(50) NULL DEFAULT NULL,
            sub_agency_compensation VARCHAR(50) NULL DEFAULT NULL,
            sub_agency_compensation_type VARCHAR(50) NULL DEFAULT NULL,
            transaction_broker_compensation VARCHAR(50) NULL DEFAULT NULL,
            transaction_broker_compensation_type VARCHAR(50) NULL DEFAULT NULL,
            listing_agreement VARCHAR(100) NULL DEFAULT NULL,
            listing_service VARCHAR(100) NULL DEFAULT NULL,
            listing_terms TEXT NULL DEFAULT NULL,
            exclusions TEXT NULL DEFAULT NULL,
            possession VARCHAR(255) NULL DEFAULT NULL,
            special_licenses TEXT NULL DEFAULT NULL,
            documents_available TEXT NULL DEFAULT NULL,
            documents_count INT NULL DEFAULT NULL,
            bridge_modification_timestamp DATETIME NULL DEFAULT NULL,
            photos_change_timestamp DATETIME NULL DEFAULT NULL,
            mlspin_listing_alert TEXT NULL DEFAULT NULL,
            mlspin_apod_available BOOLEAN NULL DEFAULT NULL,
            mlspin_sub_agency_offered BOOLEAN NULL DEFAULT NULL,
            mlspin_short_sale_lender_app_reqd BOOLEAN NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_listing_key (listing_key),
            KEY idx_extraction (extraction_id),
            KEY idx_listing_id (listing_id),
            KEY idx_status (standard_status),
            KEY idx_type (property_type),
            KEY idx_price (list_price),
            KEY idx_close_date (close_date),
            KEY idx_timestamps (modification_timestamp, creation_timestamp),
            KEY idx_agents (list_agent_mls_id, buyer_agent_mls_id),
            KEY idx_offices (list_office_mls_id, buyer_office_mls_id),
            FULLTEXT KEY ft_remarks (public_remarks, private_remarks, disclosures)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Extended listing details - dbDelta compliant. Used for both active and archive.
     */
    private function create_listing_details_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            bedrooms_total INT,
            bathrooms_total_integer INT,
            bathrooms_full INT,
            bathrooms_half INT,
            living_area DECIMAL(14,2),
            above_grade_finished_area DECIMAL(14,2),
            below_grade_finished_area DECIMAL(14,2),
            living_area_units VARCHAR(20),
            building_area_total DECIMAL(14,2),
            lot_size_acres DECIMAL(20,4),
            lot_size_square_feet DECIMAL(20,2),
            lot_size_area DECIMAL(20,4),
            year_built INT,
            year_built_effective INT,
            year_built_details VARCHAR(100),
            structure_type VARCHAR(100),
            architectural_style VARCHAR(100),
            stories_total INT,
            levels LONGTEXT,
            property_attached_yn BOOLEAN,
            attached_garage_yn BOOLEAN,
            basement LONGTEXT,
            mlspin_market_time_property INT,
            property_condition VARCHAR(100),
            mlspin_complex_complete BOOLEAN,
            mlspin_unit_building VARCHAR(50),
            mlspin_color VARCHAR(50),
            home_warranty_yn BOOLEAN,
            construction_materials LONGTEXT,
            foundation_details LONGTEXT,
            foundation_area DECIMAL(14,2),
            roof LONGTEXT,
            heating LONGTEXT,
            cooling LONGTEXT,
            utilities LONGTEXT,
            sewer LONGTEXT,
            water_source LONGTEXT,
            electric LONGTEXT,
            electric_on_property_yn BOOLEAN,
            mlspin_cooling_units INT,
            mlspin_cooling_zones INT,
            mlspin_heat_zones INT,
            mlspin_heat_units INT,
            mlspin_hot_water VARCHAR(100),
            mlspin_insulation_feature VARCHAR(100),
            interior_features LONGTEXT,
            flooring LONGTEXT,
            appliances LONGTEXT,
            fireplace_features LONGTEXT,
            fireplaces_total INT,
            fireplace_yn BOOLEAN,
            rooms_total INT,
            window_features LONGTEXT,
            door_features LONGTEXT,
            laundry_features LONGTEXT,
            security_features LONGTEXT,
            garage_spaces INT,
            garage_yn BOOLEAN,
            covered_spaces INT,
            parking_total INT,
            parking_features LONGTEXT,
            carport_yn BOOLEAN,
            -- New fields for listing_details
            cooling_yn BOOLEAN NULL DEFAULT NULL,
            number_of_units_total INT NULL DEFAULT NULL,
            -- Additional new fields (Jan 2025)
            bathrooms_total_decimal DECIMAL(5,2) NULL DEFAULT NULL,
            main_level_bedrooms INT NULL DEFAULT NULL,
            main_level_bathrooms INT NULL DEFAULT NULL,
            heating_yn BOOLEAN NULL DEFAULT NULL,
            accessibility_features TEXT NULL DEFAULT NULL,
            body_type VARCHAR(100) NULL DEFAULT NULL,
            building_area_source VARCHAR(50) NULL DEFAULT NULL,
            building_area_units VARCHAR(20) NULL DEFAULT NULL,
            living_area_source VARCHAR(50) NULL DEFAULT NULL,
            year_built_source VARCHAR(50) NULL DEFAULT NULL,
            common_walls VARCHAR(255) NULL DEFAULT NULL,
            gas TEXT NULL DEFAULT NULL,
            mlspin_bedrms_1 INT NULL DEFAULT NULL,
            mlspin_bedrms_2 INT NULL DEFAULT NULL,
            mlspin_bedrms_3 INT NULL DEFAULT NULL,
            mlspin_bedrms_4 INT NULL DEFAULT NULL,
            mlspin_flrs_1 INT NULL DEFAULT NULL,
            mlspin_flrs_2 INT NULL DEFAULT NULL,
            mlspin_flrs_3 INT NULL DEFAULT NULL,
            mlspin_flrs_4 INT NULL DEFAULT NULL,
            mlspin_f_bths_1 INT NULL DEFAULT NULL,
            mlspin_f_bths_2 INT NULL DEFAULT NULL,
            mlspin_f_bths_3 INT NULL DEFAULT NULL,
            mlspin_f_bths_4 INT NULL DEFAULT NULL,
            mlspin_h_bths_1 INT NULL DEFAULT NULL,
            mlspin_h_bths_2 INT NULL DEFAULT NULL,
            mlspin_h_bths_3 INT NULL DEFAULT NULL,
            mlspin_h_bths_4 INT NULL DEFAULT NULL,
            mlspin_levels_1 INT NULL DEFAULT NULL,
            mlspin_levels_2 INT NULL DEFAULT NULL,
            mlspin_levels_3 INT NULL DEFAULT NULL,
            mlspin_levels_4 INT NULL DEFAULT NULL,
            mlspin_square_feet_incl_base BOOLEAN NULL DEFAULT NULL,
            mlspin_square_feet_other TEXT NULL DEFAULT NULL,
            mlspin_year_round BOOLEAN NULL DEFAULT NULL,
            PRIMARY KEY  (listing_id)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Location and geographic data - dbDelta compliant. Used for both active and archive.
     */
    private function create_listing_location_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            unparsed_address VARCHAR(255),
            normalized_address VARCHAR(255),
            street_number VARCHAR(50),
            street_dir_prefix VARCHAR(20),
            street_name VARCHAR(100),
            street_dir_suffix VARCHAR(20),
            street_number_numeric INT,
            unit_number VARCHAR(30),
            entry_level VARCHAR(100),
            entry_location VARCHAR(100),
            city VARCHAR(100),
            state_or_province VARCHAR(50),
            postal_code VARCHAR(20),
            postal_code_plus_4 VARCHAR(10),
            county_or_parish VARCHAR(100),
            country VARCHAR(5) DEFAULT 'US',
            mls_area_major VARCHAR(100),
            mls_area_minor VARCHAR(100),
            subdivision_name VARCHAR(100),
            latitude DOUBLE,
            longitude DOUBLE,
            coordinates POINT NOT NULL,
            building_name TEXT,
            elementary_school VARCHAR(100),
            middle_or_junior_school VARCHAR(100),
            high_school VARCHAR(100),
            school_district VARCHAR(100),
            PRIMARY KEY  (listing_id),
            KEY idx_city_state (city, state_or_province),
            KEY idx_postal_code (postal_code),
            KEY idx_subdivision (subdivision_name),
            KEY idx_normalized_address (normalized_address),
            SPATIAL KEY spatial_coordinates (coordinates)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Financial information - dbDelta compliant. Used for both active and archive.
     */
    private function create_listing_financial_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            tax_annual_amount DECIMAL(20,2),
            tax_year INT,
            tax_assessed_value DECIMAL(20,2),
            association_yn BOOLEAN,
            association_fee DECIMAL(20,2),
            association_fee_frequency VARCHAR(20),
            association_amenities LONGTEXT,
            association_fee_includes LONGTEXT,
            mlspin_optional_fee DECIMAL(20,2),
            mlspin_opt_fee_includes LONGTEXT,
            mlspin_reqd_own_association BOOLEAN,
            mlspin_no_units_owner_occ INT,
            mlspin_dpr_flag BOOLEAN,
            mlspin_lender_owned BOOLEAN,
            gross_income DECIMAL(20,2),
            gross_scheduled_income DECIMAL(20,2),
            net_operating_income DECIMAL(20,2),
            operating_expense DECIMAL(20,2),
            total_actual_rent DECIMAL(20,2),
            mlspin_seller_discount_pts DECIMAL(5,2),
            financial_data_source VARCHAR(50),
            current_financing VARCHAR(50),
            development_status VARCHAR(50),
            existing_lease_type VARCHAR(50),
            availability_date DATE,
            mlspin_availablenow BOOLEAN,
            lease_term VARCHAR(100),
            rent_includes TEXT,
            mlspin_sec_deposit DECIMAL(20,2),
            mlspin_deposit_reqd BOOLEAN,
            mlspin_insurance_reqd BOOLEAN,
            mlspin_last_mon_reqd BOOLEAN,
            mlspin_first_mon_reqd BOOLEAN,
            mlspin_references_reqd BOOLEAN,
            tax_map_number VARCHAR(50),
            tax_book_number VARCHAR(50),
            tax_block VARCHAR(50),
            tax_lot VARCHAR(50),
            parcel_number VARCHAR(50),
            zoning VARCHAR(50),
            zoning_description VARCHAR(100),
            mlspin_master_page VARCHAR(50),
            mlspin_master_book VARCHAR(50),
            mlspin_page VARCHAR(50),
            mlspin_sewage_district VARCHAR(50),
            water_sewer_expense DECIMAL(14,2),
            electric_expense DECIMAL(14,2),
            insurance_expense DECIMAL(14,2),
            -- New fields for listing_financial
            mlspin_list_price_per_sqft DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_price_per_sqft DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_sold_price_per_sqft DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_owner_occ_source VARCHAR(255) NULL DEFAULT NULL,
            mlspin_lead_paint BOOLEAN NULL DEFAULT NULL,
            mlspin_title5 BOOLEAN NULL DEFAULT NULL,
            mlspin_perc_test BOOLEAN NULL DEFAULT NULL,
            mlspin_perc_test_date DATE NULL DEFAULT NULL,
            mlspin_square_feet_disclosures TEXT NULL DEFAULT NULL,
            -- Additional new fields (Jan 2025)
            concessions_amount DECIMAL(20,2) NULL DEFAULT NULL,
            tenant_pays TEXT NULL DEFAULT NULL,
            mlspin_rent1 DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_rent2 DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_rent3 DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_rent4 DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_lease_1 VARCHAR(100) NULL DEFAULT NULL,
            mlspin_lease_2 VARCHAR(100) NULL DEFAULT NULL,
            mlspin_lease_3 VARCHAR(100) NULL DEFAULT NULL,
            mlspin_lease_4 VARCHAR(100) NULL DEFAULT NULL,
            mlspin_market_time_broker INT NULL DEFAULT NULL,
            mlspin_market_time_broker_prev INT NULL DEFAULT NULL,
            mlspin_market_time_property_prev INT NULL DEFAULT NULL,
            mlspin_prev_market_time INT NULL DEFAULT NULL,
            PRIMARY KEY  (listing_id),
            KEY idx_tax_year (tax_year),
            KEY idx_association (association_yn, association_fee)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Property features and amenities - dbDelta compliant. Used for both active and archive.
     */
    private function create_listing_features_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            spa_yn BOOLEAN,
            spa_features LONGTEXT,
            exterior_features LONGTEXT,
            patio_and_porch_features LONGTEXT,
            lot_features LONGTEXT,
            road_surface_type VARCHAR(50),
            road_frontage_type VARCHAR(50),
            road_responsibility VARCHAR(100),
            frontage_length DECIMAL(14,2),
            frontage_type VARCHAR(50),
            fencing VARCHAR(100),
            other_structures LONGTEXT,
            other_equipment LONGTEXT,
            pasture_area DECIMAL(14,2),
            cultivated_area DECIMAL(14,2),
            waterfront_yn BOOLEAN,
            waterfront_features LONGTEXT,
            view LONGTEXT,
            view_yn BOOLEAN,
            community_features LONGTEXT,
            mlspin_waterview_flag BOOLEAN,
            mlspin_waterview_features LONGTEXT,
            green_indoor_air_quality VARCHAR(100),
            green_energy_generation VARCHAR(100),
            horse_yn BOOLEAN,
            horse_amenities LONGTEXT,
            pool_features TEXT,
            pool_private_yn BOOLEAN,
            -- New fields for listing_features
            senior_community_yn BOOLEAN NULL DEFAULT NULL,
            mlspin_outdoor_space_available BOOLEAN NULL DEFAULT NULL,
            pets_allowed BOOLEAN NULL DEFAULT NULL,
            -- Additional new fields (Jan 2025)
            additional_parcels_yn BOOLEAN NULL DEFAULT NULL,
            green_energy_efficient TEXT NULL DEFAULT NULL,
            green_water_conservation TEXT NULL DEFAULT NULL,
            wooded_area DECIMAL(14,2) NULL DEFAULT NULL,
            number_of_lots INT NULL DEFAULT NULL,
            lot_size_units VARCHAR(20) NULL DEFAULT NULL,
            farm_land_area_units VARCHAR(20) NULL DEFAULT NULL,
            mlspin_gre VARCHAR(50) NULL DEFAULT NULL,
            mlspin_hte VARCHAR(50) NULL DEFAULT NULL,
            mlspin_rfs VARCHAR(50) NULL DEFAULT NULL,
            mlspin_rme VARCHAR(50) NULL DEFAULT NULL,
            PRIMARY KEY  (listing_id),
            KEY idx_waterfront (waterfront_yn),
            KEY idx_pool (pool_private_yn),
            KEY idx_view (view_yn),
            FULLTEXT KEY ft_all_features (exterior_features, lot_features, community_features, view)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Agents table with normalized columns for searching.
     */
    private function create_agents_table() {
        $sql = "CREATE TABLE {$this->tables['agents']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_mls_id VARCHAR(50) NOT NULL,
            agent_full_name VARCHAR(255),
            agent_first_name VARCHAR(100),
            agent_last_name VARCHAR(100),
            agent_email VARCHAR(255),
            agent_phone VARCHAR(50),
            office_mls_id VARCHAR(50),
            modification_timestamp DATETIME,
            agent_data LONGTEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_agent_mls_id (agent_mls_id),
            KEY idx_agent_name (agent_last_name, agent_first_name),
            KEY idx_office_mls_id (office_mls_id)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Offices table with normalized columns for searching.
     */
    private function create_offices_table() {
        $sql = "CREATE TABLE {$this->tables['offices']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            office_mls_id VARCHAR(50) NOT NULL,
            office_name VARCHAR(255),
            office_phone VARCHAR(50),
            office_address VARCHAR(255),
            office_city VARCHAR(100),
            office_state VARCHAR(50),
            office_postal_code VARCHAR(20),
            modification_timestamp DATETIME,
            office_data LONGTEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_office_mls_id (office_mls_id),
            KEY idx_office_name (office_name),
            KEY idx_office_city_state (office_city, office_state)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Open houses table - dbDelta compliant.
     */
    private function create_open_houses_table() {
        $sql = "CREATE TABLE {$this->tables['open_houses']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            open_house_data LONGTEXT,
            expires_at DATETIME,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_listing_id (listing_id),
            KEY idx_listing_key (listing_key),
            KEY idx_expires_at (expires_at)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Extraction logs table - dbDelta compliant.
     */
    private function create_extraction_logs_table() {
        $sql = "CREATE TABLE {$this->tables['extraction_logs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            extraction_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(50) NOT NULL,
            message TEXT,
            listings_processed INT DEFAULT 0,
            duration_seconds DECIMAL(10,3),
            memory_peak_mb DECIMAL(10,2),
            api_requests_count INT DEFAULT 0,
            error_details LONGTEXT,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_extraction_id (extraction_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Media table to store repeating media items.
     */
    private function create_media_table() {
        $sql = "CREATE TABLE {$this->tables['media']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'MLS number as BIGINT',
            listing_key VARCHAR(128) NOT NULL,
            source_table VARCHAR(20) DEFAULT 'active',
            media_key VARCHAR(128) NOT NULL,
            media_url VARCHAR(255) NOT NULL,
            media_category VARCHAR(50),
            description TEXT,
            modification_timestamp DATETIME,
            order_index INT,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_listing_media (listing_key, media_key),
            KEY idx_listing_id (listing_id),
            KEY idx_listing_key (listing_key),
            KEY idx_source_table (source_table),
            KEY idx_category (media_category)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Rooms table to store repeating room details.
     */
    private function create_rooms_table() {
        $sql = "CREATE TABLE {$this->tables['rooms']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            room_type VARCHAR(100) NOT NULL,
            room_level VARCHAR(50),
            room_dimensions VARCHAR(50),
            room_features TEXT,
            PRIMARY KEY  (id),
            KEY idx_listing_id (listing_id),
            KEY idx_room_type_level (room_type, room_level)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * New: Virtual Tours table for supplementary links.
     */
    private function create_virtual_tours_table() {
        $sql = "CREATE TABLE {$this->tables['virtual_tours']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            mls_id VARCHAR(50) NOT NULL,
            virtual_tour_link_1 VARCHAR(255) NULL DEFAULT NULL,
            virtual_tour_link_2 VARCHAR(255) NULL DEFAULT NULL,
            virtual_tour_link_3 VARCHAR(255) NULL DEFAULT NULL,
            last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_mls_id (mls_id),
            KEY idx_mls_id (mls_id)
        ) {$this->charset_collate};";

        dbDelta($sql);

        // Create property history tracking table
        $sql = "CREATE TABLE {$this->tables['property_history']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id VARCHAR(50) NOT NULL,
            unparsed_address TEXT NOT NULL,
            normalized_address VARCHAR(255),
            event_type VARCHAR(50) NOT NULL,
            event_date DATETIME NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            field_name VARCHAR(100) NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            old_price DECIMAL(15,2) NULL,
            new_price DECIMAL(15,2) NULL,
            days_on_market INT NULL,
            price_per_sqft DECIMAL(10,2) NULL,
            agent_name VARCHAR(255) NULL,
            office_name VARCHAR(255) NULL,
            additional_data TEXT NULL,
            extraction_log_id BIGINT(20) UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_listing_id (listing_id),
            KEY idx_event_date (event_date),
            KEY idx_event_type (event_type),
            KEY idx_address (unparsed_address(255)),
            KEY idx_normalized_address (normalized_address)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Verify database installation
     */
    public function verify_installation() {
        $missing_tables = [];
        foreach ($this->tables as $name => $table_name) {
            if ($this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
                $missing_tables[] = $name;
            }
        }

        if (!empty($missing_tables)) {
            // Attempt to recreate the tables automatically
            $this->create_tables();
            // Re-verify after attempting to create
            $missing_tables_after_fix = [];
            foreach ($this->tables as $name => $table_name) {
                if ($this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
                    $missing_tables_after_fix[] = $name;
                }
            }
            if (!empty($missing_tables_after_fix)) {
                throw new Exception('Missing database tables: ' . implode(', ', $missing_tables_after_fix));
            }
        }

        return true;
    }

    /**
     * Get table name
     */
    public function get_table($table) {
        if (!isset($this->tables[$table])) {
            throw new Exception("Table {$table} not found");
        }
        return $this->tables[$table];
    }

    /**
     * Get all table names
     */
    public function get_tables() {
        return $this->tables;
    }

    /**
     * Clean up expired cache entries
     */
    public function cleanup_cache() {
        $now = current_time('mysql');

        $this->wpdb->delete($this->tables['agents'], ['expires_at <' => $now], ['%s']);
        $this->wpdb->delete($this->tables['offices'], ['expires_at <' => $now], ['%s']);
    }

    /**
     * Get database statistics
     */
    public function get_stats() {
        $stats = [];
        foreach ($this->tables as $name => $table) {
            $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $stats[$name] = intval($count);
        }
        return $stats;
    }
}