<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimized admin interface with enhanced performance and UX
 * Version: 2.3.9 (Fixed Undefined Constant and Save Post Callback)
 */
class BME_Admin {

    private $plugin;
    private $cache_manager;

    public function __construct(Bridge_MLS_Extractor_Pro $plugin) {
        $this->plugin = $plugin;
        $this->cache_manager = $plugin->get('cache');
        $this->init_hooks();
    }

    /**
     * Initialize admin hooks
     */
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        // Corrected: Using static method for save_post hook for robustness
        add_action('save_post_bme_extraction', ['BME_Admin', 'save_extraction_meta_static'], 10, 2);

        add_filter('manage_bme_extraction_posts_columns', [$this, 'set_extraction_columns']);
        add_action('manage_bme_extraction_posts_custom_column', [$this, 'display_extraction_column'], 10, 2);

        // Admin actions
        add_action('admin_post_bme_run_extraction', [$this, 'handle_run_extraction']);
        add_action('admin_post_bme_run_resync', [$this, 'handle_run_resync']);
        add_action('admin_post_bme_clear_data', [$this, 'handle_clear_data']);
        add_action('admin_post_bme_test_config', [$this, 'handle_test_config']);
        add_action('admin_post_bme_export_listings_csv', [$this, 'handle_export_listings_csv']);
        add_action('admin_post_bme_run_vt_import', [$this, 'handle_run_vt_import']);

        add_action('load-mls-extractions_page_bme-database-browser', [$this, 'handle_database_browser_bulk_actions']);

        // AJAX handlers
        add_action('wp_ajax_bme_get_filter_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_bme_search_listings', [$this, 'ajax_search_listings']);
        add_action('wp_ajax_bme_get_extraction_stats', [$this, 'ajax_get_extraction_stats']);
        add_action('wp_ajax_bme_live_search', [$this, 'ajax_live_search']);
        add_action('wp_ajax_bme_get_live_extraction_progress', [$this, 'ajax_get_live_extraction_progress']);

