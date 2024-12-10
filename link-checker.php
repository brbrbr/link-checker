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
 * Version:           2.4.2.6897
 * Requires at least: 6.5
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
defined('WPINC') || die;




if (! defined('BLC_PLUGIN_FILE')) {
	define('BLC_PLUGIN_FILE', __FILE__);
}


if (! defined('BLC_BASENAME')) {
	define('BLC_BASENAME', plugin_basename(BLC_PLUGIN_FILE));
}


// Scripts version.
if (! defined('BLC_SCIPTS_VERSION')) {
	define('BLC_SCIPTS_VERSION', '6559');
}



// Path to the plugin's legacy directory.
if (! defined('BLC_DIRECTORY_LEGACY')) {
	define('BLC_DIRECTORY_LEGACY', plugin_dir_path(BLC_PLUGIN_FILE) . '/legacy');
}

// Path to legacy file.
if (! defined('BLC_PLUGIN_FILE_LEGACY')) {
	//define( 'BLC_PLUGIN_FILE_LEGACY', BLC_DIRECTORY_LEGACY . '/init.php' );
	define('BLC_PLUGIN_FILE_LEGACY', BLC_DIRECTORY_LEGACY . '/init.php');
}

if (! defined('BLC_DATABASE_VERSION')) {

	define('BLC_DATABASE_VERSION', 20);
}

require_once 'autoloader.php';

$autoloaded = new Autoloader();

add_action(
	'plugins_loaded',
	function () {
		require_once 'legacy/init.php';
	},
	11
);
if (is_multisite()) {
	add_action('wp_initialize_site', '\Blc\blc_on_activate_blog', 99);
	add_action('activate_blog', '\Blc\blc_on_activate_blog');
}

register_activation_hook(
	__FILE__,
	function () {
	
		require_once BLC_DIRECTORY_LEGACY . '/includes/activation.php';
	}
);

register_deactivation_hook(BLC_PLUGIN_FILE, ['Blc\Controller\BrokenLinkChecker', 'deactivation']);

function blc_on_activate_blog($blog_id)
{

	if ($blog_id instanceof \WP_Site) {
		$blog_id = (int) $blog_id->blog_id;
	}

	if (is_plugin_active_for_network(BLC_BASENAME)) {
		switch_to_blog($blog_id);
		require BLC_DIRECTORY_LEGACY . '/includes/activation.php';
		restore_current_blog();
	}
}

