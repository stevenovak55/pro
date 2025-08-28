<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles registration of custom post types
 */
class BME_Post_Types {
    
    /**
     * Register all custom post types
     */
    public function register() {
        $this->register_extraction_cpt();
    }
    
    /**
     * Register the extraction profile custom post type
     */
    private function register_extraction_cpt() {
        $labels = [
            'name'                  => _x('Extraction Profiles', 'Post Type General Name', 'bridge-mls-extractor-pro'),
            'singular_name'         => _x('Extraction Profile', 'Post Type Singular Name', 'bridge-mls-extractor-pro'),
            'menu_name'             => __('Extraction Profiles', 'bridge-mls-extractor-pro'),
            'name_admin_bar'        => __('Extraction Profile', 'bridge-mls-extractor-pro'),
            'archives'              => __('Extraction Archives', 'bridge-mls-extractor-pro'),
            'attributes'            => __('Extraction Attributes', 'bridge-mls-extractor-pro'),
            'parent_item_colon'     => __('Parent Extraction:', 'bridge-mls-extractor-pro'),
            'all_items'             => __('All Extractions', 'bridge-mls-extractor-pro'),
            'add_new_item'          => __('Add New Extraction', 'bridge-mls-extractor-pro'),
            'add_new'               => __('Add New', 'bridge-mls-extractor-pro'),
            'new_item'              => __('New Extraction', 'bridge-mls-extractor-pro'),
            'edit_item'             => __('Edit Extraction', 'bridge-mls-extractor-pro'),
            'update_item'           => __('Update Extraction', 'bridge-mls-extractor-pro'),
            'view_item'             => __('View Extraction', 'bridge-mls-extractor-pro'),
            'view_items'            => __('View Extractions', 'bridge-mls-extractor-pro'),
            'search_items'          => __('Search Extractions', 'bridge-mls-extractor-pro'),
            'not_found'             => __('No extractions found', 'bridge-mls-extractor-pro'),
            'not_found_in_trash'    => __('No extractions found in Trash', 'bridge-mls-extractor-pro'),
            'featured_image'        => __('Featured Image', 'bridge-mls-extractor-pro'),
            'set_featured_image'    => __('Set featured image', 'bridge-mls-extractor-pro'),
            'remove_featured_image' => __('Remove featured image', 'bridge-mls-extractor-pro'),
            'use_featured_image'    => __('Use as featured image', 'bridge-mls-extractor-pro'),
            'insert_into_item'      => __('Insert into extraction', 'bridge-mls-extractor-pro'),
            'uploaded_to_this_item' => __('Uploaded to this extraction', 'bridge-mls-extractor-pro'),
            'items_list'            => __('Extractions list', 'bridge-mls-extractor-pro'),
            'items_list_navigation' => __('Extractions list navigation', 'bridge-mls-extractor-pro'),
            'filter_items_list'     => __('Filter extractions list', 'bridge-mls-extractor-pro'),
        ];
        
        $args = [
            'label'                 => __('Extraction Profile', 'bridge-mls-extractor-pro'),
            'description'           => __('MLS Data Extraction Profiles', 'bridge-mls-extractor-pro'),
            'labels'                => $labels,
            'supports'              => ['title'],
            'taxonomies'            => [],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => false, // We handle the menu ourselves
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post',
            'map_meta_cap'          => true,
            'rewrite'               => false,
            'show_in_rest'          => false,
        ];
        
        register_post_type('bme_extraction', $args);
    }
}