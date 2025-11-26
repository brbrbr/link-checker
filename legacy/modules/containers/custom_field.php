<?php

/*
Plugin Name: Custom fields
Description: Container module for post metadata.
Version: 1.0
Author: Janis Elsts

ModuleID: custom_field
ModuleCategory: container
ModuleClassName: PostMetaManager
*/

// Note : If it ever becomes necessary to check metadata on objects other than posts, it will
// be fairly easy to extract a more general metadata container class from PostMeta.

/**
 * PostMeta - A link container class for post metadata (AKA custom fields).
 *
 * Due to the way metadata works, this container differs significantly from other containers :
 *  - container_field is equal to meta name, and container_id holds the ID of the post.
 *  - There is one synch. record per post that determines the synch. state of all metadata fields of that post.
 *  - Unlinking simply deletes the meta entry in question without involving the parser.
 *  - The list of parse-able $fields is not fixed. Instead, it's initialized based on the
 *    custom field list defined in Settings -> Link Checker.
 *  - The $wrapped_object is an array (and isn't really used for anything).
 *  - update_wrapped_object() does nothing.
 *
 * @package Broken Link Checker
 * @access public
 */


use Blc\Abstract\ContainerManager;
use Blc\Container\PostMeta as Container;
use Blc\Util\Utility;


class PostMetaManager extends ContainerManager
{
    var $container_class_name  = Container::class;
    var $meta_type             = 'post';
    protected $selected_fields = array();

    function init()
    {
        parent::init();

        // Figure out which custom fields we're interested in.
        if (is_array($this->plugin_conf->options['custom_fields'])) {
            $prefix_formats = array(
                'html' => 'html',
                'url'  => 'metadata',
            );
            foreach ($this->plugin_conf->options['custom_fields'] as $meta_name) {
                // The user can add an optional "format:" prefix to specify the format of the custom field.
                $parts = explode(':', $meta_name, 2);
                if (count($parts) == 2) {
                    $type                               = strtolower($parts[0]);
                    $this->selected_fields[$parts[1]] = $prefix_formats[$type] ?? 'metadata';
                } else {
                    $this->selected_fields[$meta_name] = 'metadata';
                }
            }
        }
        if (empty($this->selected_fields)) {
            return;
        }

        // Intercept 2.9+ style metadata modification actions
        add_action("added_{$this->meta_type}_meta", $this->meta_modified(...), 10, 3);
        add_action("updated_{$this->meta_type}_meta", $this->meta_modified(...), 10, 3);
        add_action("deleted_{$this->meta_type}_meta", $this->meta_modified(...), 10, 3);

        // When a post is deleted, also delete the custom field container associated with it.
        add_action('delete_post', $this->post_deleted(...), 10, 1);
        add_action('trash_post', $this->post_deleted(...), 10, 1);

        // Re-parse custom fields when a post is restored from trash
        add_action('untrashed_post', $this->post_untrashed(...));
    }


    /**
     * Get a list of parseable fields.
     *
     * @return array
     */
    function get_parseable_fields()
    {
        return $this->selected_fields;
    }

    /**
     * Instantiate multiple containers of the container type managed by this class.
     *
     * @param array  $containers Array of assoc. arrays containing container data.
     * @param string $purpose An optional code indicating how the retrieved containers will be used.
     * @param bool   $load_wrapped_objects Preload wrapped objects regardless of purpose.
     *
     * @return array of PostMeta indexed by "container_type|container_id"
     */
    function get_containers($containers, $purpose = '', $load_wrapped_objects = false)
    {
        $containers = $this->make_containers($containers);

        /*
        When links from custom fields are displayed in Tools -> Broken Links,
        each one also shows the title of the post that the custom field(s)
        belong to. Thus it makes sense to pre-cache the posts beforehand - it's
        faster to load them all at once than to make a separate query for each
        one later.

        So make a list of involved post IDs and load them.

        Calling get_posts() will automatically populate the post cache, so we
        don't need to actually store the results anywhere in the container object().
        */
        $preload = $load_wrapped_objects || in_array($purpose, array(BLC_FOR_DISPLAY));
        if ($preload) {
            $post_ids = array();
            foreach ($containers as $container) {
                $post_ids[] = $container->container_id;
            }

            $args = array('include' => implode(',', $post_ids));
            get_posts($args);
        }

        return $containers;
    }

    /**
     * Create or update synchronization records for all containers managed by this class.
     *
     * @param bool $forced If true, assume that all synch. records are gone and will need to be recreated from scratch.
     * @return int
     */
    function resynch($forced = false): int
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        global $blclog;
        $changed = 0;

        $overlord   = \blcPostTypeOverlord::getInstance();


        // Only check custom fields on selected post types and statuses
        $escaped_post_types = "'" . implode("', '", array_map('esc_sql', $overlord->enabled_post_types)) . "'";
        $escaped_post_statuses =  "'" . implode("', '", array_map('esc_sql', $overlord->enabled_post_statuses)) . "'";

