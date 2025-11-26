<?php

/*
Plugin Name: Dummy
Description:
Version: 1.0
Author: Janis Elsts

ModuleID: dummy
ModuleCategory: container
ModuleClassName: blcDummyManager
ModuleAlwaysActive: true
ModuleHidden: true
*/

/**
 * A "dummy" container class that can be used as a fallback when the real container class can't be found.
 *
 * @package Broken Link Checker
 * @access public
 */

use Blc\Container\Dummy as Container;
use Blc\Abstract\ContainerManager;



/**
 * A dummy manager class.
 *
 * @package Broken Link Checker
 * @access public
 */
class blcDummyManager extends ContainerManager
{
    var $container_class_name  = Container::class;
    function resynch($forced = false): int
    {
        // Do nothing.
        return 0;
    }
}
