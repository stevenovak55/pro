<?php
/**
 * Plugin Name: Bridge MLS Extractor Pro - Optimized
 * Description: High-performance MLS data extraction with normalized database architecture and concurrent API processing
 * Version: 3.3
 * Author: Professional Developer
 * Text Domain: bridge-mls-extractor-pro
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('BME_PRO_VERSION', '3.3');
define('BME_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BME_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BME_CACHE_GROUP', 'bme_pro');
define('BME_API_TIMEOUT', 60);
define('BME_BATCH_SIZE', 100);
define('BME_CACHE_DURATION', 3600); // 1 hour

// Explicitly require core class files
require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-cache-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-api-client.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-data-processor.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-extraction-engine.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-admin.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-cron-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-post-types.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-virtual-tour-importer.php'; // New: Require Virtual Tour Importer
require_once BME_PLUGIN_DIR . 'includes/class-bme-address-normalizer.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-property-history-tracker.php';

/**
 * Main plugin class implementing singleton pattern
 */
final class Bridge_MLS_Extractor_Pro {

    private static $instance = null;
    private $container = [];

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_services();
    }

    /**
     * Initialize dependency injection container.
     */
    private function init_services() {
        $this->container['db'] = new BME_Database_Manager();
        $this->container['cache'] = new BME_Cache_Manager();
        $this->container['api'] = new BME_API_Client();
        $this->container['processor'] = new BME_Data_Processor($this->container['db'], $this->container['cache']);
        $this->container['extractor'] = new BME_Extraction_Engine($this->container['api'], $this->container['processor'], $this->container['cache']);
        // New: Add Virtual Tour Importer to the container
        $this->container['vt_importer'] = new BME_Virtual_Tour_Importer($this->container['db']);
    }

    /**
     * Get service from container.
     */
    public function get($service) {
        if (!isset($this->container[$service])) {
            error_log("BME Error: Attempted to get service '{$service}' but it was not found in the container.");
            throw new Exception("Service {$service} not found");
        }
        return $this->container[$service];
    }

    /**
     * Checks if the database is installed and up-to-date. Runs on every load.
     * This acts as a safeguard against failed activations or manual updates.
     */
    public function check_and_install_db() {
        $installed_version = get_option('bme_pro_version');
        if (version_compare($installed_version, BME_PRO_VERSION, '<')) {
            self::activate_plugin();
        }
    }

    /**
     * Plugin activation: Creates tables and schedules cron jobs.
     */
    public static function activate_plugin() {
        // Manually require the Database Manager as autoloader might not be ready.
        require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
        // New: Manually require Virtual Tour Importer
        require_once BME_PLUGIN_DIR . 'includes/class-bme-virtual-tour-importer.php';

        try {
            $db_manager = new BME_Database_Manager();
            $db_manager->create_tables();
            $db_manager->verify_installation();

            // Set activation flag and update version at the end of successful installation
            update_option('bme_pro_activated', true);
            update_option('bme_pro_version', BME_PRO_VERSION);

            // Schedule cron
            if (!wp_next_scheduled('bme_pro_cron_hook')) {
                wp_schedule_event(time(), 'every_15_minutes', 'bme_pro_cron_hook');
            }
            // New: Schedule Virtual Tour import cron
            if (!wp_next_scheduled('bme_pro_import_virtual_tours_hook')) {
                wp_schedule_event(time(), 'daily', 'bme_pro_import_virtual_tours_hook');
            }

        } catch (Exception $e) {
            error_log('BME Pro Activation Error: ' . $e->getMessage());
            set_transient('bme_pro_activation_error', 'Plugin activation failed: ' . $e->getMessage(), 60);
        }
    }

    /**
     * Plugin deactivation: Clears cron and optionally deletes all data.
     */
    public function deactivate() {
        wp_clear_scheduled_hook('bme_pro_cron_hook');
        // New: Clear Virtual Tour import cron
        wp_clear_scheduled_hook('bme_pro_import_virtual_tours_hook');

        $delete_on_deactivation = get_option('bme_pro_delete_on_deactivation', false);

        if ($delete_on_deactivation) {
            self::cleanup_plugin_data();
            delete_option('bme_pro_delete_on_deactivation');
        }
    }

    /**
     * Centralized function to clean up all plugin data.
     */
    public static function cleanup_plugin_data() {
        global $wpdb;

        try {
            if (!class_exists('BME_Database_Manager')) {
                require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
            }
            $db_manager = new BME_Database_Manager();
            $tables = $db_manager->get_tables();

            foreach (array_reverse($tables) as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }

            $posts = get_posts(['post_type' => 'bme_extraction', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']);
            foreach ($posts as $post_id) {
                wp_delete_post($post_id, true);
            }

            $options_to_delete = [
                'bme_pro_api_credentials', 'bme_pro_performance_settings', 'bme_pro_delete_on_uninstall',
                'bme_pro_delete_on_deactivation', 'bme_pro_activated', 'bme_pro_version',
                'bme_pro_cron_stats', 'bme_pro_cron_activity', 'bme_pro_last_cron_check',
                'bme_pro_vt_file_url' // New: Delete Virtual Tour URL option
            ];
            foreach ($options_to_delete as $option) {
                delete_option($option);
            }

            $cron_hooks = [ 'bme_pro_cron_hook', 'bme_pro_cleanup_hook', 'bme_pro_cache_cleanup', 'bme_pro_import_virtual_tours_hook' ]; // New: Add VT import hook
            foreach ($cron_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }

            $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'bme_pro_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bme_pro_%' OR option_name LIKE '_transient_timeout_bme_pro_%'");

            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group(BME_CACHE_GROUP);
            }

            error_log('Bridge MLS Extractor Pro: Plugin data cleanup completed successfully.');

        } catch (Exception $e) {
            error_log('Bridge MLS Extractor Pro: Cleanup error - ' . $e->getMessage());
        }
    }

    /**
     * Initialize plugin (runs on 'init' action)
     */
    public function init() {
        $cpt = new BME_Post_Types();
        $cpt->register();
        load_plugin_textdomain('bridge-mls-extractor-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function __clone() { _doing_it_wrong(__FUNCTION__, 'Singleton pattern violation', BME_PRO_VERSION); }
    public function __wakeup() { _doing_it_wrong(__FUNCTION__, 'Singleton pattern violation', BME_PRO_VERSION); }
}

/**
 * Global function to return the main plugin instance
 */
function bme_pro() {
    return Bridge_MLS_Extractor_Pro::instance();
}

/**
 * Initializes the plugin and its hooks.
 */
function bme_pro_init() {
    $plugin_instance = bme_pro();

    // Run the database health check on every load.
    $plugin_instance->check_and_install_db();

    // Register primary hooks
    register_activation_hook(__FILE__, ['Bridge_MLS_Extractor_Pro', 'activate_plugin']);
    register_deactivation_hook(__FILE__, [$plugin_instance, 'deactivate']);
    add_action('init', [$plugin_instance, 'init']);

    // Instantiate admin and cron components
    if (is_admin()) {
        new BME_Admin($plugin_instance);
    }
    // New: Pass virtual tour importer to cron manager
    new BME_Cron_Manager($plugin_instance->get('extractor'), $plugin_instance->get('vt_importer'));
}
add_action('plugins_loaded', 'bme_pro_init');