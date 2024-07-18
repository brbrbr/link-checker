<?php

/**
 * @author W-Shadow
 * @copyright 2009
 */


	class blcConfigurationManager {

		var $option_name;

		var $options;
		var $defaults;
		var $loaded_values;

		/**
		 * @var bool Whether options have been successfully loaded from the database.
		 */
		public $db_option_loaded = false;

		function __construct( $option_name = '', $default_settings = null ) {
			$this->option_name = $option_name;

			if ( is_array( $default_settings ) ) {
				$this->defaults = $default_settings;
			} else {
				$this->defaults = array();
			}
			$this->loaded_values = array();

			$this->options = $this->defaults;

			if ( ! empty( $this->option_name ) ) {
				$this->load_options();
			}

		
		}

		function set_defaults( $default_settings = null ) {
			if ( is_array( $default_settings ) ) {
				$this->defaults = array();
			} else {
				$this->defaults = $default_settings;
			}
			$this->options = array_merge( $this->defaults, $this->loaded_values );
		}

		/**
		 * blcOptionManager::load_options()
		 * Load plugin options from the database. The current $options values are not affected
		 * if this function fails.
		 *
		 * @param string $option_name
		 * @return bool True if options were loaded, false otherwise.
		 */
		function load_options( $option_name = '' ) {
			$this->db_option_loaded = false;

			if ( ! empty( $option_name ) ) {
				$this->option_name = $option_name;
			}

			if ( empty( $this->option_name ) ) {
				return false;
			}

			$new_options = get_option( $this->option_name );

			//Decode JSON (if applicable).
			if ( is_string( $new_options ) && ! empty( $new_options ) ) {
				$new_options = json_decode( $new_options, true );
			}

			if ( ! is_array( $new_options ) ) {
				return false;
			} else {
				$this->loaded_values    = $new_options;
				$this->options          = array_merge( $this->defaults, $this->loaded_values );
				$this->db_option_loaded = true;
				return true;
			}
			if ( empty($this->option['log_file'])) {

			}

			$this->option['log_file'] = $this->option['log_file'] ?:self::get_default_log_directory(). '/'. self::get_default_log_basename();
			$this->option['cookie_jar'] = $this->option['cookie_jar'] ?:self::get_default_log_directory(). '/'. self::get_default_cookie_basename();

			
		}

		/**
		 * blcOptionManager::save_options()
		 * Save plugin options to the database.
		 *
		 * @param string $option_name (Optional) Save the options under this name
		 * @return bool True if settings were saved, false if settings haven't been changed or if there was an error.
		 */
		function save_options( $option_name = '' ) {
			if ( ! empty( $option_name ) ) {
				$this->option_name = $option_name;
			}

			if ( empty( $this->option_name ) ) {
				return false;
			}

			return update_option( $this->option_name, json_encode( $this->options ) );
		}

		/**
		 * Retrieve a specific setting.
		 *
		 * @param string $key
		 * @param mixed $default
		 * @return mixed
		 */
		function get( $key, $default = null ) {
			if ( array_key_exists( $key, $this->options ) ) {
				return $this->options[ $key ];
			} else {
				return $default;
			}
		}

		/**
		 * Update or add a setting.
		 *
		 * @param string $key
		 * @param mixed $value
		 * @return void
		 */
		function set( $key, $value ) {
			$this->options[ $key ] = $value;
		}
		public static function get_default_log_directory()
		{
			$uploads = wp_upload_dir();

			return $uploads['basedir'] . '/broken-link-checker';
		}

		public static function get_default_log_basename()
		{
			return 'blc-log.txt';
		}
		public static function get_default_cookie_basename()
		{
			return 'blc-cookie.txt';
		}
	}
	$GLOBALS['blc_config_manager'] = new blcConfigurationManager(
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
			'show_widget_count_bubble'                => true, // Show a bubble  with broken links in the title of the dashboard widget
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
			'log_file'                         =>  '',
			'cookies_enabled'                  => true,
			'cookie_jar'                       =>'',
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
	 * Get the configuration object used by Broken Link Checker.
	 *
	 * @return blcConfigurationManager
	 */
	function blc_get_configuration()
	{
		return $GLOBALS['blc_config_manager'];
	}


