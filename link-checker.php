<?php
/**
 * Broken Link Checker - Fork
 *
 * @link              https://brokenlinkchecker.dev/wordpress/broken-link-checker
 * @since             1.0.0
 * @package           link-checker
 *
 * @wordpress-plugin
 * Plugin Name:       Broken Link Checker - Fork
 * Plugin URI:        https://brokenlinkchecker.dev/wordpress/broken-link-checker
 * Description:       This is a Fork of the broken link checkerm with only the legacy version. Checks your blog for broken links and notifies you on the dashboard if any are found.
 * Version:           2.3.0.6337
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Bram Brambring / WPMU DEV
 * Author URI:        https://brambring.nl/
 * Update URI:        https://downloads.brokenlinkchecker.dev/link-checker.json
 * Text Domain:       broken-link-checker
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html

 */

/*
Broken Link Checker is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Broken Link Checker is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Broken Link Checker. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

namespace WPMUDEV_BLC;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

// Plugin version.
if ( ! defined( 'WPMUDEV_BLC_VERSION' ) ) {
	define( 'WPMUDEV_BLC_VERSION', '2.3.0.6337' );
}

// Define WPMUDEV_BLC_PLUGIN_FILE.
if ( ! defined( 'WPMUDEV_BLC_PLUGIN_FILE' ) ) {
	define( 'WPMUDEV_BLC_PLUGIN_FILE', __FILE__ );
}

// Plugin basename.
if ( ! defined( 'WPMUDEV_BLC_BASENAME' ) ) {
	define( 'WPMUDEV_BLC_BASENAME', plugin_basename( __FILE__ ) );
}

// Plugin directory.
if ( ! defined( 'WPMUDEV_BLC_DIR' ) ) {
	define( 'WPMUDEV_BLC_DIR', plugin_dir_path( __FILE__ ) );
}

// Plugin url.
if ( ! defined( 'WPMUDEV_BLC_URL' ) ) {
	define( 'WPMUDEV_BLC_URL', plugin_dir_url( __FILE__ ) );
}
// Assets url.
if ( ! defined( 'WPMUDEV_BLC_ASSETS_URL' ) ) {
	define( 'WPMUDEV_BLC_ASSETS_URL', plugin_dir_url( __FILE__ ) . trailingslashit( 'assets' ) );
}

// Scripts version.
if ( ! defined( 'WPMUDEV_BLC_SCIPTS_VERSION' ) ) {
	define( 'WPMUDEV_BLC_SCIPTS_VERSION', WPMUDEV_BLC_VERSION );
}

// SUI version number used in BLC_SHARED_UI_VERSION and enqueues.
if ( ! defined( 'BLC_SHARED_UI_VERSION_NUMBER' ) ) {
	define( 'BLC_SHARED_UI_VERSION_NUMBER', '2-12-24' );
}

// SUI version used in admin body class.
if ( ! defined( 'BLC_SHARED_UI_VERSION' ) ) {
	define( 'BLC_SHARED_UI_VERSION', 'sui-' . BLC_SHARED_UI_VERSION_NUMBER );
}

// Path to the plugin's legacy directory.
if ( ! defined( 'BLC_DIRECTORY_LEGACY' ) ) {
	define( 'BLC_DIRECTORY_LEGACY', WPMUDEV_BLC_DIR . '/legacy' );
}

// Path to legacy file.
if ( ! defined( 'BLC_PLUGIN_FILE_LEGACY' ) ) {
	//define( 'BLC_PLUGIN_FILE_LEGACY', BLC_DIRECTORY_LEGACY . '/init.php' );
	define( 'BLC_PLUGIN_FILE_LEGACY', BLC_DIRECTORY_LEGACY . '/init.php' );
}

if ( ! defined( 'BLC_DATABASE_VERSION' ) ) {

define('BLC_DATABASE_VERSION', 19);
}


/**
 * Run plugin activation hook to setup plugin.
 *
 * @since 2.0.0
 */


	/**
	 * Main instance of plugin.
	 *
	 * Returns the main instance of WPMUDEV_BLC to prevent the need to use globals
	 * and to maintain a single copy of the plugin object.
	 * You can simply call WPMUDEV_BLC\instance() to access the object.
	 *
	 * @since  2.0.0
	 *
	 * @return object WPMUDEV_BLC\Core\Loader
	 */


	// Init the plugin and load the plugin instance for the first time.
	//add_action( 'plugins_loaded', 'WPMUDEV_BLC\\wpmudev_blc_instance' );

	add_action(
		'plugins_loaded',
		function() {
			require_once 'legacy/init.php';
		},
		11
	);
	
	register_activation_hook(
		__FILE__,
		function() {
		
			require_once BLC_DIRECTORY_LEGACY . '/includes/activation.php';
		}
	);

	register_deactivation_hook(
		__FILE__,
		function() {
			
		}
	);


// Load the legacy plugin.

