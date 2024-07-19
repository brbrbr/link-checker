<?php

/**
 * If Cloud version is enabled we can stop here to save resources used from Local.
 * When Cloud is active we still need to load init on Local admin manu page.
 * When Local is active we don't need to load on Cloud admin menu page.
 */

use Blc\Database\WPMutex;
use Blc\Includes\blcFileLogger;
use Blc\Includes\blcDummyLogger;
use Blc\Includes\blcCachedOptionLogger;
use Blc\Utils\ConfigurationManager;
use Blc\Controller\BrokenLinkChecker;
use Blc\Database\DatabaseUpgrader;

// To prevent conflicts, only one version of the plugin can be activated at any given time.
if (defined('BLC_ACTIVE')) {
    trigger_error(
        'Another version of Broken Link Checker is already active. Please deactivate it before activating this one.',
        E_USER_ERROR
    );
} else {
    define('BLC_ACTIVE', true);

    $plugin_config = ConfigurationManager::getInstance(
        // Save the plugin's configuration into this DB option
        'wsblc_options',
        // Initialize default settings
        array(
            'max_execution_time'               => 7 * 60, // (in seconds) How long the worker instance may run, at most.
            'check_threshold'                  => 72,  // (in hours) Check each link every 72 hours.
            'recheck_count'                    => 3, // How many times a broken link should be re-checked.
            'recheck_threshold'                => 30 * 60, // (in seconds) Re-check broken links after 30 minutes.
            'run_in_dashboard'                 => true, // Run the link checker algo. continuously while the Dashboard is open.
            'run_via_cron'                     => true, // Run it hourly via WordPress pseudo-cron.
            'mark_broken_links'                => true, // Whether to add the broken_link class to broken links in posts.
            'broken_link_css'                  => ".broken_link, a.broken_link {\n\ttext-decoration: line-through;\n}",
            'nofollow_broken_links'            => false, // Whether to add rel="nofollow" to broken links in posts.
            'mark_removed_links'               => false, // Whether to add the removed_link class when un-linking a link.
            'removed_link_css'                 => ".removed_link, a.removed_link {\n\ttext-decoration: line-through;\n}",
            'exclusion_list'                   => array(), // Links that contain a substring listed in this array won't be checked.
            'send_email_notifications'         => true, // Whether to send the admin email notifications about broken links
            'send_authors_email_notifications' => false, // Whether to send post authors notifications about broken links in their posts.
            'notification_email_address'       => '', // If set, send email notifications to this address instead of the admin.
            'notification_schedule'            => apply_filters('blc_notification_schedule_filter', 'daily'), // How often (at most) notifications will be sent. Possible values : 'daily', 'weekly'. There is no option for this so we've added a fitler for it.
            'last_notification_sent'           => 0, // When the last email notification was sent (Unix timestamp)
            'warnings_enabled'                 => true, // Try to automatically detect temporary problems and false positives, and report them as "Warnings" instead of broken links.
            'server_load_limit'                => null, // Stop parsing stuff & checking links if the 1-minute load average goes over this value. Only works on Linux servers. 0 = no limit.
            'enable_load_limit'                => true, // Enable/disable load monitoring.
            'custom_fields'                    => array(), // List of custom fields that can contain URLs and should be checked.
            'acf_fields'                       => array(), // List of custom fields that can contain URLs and should be checked.
            'enabled_post_statuses'            => array('publish'), // Only check posts that match one of these statuses
            'dashboard_widget_capability'      => 'edit_others_posts', // Only display the widget to users who have this capability
            'show_link_count_bubble'           => true, // Display a notification bubble in the menu when broken links are found
            'show_widget_count_bubble'         => true, // Show a bubble  with broken links in the title of the dashboard widget
            'table_layout'                     => 'flexible', // The layout of the link table. Possible values : 'classic', 'flexible'
            'table_compact'                    => true, // Compact table mode on/off
            'table_visible_columns'            => array('new-url', 'status', 'used-in', 'new-link-text'),
            'table_links_per_page'             => 30,
            'table_color_code_status'          => true, // Color-code link status text
            'need_resynch'                     => false, // [Internal flag] True if there are unparsed items.
            'current_db_version'               => 0, // The currently set-up version of the plugin's tables
            'timeout'                          => 30, // (in seconds) Links that take longer than this to respond will be treated as broken.
            'highlight_permanent_failures'     => false, // Highlight links that have appear to be permanently broken (in Tools -> Broken Links).
            'failure_duration_threshold'       => 3, // (days) Assume a link is permanently broken if it still hasn't recovered after this many days.
            'logging_enabled'                  => false,
            'log_file'                         => ConfigurationManager::get_default_log_directory() . '/' . ConfigurationManager::get_default_log_basename(),
            'cookies_enabled'                  => true,
            'cookie_jar'                       =>  ConfigurationManager::get_default_log_directory() . '/' . ConfigurationManager::get_default_cookie_basename(),
            'incorrect_path'                   => false,
            'clear_log_on'                     => '',
            'installation_complete'            => false,
            'installation_flag_cleared_on'     => 0,
            'installation_flag_set_on'         => 0,
            'show_link_actions'                => array('blc-deredirect-action' => false), // Visible link actions.
            'youtube_api_key'                  => '',
            'blc_post_modified'                => '',
        )
    );



    /**
 * Load all files pertaining to BLC's module subsystem
 */

require_once BLC_DIRECTORY_LEGACY . '/includes/module-manager.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/containers.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/checkers.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/parsers.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/any-post.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/links.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/link-query.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/instances.php';

$blc_module_manager = blcModuleManager::getInstance(
    array(
        // List of modules active by default
        'http',             // Link checker for the HTTP(s) protocol
        'link',             // HTML link parser
        'image',            // HTML image parser
        'metadata',         // Metadata (custom field) parser
        'url_field',        // URL field parser
        'comment',          // Comment container
        'custom_field',     // Post metadata container (aka custom fields)
        'acf_field',        // Post acf container (aka advanced custom fields)
        'acf',              // acf parser
        'post',             // Post content container
        'page',             // Page content container
        'youtube-checker',  // Video checker using the YouTube API
        'youtube-iframe',   // Embedded YouTube video container
        'dummy',            // Dummy container used as a fallback
    )
);



// Let other plugins register virtual modules.
do_action('blc_register_modules', $blc_module_manager);

    /***********************************************
                    Debugging stuff
     */
    // define('BLC_DEBUG', true);

    /***********************************************
                    Constants
     */

    /**
     * For performance, some internal APIs used for retrieving multiple links, instances or containers
     * can take an optional "$purpose" argument. Those APIs will try to use this argument to pre-load
     * any DB data required for the specified purpose ahead of time.

     * For example, if you're loading a bunch of link containers for the purposes of parsing them and
     * thus set $purpose to BLC_FOR_PARSING, the relevant container managers will (if applicable) precache
     * the parse-able fields in each returned container object. Still, setting $purpose to any particular
     * value does not *guarantee* any data will be preloaded - it's only a suggestion that it should.

     * The currently supported values for the $purpose argument are :
     */
    define('BLC_FOR_EDITING', 'edit');
    define('BLC_FOR_PARSING', 'parse');
    define('BLC_FOR_DISPLAY', 'display');



    /***********************************************
                    Logging
     */

    global $blclog;


    if ($plugin_config->get('logging_enabled', false) && is_writable($plugin_config->get('log_file'))) {
        $blclog = new blcFileLogger($plugin_config->get('log_file'));
    } else {
        $blclog = new blcDummyLogger();
    }



    /***********************************************
                    Utility hooks
     ************************************************/

    /**
     * Adds the following cron schedules:
     * - 10min: every 10 minutes.
     * - weekly: once per week.
     * - bimonthly : twice per month.
     *
     * @param array $schedules Existing Cron schedules.
     * @return array
     */
    function blc_cron_schedules($schedules)
    {
        $schedules['10min'] ??= array(
            'interval' => 600,
            'display'  => __('Every 10 minutes'),
        );


        $schedules['weekly'] ??= array(
            'interval' => 604800, // 7 days
            'display'  => __('Once Weekly'),
        );


        $schedules['bimonthly'] ??= array(
            'interval' => 15 * 24 * 2600, // 15 days
            'display'  => __('Twice a Month'),
        );

        return $schedules;
    }

    add_filter('cron_schedules', 'blc_cron_schedules');

    /***********************************************
                    Main functionality
     */



    // Load the plugin if installed successfully
    if ($plugin_config->installation_complete) {
        function blc_init()
        {
            global  $ws_link_checker;

            $plugin_config = ConfigurationManager::getInstance();
            $blc_module_manager = blcModuleManager::getInstance();

            static $init_done = false;
            if ($init_done) {
                return;
            }
            $init_done = true;

            // moved the IF up. No need to load all the crap every time on the front page

            if (is_admin() || defined('DOING_CRON')) {
                // Ensure the database is up to date
                if (BLC_DATABASE_VERSION !== $plugin_config->options['current_db_version']) {
                  

                    if (WPMutex::acquire('blc_dbupdate')) {
                        DatabaseUpgrader::upgrade_database();
                        WPMutex::release('blc_dbupdate');
                    }
                }

                // Load the base classes and utilities
       
         

                // Load the modules that want to be executed in all contexts
                if (is_object($blc_module_manager) && method_exists($blc_module_manager, 'load_modules')) {
                    $blc_module_manager->load_modules();
                }

                $ws_link_checker = new BrokenLinkChecker();
            } else {
                // This is user-side request, so we don't need to load the core.
                // We might need to inject the CSS for removed links, though.
                if ($plugin_config->options['mark_removed_links'] && !empty($plugin_config->options['removed_link_css'])) {
                    function blc_print_removed_link_css()
                    {
                        $plugin_config = ConfigurationManager::getInstance();
                        echo '<style type="text/css">', $plugin_config->options['removed_link_css'], '</style>';
                    }
                    add_action('wp_head', 'blc_print_removed_link_css');
                }
            }
        }

        add_action('init', 'blc_init', 2000);
    } else {
        // Display installation errors (if any) on the Dashboard.
        function blc_print_installation_errors()
        {
          
            $plugin_config = ConfigurationManager::getInstance();

            $messages = array(
                '<strong>' . __('Broken Link Checker installation failed. Try deactivating and then reactivating the plugin.', 'broken-link-checker') . '</strong>',
            );


            $logger   = new blcCachedOptionLogger('blc_installation_log');
            $messages = array_merge(
                $messages,
                array(
                    'installation_complete = ' . (isset($plugin_config->options['installation_complete']) ? intval($plugin_config->options['installation_complete']) : 'no value'),
                    'installation_flag_cleared_on = ' . $plugin_config->options['installation_flag_cleared_on'],
                    'installation_flag_set_on = ' . $plugin_config->options['installation_flag_set_on'],
                    '',
                    '<em>Installation log follows :</em>',
                ),
                $logger->get_messages()
            );


            echo '<div class="error"><p>', implode("<br>\n", $messages), '</p></div>';
        }
        add_action('admin_notices', 'blc_print_installation_errors');
    }
}
