<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimized data processor for normalized database operations with table archiving.
 * Version: 2.3.11 (Fixed Rooms Table Population)
 */
class BME_Data_Processor {

    private $db_manager;
    private $cache_manager;
    private $field_mapping;
    private $all_listing_columns;
    private $history_tracker;
    private $archived_statuses = ['Closed', 'Expired', 'Withdrawn', 'Pending', 'Canceled', 'Active Under Contract'];

    public function __construct(BME_Database_Manager $db_manager, BME_Cache_Manager $cache_manager) {
        $this->db_manager = $db_manager;
        $this->cache_manager = $cache_manager;
        $this->history_tracker = new BME_Property_History_Tracker($db_manager);
        $this->init_field_mapping();
        $this->init_all_listing_columns();
    }

    /**
     * Determines if a listing status belongs in the archive tables.
     * @param string $status The StandardStatus of the listing.
     * @return bool True if the status is an archived status, false otherwise.
     */
    public function is_archived_status($status) {
        return in_array($status, $this->archived_statuses);
    }

    /**
     * Initialize field mapping for all tables based on the expanded schema.
     */
    private function init_field_mapping() {
        $this->field_mapping = [
            'listings' => [
                'listing_key' => 'ListingKey', 'listing_id' => 'ListingId', 'modification_timestamp' => 'ModificationTimestamp',
                'creation_timestamp' => 'CreationTimestamp', 'status_change_timestamp' => 'StatusChangeTimestamp',
                'close_date' => 'CloseDate', 'purchase_contract_date' => 'PurchaseContractDate', 'listing_contract_date' => 'ListingContractDate',
                'original_entry_timestamp' => 'OriginalEntryTimestamp', 'off_market_date' => 'OffMarketDate',
                'standard_status' => 'StandardStatus', 'mls_status' => 'MlsStatus', 'property_type' => 'PropertyType',
                'property_sub_type' => 'PropertySubType', 'business_type' => 'BusinessType', 'list_price' => 'ListPrice',
                'original_list_price' => 'OriginalListPrice', 'close_price' => 'ClosePrice', 'public_remarks' => 'PublicRemarks',
                'private_remarks' => 'PrivateRemarks', 'disclosures' => 'Disclosures', 'showing_instructions' => 'ShowingInstructions',
                'photos_count' => 'PhotosCount', 'virtual_tour_url_unbranded' => 'VirtualTourURLUnbranded',
                'virtual_tour_url_branded' => 'VirtualTourURLBranded', 'list_agent_mls_id' => 'ListAgentMlsId',
                'buyer_agent_mls_id' => 'BuyerAgentMlsId', 'list_office_mls_id' => 'ListOfficeMlsId',
                'buyer_office_mls_id' => 'BuyerOfficeMlsId', 'mlspin_main_so' => 'MLSPIN_MAIN_SO',
                'mlspin_main_lo' => 'MLSPIN_MAIN_LO', 'mlspin_mse' => 'MLSPIN_MSE', 'mlspin_mgf' => 'MLSPIN_MGF',
                'mlspin_deqe' => 'MLSPIN_DEQE', 'mlspin_sold_vs_rent' => 'MLSPIN_SOLD_VS_RENT',
                'mlspin_team_member' => 'MLSPIN_TEAM_MEMBER', 'private_office_remarks' => 'PrivateOfficeRemarks',
                'buyer_agency_compensation' => 'BuyerAgencyCompensation', 'mlspin_buyer_comp_offered' => 'MLSPIN_BUYER_COMP_OFFERED',
                'mlspin_showings_defferal_date' => 'MLSPIN_SHOWINGS_DEFERRAL_DATE', 'mlspin_alert_comments' => 'MLSPIN_ALERT_COMMENTS',
                'mlspin_disclosure' => 'MLSPIN_DISCLOSURE', 'mlspin_comp_based_on' => 'MLSPIN_COMP_BASED_ON',
                'expiration_date' => 'ExpirationDate',
                // New fields for listings
                'contingency' => 'Contingency',
                'mlspin_ant_sold_date' => 'AnticipatedSoldDate',
                'mlspin_market_time_property' => 'MLSPIN_MARKET_TIME_PROPERTY',
                // Additional new fields (Jan 2025)
                'buyer_agency_compensation_type' => 'BuyerAgencyCompensationType',
                'sub_agency_compensation' => 'SubAgencyCompensation',
                'sub_agency_compensation_type' => 'SubAgencyCompensationType',
                'transaction_broker_compensation' => 'TransactionBrokerCompensation',
                'transaction_broker_compensation_type' => 'TransactionBrokerCompensationType',
                'listing_agreement' => 'ListingAgreement',
                'listing_service' => 'ListingService',
                'listing_terms' => 'ListingTerms',
                'exclusions' => 'Exclusions',
                'possession' => 'Possession',
                'special_licenses' => 'SpecialLicenses',
                'documents_available' => 'DocumentsAvailable',
                'documents_count' => 'DocumentsCount',
                'bridge_modification_timestamp' => 'BridgeModificationTimestamp',
                'photos_change_timestamp' => 'PhotosChangeTimestamp',
                'mlspin_listing_alert' => 'MLSPIN_LISTING_ALERT',
                'mlspin_apod_available' => 'MLSPIN_APOD_AVAILABLE',
                'mlspin_sub_agency_offered' => 'MLSPIN_SUB_AGENCY_OFFERED',
                'mlspin_short_sale_lender_app_reqd' => 'MLSPIN_SHORT_SALE_LENDER_APP_REQD'
            ],
            'listing_details' => [
                'bedrooms_total' => 'BedroomsTotal', 'bathrooms_total_integer' => 'BathroomsTotalInteger',
                'bathrooms_full' => 'BathroomsFull', 'bathrooms_half' => 'BathroomsHalf', 'living_area' => 'LivingArea',
                'above_grade_finished_area' => 'AboveGradeFinishedArea', 'below_grade_finished_area' => 'BelowGradeFinishedArea',
                'living_area_units' => 'LivingAreaUnits', 'building_area_total' => 'BuildingAreaTotal',
                'lot_size_acres' => 'LotSizeAcres', 'lot_size_square_feet' => 'LotSizeSquareFeet', 'lot_size_area' => 'LotSizeArea',
                'year_built' => 'YearBuilt', 'year_built_effective' => 'YearBuiltEffective', 'year_built_details' => 'YearBuiltDetails',
                'structure_type' => 'StructureType', 'architectural_style' => 'ArchitecturalStyle', 'stories_total' => 'StoriesTotal',
                'levels' => 'Levels', 'property_attached_yn' => 'PropertyAttachedYN', 'attached_garage_yn' => 'AttachedGarageYN',
                'basement' => 'Basement', 'mlspin_market_time_property' => 'MLSPIN_MARKET_TIME_PROPERTY',
                'property_condition' => 'PropertyCondition', 'mlspin_complex_complete' => 'MLSPIN_COMPLEX_COMPLETE',
                'mlspin_unit_building' => 'MLSPIN_UNIT_BUILDING', 'mlspin_color' => 'MLSPIN_COLOR',
                'home_warranty_yn' => 'HomeWarrantyYN', 'construction_materials' => 'ConstructionMaterials',
                'foundation_details' => 'FoundationDetails', 'foundation_area' => 'FoundationArea', 'roof' => 'Roof',
                'heating' => 'Heating', 'cooling' => 'Cooling', 'utilities' => 'Utilities', 'sewer' => 'Sewer',
                'water_source' => 'WaterSource', 'electric' => 'Electric', 'electric_on_property_yn' => 'ElectricOnPropertyYN',
                'mlspin_cooling_units' => 'MLSPIN_COOLING_UNITS', 'mlspin_cooling_zones' => 'MLSPIN_COOLING_ZONES',
                'mlspin_heat_zones' => 'MLSPIN_HEAT_ZONES', 'mlspin_heat_units' => 'MLSPIN_HEAT_UNITS',
                'mlspin_hot_water' => 'MLSPIN_HOT_WATER', 'mlspin_insulation_feature' => 'MLSPIN_INSULATION_FEATURE',
                'interior_features' => 'InteriorFeatures', 'flooring' => 'Flooring', 'appliances' => 'Appliances',
                'fireplace_features' => 'FireplaceFeatures', 'fireplaces_total' => 'FireplacesTotal', 'fireplace_yn' => 'FireplaceYN',
                'rooms_total' => 'RoomsTotal', 'window_features' => 'WindowFeatures', 'door_features' => 'DoorFeatures',
                'laundry_features' => 'LaundryFeatures', 'security_features' => 'SecurityFeatures', 'garage_spaces' => 'GarageSpaces',
                'garage_yn' => 'GarageYN', 'covered_spaces' => 'CoveredSpaces', 'parking_total' => 'ParkingTotal',
                'parking_features' => 'ParkingFeatures', 'carport_yn' => 'CarportYN',
                // New fields for listing_details
                'cooling_yn' => 'CoolingYN',
                'number_of_units_total' => 'NumberOfUnitsTotal',
                // Additional new fields (Jan 2025)
                'bathrooms_total_decimal' => 'BathroomsTotalDecimal',
                'main_level_bedrooms' => 'MainLevelBedrooms',
                'main_level_bathrooms' => 'MainLevelBathrooms',
                'heating_yn' => 'HeatingYN',
                'accessibility_features' => 'AccessibilityFeatures',
                'body_type' => 'BodyType',
                'building_area_source' => 'BuildingAreaSource',
                'building_area_units' => 'BuildingAreaUnits',
                'living_area_source' => 'LivingAreaSource',
                'year_built_source' => 'YearBuiltSource',
                'common_walls' => 'CommonWalls',
                'gas' => 'Gas',
                'mlspin_bedrms_1' => 'MLSPIN_BEDRMS_1',
                'mlspin_bedrms_2' => 'MLSPIN_BEDRMS_2',
                'mlspin_bedrms_3' => 'MLSPIN_BEDRMS_3',
                'mlspin_bedrms_4' => 'MLSPIN_BEDRMS_4',
                'mlspin_flrs_1' => 'MLSPIN_FLRS_1',
                'mlspin_flrs_2' => 'MLSPIN_FLRS_2',
                'mlspin_flrs_3' => 'MLSPIN_FLRS_3',
                'mlspin_flrs_4' => 'MLSPIN_FLRS_4',
                'mlspin_f_bths_1' => 'MLSPIN_F_BTHS_1',
                'mlspin_f_bths_2' => 'MLSPIN_F_BTHS_2',
                'mlspin_f_bths_3' => 'MLSPIN_F_BTHS_3',
                'mlspin_f_bths_4' => 'MLSPIN_F_BTHS_4',
                'mlspin_h_bths_1' => 'MLSPIN_H_BTHS_1',
                'mlspin_h_bths_2' => 'MLSPIN_H_BTHS_2',
                'mlspin_h_bths_3' => 'MLSPIN_H_BTHS_3',
                'mlspin_h_bths_4' => 'MLSPIN_H_BTHS_4',
                'mlspin_levels_1' => 'MLSPIN_LEVELS_1',
                'mlspin_levels_2' => 'MLSPIN_LEVELS_2',
                'mlspin_levels_3' => 'MLSPIN_LEVELS_3',
                'mlspin_levels_4' => 'MLSPIN_LEVELS_4',
                'mlspin_square_feet_incl_base' => 'MLSPIN_SQUARE_FEET_INCL_BASE',
                'mlspin_square_feet_other' => 'MLSPIN_SQUARE_FEET_OTHER',
                'mlspin_year_round' => 'MLSPIN_YEAR_ROUND'
            ],
            'listing_location' => [
                'unparsed_address' => 'UnparsedAddress', 'street_number' => 'StreetNumber', 'street_dir_prefix' => 'StreetDirPrefix',
                'street_name' => 'StreetName', 'street_dir_suffix' => 'StreetDirSuffix', 'street_number_numeric' => 'StreetNumberNumeric',
                'unit_number' => 'UnitNumber', 'entry_level' => 'EntryLevel', 'entry_location' => 'EntryLocation', 'city' => 'City',
                'state_or_province' => 'StateOrProvince', 'postal_code' => 'PostalCode', 'postal_code_plus_4' => 'PostalCodePlus4',
                'county_or_parish' => 'CountyOrParish', 'country' => 'Country', 'mls_area_major' => 'MLSAreaMajor',
                'mls_area_minor' => 'MLSAreaMinor', 'subdivision_name' => 'SubdivisionName', 'latitude' => 'Latitude',
                'longitude' => 'Longitude', 'building_name' => 'BuildingName', 'elementary_school' => 'ElementarySchool',
                'middle_or_junior_school' => 'MiddleOrJuniorSchool', 'high_school' => 'HighSchool', 'school_district' => 'SchoolDistrict'
            ],
            'listing_financial' => [
                'tax_annual_amount' => 'TaxAnnualAmount', 'tax_year' => 'TaxYear', 'tax_assessed_value' => 'TaxAssessedValue',
                'association_yn' => 'AssociationYN', 'association_fee' => 'AssociationFee',
                'association_fee_frequency' => 'AssociationFeeFrequency', 'association_amenities' => 'AssociationAmenities',
                'association_fee_includes' => 'AssociationFeeIncludes', 'mlspin_optional_fee' => 'MLSPIN_OPTIONAL_FEE',
                'mlspin_opt_fee_includes' => 'MLSPIN_OPT_FEE_INCLUDES', 'mlspin_reqd_own_association' => 'MLSPIN_REQD_OWN_ASSOCIATION',
                'mlspin_no_units_owner_occ' => 'MLSPIN_NO_UNITS_OWNER_OCC', 'mlspin_dpr_flag' => 'MLSPIN_DPR_Flag',
                'mlspin_lender_owned' => 'MLSPIN_LENDER_OWNED', 'gross_income' => 'GrossIncome',
                'gross_scheduled_income' => 'GrossScheduledIncome', 'net_operating_income' => 'NetOperatingIncome',
                'operating_expense' => 'OperatingExpense', 'total_actual_rent' => 'TotalActualRent',
                'mlspin_seller_discount_pts' => 'MLSPIN_SELLER_DISCOUNT_PTS', 'financial_data_source' => 'FinancialDataSource',
                'current_financing' => 'CurrentFinancing', 'development_status' => 'DevelopmentStatus',
                'existing_lease_type' => 'ExistingLeaseType', 'availability_date' => 'AvailabilityDate',
                'mlspin_availablenow' => 'MLSPIN_AvailableNow', 'lease_term' => 'LeaseTerm', 'rent_includes' => 'RentIncludes',
                'mlspin_sec_deposit' => 'MLSPIN_SEC_DEPOSIT', 'mlspin_deposit_reqd' => 'MLSPIN_DEPOSIT_REQD',
                'mlspin_insurance_reqd' => 'MLSPIN_INSURANCE_REQD', 'mlspin_last_mon_reqd' => 'MLSPIN_LAST_MON_REQD',
                'mlspin_first_mon_reqd' => 'MLSPIN_FIRST_MON_REQD', 'mlspin_references_reqd' => 'MLSPIN_REFERENCES_REQD',
                'tax_map_number' => 'TaxMapNumber', 'tax_book_number' => 'TaxBookNumber', 'tax_block' => 'TaxBlock',
                'tax_lot' => 'TaxLot', 'parcel_number' => 'ParcelNumber', 'zoning' => 'Zoning',
                'zoning_description' => 'ZoningDescription', 'mlspin_master_page' => 'MLSPIN_MASTER_PAGE',
                'mlspin_master_book' => 'MLSPIN_MASTER_BOOK', 'mlspin_page' => 'MLSPIN_PAGE',
                'mlspin_sewage_district' => 'MLSPIN_SEWAGE_DISTRICT', 'water_sewer_expense' => 'WaterSewerExpense',
                'electric_expense' => 'ElectricExpense', 'insurance_expense' => 'InsuranceExpense',
                // New fields for listing_financial
                'mlspin_list_price_per_sqft' => 'MLSPIN_LIST_PRICE_PER_SQFT',
                'mlspin_price_per_sqft' => 'MLSPIN_PRICE_PER_SQFT',
                'mlspin_sold_price_per_sqft' => 'MLSPIN_SOLD_PRICE_PER_SQFT',
                'mlspin_owner_occ_source' => 'MLSPIN_OWNER_OCC_SOURCE',
                'mlspin_lead_paint' => 'MLSPIN_LEAD_PAINT',
                'mlspin_title5' => 'MLSPIN_TITLE5',
                'mlspin_perc_test' => 'MLSPIN_PERC_TEST',
                'mlspin_perc_test_date' => 'MLSPIN_PERC_TEST_DATE',
                'mlspin_square_feet_disclosures' => 'MLSPIN_SQUARE_FEET_DISCLOSURES',
                // Additional new fields (Jan 2025)
                'concessions_amount' => 'ConcessionsAmount',
                'tenant_pays' => 'TenantPays',
                'mlspin_rent1' => 'MLSPIN_RENT1',
                'mlspin_rent2' => 'MLSPIN_RENT2',
                'mlspin_rent3' => 'MLSPIN_RENT3',
                'mlspin_rent4' => 'MLSPIN_RENT4',
                'mlspin_lease_1' => 'MLSPIN_LEASE_1',
                'mlspin_lease_2' => 'MLSPIN_LEASE_2',
                'mlspin_lease_3' => 'MLSPIN_LEASE_3',
                'mlspin_lease_4' => 'MLSPIN_LEASE_4',
                'mlspin_market_time_broker' => 'MLSPIN_MARKET_TIME_BROKER',
                'mlspin_market_time_broker_prev' => 'MLSPIN_MARKET_TIME_BROKER_PREV',
                'mlspin_market_time_property_prev' => 'MLSPIN_MARKET_TIME_PROPERTY_PREV',
                'mlspin_prev_market_time' => 'MLSPIN_PREV_MARKET_TIME'
            ],
            'listing_features' => [
                'spa_yn' => 'SpaYN', 'spa_features' => 'SpaFeatures', 'exterior_features' => 'ExteriorFeatures',
                'patio_and_porch_features' => 'PatioAndPorchFeatures', 'lot_features' => 'LotFeatures',
                'road_surface_type' => 'RoadSurfaceType', 'road_frontage_type' => 'RoadFrontageType',
                'road_responsibility' => 'RoadResponsibility', 'frontage_length' => 'FrontageLength',
                'frontage_type' => 'FrontageType', 'fencing' => 'Fencing', 'other_structures' => 'OtherStructures',
                'other_equipment' => 'OtherEquipment', 'pasture_area' => 'PastureArea', 'cultivated_area' => 'CultivatedArea',
                'waterfront_yn' => 'WaterfrontYN', 'waterfront_features' => 'WaterfrontFeatures', 'view' => 'View',
                'view_yn' => 'ViewYN', 'community_features' => 'CommunityFeatures', 'mlspin_waterview_flag' => 'MLSPIN_WATERVIEW_FLAG',
                'mlspin_waterview_features' => 'MLSPIN_WATERVIEW_FEATURES', 'green_indoor_air_quality' => 'GreenIndoorAirQuality',
                'green_energy_generation' => 'GreenEnergyGeneration', 'horse_yn' => 'HorseYN', 'horse_amenities' => 'HorseAmenities',
                'pool_features' => 'PoolFeatures', 'pool_private_yn' => 'PoolPrivateYN',
                // New fields for listing_features
                'senior_community_yn' => 'SeniorCommunityYN',
                'mlspin_outdoor_space_available' => 'MLSPIN_OUTDOOR_SPACE_AVAILABLE',
                'pets_allowed' => 'PetsAllowed',
                // Additional new fields (Jan 2025)
                'additional_parcels_yn' => 'AdditionalParcelsYN',
                'green_energy_efficient' => 'GreenEnergyEfficient',
                'green_water_conservation' => 'GreenWaterConservation',
                'wooded_area' => 'WoodedArea',
                'number_of_lots' => 'NumberOfLots',
                'lot_size_units' => 'LotSizeUnits',
                'farm_land_area_units' => 'FarmLandAreaUnits',
                'mlspin_gre' => 'MLSPIN_GRE',
                'mlspin_hte' => 'MLSPIN_HTE',
                'mlspin_rfs' => 'MLSPIN_RFS',
                'mlspin_rme' => 'MLSPIN_RME'
            ],
            'agents' => [
                'agent_full_name' => 'MemberFullName', 'agent_first_name' => 'MemberFirstName', 'agent_last_name' => 'MemberLastName',
                'agent_email' => 'MemberEmail', 'agent_phone' => 'MemberPreferredPhone', 'office_mls_id' => 'OfficeMlsId',
                'modification_timestamp' => 'ModificationTimestamp'
            ],
            'offices' => [
                'office_name' => 'OfficeName', 'office_phone' => 'OfficePhone', 'office_address' => 'OfficeAddress1',
                'office_city' => 'OfficeCity', 'office_state' => 'OfficeStateOrProvince', 'office_postal_code' => 'OfficePostalCode',
                'modification_timestamp' => 'ModificationTimestamp'
            ],
            'media' => [
                'media_key' => 'MediaKey', 'media_url' => 'MediaURL', 'media_category' => 'MediaCategory',
                'description' => 'ShortDescription', 'modification_timestamp' => 'ModificationTimestamp', 'order_index' => 'Order'
            ]
        ];
    }

