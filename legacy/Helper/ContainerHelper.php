<?php

namespace Blc\Helper;

use Blc\Controller\ModuleManager;

/**
 * An utility class for working with link container types.
 * All methods of this class should be called statically.
 *
 * @package Broken Link Checker
 */
class ContainerHelper
{
    /**
     * Get the manager associated with a container type.
     *
     * @param string $container_type
     * @param string $fallback If there is no manager associated with $container_type, return the manager of this container type instead.
     * @return blcContainerManager|null
     */
    static function get_manager($container_type, $fallback = '')
    {
        $module_manager    = ModuleManager::getInstance();
        $container_manager = null;
        $container_manager = $module_manager->get_module($container_type, true, 'container');

        if ($container_manager) {
            return $container_manager;
        } elseif (! empty($fallback)) {
            $container_manager = $module_manager->get_module($fallback, true, 'container');
            if ($container_manager) {
                return $container_manager;
            }
        }

        return null;
    }

    /**
     * Retrieve or instantiate a container object.
     *
     * Pass an array containing the container type (string) and ID (int) to retrieve the container
     * from the database. Alternatively, pass an associative array to create a new container object
     * from the data in the array.
     *
     * @param array $container Either [container_type, container_id], or an assoc. array of container data.
     * @return blcContainer|null
     */
    static function get_container($container)
    {
        global $wpdb; /* @var wpdb $wpdb */

        if (! is_array($container) || ( count($container) < 2 )) {
            return null;
        }

        if (is_string($container[0]) && is_numeric($container[1])) {
            // The argument is in the [container_type, id] format
            // Fetch the container's synch record.
            $rez = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}blc_synch
					WHERE container_type = %s AND container_id = %d",
                    $container[0],
                    $container[1]
                ),
                ARRAY_A
            );

            if (empty($rez)) {
                // The container wasn't found, so we'll create a new one.
                $container = array(
                    'container_type' => $container[0],
                    'container_id'   => $container[1],
                );
            } else {
                $container = $rez;
            }
        }

        $manager = self::get_manager($container['container_type']);
        if (! $manager) {
            return null;
        }

        return $manager->get_container($container);
    }

    /**
     * Retrieve or instantiate multiple link containers.
     *
     * Takes an array of container specifications and returns an array of container objects.
     * Each input array entry should be an array and consist either of a container type (string)
     * and container ID (int), or name => value pairs describing a container object.
     *
     * @see ContainerHelper::get_container()
     *
     * @param array  $containers
     * @param string $purpose Optional code indicating how the retrieved containers will be used.
     * @param string $fallback The fallback container type to use for unrecognized containers.
     * @param bool   $load_wrapped_objects Preload wrapped objects regardless of purpose.
     * @return blcContainer[] Array of blcContainer indexed by "container_type|container_id"
     */
    static function get_containers($containers, $purpose = '', $fallback = '', $load_wrapped_objects = false)
    {
        global $wpdb; /* @var wpdb $wpdb */

        // If the input is invalid or empty, return an empty array.
        if (! is_array($containers) || ( count($containers) < 1 )) {
            return array();
        }

        $first = reset($containers);
        if (! is_array($first)) {
            return array();
        }

        if (isset($first[0]) && is_string($first[0]) && is_numeric($first[1])) {
            // The argument is an array of [container_type, id].
            // Divide the container IDs by container type.
            $by_type = array();

            foreach ($containers as $container) {
                if (isset($by_type[ $container[0] ])) {
                    array_push($by_type[ $container[0] ], intval($container[1]));
                } else {
                    $by_type[ $container[0] ] = array( intval($container[1]) );
                }
            }

            // Build the SQL to fetch all the specified containers
            $q = "SELECT *
			      FROM {$wpdb->prefix}blc_synch
				  WHERE";

            $pieces = array();
            foreach ($by_type as $container_type => $container_ids) {
                $pieces[] = '( container_type = "' . esc_sql($container_type) . '" AND container_id IN (' . implode(', ', $container_ids) . ') )';
            }

            $q .= implode("\n\t OR ", $pieces);

            // Fetch the container synch. records from the DB.
            $containers = $wpdb->get_results($q, ARRAY_A); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        /*
        Divide the inputs into separate arrays by container type (again), then invoke
        the appropriate manager for each type to instantiate the container objects.
        */

        // At this point, $containers is an array of assoc. arrays comprising container data.
        $by_type = array();
        foreach ($containers as $container) {
            if (isset($by_type[ $container['container_type'] ])) {
                array_push($by_type[ $container['container_type'] ], $container);
            } else {
                $by_type[ $container['container_type'] ] = array( $container );
            }
        }

        $results = array();
        foreach ($by_type as $container_type => $entries) {
            $manager = self::get_manager($container_type, $fallback);
            if (! is_null($manager)) {
                $partial_results = $manager->get_containers($entries, $purpose, $load_wrapped_objects);
                $results         = array_merge($results, $partial_results);
            }
        }

        return $results;
    }

    /**
     * Retrieve link containers that need to be synchronized (parsed).
     *
     * @param integer $max_results The maximum number of containers to return. Defaults to returning all unsynched containers.
     * @return blcContainer[]
     */
    static function get_unsynched_containers($max_results = 0)
    {
        global $wpdb; /* @var wpdb $wpdb */

        $q = "SELECT * FROM {$wpdb->prefix}blc_synch WHERE synched = 0";
        if ($max_results > 0) {
            $q .= " LIMIT $max_results";
        }

        $container_data = $wpdb->get_results($q, ARRAY_A); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        // FB::log($container_data, "Unsynched containers");
        if (empty($container_data)) {
            return array();
        }

        $containers = self::get_containers($container_data, BLC_FOR_PARSING, 'dummy');
        return $containers;
    }

    /**
     * (Re)create and update synchronization records for all supported containers.
     * Calls the resynch() method of all registered managers.
     *
     * @param bool $forced If true, assume that no synch. records exist and build all of them from scratch.
     * @return void
     */
    static function resynch($forced = false)
    {
        global $wpdb;

        $module_manager  = ModuleManager::getInstance();
        $active_managers = $module_manager->get_active_by_category('container');
        foreach ($active_managers as $module_id => $module_data) {
            $manager = $module_manager->get_module($module_id);
            if ($manager) {
                $manager->resynch($forced);
            }
        }
    }

    /**
     * Mark as unparsed all containers that match one of the the specified formats or
     * container types and that were last parsed after a specific timestamp.
     *
     * Used by newly activated parsers to force the containers they're interested in
     * to resynchronize and thus let the parser process them.
     *
     * @param array $formats Associative array of timestamps, indexed by format IDs.
     * @param array $container_types Associative array of timestamps, indexed by container types.
     * @return bool
     */
    static function mark_as_unsynched_where($formats, $container_types)
    {
        global $wpdb; /* @var wpdb $wpdb */
        global $blclog;

        // Find containers that match any of the specified formats and add them to
        // the list of container types that need to be marked as unsynched.
        $module_manager = ModuleManager::getInstance();
        $containers     = $module_manager->get_active_by_category('container');

        foreach ($containers as $module_id => $module_data) {
            $container_manager = $module_manager->get_module($module_id);
            if ($container_manager) {
                $fields         = $container_manager->get_parseable_fields();
                $container_type = $container_manager->container_type;
                foreach ($formats as $format => $timestamp) {
                    if (in_array($format, $fields)) {
                        // Choose the earliest timestamp
                        if (isset($container_types[ $container_type ])) {
                            $container_types[ $container_type ] = min($timestamp, $container_types[ $container_type ]);
                        } else {
                            $container_types[ $container_type ] = $timestamp;
                        }
                    }
                }
            }
        }

        if (empty($container_types)) {
            return true;
        }

        // Build the query to update all synch. records that match one of the specified
        // container types and have been parsed after the specified time.
        $q = "UPDATE {$wpdb->prefix}blc_synch SET synched = 0 WHERE ";

        $pieces = array();
        foreach ($container_types as $container_type => $timestamp) {
            $pieces[] = $wpdb->prepare(
                '(container_type = %s AND last_synch >= %s)',
                $container_type,
                date('Y-m-d H:i:s', $timestamp) //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            );
        }

        $q .= implode(' OR ', $pieces);
        $blclog->log('...... Executing query: ' . $q);

        $start_time = microtime(true);
        $rez        = ( $wpdb->query($q) !== false ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $blclog->log(sprintf('...... %d rows affected, %.3f seconds', $wpdb->rows_affected, microtime(true) - $start_time));

        Utility::blc_got_unsynched_items();
        return $rez;
    }

    /**
     * Remove synch. records that reference container types not currently loaded
     *
     * @return bool
     */
    static function cleanup_containers()
    {
        global $wpdb; /* @var wpdb $wpdb */
        global $blclog;

        $module_manager = ModuleManager::getInstance();

        $start             = microtime(true);
        $active_containers = $module_manager->get_escaped_ids('container');
        $q                 = "DELETE synch.*
		      FROM {$wpdb->prefix}blc_synch AS synch
		      WHERE
	      	    synch.container_type NOT IN ({$active_containers})";
        $rez               = $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $elapsed           = microtime(true) - $start;
        $blclog->log(sprintf('... %d synch records deleted in %.3f seconds', $wpdb->rows_affected, $elapsed));

        return false !== $rez;
    }
}