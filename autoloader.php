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
	private $baseNameSpace = "Blc\\Component\\Blc\\Administrator\\Blc\\";
	private $plugin_path;
	private $legacy_dir;
	public function __construct()
	{
		$this->plugin_path    = plugin_dir_path(__FILE__);
		$this->legacy_dir = wp_normalize_path(path_join($this->plugin_path, 'legacy'));
		$this->classFile =  wp_normalize_path(path_join($this->plugin_path, $this->classFile));


		if (!is_file($this->classFile) || (defined('WP_DEBUG') && WP_DEBUG)) {
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

		$shortNameSpace = str_replace($this->baseNameSpace, '', $className);
		$parts = explode('\\', $shortNameSpace); //should be without leading \\
	
		if (\count($parts) > 0) {
			$filepath = path_join($this->legacy_dir, join(DIRECTORY_SEPARATOR, $parts)) . '.php';
			if (file_exists($filepath)) {
				include_once $filepath;
				return;
			}
		}

		if (isset($this->classMap[$className])) {
			$classfile = $this->classMap[$className];
			$filepath       = path_join($this->legacy_dir, $classfile);
			if (file_exists($filepath)) {
				include_once $filepath;
				return;
			}
		}
	}
	function link_checker_build_namespace_map()
	{
		$it = new RecursiveDirectoryIterator($this->legacy_dir);
		foreach (new RecursiveIteratorIterator($it) as $file) {
			$ext = pathinfo($file, PATHINFO_EXTENSION);
			if ($ext == 'php') {
				$in = file_get_contents($file);
				if (preg_match('#^namespace (.*);#m', $in, $m)) {
					$shortFile = ltrim(str_replace($this->legacy_dir, '', $file), '/');
					$namespace = $m[1];
					if (preg_match_all('#^class ([^\s]+)#m', $in, $n)) {
						$shortNameSpace = str_replace($this->baseNameSpace, '', $namespace);
						$parts = explode('\\', $shortNameSpace);
						$path = path_join($this->legacy_dir, join(DIRECTORY_SEPARATOR, $parts));
						if (file_exists($path)) {
							$matchClassName = basename(array_pop($file), 'php');
						} else {
							$matchClassName = '';
						}

						foreach ($n[1] as $className) {
							if ($matchClassName == $className) {
								continue;
							}
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
