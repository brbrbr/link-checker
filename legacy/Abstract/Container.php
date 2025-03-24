<?php

namespace Blc\Abstract;

use Blc\Database\TransactionManager;
use Blc\Util\Utility;
use Blc\Controller\LinkQuery;
use Blc\Abstract\Parser;
use Blc\Controller\ModuleManager;


/**
 * The base class for link containers. All containers should extend this class.
 *
 * @package Broken Link Checker
 * @access public
 */
abstract class Container
{
    var $fields        = array();
    var $default_field = '';

    var $container_type;
    var $container_id = 0;

    var $synched    = false;
    var $last_synch = '0000-00-00 00:00:00';

    var $wrapped_object = null;

    public $updating_urls;

    /**
     * Constructor
     *
     * @param array  $data
     * @param object $wrapped_object
     * @return void
     */
    function __construct($data = null, $wrapped_object = null)
    {
        $this->wrapped_object = $wrapped_object;
        if (! empty($data) && is_array($data)) {
            foreach ($data as $name => $value) {
                $this->$name = $value;
            }
        }
    }

    /**
     * Get the value of the specified field of the object wrapped by this container.
     *
     * @access protected
     *
     * @param string $field Field name. If omitted, the value of the default field will be returned.
     * @return string
     */
    function get_field($field = '')
    {
        if (empty($field)) {
            $field = $this->default_field;
        }

        $w = $this->get_wrapped_object();
        return $w?->$field;
    }

    /**
     * Update the value of the specified field in the wrapped object.
     * This method will also immediately save the changed value by calling update_wrapped_object().
     *
     * @access protected
     *
     * @param string $field Field name.
     * @param string $new_value Set the field to this value.
     * @param string $old_value The previous value of the field. Optional, but can be useful for container that need the old value to distinguish between several instances of the same field (e.g. post metadata).
     * @return bool|\WP_Error True on success, an error object if something went wrong.
     */
    function update_field($field, $new_value, $old_value = '')
    {
        $w = $this->get_wrapped_object();
        if ($w) {
            $w->$field = $new_value;
            return $this->update_wrapped_object();
        }

        // delete the container and sync data. This will trigger a rescan.
        $this->delete();
        Utility::blc_cleanup_links();
        return \WP_Error('container_not_found', "Container not found while updating $field to $new_value");
    }

    /**
     * Retrieve the entity wrapped by this container.
     * The fetched object will also be cached in the $wrapped_object variable.
     *
     * @access protected
     *
     * @param bool $ensure_consistency Set this to true to ignore the cached $wrapped_object value and retrieve an up-to-date copy of the wrapped object from the DB (or WP's internal cache).
     * @return object The wrapped object.
     */
    function get_wrapped_object($ensure_consistency = false)
    {
        trigger_error('Function Container::get_wrapped_object() must be over-ridden in a sub-class', E_USER_ERROR);
    }

    /**
     * Update the entity wrapped by the container with values currently in the $wrapped_object.
     *
     * @access protected
     *
     * @return bool|\WP_Error True on success, an error if something went wrong.
     */
    function update_wrapped_object()
    {
        trigger_error('Function Container::update_wrapped_object() must be over-ridden in a sub-class', E_USER_ERROR);
        return false;
    }

