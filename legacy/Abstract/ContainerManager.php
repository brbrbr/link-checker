<?php

namespace Blc\Abstract;


use Blc\Util\Utility;
use Blc\Abstract\Module;


/**
 * The base class for link container managers.
 *
 * Sub-classes should override at least the get_containers() and resynch() methods.
 *
 * @package Broken Link Checker
 * @access public
 */
abstract class ContainerManager extends Module
{
    var $container_type       = '';
    var $fields               = array();
    var $container_class_name = 'Container';
    var $updating_urls        = '';

    /**
     * Do whatever setup necessary that wasn't already done in the constructor.
     *
     * This method was added so that sub-classes would have something "safe" to
     * over-ride without having to deal with PHP4/5 constructors.
     *
     * @return void
     */
    function init()
    {
        parent::init();
        $this->container_type = $this->module_id;
        // Sub-classes might also use it to set up hooks, etc.
    }

    /**
     * Instantiate a link container.
     *
     * @param array $container An associative array of container data.
     * @return Container
     */
    function get_container($container)
    {
        $container['fields'] = $this->get_parseable_fields();
        $container_obj       = new $this->container_class_name($container);
        return $container_obj;
    }

    /**
     * Instantiate multiple containers of the container type managed by this class and optionally
     * pre-load container data used for display/parsing.
     *
     * Sub-classes should, if possible, use the $purpose argument to pre-load any extra data required for
     * the specified task right away, instead of making multiple DB roundtrips later. For example, if
     * $purpose is set to the BLC_FOR_DISPLAY constant, you might want to preload any DB data that the
     * container will need in Container::ui_get_source().
     *
     * @see Container::make_containers()
     * @see Container::ui_get_source()
     * @see Container::ui_get_action_links()
     *
     * @param array  $containers Array of assoc. arrays containing container data.
     * @param string $purpose An optional code indicating how the retrieved containers will be used.
     * @param bool   $load_wrapped_objects Preload wrapped objects regardless of purpose.
     *
     * @return array of Container indexed by "container_type|container_id"
     */
    function get_containers($containers, $purpose = '', $load_wrapped_objects = false)
    {
        return $this->make_containers($containers);
    }

    /**
     * Instantiate multiple containers of the container type managed by this class
     *
     * @param array $containers Array of assoc. arrays containing container data.
     * @return array of Container indexed by "container_type|container_id"
     */
    function make_containers($containers)
    {
        $results = array();
        foreach ($containers as $container) {
            $key             = $container['container_type'] . '|' . $container['container_id'];
            $results[$key] = $this->get_container($container);
        }
        return $results;
    }

    /**
     * Create or update synchronization records for all containers managed by this class.
     *
     * Must be over-ridden in subclasses.
     *
     * @param bool $forced If true, assume that all synch. records are gone and will need to be recreated from scratch.
     * @return int
     */
    function resynch($forced = false): int
    {
        trigger_error('Function ContainerManager::resynch() must be over-ridden in a sub-class', E_USER_ERROR);
        return 0;
    }

    /**
     * Resynch when activated.
     *
     * @uses ContainerManager::resynch()
     *
     * @return void
     */
    function activated()
    {
        $this->resynch();
        Utility::blc_got_unsynched_items();
    }

    /**
     * Get a list of the parseable fields and their formats common to all containers of this type.
     *
     * @return array Associative array of formats indexed by field name.
     */
    function get_parseable_fields()
    {
        return $this->fields;
    }
}
