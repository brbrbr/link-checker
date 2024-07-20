<?php

/**
 * @author W-Shadow
 * @copyright 2009
 */

namespace Blc\Util;


final class ConfigurationManager
{
    private  $name = '';
    public  $options = [];
    private  $defaults = [];
    private  $loaded_values = [];
    private static $instances = [];

    /**
     * @var bool Whether options have been successfully loaded from the database.
     */

     //final class so we can use the __construct with the singleton
    private function __construct(string $name, ?array $default_settings = null)
    {
        $this->name = $name;

        if (is_array($default_settings)) {
            $this->defaults = $default_settings;
        }
        $this->load_options();
    }

    final public static function getInstance(string $name = 'wsblc_options', ?array $default_settings = null)
    {

        if (!isset(static::$instances[$name]) || !static::$instances[$name] instanceof static) {
            static::$instances[$name] = new static($name, $default_settings);
        }

        return static::$instances[$name];
    }

    public function set_defaults(array $default_settings)
    {
        $this->defaults = $default_settings;
    }

    /**
     * blcOptionManager::load_options()
     * Load plugin options from the database. The current $options values are not affected
     * if this function fails.
     *
     * @param string $name
     * @return bool True if options were loaded, false otherwise.
     */
    private function load_options()
    {

        $new_options = get_option($this->name);

        // Decode JSON (if applicable).
        if (is_string($new_options) && !empty($new_options)) {
            $new_options = json_decode($new_options, true);
        }

        if (!is_array($new_options)) {
            $this->options = $this->defaults;
            $this->save_options();
        } else {
            $this->loaded_values    = $new_options;
            $this->options          = array_merge($this->defaults, $this->loaded_values);
        }

        $this->options = apply_filters("broken-link-checker-options-loaded", $this->options, $this->name);
    }

    /**
     * blcOptionManager::save_options()
     * Save plugin options to the database.
     *
     * @param string $name (Optional) Save the options under this name
     * @return bool True if settings were saved, false if settings haven't been changed or if there was an error.
     */
    public function save_options()
    {
        return update_option($this->name, json_encode($this->options));
    }

    /**
     * blcOptionManager::save_options()
     * Save plugin options to the database.
     *
     * @param string $name (Optional) Save the options under this name
     * @return bool True if settings were saved, false if settings haven't been changed or if there was an error.
     */
    public function get_options()
    {
        return $this->options;
    }

    public function __get($name)
    {
        $name = strtolower($name);
        if ('options' === $name) {
            return $this->get_options();
        }
        return $this->get($name);
    }

    public function __set($name, $value)
    {
        $name = strtolower($name);
        $this->set($name, $value);
    }


    /**
     * Retrieve a specific setting.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return $this->options[$key] ?? $default;
        // return apply_filters( "broken-link_checker-option-{$key}", $this->options[$key]??$default, $key, $this->name );
    }

    /**
     * Update or add a setting.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public function set($key, $value)
    {
        $this->options[$key] = $value;
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