    /**
     * Parse the container for links and save the results to the DB.
     *
     * @return void
     */
    function synch()
    {
        // FB::log("Parsing {$this->container_type}[{$this->container_id}]");

        // Remove any existing link instance records associated with the container
        $this->delete_instances();

        // Load the wrapped object, if not done already
        $this->get_wrapped_object();

        // FB::log($this->fields, "Parseable fields :");

        // Iterate over all parse-able fields
        foreach ($this->fields as $name => $format) {
            // Get the field value
            $value = $this->get_field($name);
            if (empty($value)) {
                // FB::log($name, "Skipping empty field");
                continue;
            }
            // FB::log($name, "Parsing field");

            // Get all parsers applicable to this field
            $parsers =  ModuleManager::getInstance()->get_parsers($format, $this->container_type);
            // FB::log($parsers, "Applicable parsers");

            if (empty($parsers)) {
                continue;
            }

            $base_url          = $this->base_url();
            $default_link_text = $this->default_link_text($name);

            // Parse the field with each parser
            foreach ($parsers as $parser) {
                // FB::log("Parsing $name with '{$parser->parser_type}' parser");
                $found_instances = $parser->parse($value, $base_url, $default_link_text);
                // FB::log($found_instances, "Found instances");

                $transactionManager = TransactionManager::getInstance();
                $transactionManager->start();

                // Complete the link instances by adding container info, then save them to the DB.
                foreach ($found_instances as $instance) {
                    $instance->set_container($this, $name);
                    $instance->save();
                }

                $transactionManager->commit();
            }
        }

        $this->mark_as_synched();
    }

