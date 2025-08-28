<?php
/**
 * Uninstall script for Bridge MLS Extractor Pro
 *
 * This file is executed when the plugin is deleted from the WordPress admin.
 * It will only run if the user has explicitly opted to delete all data.
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if user has opted to delete all data on uninstall
$delete_data = get_option('bme_pro_delete_on_uninstall', false);

if ($delete_data) {
    // Define plugin path for require_once
    $plugin_file = WP_PLUGIN_DIR . '/' . plugin_basename(__DIR__) . '/bridge-mls-extractor-pro.php';

    if (file_exists($plugin_file)) {
        require_once $plugin_file;
        
        // Call the centralized cleanup method
        if (class_exists('Bridge_MLS_Extractor_Pro') && method_exists('Bridge_MLS_Extractor_Pro', 'cleanup_plugin_data')) {
            Bridge_MLS_Extractor_Pro::cleanup_plugin_data();
        }
    }
}