    private function init_all_listing_columns() {
        $this->all_listing_columns = [];
        $label_map = [
            'listing_id' => 'MLS #', 'list_price' => 'Price', 'bedrooms_total' => 'Beds',
            'bathrooms_total_integer' => 'Baths', 'living_area' => 'Sq Ft', 'year_built' => 'Year Built',
            'days_on_market' => 'DOM', 'close_date' => 'Close Date', 'close_price' => 'Close Price'
        ];

        // Combine all relevant fields from listings, details, location, financial, features
        $relevant_tables = ['listings', 'listing_details', 'listing_location', 'listing_financial', 'listing_features'];
        foreach ($relevant_tables as $table_name) {
            if (isset($this->field_mapping[$table_name])) {
                foreach ($this->field_mapping[$table_name] as $db_field => $api_field) {
                    $label = $label_map[$db_field] ?? ucwords(str_replace('_', ' ', $db_field));
                    $this->all_listing_columns[$db_field] = $label;
                }
            }
        }

        // Add special columns not directly from field mapping
        $this->all_listing_columns['address'] = __('Full Address', 'bridge-mls-extractor-pro');
        $this->all_listing_columns['coordinates'] = __('Coordinates (Geo)', 'bridge-mls-extractor-pro');
        // Add agent and office names for export
        $this->all_listing_columns['list_agent_full_name'] = __('List Agent', 'bridge-mls-extractor-pro');
        $this->all_listing_columns['buyer_agent_full_name'] = __('Buyer Agent', 'bridge-mls-extractor-pro');
        $this->all_listing_columns['list_office_name'] = __('List Office', 'bridge-mls-extractor-pro');
        $this->all_listing_columns['buyer_office_name'] = __('Buyer Office', 'bridge-mls-extractor-pro');
        // New: Add virtual tour links for export
        $this->all_listing_columns['virtual_tour_link_1'] = __('Virtual Tour Link 1', 'bridge-mls-extractor-pro');
        $this->all_listing_columns['virtual_tour_link_2'] = __('Virtual Tour Link 2', 'bridge-mls-extractor-pro');
        $this->all_listing_columns['virtual_tour_link_3'] = __('Virtual Tour Link 3', 'bridge-mls-extractor-pro');
    }