    /**
     * Mark the container as successfully synchronized (parsed for links).
     *
     * @return bool
     */
    function mark_as_synched()
    {
        global $wpdb; /* @var wpdb $wpdb */

        $this->last_synch = time();

        $rez = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}blc_synch( container_id, container_type, synched, last_synch)
				VALUES( %d, %s, %d, NOW() )
				ON DUPLICATE KEY UPDATE synched = VALUES(synched), last_synch = VALUES(last_synch)",
                $this->container_id,
                $this->container_type,
                1
            )
        );

        return ( false !== $rez );
    }

    /**
     * Container::mark_as_unsynched()
     * Mark the container as not synchronized (not parsed, or modified since the last parse).
     * The plugin will attempt to (re)parse the container at the earliest opportunity.
     *
     * @return bool
     */
    function mark_as_unsynched()
    {
        global $wpdb; /* @var wpdb $wpdb */

        $rez = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}blc_synch( container_id, container_type, synched, last_synch)
			  	VALUES( %d, %s, %d, '0000-00-00 00:00:00' )
			  	ON DUPLICATE KEY UPDATE synched = VALUES(synched)",
                $this->container_id,
                $this->container_type,
                0
            )
        );

        Utility::blc_got_unsynched_items();

        return ( false !== $rez );
    }

    /**
     * Get the base URL of the container. Used to normalize relative URLs found
     * in the container. For example, for posts this would be the post permalink.
     *
     * @return string
     */
    function base_url()
    {
        return home_url();
    }

    /**
     * Get the default link text to use for links found in a specific container field.
     *
     * This is generally only meaningful for non-HTML container fields.
     * For example, if the container is post metadata, the default
     * link text might be equal to the name of the custom field.
     *
     * @param string $field
     * @return string
     */
    function default_link_text($field = '')
    {
        return '';
    }



    /**
     * Delete the DB record of this container.
     * Also deletes the DB records of all link instances associated with it.
     * Calling this method will not affect the WP entity (e.g. a post) corresponding to this container.
     *
     * @return bool
     */
    function delete()
    {
        global $wpdb; /* @var wpdb $wpdb */

        // Delete instances first.
        $rez = $this->delete_instances();

        if (! $rez) {
            // return false;
        }

        // Now delete the container record.
        $q = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}blc_synch
				WHERE container_id = %d AND container_type = %s",
                $this->container_id,
                $this->container_type
            )
        );

        if (false === $q) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Delete all link instance records associated with this container.
     * NB: Calling this method will not affect the WP entity (e.g. a post) corresponding to this container.
     *
     * @return bool
     */
    function delete_instances()
    {
        global $wpdb; /* @var wpdb $wpdb */

        $q = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}blc_instances
			  	WHERE container_id = %d AND container_type = %s",
                $this->container_id,
                $this->container_type
            )
        );

        if (false === $q) {
            return false;
        } else {
            return true;
        }
    }








    /**
     * Change all links with the specified URL to a new URL.
     *
     * @param string    $field_name
     * @param Parser $parser
     * @param string    $new_url
     * @param string    $old_url
     * @param string    $old_raw_url
     * @param string    $new_text Optional.
     *
     * @return array|\WP_Error The new value of raw_url on success, or an error object if something went wrong.
     */
    function edit_link($field_name, $parser, $new_url, $old_url = '', $old_raw_url = '', $new_text = null)
    {
        // Ensure we're operating on a consistent copy of the wrapped object.
        /*
        Explanation

        Consider this scenario where the container object wraps a blog post :
            1) The container object gets created and loads the post data.
            2) Someone modifies the DB data corresponding to the post.
            3) The container tries to edit a link present in the post. However, the post
            has changed since the time it was first cached, so when the container updates
            the post with it's changes, it will overwrite whatever modifications were made
            in step 2.

        This would not be a problem if WP entities like posts and comments were
        actually real objects, not just bags of key=>value pairs, but oh well.

        Therefore, it is necessary to re-load the wrapped object before editing it.
        */
        $this->get_wrapped_object(true);

        // Get the current value of the field that needs to be edited.
        $old_value = $this->get_field($field_name);

        // store the new url
        $this->updating_urls = array(
            'old_url' => $old_url,
            'new_url' => $new_url,
        );

        // Have the parser modify the specified link. If successful, the parser will
        // return an associative array with two keys - 'content' and 'raw_url'.
        // Otherwise we'll get an instance of \WP_Error.
        if ($parser->is_link_text_editable()) {
            $edit_result = $parser->edit($old_value, $new_url, $old_url, $old_raw_url, $new_text);
        } else {
            $edit_result = $parser->edit($old_value, $new_url, $old_url, $old_raw_url);
        }
        if (is_wp_error($edit_result)) {
            return $edit_result;
        }

        // Update the field with the new value returned by the parser.
        $update_result = $this->update_field($field_name, $edit_result['content'], $old_value);
        if (is_wp_error($update_result)) {
            return $update_result;
        }

        // Return the new values to the instance.
        unset($edit_result['content']); // (Except content, which it doesn't need.)
        return $edit_result;
    }

    /**
     * Remove all links with the specified URL, leaving their anchor text intact.
     *
     * @param string    $field_name
     * @param Parser $parser
     * @param string    $url
     * @param string    $raw_url
     * @return bool|\WP_Error True on success, or an error object if something went wrong.
     */
    function unlink($field_name, $parser, $url, $raw_url = '')
    {
        // Ensure we're operating on a consistent copy of the wrapped object.
        $this->get_wrapped_object(true);

        $old_value = $this->get_field($field_name);

        $new_value = $parser->unlink($old_value, $url, $raw_url);
        if (is_wp_error($new_value)) {
            return $new_value;
        }

        return $this->update_field($field_name, $new_value, $old_value);
    }

    /**
     * Retrieve a list of links found in this container.
     *
     * @access public
     *
     * @return array of Link
     */
    function get_links()
    {
        $params = array(
            's_container_type' => $this->container_type,
            's_container_id'   => $this->container_id,
        );
        return LinkQuery::blc_get_links($params);
    }


    /**
     * Get action links to display in the "Source" column of the Tools -> Broken Links link table.
     *
     * @param string $container_field
     * @return array
     */
    function ui_get_action_links($container_field)
    {
        return array();
    }

    /**
     * Get the container name to display in the "Source" column of the Tools -> Broken Links link table.
     *
     * @param string $container_field
     * @param string $context
     * @return string
     */
    function ui_get_source($container_field, $context = 'display')
    {
        return sprintf('%s[%d] : %s', $this->container_type, $this->container_id, $container_field);
    }

    /**
     * Get edit URL. Returns the URL of the Dashboard page where the item associated with this
     * container can be edited.
     *
     * HTML entities like '&' will be properly escaped for display.
     *
     * @access protected
     *
     * @return string
     */
    function get_edit_url()
    {
        // Should be over-ridden in a sub-class.
        return '';
    }
}



