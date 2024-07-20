<?php

/**
 * almost PSR-4 autoloader class.
 *
 *  
 */




namespace Blc;

class Autoloader
{
	private $base_name_space = 'Blc\\';
	private $legacy_dir;
	public function __construct()
	{

		$plugin_path = plugin_dir_path(__FILE__);
		$this->legacy_dir  = wp_normalize_path(path_join($plugin_path, 'legacy'));

		/**
		 * Register autoloader callback.
		 */

		spl_autoload_register(array($this, 'autoloader'));
	}

	public function autoloader($class_name)
	{
		// If the specified $class_name does not include our namespace, duck out.
		if (!str_starts_with($class_name, 'Blc')) {
			return;
		}

		$short_class_name = str_replace($this->base_name_space, '', $class_name);
		$class_path = str_replace('\\', DIRECTORY_SEPARATOR, $short_class_name);


		$filepath = path_join($this->legacy_dir, $class_path) . '.php';

		if (file_exists($filepath)) {
			include_once $filepath;
			return;
		}
	}
}
