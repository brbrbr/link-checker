<?php

/**
 * If Cloud version is enabled we can stop here to save resources used from Local.
 * When Cloud is active we still need to load init on Local admin manu page.
 * When Local is active we don't need to load on Cloud admin menu page.
 */

use Blc\Database\WPMutex;
use Blc\Logger\FileLogger;
use Blc\Logger\DummyLogger;
use Blc\Logger\CachedOptionLogger;
use Blc\Util\ConfigurationManager;
use Blc\Controller\BrokenLinkChecker;

use Blc\Controller\BrokenLinkCheckerSite;
use Blc\Database\DatabaseUpgrader;

// To prevent conflicts, only one version of the plugin can be activated at any given time.
if (defined('BLC_ACTIVE')) {
    trigger_error(
        'Another version of Broken Link Checker is already active. Please deactivate it before activating this one.',
        E_USER_ERROR
    );
} else {
    define('BLC_ACTIVE', true);

    $plugin_config = ConfigurationManager::getInstance();

    /**
     * Load all files pertaining to BLC's module subsystem
     */






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
        $blclog = new FileLogger($plugin_config->get('log_file'));
    } else {
        $blclog = new DummyLogger();
    }

    // Load the plugin if installed successfully
    if ($plugin_config->installation_complete) {

        function blc_init()
        {
           
         
            if (is_admin() || wp_doing_cron()) {
                require_once BLC_DIRECTORY_LEGACY . '/includes/any-post.php';
                new BrokenLinkChecker();
            } else {
                new BrokenLinkCheckerSite();
            }
        }

        function blc_rest_api_init()
        {
            if (defined('REST_REQUEST')) {
                require_once BLC_DIRECTORY_LEGACY . '/includes/any-post.php';
                new BrokenLinkChecker();
            }
        }
        add_action('init', 'blc_init', 2000);
        add_action('rest_api_init', 'blc_rest_api_init', 2000);
    } else {
        if (get_option('blc_activation_enabled')) {
            // Display installation errors (if any) on the Dashboard.
            function blc_print_installation_errors()
            {

                $plugin_config = ConfigurationManager::getInstance();
                $messages = array(
                    '<strong>' . __('Broken Link Checker installation failed. Try deactivating and then reactivating the plugin.', 'link-checker') . '</strong>',
                );

                $logger   = new CachedOptionLogger('blc_installation_log');
                $messages = array_merge(
                    $messages,
                    array(
                        'installation_complete = ' . (isset($plugin_config->options['installation_complete']) ? intval($plugin_config->options['installation_complete']) : 'no value'),
                        'installation_flag_cleared_on = ' . ($plugin_config->options['installation_flag_cleared_on'] ?? 'no value'),
                        'installation_flag_set_on = ' . ($plugin_config->options['installation_flag_set_on'] ?? 'no value'),
                        '',
                        '<em>Installation log follows :</em>',
                    ),
                    $logger->get_messages()
                );


                echo '<div class="error"><p>', implode("<br>\n", $messages), '</p></div>';
            }
            add_action('admin_notices', 'blc_print_installation_errors');
        } else {
            // fallback in case the activation hooks didn't run. //multisite
            require_once BLC_DIRECTORY_LEGACY . '/includes/activation.php';
        }
    }
}
