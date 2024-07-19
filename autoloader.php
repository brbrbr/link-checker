<?php

/**
 * The core autoloader class.
 *
 * @link    https://wordpress.org/plugins/broken-link-checker/
 * @since   2.0.0
 *
 * @author  WPMUDEV (https://wpmudev.com)
 * @package WPMUDEV_BLC/Core/Utils
 *
 * @copyright (c) 2022, Incsub (http://incsub.com)
 */

// Only if required.
/**
 * The autoload function being registered. If null, then the default implementation of spl_autoload() will be registered.
 *
 * @param string $class_name The fully-qualified name of the class to load.
 *
 * @since 2.0.0
 */


namespace Blc;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Autoloader
{
	private $class_map       = array();
	private $class_file      = 'class_map.php';
	private $base_name_space = 'Blc\\';
	private $plugin_path;
	private $legacy_dir;
	public function __construct()
	{

		$this->plugin_path = plugin_dir_path(__FILE__);
		$this->legacy_dir  = wp_normalize_path(path_join($this->plugin_path, 'legacy'));
		$this->class_file  = wp_normalize_path(path_join($this->plugin_path, $this->class_file));

		if (!is_file($this->class_file) || (defined('WP_DEBUG') && WP_DEBUG)) {
			$this->buildNamespaceMap();
		}

		$this->class_map = require $this->class_file;

		/**
		 * Register autoloader callback.
		 */

		spl_autoload_register(array($this, 'autoloader'));
	}
	/**
	 * Instantiates the WordPress filesystem for use.
	 *
	 * @return object
	 */
	private static function getFilesystem()
	{
		global $wp_filesystem;

		if (empty($wp_filesystem)) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}


	private function writeNamespaceFile($elements)
	{
		$wp_filesystem = self::getFilesystem();

		$content   = array();
		$content[] = '<?php';
		$content[] = 'defined(\'WPINC\') or die;';
		$content[] = 'return [';

		foreach ($elements as $namespace => $path) {
			$content[] = "\t'{$namespace}' => '{$path}',";
		}
		$content[] = '];';

		$wp_filesystem->put_contents($this->class_file, implode("\n", $content));
	}

	public function autoloader($class_name)
	{
		// If the specified $class_name does not include our namespace, duck out.
		if (!str_starts_with($class_name, 'Blc')) {
			return;
		}

		$short_name_space = str_replace($this->base_name_space, '', $class_name);
		$parts            = explode('\\', $short_name_space); // should be without leading \\

		if (\count($parts) > 1) {
			$file = array_pop($parts);
			// todo interfaces etc
			$file     = "{$file}.php";
			$filepath = path_join($this->legacy_dir, join(DIRECTORY_SEPARATOR, $parts)) . DIRECTORY_SEPARATOR . $file;
			if (file_exists($filepath)) {
				include_once $filepath;
				return;
			}
		}

		if (isset($this->class_map[$class_name])) {
			$class_file = $this->class_map[$class_name];
			$filepath   = path_join($this->legacy_dir, $class_file);
			if (file_exists($filepath)) {
				include_once $filepath;
				return;
			}
		}
	}

	private function buildNamespaceMap()
	{
		$wp_filesystem = self::getFilesystem();
		$it = new RecursiveDirectoryIterator($this->legacy_dir);
		foreach (new RecursiveIteratorIterator($it) as $file) {
			$ext = pathinfo($file, PATHINFO_EXTENSION);
			if ('php' === $ext) {
				$in = $wp_filesystem->get_contents($file);
				if (preg_match('#^namespace (.*);#m', $in, $m)) {
					$short_file = ltrim(str_replace($this->legacy_dir, '', $file), '/');
					$namespace  = $m[1];
					if (preg_match_all('#^class ([^\s]+)#m', $in, $n)) {
						$shortNameSpace = str_replace($this->base_name_space, '', $namespace);
						$parts = explode('\\', $shortNameSpace);
						$path = path_join($this->legacy_dir, join(DIRECTORY_SEPARATOR, $parts));
						if (file_exists($path)) {
							
							$matchClassName = basename($file, '.php');
						} else {
							$matchClassName = '';
						}
						foreach ($n[1] as $className) {
							
							if ($matchClassName == $className) {
							
								continue;
							}

							$full_class               = "$namespace\\$className";
							$full_class               = str_replace('\\', '\\\\', $full_class);
							$class_map[$full_class] = $short_file;
						}
					}
				}
			}
		}
		$this->writeNamespaceFile($class_map);
	}
}