        if ($forced) {
            $blclog->log('...... Deleting custom field synch records corresponding to deleted posts');
            $start = microtime(true);
            $q     = "DELETE `synch`.* FROM `{$wpdb->prefix}blc_synch` AS `synch` WHERE `synch`.`container_type` = '{$this->container_type}'";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows deleted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
            $changed +=  $wpdb->rows_affected;
            // Create new synchronization records for all posts.

        } else {
            // Delete synch records corresponding to posts that no longer exist.
            $blclog->log('...... Deleting custom field synch records corresponding to deleted posts');
            $start = microtime(true);
            $q     = "DELETE `synch`.*
				  FROM
				 `{$wpdb->prefix}blc_synch` `synch` 
				  WHERE
                   `synch`.`container_type` = '{$this->container_type}'
                   AND
                    NOT EXISTS (SELECT `ID` FROM `{$wpdb->posts}` `posts` WHERE  `posts`.`ID` =  `synch`.`container_id`)";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows deleted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
            $changed +=  $wpdb->rows_affected;

            // Remove the 'synched' flag from all posts that have been updated
            // since the last time they were parsed/synchronized.
            $blclog->log('...... Marking custom fields on changed posts as unsynched');
            $start = microtime(true);
            $q     = "UPDATE `{$wpdb->prefix}blc_synch`  `synch` SET `synched` = 0
				  WHERE
                   `synch`.`container_type` = '{$this->container_type}'
                   AND
                     EXISTS (SELECT `ID` FROM `{$wpdb->posts}` `posts` WHERE  `posts`.`ID` =  `synch`.`container_id` AND `synch`.`last_synch` < `posts`.`post_modified`)";

            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows updated in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
            $changed +=  $wpdb->rows_affected;
        }
        // Create synch. records for posts that don't have them.
        $blclog->log('...... Creating custom field synch records for new ' . $escaped_post_types);
        $start = microtime(true);
        $q     = "INSERT IGNORE INTO `{$wpdb->prefix}blc_synch` (container_id, container_type, synched)
				  SELECT `id`, '{$this->container_type}', 0
				  FROM
				  `{$wpdb->posts}` `posts`
				  WHERE
				     `posts`.`post_status` IN ({$escaped_post_statuses})
	 				AND  `posts`.`post_type` IN ({$escaped_post_types})";
        $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $blclog->log(sprintf('...... %d rows inserted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
        $changed +=  $wpdb->rows_affected;

        return $changed;
    }

    /**
     * Mark custom fields as unsynched when they're modified or deleted.
     *
     * @param array|int $meta_id
     * @param int       $object_id
     * @param string    $meta_key
     * @param string    $meta_value
     * @return void
     */
    function meta_modified($meta_id, $object_id = 0, $meta_key = '')
    {

        // Metadata changes only matter to us if the modified key
        // is one that the user wants checked.
        if (empty($this->selected_fields)) {
            return;
        }
        global $blclog;
        global $wpdb;
        /** @var wpdb $wpdb */

        // If object_id isn't specified then the hook was probably called from the
        // stupidly inconsistent delete_meta() function in /wp-admin/includes/post.php.
        if (empty($object_id)) {
            // We must manually retrieve object_id and meta_key from the DB.
            if (is_array($meta_id)) {
                $meta_id = array_shift($meta_id);
            }

            $meta = $wpdb->get_row($wpdb->prepare("SELECT `post_id`,`meta_key` FROM $wpdb->postmeta WHERE meta_id = %d", $meta_id), ARRAY_A);
            if (empty($meta)) {
                return;
            }

            ['post_id' => $object_id, 'meta_key' => $meta_key] = $meta;
        }


        if (! array_key_exists($meta_key, $this->selected_fields)) {
            return;
        }

        // Skip revisions. We only care about custom fields on the main post.
        $post = get_post($object_id);
        if (empty($post) || ! isset($post->post_type) || ('revision' === $post->post_type)) {
            return;
        }

        $container = $this->get_container(array('container_type' => $this->container_type, 'container_id' => intval($object_id)));

        $container->mark_as_unsynched();
        $blclog->log('Container marked unsynhced', array($this->container_type, $object_id));
    }

    /**
     * Delete custom field synch. records when the post that they belong to is deleted.
     *
     * @param int $post_id
     * @return void
     */
    function post_deleted($post_id)
    {
        global $blclog;
        // Get the associated container object

        $container = $this->get_container(array('container_type' => $this->container_type, 'container_id' => intval($post_id)));
        if (null != $container) {
            // Delete it
            $container->delete();
            // Clean up any dangling links
            Utility::blc_cleanup_links();
        }
        $blclog->log('Container deleted',  $post_id);
    }

    /**
     * When a post is restored, mark all of its custom fields as unparsed.
     * Called via the 'untrashed_post' action.
     *
     * @param int $post_id
     * @return void
     */
    function post_untrashed($post_id)
    {
        global $blclog;
        // Get the associated container object
        $container = $this->get_container(array('container_type' => $this->container_type, 'container_id' => intval($post_id)));
        $container->mark_as_unsynched();
        $blclog->log('Container untrashed',  $post_id);
    }
}