    public function get_all_listing_columns() {
        return $this->all_listing_columns;
    }

    public function process_listings_batch($extraction_id, $listings, $related_data = []) {
        $start_time = microtime(true);
        $memory_start = memory_get_usage();

        $processed = 0;
        $errors = [];

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($listings as $listing) {
                try {
                    $this->process_single_listing($extraction_id, $listing, $related_data);
                    $processed++;
                } catch (Exception $e) {
                    $errors[] = ['listing_id' => $listing['ListingId'] ?? 'Unknown', 'error' => $e->getMessage()];
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $duration = microtime(true) - $start_time;
        $memory_peak = memory_get_peak_usage() - $memory_start;
        $this->log_batch_performance($processed, $duration, $memory_peak);

        return ['processed' => $processed, 'errors' => $errors, 'duration' => $duration, 'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2)];
    }

    private function process_single_listing($extraction_id, $listing, $related_data) {
        $is_archived = $this->is_archived_status($listing['StandardStatus']);
        $table_suffix = $is_archived ? '_archive' : '';

        $listing_id = $this->process_core_listing($extraction_id, $listing, $table_suffix);

        // Process all related data tables
        $this->process_listing_details($listing_id, $listing, $table_suffix);
        $this->process_listing_location($listing_id, $listing, $table_suffix);
        $this->process_listing_financial($listing_id, $listing, $table_suffix);
        $this->process_listing_features($listing_id, $listing, $table_suffix);

        // Process shared data (agents, offices)
        $this->save_related_data($listing, $related_data);

        // Process new relational data (media, rooms) - not archived
        $this->process_media($listing_id, $listing);
        $this->process_rooms($listing_id, $listing);

        // Only process open houses for non-archived listings
        if (!$is_archived && !empty($related_data['open_houses'][$listing['ListingKey']])) {
            $this->process_open_houses($listing_id, $listing['ListingKey'], $related_data['open_houses'][$listing['ListingKey']]);
        }

        return $listing_id;
    }

    private function process_core_listing($extraction_id, $listing, $table_suffix) {
        global $wpdb;
        $data = ['extraction_id' => $extraction_id];

        foreach ($this->field_mapping['listings'] as $db_field => $api_field) {
            if (isset($listing[$api_field])) {
                $value = $this->sanitize_field_value($listing[$api_field]);
                 if (strpos($db_field, '_yn') !== false || strpos($db_field, '_flag') !== false || strpos($api_field, '_REQD') !== false || strpos($api_field, 'Offered') !== false) {
                    $value = $this->convert_to_boolean($value);
                }
                $data[$db_field] = $value;
            }
        }

        $table = $this->db_manager->get_table('listings' . $table_suffix);
        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE listing_key = %s", $data['listing_key']));

        if ($existing_id) {
            // Get existing data to track changes
            $existing_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $existing_id), ARRAY_A);
            
            // Track changes before updating - use the MLS listing_id for history tracking
            $this->history_tracker->track_property_changes($data['listing_id'], $data, $existing_data, $extraction_id);
            
            $wpdb->update($table, $data, ['id' => $existing_id]);
            return $existing_id;
        } else {
            // New listing - insert first to get the ID
            $wpdb->insert($table, $data);
            $new_id = $wpdb->insert_id;
            
            // Track as new listing - use the MLS listing_id for history tracking
            $this->history_tracker->track_new_listing($data['listing_id'], $data, $extraction_id);
            
            return $new_id;
        }
    }

    private function process_listing_details($listing_id, $listing, $table_suffix) {
        $this->process_related_table('listing_details', $listing_id, $listing, $table_suffix);
    }

    private function process_listing_location($listing_id, $listing, $table_suffix) {
        global $wpdb;
        $table = $this->db_manager->get_table('listing_location' . $table_suffix);
        $data = ['listing_id' => $listing_id];

        foreach ($this->field_mapping['listing_location'] as $db_field => $api_field) {
            if (isset($listing[$api_field])) {
                $data[$db_field] = $this->sanitize_field_value($listing[$api_field]);
            }
        }

        // Normalize the address if we have unparsed_address
        if (!empty($data['unparsed_address'])) {
            $data['normalized_address'] = BME_Address_Normalizer::normalize($data['unparsed_address']);
        }

        if (count($data) <= 1) return;

        $lat = $listing['Latitude'] ?? null;
        $lon = $listing['Longitude'] ?? null;
        // Ensure coordinates are valid numbers for POINT creation
        $point_str = (is_numeric($lat) && is_numeric($lon)) ? "POINT({$lon} {$lat})" : "POINT(0 0)";

        $columns = '`' . implode('`, `', array_keys($data)) . '`, `coordinates`';
        $placeholders = implode(', ', array_fill(0, count($data), '%s'));

        $sql = "REPLACE INTO `{$table}` ({$columns}) VALUES ({$placeholders}, ST_PointFromText(%s))";

        $values = array_values($data);
        $values[] = $point_str;

        $wpdb->query($wpdb->prepare($sql, $values));
    }

    private function process_listing_financial($listing_id, $listing, $table_suffix) {
        $this->process_related_table('listing_financial', $listing_id, $listing, $table_suffix);
    }

    private function process_listing_features($listing_id, $listing, $table_suffix) {
        $this->process_related_table('listing_features', $listing_id, $listing, $table_suffix);
    }

    private function process_related_table($table_name, $listing_id, $listing, $table_suffix) {
        global $wpdb;
        $data = ['listing_id' => $listing_id];

        if (!isset($this->field_mapping[$table_name])) return;

        foreach ($this->field_mapping[$table_name] as $db_field => $api_field) {
            if (isset($listing[$api_field])) {
                $value = $this->sanitize_field_value($listing[$api_field]);
                if (strpos($db_field, '_yn') !== false || strpos($db_field, '_flag') !== false || strpos($api_field, '_REQD') !== false || strpos($api_field, 'Offered') !== false) {
                    $value = $this->convert_to_boolean($value);
                }
                $data[$db_field] = $value;
            }
        }

        if (count($data) <= 1) return;

        $table = $this->db_manager->get_table($table_name . $table_suffix);
        $wpdb->replace($table, $data);
    }

    private function process_media($listing_id, $listing_data) {
        if (!isset($listing_data['Media']) || !is_array($listing_data['Media'])) {
            return;
        }

        global $wpdb;
        $table = $this->db_manager->get_table('media');
        
        // Get the listing_key for this listing to ensure uniqueness
        $listing_key = $listing_data['ListingKey'] ?? '';
        
        if (empty($listing_key)) {
            error_log("BME Media Processor: Missing ListingKey for listing_id $listing_id");
            return;
        }
        
        // Get the MLS number and convert to BIGINT
        $mls_number = $listing_data['ListingId'] ?? '';
        if (empty($mls_number)) {
            error_log("BME Media Processor: Missing MLS number (ListingId) for listing_id $listing_id");
            return;
        }
        
        // Convert MLS number to BIGINT (remove any non-numeric characters)
        $mls_number_bigint = preg_replace('/[^0-9]/', '', $mls_number);
        if (empty($mls_number_bigint) || !is_numeric($mls_number_bigint)) {
            error_log("BME Media Processor: Invalid MLS number format: $mls_number");
            return;
        }
        
        // Determine source table based on status
        $is_archived = $this->is_listing_archived($listing_data);
        $source_table = $is_archived ? 'archive' : 'active';
        
        // Delete existing media for this MLS number to avoid duplicates
        $deleted = $wpdb->delete($table, ['listing_id' => (int)$mls_number_bigint]);
        
        if ($deleted > 0) {
            error_log("BME Media Processor: Deleted $deleted existing media records for MLS# $mls_number");
        }

        $inserted_count = 0;
        
        foreach ($listing_data['Media'] as $media_item) {
            $data = [
                'listing_id' => (int)$mls_number_bigint,  // Store MLS# as BIGINT instead of internal ID
                'listing_key' => $listing_key,
                'source_table' => $source_table
            ];
            
            foreach ($this->field_mapping['media'] as $db_field => $api_field) {
                if (isset($media_item[$api_field])) {
                    $data[$db_field] = $this->sanitize_field_value($media_item[$api_field]);
                }
            }
            
            if (!empty($data['media_key']) && !empty($data['media_url'])) {
                $result = $wpdb->insert($table, $data);
                
                if ($result !== false) {
                    $inserted_count++;
                } else {
                    error_log("BME Media Processor: Failed to insert media for listing_key $listing_key, media_key: " . $data['media_key']);
                    error_log("BME Media Processor: DB Error: " . $wpdb->last_error);
                }
            }
        }
        
        if ($inserted_count > 0) {
            error_log("BME Media Processor: Inserted $inserted_count media records for listing_key $listing_key (source: $source_table)");
        }
    }

    private function process_rooms($listing_id, $listing_data) {
        // Removed the incorrect check for top-level 'Rooms' key.
        // Room data fields are expected to be directly within $listing_data,
        // and the regex below will correctly find them.

        global $wpdb;
        $table = $this->db_manager->get_table('rooms');

        // Delete existing rooms for this listing to avoid duplicates on update
        $wpdb->delete($table, ['listing_id' => $listing_id]);

        $rooms_aggregated = [];
        // Regex to capture RoomName and Attribute (e.g., Room1Area, Room2Level)
        $pattern = '/^Room([a-zA-Z0-9]+)(Area|Length|Width|Level|Features)$/';

        foreach ($listing_data as $key => $value) {
            if (preg_match($pattern, $key, $matches)) {
                $room_name = $matches[1];
                $attribute = strtolower($matches[2]);

                if (!isset($rooms_aggregated[$room_name])) {
                    $rooms_aggregated[$room_name] = [];
                }
                $rooms_aggregated[$room_name][$attribute] = $this->sanitize_field_value($value);
            }
        }

        if (empty($rooms_aggregated)) {
            return;
        }

        foreach ($rooms_aggregated as $room_name => $attributes) {
            // Format room name (e.g., "MasterBedroom" to "Master Bedroom")
            $formatted_room_name = preg_replace('/(?<!^)[A-Z]/', ' $0', $room_name);

            $length = $attributes['length'] ?? null;
            $width = $attributes['width'] ?? null;
            $dimensions = ($length && $width) ? "{$length} x {$width}" : null;

            $data_to_insert = [
                'listing_id' => $listing_id,
                'room_type' => $formatted_room_name,
                'room_level' => $attributes['level'] ?? null,
                'room_dimensions' => $dimensions,
                'room_features' => $attributes['features'] ?? null,
            ];

            if (!empty($data_to_insert['room_type'])) {
                $wpdb->insert($table, $data_to_insert);
            }
        }
    }

    private function save_related_data($listing, $related_data) {
        // Save List Agent data
        if (!empty($listing['ListAgentMlsId']) && isset($related_data['agents'][$listing['ListAgentMlsId']])) {
            $this->save_agent_data($listing['ListAgentMlsId'], $related_data['agents'][$listing['ListAgentMlsId']]);
        }
        // Save Buyer Agent data
        if (!empty($listing['BuyerAgentMlsId']) && isset($related_data['agents'][$listing['BuyerAgentMlsId']])) {
            $this->save_agent_data($listing['BuyerAgentMlsId'], $related_data['agents'][$listing['BuyerAgentMlsId']]);
        }
        // Save List Office data
        if (!empty($listing['ListOfficeMlsId']) && isset($related_data['offices'][$listing['ListOfficeMlsId']])) {
            $this->save_office_data($listing['ListOfficeMlsId'], $related_data['offices'][$listing['ListOfficeMlsId']]);
        }
        // Save Buyer Office data
        if (!empty($listing['BuyerOfficeMlsId']) && isset($related_data['offices'][$listing['BuyerOfficeMlsId']])) {
            $this->save_office_data($listing['BuyerOfficeMlsId'], $related_data['offices'][$listing['BuyerOfficeMlsId']]);
        }
    }

    private function save_agent_data($agent_mls_id, $api_data) {
        global $wpdb;
        $table = $this->db_manager->get_table('agents');
        $columns = $this->field_mapping['agents'];
        $data_to_insert = ['agent_mls_id' => $agent_mls_id];
        $remaining_data = $api_data; // Copy to remove mapped fields

        foreach ($columns as $db_field => $api_field) {
            if (isset($api_data[$api_field])) {
                $data_to_insert[$db_field] = $this->sanitize_field_value($api_data[$api_field]);
                unset($remaining_data[$api_field]); // Remove from remaining data
            }
        }
        // Store any unmapped API data as JSON
        $data_to_insert['agent_data'] = json_encode($remaining_data, JSON_UNESCAPED_UNICODE);
        $wpdb->replace($table, $data_to_insert); // Use replace to insert or update
    }

    private function save_office_data($office_mls_id, $api_data) {
        global $wpdb;
        $table = $this->db_manager->get_table('offices');
        $columns = $this->field_mapping['offices'];
        $data_to_insert = ['office_mls_id' => $office_mls_id];
        $remaining_data = $api_data; // Copy to remove mapped fields

        foreach ($columns as $db_field => $api_field) {
            if (isset($api_data[$api_field])) {
                $data_to_insert[$db_field] = $this->sanitize_field_value($api_data[$api_field]);
                unset($remaining_data[$api_field]); // Remove from remaining data
            }
        }
        // Store any unmapped API data as JSON
        $data_to_insert['office_data'] = json_encode($remaining_data, JSON_UNESCAPED_UNICODE);
        $wpdb->replace($table, $data_to_insert); // Use replace to insert or update
    }

    private function process_open_houses($listing_id, $listing_key, $open_houses) {
        global $wpdb;
        $table = $this->db_manager->get_table('open_houses');
        // Delete existing open houses for this listing to avoid duplicates on update
        $wpdb->delete($table, ['listing_id' => $listing_id]);

        foreach ($open_houses as $open_house) {
            $expires_at = null;
            if (isset($open_house['OpenHouseEndTime'])) {
                try {
                    $end_time = new DateTime($open_house['OpenHouseEndTime'], new DateTimeZone('UTC'));
                    $expires_at = $end_time->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    error_log("BME Data Processor: Invalid OpenHouseEndTime format for listing_id {$listing_id}: " . $open_house['OpenHouseEndTime']);
                }
            }

            $wpdb->insert($table, [
                'listing_id' => $listing_id,
                'listing_key' => $listing_key,
                'open_house_data' => json_encode($open_house, JSON_UNESCAPED_UNICODE), // Store full data as JSON
                'expires_at' => $expires_at,
            ]);
        }
    }

    private function sanitize_field_value($value) {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE);
        // For string values, use wp_kses_post for general sanitization, or more specific if needed
        if (is_string($value)) return wp_kses_post($value);
        return $value;
    }

