<?php
namespace Blc\Abstract;

use Blc\Util\ConfigurationManager;
use Blc\Controller\ModuleManager;

/**
 * @author Janis Elsts
 * @copyright 2010
 */

/**
 * Base class for BLC modules.
 *
 * @package Broken Link Checker
 * @author Janis Elsts
 * @access public
 */
abstract class Module
{
    var $module_id;      // The ID of this module. Usually a lowercase string.
    var $cached_header;  // An associative array containing the header data of the module file.
    var $plugin_conf;    // A reference to the plugin's global configuration object.
    var $module_manager; // A reference to the module manager.

    /**
     * Class constructor
     *
     * @param string                  $module_id
     * @param array                   $cached_header
     * @param ConfigurationManager $plugin_conf
     * @param ModuleManager        $module_manager
     * @return void
     */
    function __construct(string $module_id, array $cached_header, ConfigurationManager $plugin_conf, ModuleManager $module_manager)
    {
        $this->module_id      = $module_id;
        $this->cached_header  = $cached_header;
        $this->plugin_conf    = $plugin_conf;
        $this->module_manager = $module_manager;

        $this->init();
    }

    /**
     * Module initializer. Called when the module is first instantiated.
     * The default implementation does nothing. Override it in a subclass to
     * specify some sort of start-up behaviour.
     *
     * @return void
     */
    function init()
    {
        // Should be overridden in a sub-class.
    }

    /**
     * Called when the module is activated.
     * Should be overridden in a sub-class.
     *
     * @return void
     */
    function activated()
    {
        // Should be overridden in a sub-class.
    }

    /**
     * Called when the module is deactivated.
     * Should be overridden in a sub-class.
     *
     * @return void
     */
    function deactivated()
    {
        // Should be overridden in a sub-class.
    }

    /**
     * Called when BLC itself is activated.
     * Usually this method just calls activated(), but subclasses could override it for special handling.
     */
    function plugin_activated()
    {
        $this->activated();
    }
}
