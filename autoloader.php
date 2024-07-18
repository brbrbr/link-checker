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
class linkCheckerAutoload
{
	private $classMap = [];
	private $classFile = 'classmap.php';

	public function __construct()
	{
		$plugin_path    = plugin_dir_path(__FILE__);

		$this->classFile =  wp_normalize_path(path_join($plugin_path, $this->classFile));
	 

		if (!is_file($this->classFile) || (defined('WP_DEBUG') && WP_DEBUG )) {
			$this->link_checker_build_namespace_map();		
		}

		$this->classMap =  require $this->classFile;

		/**
		 * Register autoloader callback.
		 */
		
		spl_autoload_register([$this, 'link_checker_autoloader']);
	}

	private function writeNamespaceFile($elements)
	{
		$content   = [];
		$content[] = "<?php";
		$content[] = 'defined(\'WPINC\') or die;';
		$content[] = 'return [';

		foreach ($elements as $namespace => $path) {
			$content[] = "\t'{$namespace}' => '{$path}',";
		}

		$content[] = '];';
		file_put_contents($this->classFile, implode("\n", $content));
	}

	function link_checker_autoloader($className)
	{
		// If the specified $class_name does not include our namespace, duck out.
		if (!str_starts_with($className, 'Blc')) {
			return;
		}
	
		if (isset($this->classMap[$className])) {
			$classfile = $this->classMap[$className];
			$plugin_path    = plugin_dir_path(__FILE__);
			$filepath       = wp_normalize_path(path_join($plugin_path, $classfile));

			if (file_exists($filepath)) {
				include_once $filepath;
			}
		}
	}
	function link_checker_build_namespace_map()
	{
		$classMap = [];
		$plugin_path    = plugin_dir_path(__FILE__);
		$it = new RecursiveDirectoryIterator($plugin_path);
		foreach (new RecursiveIteratorIterator($it) as $file) {
			$ext = pathinfo($file, PATHINFO_EXTENSION);

			if ($ext == 'php') {
				$in = file_get_contents($file);
				if (preg_match('#^namespace (.*);#m', $in, $m)) {
					$shortFile = str_replace($plugin_path, '', $file);
					$namespace = $m[1];
					if (preg_match_all('#^class ([^\s]+)#m', $in, $n)) {


						foreach ($n[1] as $className) {
							$fullClass = "$namespace\\$className";
							$fullClass = str_replace('\\', '\\\\', $fullClass);
							$classMap[$fullClass] = $shortFile;
						}
					}
				}
			}
		}
		$this->writeNamespaceFile($classMap);
	}
}

$autoloaded = new linkCheckerAutoload();