    private function convert_to_boolean($value) {
        if (is_bool($value)) return $value ? 1 : 0;
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', 'yes', 'y', '1']) ? 1 : 0;
        }
        return intval($value) ? 1 : 0;
    }

    /**
     * Prepares common query parts (joins and where clauses) for search and count.
     * Ensures necessary joins are included for selected columns, even if not filtered.
     *
     * @param array $filters Associative array of filters.
     * @param string $table_suffix Suffix for table names ('', '_archive').
     * @return array Contains 'joins' and 'wheres' arrays.
     */
    private function _prepare_search_query_parts($filters, $table_suffix = '') {
        global $wpdb;
        $tables = [
            'listings' => $this->db_manager->get_table('listings' . $table_suffix),
            'listing_location' => $this->db_manager->get_table('listing_location' . $table_suffix),
            'listing_details' => $this->db_manager->get_table('listing_details' . $table_suffix),
            'agents' => $this->db_manager->get_table('agents'),
            'offices' => $this->db_manager->get_table('offices'),
            'virtual_tours' => $this->db_manager->get_table('virtual_tours'), // New: Virtual Tours table
        ];
        $joins = [];
        $wheres = [];

        // Define how each filter field maps to its table alias and database column
        $filter_map = [
            'standard_status' => ['table_alias' => 'l', 'field' => 'standard_status'],
            'property_type' => ['table_alias' => 'l', 'field' => 'property_type'],
            'listing_id' => ['table_alias' => 'l', 'field' => 'listing_id'],
            'price_min' => ['table_alias' => 'l', 'field' => 'list_price', 'compare' => '>='],
            'price_max' => ['table_alias' => 'l', 'field' => 'list_price', 'compare' => '<='],
            'bedrooms_min' => ['table_alias' => 'ld', 'field' => 'bedrooms_total', 'compare' => '>=', 'join' => "LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id"],
            'bathrooms_min' => ['table_alias' => 'ld', 'field' => 'bathrooms_total_integer', 'compare' => '>=', 'join' => "LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id"],
            'year_built_min' => ['table_alias' => 'ld', 'field' => 'year_built', 'compare' => '>=', 'join' => "LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id"],
            'year_built_max' => ['table_alias' => 'ld', 'field' => 'year_built', 'compare' => '<=', 'join' => "LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id"],
            'city' => ['table_alias' => 'll', 'field' => 'city', 'join' => "LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id"],
            'days_on_market_max' => ['table_alias' => 'ld', 'field' => 'mlspin_market_time_property', 'compare' => '<=', 'join' => "LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id"],
            'list_agent_mls_id' => ['table_alias' => 'l', 'field' => 'list_agent_mls_id'],
            'buyer_agent_mls_id' => ['table_alias' => 'l', 'field' => 'buyer_agent_mls_id'],
            'list_office_mls_id' => ['table_alias' => 'l', 'field' => 'list_office_mls_id'],
            'buyer_office_mls_id' => ['table_alias' => 'l', 'field' => 'buyer_office_mls_id'],
        ];

        // Add joins for columns always needed in the SELECT statement for the browser table
        $required_joins = [
            "LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id",
            "LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id",
        ];
        foreach ($required_joins as $join_clause) {
            if (!in_array($join_clause, $joins)) {
                $joins[] = $join_clause;
            }
        }

        foreach ($filters as $field => $value) {
            if (empty($value) && $value !== '0') continue; // Skip empty filters unless value is 0

            if (isset($filter_map[$field])) {
                $map = $filter_map[$field];
                // Add join if specified and not already added
                if (isset($map['join']) && !in_array($map['join'], $joins)) {
                    $joins[] = $map['join'];
                }

                $db_field = $map['field'] ?? $field;
                $compare = $map['compare'] ?? '=';
                $type = $map['type'] ?? '%s'; // Default to string type for prepare

                // Special handling for numeric comparisons
                if (in_array($field, ['price_min', 'price_max', 'bedrooms_min', 'bathrooms_min', 'year_built_min', 'year_built_max', 'days_on_market_max'])) {
                    $type = '%d'; // Use integer type for numbers
                    $value = absint($value); // Ensure integer
                }

                $wheres[] = $wpdb->prepare("{$map['table_alias']}.{$db_field} {$compare} {$type}", $value);
            }
        }

        // Handle search query (s)
        if (isset($filters['search_query']) && !empty($filters['search_query'])) {
            $search_query = trim($filters['search_query']);
            $like_term = '%' . $wpdb->esc_like($search_query) . '%';

            // Define all necessary joins for the search
            $search_joins = [
                "LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id",
                "LEFT JOIN {$tables['agents']} la ON l.list_agent_mls_id = la.agent_mls_id",
                "LEFT JOIN {$tables['agents']} ba ON l.buyer_agent_mls_id = ba.agent_mls_id",
                "LEFT JOIN {$tables['offices']} lo ON l.list_office_mls_id = lo.office_mls_id",
                "LEFT JOIN {$tables['offices']} bo ON l.buyer_office_mls_id = bo.office_mls_id",
            ];
            foreach($search_joins as $join) {
                if (!in_array($join, $joins)) {
                    $joins[] = $join;
                }
            }

            $search_clauses = [
                $wpdb->prepare("l.listing_id LIKE %s", $like_term),
                $wpdb->prepare("ll.unparsed_address LIKE %s", $like_term),
                $wpdb->prepare("ll.city LIKE %s", $like_term),
                $wpdb->prepare("la.agent_full_name LIKE %s", $like_term),
                $wpdb->prepare("ba.agent_full_name LIKE %s", $like_term),
                $wpdb->prepare("lo.office_name LIKE %s", $like_term),
                $wpdb->prepare("bo.office_name LIKE %s", $like_term),
                "MATCH(l.public_remarks, l.private_remarks, l.disclosures) AGAINST ('" . esc_sql($search_query) . "' IN BOOLEAN MODE)",
            ];
            $wheres[] = "(" . implode(' OR ', $search_clauses) . ")";
        }


        return ['joins' => $joins, 'wheres' => $wheres];
    }

    /**
     * Searches listings based on filters, with pagination and sorting.
     *
     * @param array $filters Associative array of filters.
     * @param int $limit Number of results to return. Use -1 for no limit.
     * @param int $offset Offset for pagination.
     * @param string $orderby Column to order by.
     * @param string $order Order direction (ASC/DESC).
     * @return array Array of listing data.
     */
    public function search_listings($filters, $limit = 30, $offset = 0, $orderby = 'modification_timestamp', $order = 'DESC') {
        global $wpdb;
        $dataset = $filters['dataset'] ?? 'active';
        unset($filters['dataset']); // Remove dataset from filters to avoid issues in query parts

        // Map sortable columns to their correct table aliases
        $sortable_column_map = [
            'listing_id' => 'l.listing_id',
            'standard_status' => 'l.standard_status',
            'property_type' => 'l.property_type',
            'list_price' => 'l.list_price',
            'close_price' => 'l.close_price',
            'bedrooms_total' => 'ld.bedrooms_total',
            'bathrooms_total_integer' => 'ld.bathrooms_total_integer',
            'living_area' => 'ld.living_area',
            'mlspin_market_time_property' => 'ld.mlspin_market_time_property',
            'modification_timestamp' => 'l.modification_timestamp',
            'creation_timestamp' => 'l.creation_timestamp', // Added for completeness if needed
            'close_date' => 'l.close_date', // Added for completeness if needed
        ];

        // Validate and apply orderby
        $orderby_clause = $sortable_column_map[$orderby] ?? 'l.modification_timestamp'; // Default to l.modification_timestamp
        $order = (strtoupper($order) === 'ASC') ? 'ASC' : 'DESC';

        $build_query = function($table_suffix) use ($filters, $orderby_clause, $order) {
            $query_parts = $this->_prepare_search_query_parts($filters, $table_suffix);
            $tables = [
                'listings' => $this->db_manager->get_table('listings' . $table_suffix),
                'listing_location' => $this->db_manager->get_table('listing_location' . $table_suffix),
                'listing_details' => $this->db_manager->get_table('listing_details' . $table_suffix),
            ];

            // Select all columns needed for the list table display, ensuring aliases are correct
            $select_clause = "SELECT
                l.id, l.listing_id, l.standard_status, l.property_type, l.list_price, l.close_price, l.modification_timestamp, l.listing_key,
                ll.unparsed_address, ll.city, ll.state_or_province, ll.postal_code,
                ld.bedrooms_total, ld.bathrooms_total_integer, ld.living_area, ld.mlspin_market_time_property";

            $joins = $query_parts['joins'];

            $sql = "{$select_clause} FROM {$tables['listings']} l " . implode(' ', array_unique($joins));
            if (!empty($query_parts['wheres'])) {
                $sql .= " WHERE " . implode(' AND ', $query_parts['wheres']);
            }
            return $sql;
        };

        if ($dataset === 'all') {
            $active_sql = $build_query('');
            $archive_sql = $build_query('_archive');
            // Use UNION ALL to combine results from active and archive tables
            $sql = "($active_sql) UNION ALL ($archive_sql)";
        } elseif ($dataset === 'closed') {
            $sql = $build_query('_archive');
        } else { // 'active' or default
            $sql = $build_query('');
        }

        // Apply final order by and limit/offset
        $sql .= " ORDER BY {$orderby_clause} {$order}";

        if ($limit !== -1) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Gets the total count of listings matching the given filters.
     *
     * @param array $filters Associative array of filters.
     * @return int Total count of listings.
     */
    public function get_search_count($filters) {
        global $wpdb;
        $dataset = $filters['dataset'] ?? 'active';
        unset($filters['dataset']);

        $build_count_query = function($table_suffix) use ($filters) {
            $query_parts = $this->_prepare_search_query_parts($filters, $table_suffix);
            $tables = ['listings' => $this->db_manager->get_table('listings' . $table_suffix)];
            $sql = "SELECT COUNT(DISTINCT l.id) FROM {$tables['listings']} l " . implode(' ', array_unique($query_parts['joins']));
            if (!empty($query_parts['wheres'])) {
                $sql .= " WHERE " . implode(' AND ', $query_parts['wheres']);
            }
            return $sql;
        };

        if ($dataset === 'all') {
            $active_sql = $build_count_query('');
            $archive_sql = $build_count_query('_archive');
            // Sum counts from both tables
            $sql = "SELECT (SELECT COUNT(DISTINCT l.id) FROM {$this->db_manager->get_table('listings')} l " . implode(' ', array_unique($this->_prepare_search_query_parts($filters, '')['joins'])) . (empty($this->_prepare_search_query_parts($filters, '')['wheres']) ? '' : ' WHERE ' . implode(' AND ', $this->_prepare_search_query_parts($filters, '')['wheres'])) . ") + (SELECT COUNT(DISTINCT l.id) FROM {$this->db_manager->get_table('listings_archive')} l " . implode(' ', array_unique($this->_prepare_search_query_parts($filters, '_archive')['joins'])) . (empty($this->_prepare_search_query_parts($filters, '_archive')['wheres']) ? '' : ' WHERE ' . implode(' AND ', $this->_prepare_search_query_parts($filters, '_archive')['wheres'])) . ") AS total_count";
            return intval($wpdb->get_var($sql));
        } elseif ($dataset === 'closed') {
            return intval($wpdb->get_var($build_count_query('_archive')));
        } else { // 'active' or default
            return intval($wpdb->get_var($build_count_query('')));
        }
    }

    /**
     * Retrieves full listing data for a given set of listing IDs, including data from joined tables.
     * This is used for the "Export Selected" functionality.
     *
     * @param array $ids Array of listing IDs (from the 'id' column of the listings table).
     * @param array $select_columns Optional. Array of specific column keys to select. If empty, all mapped columns are selected.
     * @return array Array of listing data.
     */
    public function get_listings_by_ids(array $ids, array $select_columns = []) {
        global $wpdb;

        if (empty($ids)) {
            return [];
        }

        // Sanitize IDs to ensure they are integers
        $sanitized_ids = array_map('absint', $ids);
        $id_placeholders = implode(',', array_fill(0, count($sanitized_ids), '%d'));

        $tables = [
            'listings' => $this->db_manager->get_table('listings'),
            'listings_archive' => $this->db_manager->get_table('listings_archive'),
            'listing_details' => $this->db_manager->get_table('listing_details'),
            'listing_details_archive' => $this->db_manager->get_table('listing_details_archive'),
            'listing_location' => $this->db_manager->get_table('listing_location'),
            'listing_location_archive' => $this->db_manager->get_table('listing_location_archive'),
            'listing_financial' => $this->db_manager->get_table('listing_financial'),
            'listing_financial_archive' => $this->db_manager->get_table('listing_financial_archive'),
            'listing_features' => $this->db_manager->get_table('listing_features'),
            'listing_features_archive' => $this->db_manager->get_table('listing_features_archive'),
            'agents' => $this->db_manager->get_table('agents'),
            'offices' => $this->db_manager->get_table('offices'),
            'media' => $this->db_manager->get_table('media'),
            'rooms' => $this->db_manager->get_table('rooms'),
            'open_houses' => $this->db_manager->get_table('open_houses'),
            'virtual_tours' => $this->db_manager->get_table('virtual_tours'), // New: Virtual Tours table
        ];

        // Determine which tables to query based on the IDs.
        // A listing could be in active or archive. We need to check both.
        // We'll use UNION ALL to get all relevant data.
        $union_parts = [];
        $select_fields = [];

        // Build the list of all possible columns from all relevant tables
        $all_db_columns = [];
        foreach ($this->field_mapping as $table_key => $fields) {
            // Exclude 'media' and 'rooms' from the main select as they are one-to-many and handled separately
            if (in_array($table_key, ['agents', 'offices', 'media', 'rooms', 'open_houses'])) continue;
            foreach ($fields as $db_field => $api_field) {
                $all_db_columns[$db_field] = $db_field; // Use db_field as key and value
            }
        }
        // Add special columns like 'address', 'coordinates'
        $all_db_columns['unparsed_address'] = 'unparsed_address';
        $all_db_columns['latitude'] = 'latitude';
        $all_db_columns['longitude'] = 'longitude';
        $all_db_columns['coordinates'] = 'coordinates'; // Will be handled as ST_AsText(ll.coordinates)
        // New: Add virtual tour fields explicitly for selection
        $all_db_columns['virtual_tour_link_1'] = 'virtual_tour_link_1';
        $all_db_columns['virtual_tour_link_2'] = 'virtual_tour_link_2';
        $all_db_columns['virtual_tour_link_3'] = 'virtual_tour_link_3';


        // If specific columns are requested, filter down to those
        if (!empty($select_columns)) {
            // Ensure 'id', 'listing_key', 'listing_id', 'standard_status' are always selected for internal processing/joining
            $select_columns = array_unique(array_merge($select_columns, ['id', 'listing_key', 'listing_id', 'standard_status']));
            foreach ($select_columns as $col) {
                if (isset($all_db_columns[$col])) {
                    $select_fields[] = $all_db_columns[$col];
                }
            }
        } else {
            // If no specific columns, select all mapped columns for export
            $select_fields = array_values($all_db_columns);
        }

        $select_parts = [];
        $joins_active = [];
        $joins_archive = [];

        // Dynamically build SELECT and JOIN clauses based on selected fields
        foreach ($select_fields as $field) {
            if (strpos($field, 'coordinates') !== false) {
                $select_parts[] = "ST_AsText(ll.coordinates) AS coordinates";
                if (!in_array("LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id", $joins_active)) $joins_active[] = "LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id";
                if (!in_array("LEFT JOIN {$tables['listing_location_archive']} ll ON l.id = ll.listing_id", $joins_archive)) $joins_archive[] = "LEFT JOIN {$tables['listing_location_archive']} ll ON l.id = ll.listing_id";
            } elseif (isset($this->field_mapping['listings'][$field])) {
                $select_parts[] = "l.{$field}";
            } elseif (isset($this->field_mapping['listing_details'][$field])) {
                $select_parts[] = "ld.{$field}";
                if (!in_array("LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id", $joins_active)) $joins_active[] = "LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id";
                if (!in_array("LEFT JOIN {$tables['listing_details_archive']} ld ON l.id = ld.listing_id", $joins_archive)) $joins_archive[] = "LEFT JOIN {$tables['listing_details_archive']} ld ON l.id = ld.listing_id";
            } elseif (isset($this->field_mapping['listing_location'][$field])) {
                $select_parts[] = "ll.{$field}";
                if (!in_array("LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id", $joins_active)) $joins_active[] = "LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id";
                if (!in_array("LEFT JOIN {$tables['listing_location_archive']} ll ON l.id = ll.listing_id", $joins_archive)) $joins_archive[] = "LEFT JOIN {$tables['listing_location_archive']} ll ON l.id = ll.listing_id";
            } elseif (isset($this->field_mapping['listing_financial'][$field])) {
                $select_parts[] = "lf.{$field}";
                if (!in_array("LEFT JOIN {$tables['listing_financial']} lf ON l.id = lf.listing_id", $joins_active)) $joins_active[] = "LEFT JOIN {$tables['listing_financial']} lf ON l.id = lf.listing_id";
                if (!in_array("LEFT JOIN {$tables['listing_financial_archive']} lf ON l.id = lf.listing_id", $joins_archive)) $joins_archive[] = "LEFT JOIN {$tables['listing_financial_archive']} lf ON l.id = lf.listing_id";
            } elseif (isset($this->field_mapping['listing_features'][$field])) {
                $select_parts[] = "lfeat.{$field}";
                if (!in_array("LEFT JOIN {$tables['listing_features']} lfeat ON l.id = lfeat.listing_id", $joins_active)) $joins_active[] = "LEFT JOIN {$tables['listing_features']} lfeat ON l.id = lfeat.listing_id";
                if (!in_array("LEFT JOIN {$tables['listing_features_archive']} lfeat ON l.id = lfeat.listing_id", $joins_archive)) $joins_archive[] = "LEFT JOIN {$tables['listing_features_archive']} lfeat ON l.id = lfeat.listing_id";
            } elseif (strpos($field, 'agent_full_name') !== false) {
                // Determine if list or buyer agent
                $alias = (strpos($field, 'list_') === 0) ? 'agent_list' : 'agent_buyer';
                $select_parts[] = "{$alias}.agent_full_name AS {$field}";
                $join_clause = "LEFT JOIN {$tables['agents']} {$alias} ON l." . (strpos($field, 'list_') === 0 ? 'list_agent_mls_id' : 'buyer_agent_mls_id') . " = {$alias}.agent_mls_id";
                if (!in_array($join_clause, $joins_active)) $joins_active[] = $join_clause;
                if (!in_array($join_clause, $joins_archive)) $joins_archive[] = $join_clause;
            } elseif (strpos($field, 'office_name') !== false) {
                // Determine if list or buyer office
                $alias = (strpos($field, 'list_') === 0) ? 'office_list' : 'office_buyer';
                $select_parts[] = "{$alias}.office_name AS {$field}";
                $join_clause = "LEFT JOIN {$tables['offices']} {$alias} ON l." . (strpos($field, 'list_') === 0 ? 'list_office_mls_id' : 'buyer_office_mls_id') . " = {$alias}.office_mls_id";
                if (!in_array($join_clause, $joins_active)) $joins_active[] = $join_clause;
                if (!in_array($join_clause, $joins_archive)) $joins_archive[] = $join_clause;
            } elseif (strpos($field, 'virtual_tour_link_') !== false) { // New: Handle virtual tour link selection
                $select_parts[] = "vt.{$field}";
                $join_clause = "LEFT JOIN {$tables['virtual_tours']} vt ON l.listing_id = vt.mls_id";
                if (!in_array($join_clause, $joins_active)) $joins_active[] = $join_clause;
                if (!in_array($join_clause, $joins_archive)) $joins_archive[] = $join_clause;
            }
        }

        $select_clause = implode(', ', array_unique($select_parts));

        // Query active listings
        $union_parts[] = $wpdb->prepare(
            "SELECT {$select_clause} FROM {$tables['listings']} l " . implode(' ', array_unique($joins_active)) . " WHERE l.id IN ({$id_placeholders})",
            ...$sanitized_ids
        );

        // Query archive listings
        $union_parts[] = $wpdb->prepare(
            "SELECT {$select_clause} FROM {$tables['listings_archive']} l " . implode(' ', array_unique($joins_archive)) . " WHERE l.id IN ({$id_placeholders})",
            ...$sanitized_ids
        );

        $sql = implode(' UNION ALL ', $union_parts);
        $results = $wpdb->get_results($sql, ARRAY_A);

        // Fetch one-to-many relationships (media, rooms, open_houses)
        // This is done separately as they are arrays and complicate the main SQL join
        $final_results = [];
        foreach ($results as $listing) {
            $listing_id = $listing['id'];
            $listing_key = $listing['listing_key'];
            $listing_mls_id = $listing['listing_id'];

            // Fetch Media
            $media_items = $wpdb->get_results($wpdb->prepare(
                "SELECT media_key, media_url, media_category, description, order_index FROM {$tables['media']} WHERE listing_id = %d ORDER BY order_index ASC",
                $listing_id
            ), ARRAY_A);
            $listing['media'] = $media_items;

            // Fetch Rooms
            $room_items = $wpdb->get_results($wpdb->prepare(
                "SELECT room_type, room_level, room_dimensions, room_features FROM {$tables['rooms']} WHERE listing_id = %d ORDER BY room_type ASC",
                $listing_id
            ), ARRAY_A);
            $listing['rooms'] = $room_items;

            // Fetch Open Houses (only for active listings, check status)
            if (!empty($listing['standard_status']) && !$this->is_archived_status($listing['standard_status'])) {
                $open_houses = $wpdb->get_results($wpdb->prepare(
                    "SELECT open_house_data FROM {$tables['open_houses']} WHERE listing_id = %d AND expires_at > NOW() ORDER BY expires_at ASC",
                    $listing_id
                ), ARRAY_A);
                // Decode the JSON data for open houses
                $listing['open_houses'] = array_map(function($oh) {
                    return json_decode($oh['open_house_data'], true);
                }, $open_houses);
            } else {
                $listing['open_houses'] = [];
            }

            $final_results[] = $listing;
        }

        return $final_results;
    }

    private function log_batch_performance($processed, $duration, $memory_peak) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('BME Batch Performance - Processed: %d listings in %.3f seconds (%.2f listings/sec), Peak Memory: %.2f MB', $processed, $duration, $processed / max($duration, 0.001), $memory_peak / 1024 / 1024));
        }
    }

    public function clear_extraction_data($extraction_id) {
        global $wpdb;
        $table_active = $this->db_manager->get_table('listings');
        $table_archive = $this->db_manager->get_table('listings_archive');

        $deleted_active = $wpdb->delete($table_active, ['extraction_id' => $extraction_id], ['%d']);
        $deleted_archive = $wpdb->delete($table_archive, ['extraction_id' => $extraction_id], ['%d']);

        // Delete related data in one-to-one tables (details, location, financial, features)
        $related_tables = ['listing_details', 'listing_location', 'listing_financial', 'listing_features'];
        foreach ($related_tables as $table_key) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->db_manager->get_table($table_key)} WHERE listing_id IN (SELECT id FROM {$table_active} WHERE extraction_id = %d)", $extraction_id));
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->db_manager->get_table($table_key . '_archive')} WHERE listing_id IN (SELECT id FROM {$table_archive} WHERE extraction_id = %d)", $extraction_id));
        }

        // Delete related data in one-to-many tables (media, rooms, open_houses)
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->db_manager->get_table('media')} WHERE listing_id IN (SELECT id FROM {$table_active} WHERE extraction_id = %d UNION SELECT id FROM {$table_archive} WHERE extraction_id = %d)", $extraction_id, $extraction_id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->db_manager->get_table('rooms')} WHERE listing_id IN (SELECT id FROM {$table_active} WHERE extraction_id = %d UNION SELECT id FROM {$table_archive} WHERE extraction_id = %d)", $extraction_id, $extraction_id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->db_manager->get_table('open_houses')} WHERE listing_id IN (SELECT id FROM {$table_active} WHERE extraction_id = %d UNION SELECT id FROM {$table_archive} WHERE extraction_id = %d)", $extraction_id, $extraction_id));

        return $deleted_active + $deleted_archive;
    }

    /**
     * Get extraction statistics for a given extraction ID.
     * This method now counts ALL listings associated with the extraction ID, regardless of price.
     *
     * @param int $extraction_id The ID of the extraction profile.
     * @return array An associative array of statistics.
     */
    public function get_extraction_stats($extraction_id) {
        global $wpdb;
        $table_active = $this->db_manager->get_table('listings');
        $table_archive = $this->db_manager->get_table('listings_archive');

        // Query to get total listings (count all, regardless of price)
        $total_listings_query = $wpdb->prepare(
            "SELECT COUNT(id) as counts FROM {$table_active} WHERE extraction_id = %d
             UNION ALL
             SELECT COUNT(id) as counts FROM {$table_archive} WHERE extraction_id = %d",
            $extraction_id, $extraction_id
        );
        // Log the query for debugging
        error_log("BME Debug: Total Listings Query for extraction_id {$extraction_id}: " . $total_listings_query);

        $total_listings = (int) $wpdb->get_var("SELECT SUM(counts) FROM ({$total_listings_query}) AS counts_union");
        // Log the result
        error_log("BME Debug: Total Listings Result for extraction_id {$extraction_id}: {$total_listings}");


        // Query to get price-related stats (only for listings with prices > 0)
        $price_stats_query = $wpdb->prepare(
            "SELECT
                AVG(CASE WHEN list_price > 0 THEN list_price ELSE NULL END) as avg_list_price,
                MIN(CASE WHEN list_price > 0 THEN list_price ELSE NULL END) as min_list_price,
                MAX(CASE WHEN list_price > 0 THEN list_price ELSE NULL END) as max_list_price,
                MIN(creation_timestamp) as oldest_listing_active,
                MAX(modification_timestamp) as newest_update_active
            FROM {$table_active} WHERE extraction_id = %d",
            $extraction_id
        );
        $price_stats_active = $wpdb->get_row($price_stats_query, ARRAY_A);

        $price_stats_archive_query = $wpdb->prepare(
            "SELECT
                AVG(CASE WHEN close_price > 0 THEN close_price ELSE NULL END) as avg_close_price,
                MIN(CASE WHEN close_price > 0 THEN close_price ELSE NULL END) as min_close_price,
                MAX(CASE WHEN close_price > 0 THEN close_price ELSE NULL END) as max_close_price,
                MIN(creation_timestamp) as oldest_listing_archive,
                MAX(modification_timestamp) as newest_update_archive
            FROM {$table_archive} WHERE extraction_id = %d",
            $extraction_id
        );
        $price_stats_archive = $wpdb->get_row($price_stats_archive_query, ARRAY_A);

        // Combine price stats, prioritizing active prices if available
        $avg_price = $price_stats_active['avg_list_price'] ?? $price_stats_archive['avg_close_price'] ?? 0;
        $min_price = min($price_stats_active['min_list_price'] ?? PHP_INT_MAX, $price_stats_archive['min_close_price'] ?? PHP_INT_MAX);
        $max_price = max($price_stats_active['max_list_price'] ?? 0, $price_stats_archive['max_close_price'] ?? 0);

        // Determine overall oldest and newest update timestamps
        $oldest_listing = null;
        if (!empty($price_stats_active['oldest_listing_active'])) {
            $oldest_listing = $price_stats_active['oldest_listing_active'];
        }
        if (!empty($price_stats_archive['oldest_listing_archive']) && ($oldest_listing === null || $price_stats_archive['oldest_listing_archive'] < $oldest_listing)) {
            $oldest_listing = $price_stats_archive['oldest_listing_archive'];
        }

        $newest_update = null;
        if (!empty($price_stats_active['newest_update_active'])) {
            $newest_update = $price_stats_active['newest_update_active'];
        }
        if (!empty($price_stats_archive['newest_update_archive']) && ($newest_update === null || $price_stats_archive['newest_update_archive'] > $newest_update)) {
            $newest_update = $price_stats_archive['newest_update_archive'];
        }


        $statuses_active = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT standard_status FROM {$table_active} WHERE extraction_id = %d", $extraction_id));
        $statuses_archive = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT standard_status FROM {$table_archive} WHERE extraction_id = %d", $extraction_id));
        $unique_statuses = count(array_unique(array_merge($statuses_active, $statuses_archive)));

        $stats = [
            'total_listings' => $total_listings,
            'avg_price' => round($avg_price, 2),
            'min_price' => ($min_price === PHP_INT_MAX) ? 0 : intval($min_price),
            'max_price' => intval($max_price),
            'oldest_listing' => $oldest_listing,
            'newest_update' => $newest_update,
            'unique_statuses' => $unique_statuses,
        ];

        return $stats;
    }

    public function delete_past_open_houses() {
        global $wpdb;
        $table = $this->db_manager->get_table('open_houses');

        $current_time_gmt = current_time('mysql', 1);

        $query = $wpdb->prepare(
            "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s",
            $current_time_gmt
        );

        return $wpdb->query($query);
    }

    /**
     * Get suggestions for live search autocomplete.
     * @param string $term The search term.
     * @return array Array of suggestion objects.
     */
    public function live_search_suggestions($term) {
        global $wpdb;
        $like_term = '%' . $wpdb->esc_like($term) . '%';
        $limit = 5; // Limit suggestions per query part to keep it fast

        $queries = [];
        $tables = [
            'listings' => $this->db_manager->get_table('listings'),
            'listings_archive' => $this->db_manager->get_table('listings_archive'),
            'listing_location' => $this->db_manager->get_table('listing_location'),
            'listing_location_archive' => $this->db_manager->get_table('listing_location_archive'),
            'agents' => $this->db_manager->get_table('agents'),
            'offices' => $this->db_manager->get_table('offices'),
        ];

        // MLS # (from active and archive)
        $queries[] = $wpdb->prepare("(SELECT listing_id AS value, CONCAT('MLS #: ', listing_id) AS label, 'listing_id' as type FROM {$tables['listings']} WHERE listing_id LIKE %s LIMIT %d)", $like_term, $limit);
        $queries[] = $wpdb->prepare("(SELECT listing_id AS value, CONCAT('MLS #: ', listing_id) AS label, 'listing_id' as type FROM {$tables['listings_archive']} WHERE listing_id LIKE %s LIMIT %d)", $like_term, $limit);

        // Address (from active and archive)
        $queries[] = $wpdb->prepare("(SELECT unparsed_address AS value, CONCAT('Address: ', unparsed_address) AS label, 'address' as type FROM {$tables['listing_location']} WHERE unparsed_address LIKE %s LIMIT %d)", $like_term, $limit);
        $queries[] = $wpdb->prepare("(SELECT unparsed_address AS value, CONCAT('Address: ', unparsed_address) AS label, 'address' as type FROM {$tables['listing_location_archive']} WHERE unparsed_address LIKE %s LIMIT %d)", $like_term, $limit);

        // Street Name (from active and archive)
        $queries[] = $wpdb->prepare("(SELECT street_name AS value, CONCAT('Street: ', street_name) AS label, 'street_name' as type FROM {$tables['listing_location']} WHERE street_name LIKE %s GROUP BY street_name LIMIT %d)", $like_term, $limit);
        $queries[] = $wpdb->prepare("(SELECT street_name AS value, CONCAT('Street: ', street_name) AS label, 'street_name' as type FROM {$tables['listing_location_archive']} WHERE street_name LIKE %s GROUP BY street_name LIMIT %d)", $like_term, $limit);

        // City (from active and archive)
        $queries[] = $wpdb->prepare("(SELECT city AS value, CONCAT('City: ', city) AS label, 'city' as type FROM {$tables['listing_location']} WHERE city LIKE %s GROUP BY city LIMIT %d)", $like_term, $limit);
        $queries[] = $wpdb->prepare("(SELECT city AS value, CONCAT('City: ', city) AS label, 'city' as type FROM {$tables['listing_location_archive']} WHERE city LIKE %s GROUP BY city LIMIT %d)", $like_term, $limit);

        // Postal Code (from active and archive)
        $queries[] = $wpdb->prepare("(SELECT postal_code AS value, CONCAT('Postal Code: ', postal_code) AS label, 'postal_code' as type FROM {$tables['listing_location']} WHERE postal_code LIKE %s GROUP BY postal_code LIMIT %d)", $like_term, $limit);
        $queries[] = $wpdb->prepare("(SELECT postal_code AS value, CONCAT('Postal Code: ', postal_code) AS label, 'postal_code' as type FROM {$tables['listing_location_archive']} WHERE postal_code LIKE %s GROUP BY postal_code LIMIT %d)", $like_term, $limit);

        // Agent Name
        $queries[] = $wpdb->prepare("(SELECT agent_full_name AS value, CONCAT('Agent: ', agent_full_name) AS label, 'agent' as type FROM {$tables['agents']} WHERE agent_full_name LIKE %s LIMIT %d)", $like_term, $limit);

        // Office Name
        $queries[] = $wpdb->prepare("(SELECT office_name AS value, CONCAT('Office: ', office_name) AS label, 'office' as type FROM {$tables['offices']} WHERE office_name LIKE %s LIMIT %d)", $like_term, $limit);

        $sql = implode(' UNION ALL ', $queries);
        $sql .= $wpdb->prepare(" LIMIT %d", 30); // Overall limit for suggestions

        $results = $wpdb->get_results($sql);

        // Deduplicate results based on the label to avoid showing the same thing twice
        $unique_results = [];
        if (is_array($results)) {
            foreach ($results as $result) {
                if (!isset($unique_results[$result->label])) {
                    $unique_results[$result->label] = $result;
                }
            }
        }

        return array_values($unique_results);
    }
    
    /**
     * Track property changes for history
     */
    private function track_property_changes($listing_id, $new_data, $existing_data, $extraction_id) {
        global $wpdb;
        $history_table = $this->db_manager->get_table('property_history');
        
        // Get address from location data if not in main data
        $unparsed_address = $new_data['unparsed_address'] ?? '';
        if (empty($unparsed_address) && !empty($new_data['id'])) {
            $location_table = $this->db_manager->get_table('listing_location');
            $unparsed_address = $wpdb->get_var($wpdb->prepare(
                "SELECT unparsed_address FROM {$location_table} WHERE listing_id = %d",
                $new_data['id']
            ));
        }
        
        // Track price changes
        if (isset($new_data['list_price']) && isset($existing_data['list_price']) && 
            $new_data['list_price'] != $existing_data['list_price']) {
            
            $wpdb->insert($history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'event_type' => 'price_change',
                'event_date' => current_time('mysql'),
                'field_name' => 'list_price',
                'old_value' => $existing_data['list_price'],
                'new_value' => $new_data['list_price'],
                'old_price' => $existing_data['list_price'],
                'new_price' => $new_data['list_price'],
                'extraction_log_id' => $extraction_id
            ]);
        }
        
        // Track status changes
        if (isset($new_data['standard_status']) && isset($existing_data['standard_status']) && 
            $new_data['standard_status'] != $existing_data['standard_status']) {
            
            $wpdb->insert($history_table, [
                'listing_id' => $listing_id,
                'unparsed_address' => $unparsed_address,
                'event_type' => 'status_change',
                'event_date' => current_time('mysql'),
                'field_name' => 'standard_status',
                'old_value' => $existing_data['standard_status'],
                'new_value' => $new_data['standard_status'],
                'old_status' => $existing_data['standard_status'],
                'new_status' => $new_data['standard_status'],
                'extraction_log_id' => $extraction_id
            ]);
        }
        
        // Track other significant changes
        $fields_to_track = [
            'bedrooms_total', 'bathrooms_full', 'bathrooms_half', 
            'living_area', 'lot_size_acres', 'property_sub_type'
        ];
        
        foreach ($fields_to_track as $field) {
            if (isset($new_data[$field]) && isset($existing_data[$field]) && 
                $new_data[$field] != $existing_data[$field]) {
                
                $wpdb->insert($history_table, [
                    'listing_id' => $listing_id,
                    'unparsed_address' => $unparsed_address,
                    'event_type' => 'field_change',
                    'event_date' => current_time('mysql'),
                    'field_name' => $field,
                    'old_value' => $existing_data[$field],
                    'new_value' => $new_data[$field],
                    'extraction_log_id' => $extraction_id
                ]);
            }
        }
    }
    
    /**
     * Track new listing
     */
    private function track_new_listing($listing_id, $data, $extraction_id) {
        global $wpdb;
        $history_table = $this->db_manager->get_table('property_history');
        
        // Get address
        $unparsed_address = $data['unparsed_address'] ?? '';
        
        // Determine the actual event date
        // Priority: creation_timestamp > original_entry_timestamp > listing_contract_date > current time
        $event_date = null;
        if (!empty($data['creation_timestamp'])) {
            $event_date = $data['creation_timestamp'];
        } elseif (!empty($data['original_entry_timestamp'])) {
            $event_date = $data['original_entry_timestamp'];
        } elseif (!empty($data['listing_contract_date'])) {
            $event_date = $data['listing_contract_date'] . ' 00:00:00';
        } else {
            $event_date = current_time('mysql');
        }
        
        $wpdb->insert($history_table, [
            'listing_id' => $listing_id,
            'unparsed_address' => $unparsed_address,
            'event_type' => 'new_listing',
            'event_date' => $event_date,
            'field_name' => 'initial_listing',
            'new_value' => $data['list_price'] ?? 0,
            'new_price' => $data['list_price'] ?? 0,
            'new_status' => $data['standard_status'] ?? 'Active',
            'extraction_log_id' => $extraction_id
        ]);
    }
    
    /**
     * Determine if a listing should be archived based on its status
     */
    private function is_listing_archived($listing_data) {
        $archived_statuses = ['Closed', 'Expired', 'Withdrawn', 'Pending', 'Canceled', 'Active Under Contract'];
        $status = $listing_data['StandardStatus'] ?? '';
        return in_array($status, $archived_statuses);
    }
}