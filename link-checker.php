<?php
/**
 * Link Checker
 *
 * @link              https://brokenlinkchecker.dev/wordpress/broken-link-checker
 * @since             1.0.0
 * @package           link-checker
 *
 * @wordpress-plugin
 * Plugin Name:       Link Checker
 * Donate link:		  https://www.paypal.com/donate/?hosted_button_id=MV4L54K4UUF8W
 * Plugin URI:        https://brokenlinkchecker.dev/wordpress/broken-link-checker
 * Description:       Checks your website for broken links and notifies you on the dashboard if any are found. This is a Fork of the broken link checker maintained by WPMU DEV with only the legacy version. 
 * Version:           2.3.1.6554
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Bram Brambring
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
namespace Blc;




// If this file is called directly, abort.
defined( 'WPINC' ) || die;



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
	define( 'WPMUDEV_BLC_SCIPTS_VERSION', '2.3.1.0' );
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

define('BLC_DATABASE_VERSION', 20);
}

require_once 'autoloader.php';

$autoloaded = new Autoloader();

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




// Load the legacy plugin.