        add_action('admin_notices', [$this, 'display_admin_notices']);
        add_action('admin_notices', [$this, 'display_vt_import_notices']);
    }

    /**
     * Add admin menus
     */
    public function add_admin_menus() {
        add_menu_page(
            __('MLS Extractions Pro', 'bridge-mls-extractor-pro'),
            __('MLS Extractions', 'bridge-mls-extractor-pro'),
            'manage_options',
            'edit.php?post_type=bme_extraction',
            '',
            'dashicons-database-export',
            25
        );

        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Database Browser', 'bridge-mls-extractor-pro'),
            __('Database Browser', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-database-browser',
            [$this, 'render_database_browser']
        );

        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Performance Dashboard', 'bridge-mls-extractor-pro'),
            __('Performance Dashboard', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-performance',
            [$this, 'render_performance_dashboard']
        );

        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Activity Logs', 'bridge-mls-extractor-pro'),
            __('Activity Logs', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-activity-logs',
            [$this, 'render_activity_logs']
        );

        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Settings', 'bridge-mls-extractor-pro'),
            __('Settings', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue admin assets with cache busting
     */
    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();

        if (!$screen || strpos($screen->id, 'bme') === false) {
            return;
        }

        $version = defined('BME_PRO_VERSION') ? BME_PRO_VERSION : '1.0';
        $css_file_path = BME_PLUGIN_DIR . 'assets/admin.css';
        $js_file_path = BME_PLUGIN_DIR . 'assets/admin.js';

        $css_version = $version . '.' . (file_exists($css_file_path) ? filemtime($css_file_path) : '');
        $js_version = $version . '.' . (file_exists($js_file_path) ? filemtime($js_file_path) : '');

        wp_enqueue_style('bme-admin', BME_PLUGIN_URL . 'assets/admin.css', [], $css_version);
        // Add jquery-ui-autocomplete as a dependency for our admin script
        wp_enqueue_script('bme-admin', BME_PLUGIN_URL . 'assets/admin.js', ['jquery', 'wp-util', 'jquery-ui-autocomplete'], $js_version, true);

        wp_localize_script('bme-admin', 'bmeAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bme_admin_nonce'),
            'strings' => [
                'confirmClear' => __('Are you sure? This will delete all data for this extraction.', 'bridge-mls-extractor-pro'),
                'confirmResync' => __('This will clear existing data and re-download everything. Continue?', 'bridge-mls-extractor-pro'),
                'loading' => __('Loading...', 'bridge-mls-extractor-pro'),
                'error' => __('An error occurred. Please try again.', 'bridge-mls-extractor-pro'),
                'allCitiesWarning' => __('You have not specified any cities. This will extract listings from ALL cities in the selected states. Are you sure you want to proceed?', 'bridge-mls-extractor-pro'),
                'saveFirst' => __('Please save your changes before running an extraction.', 'bridge-mls-extractor-pro'),
                'liveProgressTitle' => __('Live Extraction Progress', 'bridge-mls-extractor-pro'),
                'currentStatus' => __('Status:', 'bridge-mls-extractor-pro'),
                'totalProcessed' => __('Processed:', 'bridge-mls-extractor-pro'),
                'currentListing' => __('Current:', 'bridge-mls-extractor-pro'),
                'lastUpdated' => __('Last Update:', 'bridge-mls-extractor-pro'),
                'duration' => __('Duration:', 'bridge-mls-extractor-pro'),
                'propertyTypes' => __('Property Types:', 'bridge-mls-extractor-pro'),
            ]
        ]);

        // Enqueue Select2 and jQuery UI styles specifically for the browser page
        if (strpos($hook, 'bme-database-browser') !== false) {
            wp_enqueue_style('jquery-ui-style', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0-rc.0', true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0-rc.0');
        }
    }

    /**
     * Add meta boxes for extraction CPT
     */
    public function add_meta_boxes() {
        add_meta_box(
            'bme_extraction_config',
            __('Extraction Configuration', 'bridge-mls-extractor-pro'),
            [$this, 'render_extraction_config_meta_box'],
            'bme_extraction',
            'normal',
            'high'
        );

        add_meta_box(
            'bme_extraction_stats',
            __('Statistics & Actions', 'bridge-mls-extractor-pro'),
            [$this, 'render_extraction_stats_meta_box'],
            'bme_extraction',
            'side',
            'default'
        );

        // New: Live Progress Meta Box
        add_meta_box(
            'bme_extraction_live_progress',
            __('Live Progress', 'bridge-mls-extractor-pro'),
            [$this, 'render_live_progress_meta_box'],
            'bme_extraction',
            'side',
            'high'
        );
    }

    /**
     * Render extraction configuration meta box with enhanced validation logic
     */
    public function render_extraction_config_meta_box($post) {
        wp_nonce_field('bme_save_extraction_meta', 'bme_extraction_nonce');

        $config = [
            'statuses' => get_post_meta($post->ID, '_bme_statuses', true) ?: [],
            'cities' => get_post_meta($post->ID, '_bme_cities', true),
            'states' => get_post_meta($post->ID, '_bme_states', true) ?: [],
            'list_agent_id' => get_post_meta($post->ID, '_bme_list_agent_id', true),
            'buyer_agent_id' => get_post_meta($post->ID, '_bme_buyer_agent_id', true),
            'lookback_months' => get_post_meta($post->ID, '_bme_lookback_months', true) ?: 12,
            'schedule' => get_post_meta($post->ID, '_bme_schedule', true) ?: 'none'
        ];

        ?>
        <div id="bme-unsaved-changes-notice" class="notice notice-warning inline" style="display: none;">
            <p><?php _e('You have unsaved changes. Please save or update the profile.', 'bridge-mls-extractor-pro'); ?></p>
        </div>
        <table class="form-table bme-config-table">
            <tr>
                <th><label for="bme_schedule"><?php _e('Schedule', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <select name="bme_schedule" id="bme_schedule" class="regular-text">
                        <?php
                        $schedules = array_merge(['none' => ['display' => __('Manual Only', 'bridge-mls-extractor-pro')]], wp_get_schedules());
                        $allowed_schedules = ['none', 'every_15_minutes', 'every_30_minutes', 'hourly', 'twicedaily', 'daily'];

                        foreach ($schedules as $key => $details) {
                            if (in_array($key, $allowed_schedules)) {
                                printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($config['schedule'], $key, false), esc_html($details['display']));
                            }
                        }
                        ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label><?php _e('Listing Statuses', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <p class="description"><?php _e('Select statuses from ONE group. Active/Pending are for current listings. Closed/Archived are for historical data and require a lookback period.', 'bridge-mls-extractor-pro'); ?></p>
                    <fieldset id="bme-statuses">
                        <div style="margin-bottom: 15px;">
                            <strong><?php _e('Active / Pending Group', 'bridge-mls-extractor-pro'); ?></strong><br>
                            <?php
                            $active_statuses = ['Active', 'Active Under Contract', 'Pending'];
                            foreach ($active_statuses as $status) {
                                printf('<label><input type="checkbox" name="bme_statuses[]" value="%s" %s data-group="active"> %s</label><br>', esc_attr($status), checked(in_array($status, $config['statuses']), true, false), esc_html($status));
                            }
                            ?>
                        </div>
                        <div>
                            <strong><?php _e('Closed / Archived Group', 'bridge-mls-extractor-pro'); ?></strong><br>
                            <?php
                            $archived_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled'];
                            foreach ($archived_statuses as $status) {
                                printf('<label><input type="checkbox" name="bme_statuses[]" value="%s" %s data-group="archived"> %s</label><br>', esc_attr($status), checked(in_array($status, $config['statuses']), true, false), esc_html($status));
                            }
                            ?>
                        </div>
                    </fieldset>
                </td>
            </tr>

            <tr id="bme-lookback-row" style="display: none;">
                <th><label for="bme_lookback_months"><?php _e('Archived Listings Lookback', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="number" name="bme_lookback_months" id="bme_lookback_months" value="<?php echo esc_attr($config['lookback_months']); ?>" class="small-text" min="1" max="120" step="1">
                    <span><?php _e('months', 'bridge-mls-extractor-pro'); ?></span>
                    <p class="description"><?php _e('Required. How many months back to search for archived listings.', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="bme_cities"><?php _e('Cities', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <textarea name="bme_cities" id="bme_cities" rows="3" class="large-text" placeholder="Boston, Cambridge, Somerville"><?php echo esc_textarea($config['cities']); ?></textarea>
                    <p class="description"><?php _e('Comma-separated list. Leave blank to include all cities (a confirmation will be required).', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('States/Provinces', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <fieldset>
                        <?php
                        $state_options = ['MA', 'NH', 'RI', 'VT', 'CT', 'ME'];
                        foreach ($state_options as $state) {
                            printf('<label><input type="checkbox" name="bme_states[]" value="%s" %s> %s</label> ', esc_attr($state), checked(in_array($state, $config['states']), true, false), esc_html($state));
                        }
                        ?>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th><label for="bme_list_agent_id"><?php _e('List Agent MLS ID', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="text" name="bme_list_agent_id" id="bme_list_agent_id" value="<?php echo esc_attr($config['list_agent_id']); ?>" class="regular-text">
                </td>
            </tr>
            <tr id="bme-buyer-agent-row" style="display: none;">
                <th><label for="bme_buyer_agent_id"><?php _e('Buyer Agent MLS ID', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="text" name="bme_buyer_agent_id" id="bme_buyer_agent_id" value="<?php echo esc_attr($config['buyer_agent_id']); ?>" class="regular-text">
                </td>
            </tr>
        </table>

        <script>
        jQuery(document).ready(function($) {
            const form = $('#post');
            const statusesFieldset = $('#bme-statuses');
            let isDirty = false;

            function setDirty(dirty) {
                isDirty = dirty;
                $('#bme-unsaved-changes-notice').toggle(dirty);
                $('.bme-action-button').prop('disabled', dirty).toggleClass('disabled', dirty);
                if(dirty) {
                    $('.bme-action-button').attr('title', bmeAdmin.strings.saveFirst);
                } else {
                    $('.bme-action-button').removeAttr('title');
                }
            }

            form.on('change keyup', 'input, select, textarea', function() {
                setDirty(true);
            });

            setDirty(false);

            function handleStatusSelection() {
                const checked = statusesFieldset.find('input:checked');
                const firstCheckedGroup = checked.length > 0 ? checked.first().data('group') : null;

                statusesFieldset.find('input').each(function() {
                    const currentGroup = $(this).data('group');
                    $(this).prop('disabled', firstCheckedGroup && currentGroup !== firstCheckedGroup);
                });

                $('#bme-lookback-row').toggle(firstCheckedGroup === 'archived');

                const showBuyerAgent = checked.is('[value="Active Under Contract"], [value="Pending"], [value="Closed"]');
                $('#bme-buyer-agent-row').toggle(showBuyerAgent);
            }

            statusesFieldset.on('change', 'input', handleStatusSelection);
            handleStatusSelection();

            form.on('submit', function(e) {
                const isArchivedSelected = statusesFieldset.find('input[data-group="archived"]:checked').length > 0;
                const lookbackInput = $('#bme_lookback_months');
                if (isArchivedSelected && (!lookbackInput.val() || parseInt(lookbackInput.val(), 10) <= 0)) {
                    alert('<?php _e('The "Archived Listings Lookback" is required and must be greater than 0 when selecting a Closed/Archived status.', 'bridge-mls-extractor-pro'); ?>');
                    lookbackInput.focus();
                    e.preventDefault();
                    return false;
                }

                const citiesInput = $('#bme_cities');
                if (citiesInput.val().trim() === '') {
                    if (!confirm(bmeAdmin.strings.allCitiesWarning)) {
                        citiesInput.focus();
                        e.preventDefault();
                        return false;
                    }
                }

                setDirty(false);
            });

            $(document).on('click', '.bme-action-button', function(e) {
                if (isDirty) {
                    e.preventDefault();
                    alert(bmeAdmin.strings.saveFirst);
                    return false;
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render extraction statistics meta box
     */
    public function render_extraction_stats_meta_box($post) {
        $stats = $this->cache_manager->get_extraction_stats($post->ID);

        if ($stats === false) {
            $data_processor = $this->plugin->get('processor');
            $stats = $data_processor->get_extraction_stats($post->ID);
            $this->cache_manager->cache_extraction_stats($post->ID, $stats);
        }

        $last_run_status = get_post_meta($post->ID, '_bme_last_run_status', true);

        ?>
        <div class="bme-stats-grid">
             <div class="bme-stat-item">
                <div class="bme-stat-value"><?php echo esc_html(number_format($stats['total_listings'] ?? 0)); ?></div>
                <div class="bme-stat-label"><?php _e('Listings', 'bridge-mls-extractor-pro'); ?></div>
            </div>
            <div class="bme-stat-item">
                <div class="bme-stat-value <?php echo esc_attr($this->get_status_class($last_run_status)); ?>">
                    <?php echo esc_html($last_run_status ?: __('Never', 'bridge-mls-extractor-pro')); ?>
                </div>
                <div class="bme-stat-label"><?php _e('Last Run', 'bridge-mls-extractor-pro'); ?></div>
            </div>
        </div>

        <div class="bme-actions">
            <?php
            $run_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_extraction&post_id=' . $post->ID), 'bme_run_extraction_' . $post->ID);
            $resync_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_resync&post_id=' . $post->ID), 'bme_run_resync_' . $post->ID);
            $clear_url = wp_nonce_url(admin_url('admin-post.php?action=bme_clear_data&post_id=' . $post->ID), 'bme_clear_data_' . $post->ID);
            $test_url = wp_nonce_url(admin_url('admin-post.php?action=bme_test_config&post_id=' . $post->ID), 'bme_test_config_' . $post->ID);
            ?>
            <a href="<?php echo esc_url($test_url); ?>" class="button button-secondary bme-action-button"><?php _e('Test Config', 'bridge-mls-extractor-pro'); ?></a>
            <a href="<?php echo esc_url($run_url); ?>" class="button button-primary bme-action-button" id="bme-run-extraction-button" data-extraction-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Run Now', 'bridge-mls-extractor-pro'); ?></a>
            <a href="<?php echo esc_url($resync_url); ?>" class="button button-secondary bme-action-button bme-confirm-resync" id="bme-resync-extraction-button" data-extraction-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Full Resync', 'bridge-mls-extractor-pro'); ?></a>
            <a href="<?php echo esc_url($clear_url); ?>" class="button button-link-delete bme-action-button bme-confirm-clear"><?php _e('Clear Data', 'bridge-mls-extractor-pro'); ?></a>
        </div>
        <?php
    }

    /**
     * New: Render Live Progress Meta Box
     */
    public function render_live_progress_meta_box($post) {
        $progress = $this->plugin->get('extractor')->get_live_progress($post->ID);
        $is_running = ($progress && $progress['status'] === 'running');
        ?>
        <div id="bme-live-progress-container" data-extraction-id="<?php echo esc_attr($post->ID); ?>">
            <div id="bme-live-progress-content" style="<?php echo $is_running ? '' : 'display: none;'; ?>">
                <p><strong><?php esc_html_e('Status:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-status"></span></p>
                <p><strong><?php esc_html_e('Processed:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-processed"></span></p>
                <p><strong><?php esc_html_e('Current:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-current-listing"></span></p>
                <p><strong><?php esc_html_e('Last Update:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-last-updated"></span></p>
                <p><strong><?php esc_html_e('Duration:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-duration"></span></p>
                <p><strong><?php esc_html_e('Property Types:', 'bridge-mls-extractor-pro'); ?></strong> <br><span id="bme-live-property-types"></span></p>
                <p id="bme-live-message"></p>
                <p id="bme-live-error-message" style="color: red;"></p>
            </div>
            <div id="bme-live-progress-not-running" style="<?php echo $is_running ? 'display: none;' : ''; ?>">
                <p><?php _e('No active extraction running for this profile.', 'bridge-mls-extractor-pro'); ?></p>
            </div>
        </div>
        <script>
            // Pass initial state to JS
            window.bmeLiveProgressInitialState = <?php echo json_encode($progress); ?>;
        </script>
        <?php
    }

    /**
     * Save extraction meta data with enhanced validation
     */
    public static function save_extraction_meta_static($post_id, $post) { // Made static
        if (!isset($_POST['bme_extraction_nonce']) || !wp_verify_nonce($_POST['bme_extraction_nonce'], 'bme_save_extraction_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if ($post->post_type !== 'bme_extraction') return;

        // Get plugin instance via global function
        $plugin_instance = bme_pro();
        $cache_manager = $plugin_instance->get('cache');

        $statuses = isset($_POST['bme_statuses']) && is_array($_POST['bme_statuses']) ? array_map('sanitize_text_field', wp_unslash($_POST['bme_statuses'])) : [];

        $active_group = ['Active', 'Active Under Contract', 'Pending'];
        $archived_group = ['Closed', 'Expired', 'Withdrawn', 'Canceled'];

        if (!empty(array_intersect($statuses, $active_group)) && !empty(array_intersect($statuses, $archived_group))) {
            add_settings_error('bme_pro_settings', 'status_conflict', __('Error: You cannot select statuses from both Active and Archived groups in the same extraction.', 'bridge-mls-extractor-pro'), 'error');
            set_transient('settings_errors', get_settings_errors(), 30);
            return;
        }

        $lookback_months = isset($_POST['bme_lookback_months']) ? absint($_POST['bme_lookback_months']) : 0;
        if (!empty(array_intersect($statuses, $archived_group)) && $lookback_months <= 0) {
            add_settings_error('bme_pro_settings', 'lookback_required', __('Error: The "Archived Listings Lookback" is required and must be greater than 0 for the selected statuses.', 'bridge-mls-extractor-pro'), 'error');
            set_transient('settings_errors', get_settings_errors(), 30);
            return;
        }

        update_post_meta($post_id, '_bme_statuses', $statuses);
        update_post_meta($post_id, '_bme_lookback_months', $lookback_months);

        $fields_to_save = [
            '_bme_schedule' => 'sanitize_key',
            '_bme_cities' => 'sanitize_textarea_field',
            '_bme_list_agent_id' => 'sanitize_text_field',
            '_bme_buyer_agent_id' => 'sanitize_text_field',
        ];

        foreach ($fields_to_save as $meta_key => $sanitize_callback) {
            $post_key = str_replace('_bme_', 'bme_', $meta_key);
            if (isset($_POST[$post_key])) {
                update_post_meta($post_id, $meta_key, call_user_func($sanitize_callback, $_POST[$post_key]));
            }
        }

        $states = isset($_POST['bme_states']) && is_array($_POST['bme_states']) ? array_map('sanitize_text_field', wp_unslash($_POST['bme_states'])) : [];
        update_post_meta($post_id, '_bme_states', $states);

        $cache_manager->delete('extraction_stats_' . $post_id); // Use the retrieved cache_manager instance
    }

    /**
     * Set custom columns for extraction list
     */
    public function set_extraction_columns($columns) {
        return [
            'cb' => $columns['cb'],
            'title' => $columns['title'],
            'schedule' => __('Schedule', 'bridge-mls-extractor-pro'),
            'listings_count' => __('Listings', 'bridge-mls-extractor-pro'),
            'last_run' => __('Last Run', 'bridge-mls-extractor-pro'),
            'performance' => __('Performance', 'bridge-mls-extractor-pro'),
            'actions' => __('Actions', 'bridge-mls-extractor-pro'),
            'date' => $columns['date']
        ];
    }

    /**
     * Display custom column content
     */
    public function display_extraction_column($column, $post_id) {
        switch ($column) {
            case 'schedule':
                $schedule_key = get_post_meta($post_id, '_bme_schedule', true) ?: 'none';
                if ($schedule_key === 'none') {
                    echo '<span class="bme-schedule-disabled">' . esc_html__('Manual', 'bridge-mls-extractor-pro') . '</span>';
                } else {
                    $schedules = wp_get_schedules();
                    $display = $schedules[$schedule_key]['display'] ?? ucfirst($schedule_key);
                    echo '<span class="bme-schedule-active">' . esc_html($display) . '</span>';
                }
                break;
            case 'listings_count':
                $stats = $this->cache_manager->get_extraction_stats($post_id);
                if (!$stats) {
                    $data_processor = $this->plugin->get('processor');
                    $stats = $data_processor->get_extraction_stats($post_id);
                    $this->cache_manager->cache_extraction_stats($post_id, $stats);
                }
                echo '<strong>' . esc_html(number_format($stats['total_listings'] ?? 0)) . '</strong>';
                break;
            case 'last_run':
                $status = get_post_meta($post_id, '_bme_last_run_status', true);
                $time = get_post_meta($post_id, '_bme_last_run_time', true);
                if ($status && $time) {
                    printf(
                        '<div class="bme-last-run %s"><strong>%s</strong><br><small>%s ago</small></div>',
                        esc_attr($this->get_status_class($status)),
                        esc_html($status),
                        esc_html(human_time_diff($time))
                    );
                } else {
                    echo '<span class="bme-never">' . esc_html__('Never', 'bridge-mls-extractor-pro') . '</span>';
                }
                break;
            case 'performance':
                $duration = get_post_meta($post_id, '_bme_last_run_duration', true);
                $count = get_post_meta($post_id, '_bme_last_run_count', true);
                if ($duration > 0 && $count > 0) {
                    printf(
                        '<div class="bme-performance"><strong>%.1fs</strong><br><small>%.1f listings/sec</small></div>',
                        esc_html($duration),
                        esc_html($count / $duration)
                    );
                } else {
                    echo '—';
                }
                break;
            case 'actions':
                $run_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_extraction&post_id=' . $post_id), 'bme_run_extraction_' . $post_id);
                printf('<a href="%s" class="button button-small button-primary">%s</a>', esc_url($run_url), esc_html__('Run', 'bridge-mls-extractor-pro'));
                break;
        }
    }

    /**
     * Get CSS class for status
     */
    private function get_status_class($status) {
        $status_slug = strtolower(str_replace(' ', '-', $status ?? ''));
        return 'bme-status-' . sanitize_html_class($status_slug, 'unknown');
    }

    /**
     * Process bulk actions from the Database Browser list table.
     */
    public function handle_database_browser_bulk_actions() {
        require_once BME_PLUGIN_DIR . 'includes/class-bme-listings-list-table.php';

        $list_table = new BME_Advanced_Listings_List_Table($this->plugin);
        $current_action = $list_table->current_action();

        if ($current_action !== 'export_selected') {
            return;
        }

        check_admin_referer('bulk-listings');

        if (empty($_POST['bme_listings'])) {
            wp_redirect(add_query_arg('message', 'no_listings_selected', wp_get_referer()));
            exit;
        }

        $listing_ids = array_map('absint', $_POST['bme_listings']);

        $this->run_export($listing_ids);
    }

    /**
     * Render database browser page
     */
    public function render_database_browser() {
        try {
            $this->plugin->get('db')->verify_installation();
        } catch (Exception $e) {
            echo '<div class="wrap"><h1>' . esc_html__('Database Error', 'bridge-mls-extractor-pro') . '</h1>';
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo '<strong>' . esc_html__('Plugin database tables are missing or out of date.', 'bridge-mls-extractor-pro') . '</strong><br>';
            echo esc_html__('The plugin has attempted an automatic update. Please refresh this page. If the error persists, please try deactivating and reactivating the plugin.', 'bridge-mls-extractor-pro');
            echo '</p></div></div>';
            return;
        }

        require_once BME_PLUGIN_DIR . 'includes/class-bme-listings-list-table.php';

        $list_table = new BME_Advanced_Listings_List_Table($this->plugin);
        $list_table->prepare_items();

        $raw_filters = $list_table->get_filters_from_request();
        $filters_for_url = [];
        foreach($raw_filters as $key => $value) {
            $filters_for_url['filter_' . $key] = $value;
        }
        if (isset($_REQUEST['s'])) {
            $filters_for_url['s'] = $_REQUEST['s'];
        }

        $_SERVER['REQUEST_URI'] = add_query_arg($filters_for_url, $_SERVER['REQUEST_URI']);

        $current_dataset = $raw_filters['dataset'] ?? 'active';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Database Browser', 'bridge-mls-extractor-pro'); ?></h1>
            <hr class="wp-header-end">

            <form method="post">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                <?php wp_nonce_field('bme_database_browser_filter', 'bme_filter_nonce'); ?>

                <div class="bme-filters-panel">
                    <div class="bme-filters-row">
                        <div class="bme-filter-group">
                            <label for="filter_dataset"><?php esc_html_e('Dataset', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_dataset" id="filter_dataset">
                                <option value="active" <?php selected($current_dataset, 'active'); ?>><?php esc_html_e('Active Listings', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="closed" <?php selected($current_dataset, 'closed'); ?>><?php esc_html_e('Closed/Off-Market', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="all" <?php selected($current_dataset, 'all'); ?>><?php esc_html_e('All Listings', 'bridge-mls-extractor-pro'); ?></option>
                            </select>
                        </div>
                        <div class="bme-filter-group">
                            <label for="filter_standard_status"><?php esc_html_e('Status', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_standard_status" id="filter_standard_status" class="bme-filter-select" data-placeholder="<?php esc_attr_e('All Statuses', 'bridge-mls-extractor-pro'); ?>">
                                <option value=""></option>
                                <?php echo $this->render_filter_options('standard_status', $raw_filters['standard_status'] ?? ''); ?>
                            </select>
                        </div>
                        <div class="bme-filter-group">
                            <label for="filter_property_type"><?php esc_html_e('Property Type', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_property_type" id="filter_property_type" class="bme-filter-select" data-placeholder="<?php esc_attr_e('All Types', 'bridge-mls-extractor-pro'); ?>">
                                <option value=""></option>
                                <?php echo $this->render_filter_options('property_type', $raw_filters['property_type'] ?? ''); ?>
                            </select>
                        </div>
                        <div class="bme-filter-group">
                            <label for="filter_city"><?php esc_html_e('City', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_city" id="filter_city" class="bme-filter-select" data-placeholder="<?php esc_attr_e('All Cities', 'bridge-mls-extractor-pro'); ?>">
                                <option value=""></option>
                                <?php echo $this->render_filter_options('city', $raw_filters['city'] ?? ''); ?>
                            </select>
                        </div>
                    </div>
                    <div class="bme-filters-actions">
                        <?php $list_table->search_box(__('Search Address, MLS#, Agent...', 'bridge-mls-extractor-pro'), 'bme-listing-search'); ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'bridge-mls-extractor-pro'); ?></button>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=bme_extraction&page=bme-database-browser')); ?>" class="button"><?php esc_html_e('Clear', 'bridge-mls-extractor-pro'); ?></a>
                    </div>
                </div>

                <?php $list_table->display(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render filter options with caching
     */
    private function render_filter_options($field, $current_value) {
        $values = $this->cache_manager->get_filter_values($field);

        $options = '';
        if (is_array($values)) {
            foreach ($values as $value) {
                $options .= sprintf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current_value, $value, false), esc_html($value));
            }
        }
        return $options;
    }

    /**
     * Handle admin actions
     */
    private function handle_admin_action($action_name, $nonce_action, $success_message, $fail_message, $is_resync = false) {
        if (!isset($_GET['post_id'])) {
            // For VT import, post_id is not required, check for specific action
            if ($action_name === 'run_vt_import') {
                if (!current_user_can('manage_options') || !check_admin_referer($nonce_action)) {
                    wp_die('Invalid request.');
                }
                $this->plugin->get('vt_importer')->import_virtual_tours(); // This method now sets its own transient messages
                $redirect_url = admin_url('admin.php?page=bme-settings');
                wp_redirect($redirect_url); // Redirect without specific success/fail messages here, as they come from transient
                exit;
            }
            wp_die('Missing post ID.');
        }

        $post_id = absint($_GET['post_id']);
        if (!$post_id || !current_user_can('edit_post', $post_id) || !check_admin_referer($nonce_action . '_' . $post_id)) {
            wp_die('Invalid request.');
        }

        $success = false;
        $redirect_url = admin_url('edit.php?post_type=bme_extraction');

        switch($action_name) {
            case 'run_extraction':
                $success = $this->plugin->get('extractor')->run_extraction($post_id, $is_resync);
                break;
            case 'clear_data':
                $cleared = $this->plugin->get('processor')->clear_extraction_data($post_id);
                update_post_meta($post_id, '_bme_last_modified', '1970-01-01T00:00:00Z');
                $this->cache_manager->delete('extraction_stats_' . $post_id);
                $success = true;
                $success_message = sprintf(__('Data cleared. %d listings removed.', 'bridge-mls-extractor-pro'), $cleared);
                break;
            case 'test_config':
                 $result = $this->plugin->get('extractor')->test_extraction_config($post_id);
                 $success = $result['success'];
                 $redirect_url = admin_url('post.php?post=' . $post_id . '&action=edit');
                 $fail_message = $result['error'] ?? $fail_message;
                 break;
        }

        wp_redirect(add_query_arg('message', $success ? $success_message : $fail_message, $redirect_url));
        exit;
    }

    public function handle_run_extraction() { $this->handle_admin_action('run_extraction', 'bme_run_extraction', 'extraction_success', 'extraction_failed', false); }
    public function handle_run_resync() { $this->handle_admin_action('run_extraction', 'bme_run_resync', 'resync_success', 'resync_failed', true); }
    public function handle_clear_data() { $this->handle_admin_action('clear_data', 'bme_clear_data', 'data_cleared', 'clear_failed'); }
    public function handle_test_config() { $this->handle_admin_action('test_config', 'bme_test_config', 'config_valid', 'config_invalid'); }
    public function handle_run_vt_import() { $this->handle_admin_action('run_vt_import', 'bme_run_vt_import', 'vt_import_success', 'vt_import_failed'); }

    /**
     * Centralized export function. Can be called for selected IDs or with filters.
     */
    private function run_export($listing_ids = [], $filters = [], $selected_columns = []) {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $data_processor = $this->plugin->get('processor');

        if (empty($selected_columns)) {
            $selected_columns = array_keys($data_processor->get_all_listing_columns());
        }

        $listings = [];
        if (!empty($listing_ids)) {
            $listings = $data_processor->get_listings_by_ids($listing_ids, $selected_columns);
        } elseif (!empty($filters)) {
            $listings = $data_processor->search_listings($filters, -1, 0, 'modification_timestamp', 'DESC');
        }

        header('Content-Type: text/csv; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename="mls-listings-export-' . date('Ymd-His') . '.csv"');
        $output = fopen('php://output', 'w');

        $all_columns_map = $data_processor->get_all_listing_columns();
        $header_row = array_map(fn($col_key) => $all_columns_map[$col_key] ?? ucfirst(str_replace('_', ' ', $col_key)), $selected_columns);
        fputcsv($output, $header_row);

        foreach ($listings as $listing) {
            $row = [];
            foreach ($selected_columns as $col_key) {
                $value = $listing[$col_key] ?? '';
                $row[] = is_array($value) ? json_encode($value) : $value;
            }
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    /**
     * Handles the "Export All Filtered" button submission from admin-post.php
     */
    public function handle_export_listings_csv() {
        if (!current_user_can('manage_options') || !isset($_POST['bme_export_nonce']) || !wp_verify_nonce($_POST['bme_export_nonce'], 'bme_export_listings_csv_nonce')) {
            wp_die('Permission denied.');
        }

        $filters = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'filter_') === 0 && !empty($value)) {
                $filters[str_replace('filter_', '', $key)] = sanitize_text_field($value);
            }
        }
        if (isset($_POST['s']) && !empty($_POST['s'])) {
            $filters['search_query'] = sanitize_text_field($_POST['s']);
        }

        $selected_columns = isset($_POST['bme_export_columns']) && is_array($_POST['bme_export_columns']) ? array_map('sanitize_text_field', $_POST['bme_export_columns']) : [];

        $this->run_export([], $filters, $selected_columns);
    }

    /**
     * AJAX handlers
     */
    public function ajax_get_filter_values() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        $field = sanitize_key($_POST['field'] ?? '');
        if (empty($field)) wp_send_json_error();
        $values = $this->cache_manager->get_filter_values($field);
        wp_send_json_success($values);
    }

    public function ajax_search_listings() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        $filters = isset($_POST['filters']) ? wp_unslash($_POST['filters']) : [];
        $page = absint($_POST['page'] ?? 1);
        $per_page = 30;
        $offset = ($page - 1) * $per_page;

        $data_processor = $this->plugin->get('processor');
        $results = $data_processor->search_listings($filters, $per_page, $offset);
        $total = $data_processor->get_search_count($filters);

        wp_send_json_success(['listings' => $results, 'total' => $total]);
    }

    public function ajax_get_extraction_stats() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        $extraction_id = absint($_POST['extraction_id'] ?? 0);
        if (!$extraction_id) wp_send_json_error();

        $stats = $this->cache_manager->get_extraction_stats($extraction_id);
        if (!$stats) {
            $data_processor = $this->plugin->get('processor');
            $stats = $data_processor->get_extraction_stats($extraction_id);
            $this->cache_manager->cache_extraction_stats($extraction_id, $stats);
        }
        wp_send_json_success($stats);
    }

    /**
     * AJAX handler for live search suggestions.
     */
    public function ajax_live_search() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        if (strlen($term) < 3) {
            wp_send_json_success([]); // Return empty for short terms
            return;
        }

        $data_processor = $this->plugin->get('processor');
        $suggestions = $data_processor->live_search_suggestions($term);

        wp_send_json_success($suggestions);
    }

    /**
     * New AJAX handler to get live extraction progress.
     */
    public function ajax_get_live_extraction_progress() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $extraction_id = absint($_POST['extraction_id'] ?? 0);
        if (!$extraction_id) {
            wp_send_json_error('Missing extraction ID.');
        }

        $progress = $this->plugin->get('extractor')->get_live_progress($extraction_id);

        if ($progress) {
            wp_send_json_success($progress);
        } else {
            // If no progress found, assume not running or completed/cleared
            wp_send_json_success(['status' => 'not_running', 'message' => __('No active extraction found.', 'bridge-mls-extractor-pro')]);
        }
    }

    /**
     * Display general admin notices (e.g., from other plugin functions).
     */
    public function display_admin_notices() {
        if ($errors = get_transient('settings_errors')) {
            foreach ($errors as $error) {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($error['type']), esc_html($error['message']));
            }
            delete_transient('settings_errors');
        }

        if (isset($_GET['message'])) {
            $messages = [
                'extraction_success' => ['success', __('Extraction completed successfully.', 'bridge-mls-extractor-pro')],
                'extraction_failed' => ['error', __('Extraction failed. Check logs.', 'bridge-mls-extractor-pro')],
                'resync_success' => ['success', __('Full resync completed.', 'bridge-mls-extractor-pro')],
                'data_cleared' => ['success', sprintf(__('Data cleared. %d listings removed.', 'bridge-mls-extractor-pro'), absint($_GET['count'] ?? 0))], // Added count
                'clear_failed' => ['error', __('Failed to clear data.', 'bridge-mls-extractor-pro')],
                'config_valid' => ['success', __('Configuration is valid.', 'bridge-mls-extractor-pro')],
                'config_invalid' => ['error', __('Configuration has errors. ' . (isset($_GET['test_result']) ? base64_decode($_GET['test_result']) : ''), 'bridge-mls-extractor-pro')],
                'no_listings_selected' => ['warning', __('You did not select any listings to export.', 'bridge-mls-extractor-pro')],
            ];

            $message_key = sanitize_key($_GET['message']);
            if (isset($messages[$message_key])) {
                [$type, $text] = $messages[$message_key];
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
            }
        }
    }

    /**
     * Display specific admin notices for Virtual Tour import.
     */
    public function display_vt_import_notices() {
        if ($message = get_transient('bme_pro_vt_import_success_message')) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($message));
            delete_transient('bme_pro_vt_import_success_message');
        }
        if ($message = get_transient('bme_pro_vt_import_error_message')) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($message));
            delete_transient('bme_pro_vt_import_error_message');
        }
    }

    /**
     * Register and sanitize settings
     */
    public function register_settings() {
        register_setting('bme_pro_settings', 'bme_pro_api_credentials', [$this, 'sanitize_api_credentials']);
        register_setting('bme_pro_settings', 'bme_pro_performance_settings', [$this, 'sanitize_performance_settings']);
        register_setting('bme_pro_data_settings', 'bme_pro_delete_on_deactivation', 'boolval');
        register_setting('bme_pro_data_settings', 'bme_pro_delete_on_uninstall', 'boolval');
        register_setting('bme_pro_settings', 'bme_pro_vt_file_url', 'esc_url_raw');
    }

    public function sanitize_api_credentials($input) {
        $sanitized_input = [];
        $sanitized_input['server_token'] = sanitize_text_field($input['server_token'] ?? '');
        $sanitized_input['endpoint_url'] = esc_url_raw($input['endpoint_url'] ?? '');
        return $sanitized_input;
    }

    public function sanitize_performance_settings($input) {
        $sanitized_input = [];
        $sanitized_input['api_timeout'] = max(30, absint($input['api_timeout'] ?? 60));
        $sanitized_input['batch_size'] = max(10, min(500, absint($input['batch_size'] ?? 100)));
        $sanitized_input['cache_duration'] = max(300, absint($input['cache_duration'] ?? 3600));
        return $sanitized_input;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BME Pro Settings', 'bridge-mls-extractor-pro'); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('bme_pro_settings');
                $api_credentials = get_option('bme_pro_api_credentials', []);
                $perf_settings = get_option('bme_pro_performance_settings', []);
                $vt_file_url = get_option('bme_pro_vt_file_url', '');
                ?>
                <h2><?php esc_html_e('API Credentials', 'bridge-mls-extractor-pro'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bme_server_token"><?php esc_html_e('API Server Token', 'bridge-mls-extractor-pro'); ?></label></th>
                        <td><input type="password" id="bme_server_token" name="bme_pro_api_credentials[server_token]" value="<?php echo esc_attr($api_credentials['server_token'] ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bme_endpoint_url"><?php esc_html_e('API Endpoint URL', 'bridge-mls-extractor-pro'); ?></label></th>
                        <td><input type="url" id="bme_endpoint_url" name="bme_pro_api_credentials[endpoint_url]" value="<?php echo esc_attr($api_credentials['endpoint_url'] ?? ''); ?>" class="large-text"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Performance', 'bridge-mls-extractor-pro'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bme_batch_size"><?php esc_html_e('Batch Size', 'bridge-mls-extractor-pro'); ?></label></th>
                        <td><input type="number" id="bme_batch_size" name="bme_pro_performance_settings[batch_size]" value="<?php echo esc_attr($perf_settings['batch_size'] ?? 100); ?>" class="small-text">
                        <p class="description"><?php _e('Number of listings to fetch per API request (Default: 100).', 'bridge-mls-extractor-pro'); ?></p></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Virtual Tour File Import', 'bridge-mls-extractor-pro'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bme_pro_vt_file_url"><?php esc_html_e('Virtual Tour File URL', 'bridge-mls-extractor-pro'); ?></label></th>
                        <td>
                            <input type="url" id="bme_pro_vt_file_url" name="bme_pro_vt_file_url" value="<?php echo esc_attr($vt_file_url); ?>" class="large-text">
                            <p class="description">
                                <?php _e('Enter the direct URL to your MLS Virtual Tour text file (e.g., `https://idx.mlspin.com/...&filetype=VT`).', 'bridge-mls-extractor-pro'); ?><br>
                                <strong><?php _e('Important:', 'bridge-mls-extractor-pro'); ?></strong> <?php _e('If your MLS password changes and affects this URL, you must update it here for virtual tours to continue syncing.', 'bridge-mls-extractor-pro'); ?><br>
                                <?php _e('This file is automatically imported daily via cron, but you can manually trigger it below.', 'bridge-mls-extractor-pro'); ?>
                            </p>
                            <?php
                            $run_vt_import_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_vt_import'), 'bme_run_vt_import');
                            ?>
                            <a href="<?php echo esc_url($run_vt_import_url); ?>" class="button button-secondary"><?php _e('Manually Import Virtual Tours Now', 'bridge-mls-extractor-pro'); ?></a>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <form method="post" action="options.php">
                <?php
                settings_fields('bme_pro_data_settings');
                $delete_on_deactivation = get_option('bme_pro_delete_on_deactivation', false);
                $delete_on_uninstall = get_option('bme_pro_delete_on_uninstall', false);
                ?>
                <h2><?php esc_html_e('Data Management', 'bridge-mls-extractor-pro'); ?></h2>
                <table class="form-table">
                    <tr style="background-color: #fffbe5;">
                        <th scope="row">
                            <label for="bme_delete_on_deactivation"><?php esc_html_e('Cleanup on Deactivation', 'bridge-mls-extractor-pro'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label for="bme_delete_on_deactivation">
                                    <input type="checkbox" name="bme_pro_delete_on_deactivation" id="bme_delete_on_deactivation" value="1" <?php checked($delete_on_deactivation, true); ?>>
                                    <strong style="color: #dc3232;"><?php esc_html_e('Delete all plugin data upon deactivation.', 'bridge-mls-extractor-pro'); ?></strong>
                                </label>
                                <p class="description"><?php _e('Warning: This is a destructive action. All data will be deleted when you deactivate the plugin.', 'bridge-mls-extractor-pro'); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="bme_delete_on_uninstall"><?php esc_html_e('Cleanup on Deletion', 'bridge-mls-extractor-pro'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label for="bme_delete_on_uninstall">
                                    <input type="checkbox" name="bme_pro_delete_on_uninstall" id="bme_delete_on_uninstall" value="1" <?php checked($delete_on_uninstall, true); ?>>
                                    <?php esc_html_e('Delete all plugin data when the plugin is deleted from the WordPress admin.', 'bridge-mls-extractor-pro'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Data Settings', 'bridge-mls-extractor-pro')); ?>
            </form>
        </div>
        <?php
    }

    public function render_performance_dashboard() { echo '<div class="wrap"><h1>Performance Dashboard</h1><p>Coming soon.</p></div>'; }
    public function render_activity_logs() { echo '<div class="wrap"><h1>Activity Logs</h1><p>Coming soon.</p></div>'; }
}