<?php

/**
 * If Cloud version is enabled we can stop here to save resources used from Local.
 * When Cloud is active we still need to load init on Local admin manu page.
 * When Local is active we don't need to load on Cloud admin menu page.
 */


use Blc\Component\Blc\Administrator\Blc\Includes\WPMutex;

// To prevent conflicts, only one version of the plugin can be activated at any given time.
if (defined('BLC_ACTIVE')) {
	trigger_error(
		'Another version of Broken Link Checker is already active. Please deactivate it before activating this one.',
		E_USER_ERROR
	);
} else {

	define('BLC_ACTIVE', true);

	// Fail fast if the WP version is unsupported. The $wp_version variable may be obfuscated by other
	// plugins, so use function detection to determine the version. get_post_stati was introduced in WP 3.0.0
	if (!function_exists('get_post_stati')) {
		trigger_error(
			'This version of Broken Link Checker requires WordPress 3.0 or later!',
			E_USER_ERROR
		);
	}

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
					Configuration
	 */

	// Load and initialize the plugin's configuration
	require_once BLC_DIRECTORY_LEGACY . '/includes/config-manager.php';



	/***********************************************
					Logging
	 */

	require_once  BLC_DIRECTORY_LEGACY . '/includes/logger.php';

	$blc_config_manager = blc_get_configuration();
	global $blclog;
	if ($blc_config_manager->get('logging_enabled', false) && is_writable($blc_config_manager->get('log_file'))) {
		$blclog = new blcFileLogger($blc_config_manager->get('log_file'));
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

		if (!isset($schedules['10min'])) {
			$schedules['10min'] = array(
				'interval' => 600,
				'display'  => __('Every 10 minutes'),
			);
		}

		if (!isset($schedules['weekly'])) {
			$schedules['weekly'] = array(
				'interval' => 604800, // 7 days
				'display'  => __('Once Weekly'),
			);
		}
		if (!isset($schedules['bimonthly'])) {
			$schedules['bimonthly'] = array(
				'interval' => 15 * 24 * 2600, // 15 days
				'display'  => __('Twice a Month'),
			);
		}

		return $schedules;
	}
	add_filter('cron_schedules', 'blc_cron_schedules');

	/***********************************************
					Main functionality
	 */



	// Load the plugin if installed successfully
	if ($blc_config_manager->options['installation_complete']) {
		function blc_init()
		{
			global $blc_module_manager, $ws_link_checker;
			$blc_config_manager = blc_get_configuration();

			static $init_done = false;
			if ($init_done) {
				return;
			}
			$init_done = true;

			//moved the IF up. No need to load all the crap every time on the front page

			if (is_admin() || defined('DOING_CRON')) {
				// Ensure the database is up to date
				if (BLC_DATABASE_VERSION !== $blc_config_manager->options['current_db_version']) {

					require_once BLC_DIRECTORY_LEGACY . '/includes/admin/db-upgrade.php';
				

					if (WPMutex::acquire('blc_dbupdate')) {
						blcDatabaseUpgrader::upgrade_database();
						WPMutex::release('blc_dbupdate');
					}
				}

				// Load the base classes and utilities
				require_once BLC_DIRECTORY_LEGACY . '/includes/links.php';
				require_once BLC_DIRECTORY_LEGACY . '/includes/link-query.php';
				require_once BLC_DIRECTORY_LEGACY . '/includes/instances.php';
				require_once BLC_DIRECTORY_LEGACY . '/includes/utility-class.php';
				// Load the module subsystem
				require_once BLC_DIRECTORY_LEGACY . '/includes/modules.php';

				// Load the modules that want to be executed in all contexts
				if (is_object($blc_module_manager) && method_exists($blc_module_manager, 'load_modules')) {
					$blc_module_manager->load_modules();
				}


				// It's an admin-side or Cron request. Load the core.
				require_once BLC_DIRECTORY_LEGACY . '/core/core.php';
				$ws_link_checker = new wsBrokenLinkChecker();
			} else {

				// This is user-side request, so we don't need to load the core.
				// We might need to inject the CSS for removed links, though.
				if ($blc_config_manager->options['mark_removed_links'] && !empty($blc_config_manager->options['removed_link_css'])) {
					function blc_print_removed_link_css()
					{
						$blc_config_manager = blc_get_configuration();
						echo '<style type="text/css">', $blc_config_manager->options['removed_link_css'], '</style>';
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
			global  $wpdb;
			$blc_config_manager = blc_get_configuration();

			if ($blc_config_manager->options['installation_complete']) {
				return;
			}

			$messages = array(
				'<strong>' . __('Broken Link Checker installation failed. Try deactivating and then reactivating the plugin.', 'broken-link-checker') . '</strong>',
			);

			if (!$blc_config_manager->db_option_loaded) {
				$messages[] = sprintf(
					'<strong>Failed to load plugin settings from the "%s" option.</strong>',
					$blc_config_manager->option_name
				);
				$messages[] = '';

				$serialized_config = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = %s",
						$blc_config_manager->option_name
					)
				);

				if (null === $serialized_config) {
					$messages[] = "Option doesn't exist in the {$wpdb->options} table.";
				} else {
					$messages[] = "Option exists in the {$wpdb->options} table and has the following value:";
					$messages[] = '';
					$messages[] = '<textarea cols="120" rows="20">' . htmlentities($serialized_config) . '</textarea>';
				}
			} else {
				$logger   = new blcCachedOptionLogger('blc_installation_log');
				$messages = array_merge(
					$messages,
					array(
						'installation_complete = ' . (isset($blc_config_manager->options['installation_complete']) ? intval($blc_config_manager->options['installation_complete']) : 'no value'),
						'installation_flag_cleared_on = ' . $blc_config_manager->options['installation_flag_cleared_on'],
						'installation_flag_set_on = ' . $blc_config_manager->options['installation_flag_set_on'],
						'',
						'<em>Installation log follows :</em>',
					),
					$logger->get_messages()
				);
			}

			echo '<div class="error"><p>', implode("<br>\n", $messages), '</p></div>';
		}
		add_action('admin_notices', 'blc_print_installation_errors');
	}
}
