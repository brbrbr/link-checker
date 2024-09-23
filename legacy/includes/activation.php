<?php

use Blc\Util\Utility;
use Blc\Logger\CachedOptionLogger;
use Blc\Util\ConfigurationManager;
use Blc\Database\DatabaseUpgrader;
use Blc\Controller\ModuleManager;


require_once BLC_DIRECTORY_LEGACY . '/includes/any-post.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/links.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/link-query.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/link-query.php';


error_log("\n\n\n==========\n\n", 3, '/tmp/s.log');
if (get_option('blc_activation_enabled')) {
    return;
}

update_option('blc_activation_enabled', true);

ConfigurationManager::clearInstance('wsblc_options');

$plugin_config =   ConfigurationManager::getInstance(
    // Save the plugin's configuration into this DB option
    'wsblc_options',
    // Initialize default settings
    [
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
        'active_modules' => [],
    ]

);

global  $wpdb, $blclog;
$queryCnt = $wpdb->num_queries;

// Completing the installation/upgrade is required for the plugin to work, so make sure
// the script doesn't get aborted by (for example) the browser timing out.
// set_time_limit( 300 );  //5 minutes should be plenty, anything more would probably indicate an infinite loop or a deadlock
if (Utility::is_host_wp_engine() || Utility::is_host_flywheel()) {
    set_time_limit(60);
} else {
    set_time_limit(300);
}

ignore_user_abort(true);

// Log installation progress to a DB option
$blclog = new CachedOptionLogger('blc_installation_log');
register_shutdown_function(array($blclog, 'save')); // Make sure the log is saved even if the plugin crashes

$blclog->clear();
$blclog->info(sprintf('Plugin activated at %s.', date_i18n('Y-m-d H:i:s')));
$activation_start = microtime(true);

// Reset the "installation_complete" flag
$plugin_config->options['installation_complete']        = false;
$plugin_config->options['installation_flag_cleared_on'] = date('c') . ' (' . microtime(true) . ')'; //phpcs:ignore
// Note the time of the first installation (not very accurate, but still useful)

$plugin_config->options['first_installation_timestamp'] ??= time();

$plugin_config->save_options();
$blclog->info('Installation/update begins.');


// Load the module subsystem




// Prepare the database.
$blclog->info('Upgrading the database...');
$upgrade_start = microtime(true);

DatabaseUpgrader::upgrade_database();
$blclog->info(sprintf('--- Total: %.3f seconds', microtime(true) - $upgrade_start));



// Notify modules that the plugin has been activated. This will cause container
// modules to create and update synch. records for all new/modified posts and other items.
$blclog->info('Notifying modules...');
$notification_start = microtime(true);
error_log("\n\n\n=====PRE =====\n\n", 3, '/tmp/s.log');
$moduleManager = ModuleManager::getInstance(
    [
        // List of modules active by default
        'http',             // Link checker for the HTTP(s) protocol
        'link',             // HTML link parser
        'image',            // HTML image parser
        'url_field',        // URL field parser
        'comment',          // Comment container
        'post',             // Post content container
        'page',             // Page content container
        'youtube-checker',  // Video checker using the YouTube API
        'youtube-iframe',   // Embedded YouTube video container
        'dummy',            // Dummy container used as a fallback
    ]

);
\blcPostTypeOverlord::getInstance();
// Let other plugins register virtual modules.
do_action('blc_register_modules', $moduleManager);

error_log("\n\n\n=====POST =====\n\n", 3, '/tmp/s.log');
$moduleManager->plugin_activated();

// Remove invalid DB entries
$blclog->info('Cleaning up the database...');
$cleanup_start = microtime(true);
Utility::blc_cleanup_database();
$blclog->info(sprintf('--- Total: %.3f seconds', microtime(true) - $cleanup_start));

Utility::blc_got_unsynched_items();

$blclog->info(sprintf('--- Total: %.3f seconds', microtime(true) - $notification_start));

// Turn off load limiting if it's not available on this server.
$blclog->info('Updating server load limit settings...');
$load = Utility::get_server_load();
if (empty($load)) {
    $plugin_config->options['enable_load_limit'] = false;
    $blclog->info('Disable load limit. Cannot retrieve current load average.');
} elseif ($plugin_config->options['enable_load_limit'] && !isset($plugin_config->options['server_load_limit'])) {
    $fifteen_minutes                                  = floatval(end($load));
    $default_load_limit                               = round(max(min($fifteen_minutes * 2, $fifteen_minutes + 2), 4));
    $plugin_config->options['server_load_limit'] = $default_load_limit;

    $blclog->info(
        sprintf(
            'Set server load limit to %.2f. Current load average is %.2f',
            $default_load_limit,
            $fifteen_minutes
        )
    );
}

// And optimize my DB tables, too (for good measure)
$blclog->info('Optimizing the database...');
$optimize_start = microtime(true);
Utility::optimize_database();
$blclog->info(sprintf('--- Total: %.3f seconds', microtime(true) - $optimize_start));

$blclog->info('Completing installation...');
$plugin_config->installation_complete    = true;
$plugin_config->installation_flag_set_on = date('c') . ' (' . microtime(true) . ')'; //phpcs:ignore

if ($plugin_config->save_options()) {
    $blclog->info('Configuration saved.');
} else {
    $blclog->error('Error saving plugin configuration!');
}


$blclog->info(
    sprintf(
        'Installation/update completed at %s with %d queries executed.',
        date_i18n('Y-m-d H:i:s'),
        $wpdb->num_queries - $queryCnt
    )
);
$blclog->info(sprintf('Total time: %.3f seconds', microtime(true) - $activation_start));
$blclog->save();

error_log(var_export($plugin_config, true), 3, '/tmp/s.log');
