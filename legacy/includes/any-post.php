<?php

use Blc\Util\ConfigurationManager;
use Blc\Controller\ModuleManager;
use Blc\Abstract\ContainerManager;
use Blc\Abstract\Container;
use Blc\Helper\ContainerHelper;
use Blc\Util\Utility;
use Blc\Integrations\Elementor;
use Blc\Integrations\SiteOrigin;


/**
 * The manager to rule all (post) managers.
 *
 * This class dynamically registers container modules for the available post types
 * (including custom post types) and does stuff that pertain to all of them, such
 * as handling save/delete hooks and (re)creating synch records.
 *
 * @package Broken Link Checker
 * @author Janis Elsts
 * @access private
 */
class blcPostTypeOverlord
{
    public $enabled_post_types    = array();  // Post types currently selected for link checking
    public $enabled_post_statuses = array('publish'); // Only posts that have one of these statuses shall be checked

    var $plugin_conf;
    var $resynch_already_done = false;

    /**
     *
     * @return void
     */
    final private function __construct()
    {

        $this->plugin_conf = ConfigurationManager::getInstance();

        if (isset($this->plugin_conf->options['enabled_post_statuses'])) {
            $this->enabled_post_statuses = $this->plugin_conf->options['enabled_post_statuses'];
        }

        // Register a virtual container module for each enabled post type
        $module_manager = ModuleManager::getInstance();

        $post_types = get_post_types(array(), 'objects');
        $exceptions = array('revision', 'nav_menu_item', 'attachment');

        foreach ($post_types as $data) {
            $post_type = $data->name;

            if (in_array($post_type, $exceptions)) {
                continue;
            }

            $module_manager->register_virtual_module(
                $post_type,
                array(
                    'Name'            => $data->labels->name,
                    'ModuleCategory'  => 'container',
                    'ModuleContext'   => 'all',
                    'ModuleClassName' => 'blcAnyPostContainerManager',
                )
            );
        }

        // These hooks update the synch & instance records when posts are added, deleted or modified.
        add_action('delete_post', $this->post_deleted(...));
        add_action('save_post', $this->post_saved(...));
        // We also treat post trashing/untrashing as delete/save.
        add_action('trashed_post', $this->post_deleted(...));
        add_action('untrash_post', $this->post_saved(...));
    }


    public function __clone()/*: void*/
    {
        throw new \Error('Class singleton cant be cloned. (' . static::class . ' )');
    }

    public function __wakeup(): void
    {
        throw new \Error('Class singleton cant be serialized. (' . static::class . ' )');
    }
    /**
     * Retrieve an instance of the overlord class.
     *
     * @return blcPostTypeOverlord
     */
    static function getInstance()
    {
        static $instance = null;
        if (is_null($instance)) {
            $instance = new blcPostTypeOverlord();
        }
        return $instance;
    }

    /**
     * Notify the overlord that a post type is active.
     *
     * Called by individual instances of blcAnyPostContainerManager to let
     * the overlord know that they've been created. Since a module instance
     * is only created if the module is active, this event indicates that
     * the user has enabled the corresponding post type for link checking.
     *
     * @param string $post_type
     * @return void
     */
    function post_type_enabled($post_type)
    {
        if (! in_array($post_type, $this->enabled_post_types)) {
            $this->enabled_post_types[] = $post_type;
        }
    }

    /**
     * Remove the synch. record and link instances associated with a post when it's deleted or should not be indexed (anymore)
     *
     * @param int $post_id
     * @return void
     */
    function post_deleted($post_id)
    {
        global $blclog;
        $blclog->log(__CLASS__ . '::' . __FUNCTION__);
        global $wpdb;

        $post_id = intval($post_id);
        // Get the container type matching the type of the deleted post
        $post = get_post($post_id);
        if (! $post) {
            return;
        }

        // Get the associated container object
        $post_type      = get_post_type($post);
        $post_container = ContainerHelper::get_container(array($post_type, $post_id));

        if ($post_container) {
            $blclog->log(sprintf('Removing container instance: %s', $post_container->container_id));
            // Delete the container
            $post_container->delete();
        }
        Utility::blc_got_unsynched_items(); // let Brokenlinkchecker::work take core of the rest
        return;

        /* this doesn't make any sense at all, the delete above while clear all instances en synches for the container 
so all is gone and the code below will not find anything 
but is misses the Utility::blc_cleanup_links
*/
        if ($post_container) {
            $post_container->delete();
            // Firstly: See if we have any current instances
            $q_current_instance_ids = $wpdb->prepare(
                'SELECT instance_id FROM `' . $wpdb->prefix . 'blc_instances` WHERE container_id = %d AND container_type = %s',
                $post_id,
                $post_type
            );

            $blclog->log(sprintf('Removing container instance: %s', $q_current_instance_ids));

            $current_instance_ids_results = $wpdb->get_results($q_current_instance_ids, ARRAY_A);

            if ($wpdb->num_rows == 0) {
                $blclog->log(__CLASS__ . '::' . __FUNCTION__ . ': No current instances present, skip cleanup at once');
                // No current instances present, skip cleanup at once
                return;
            }

            $current_instance_ids = wp_list_pluck($current_instance_ids_results, 'instance_id');

            // Secondly: Get all link_ids used in our current instances
            $q_current_link_ids = 'SELECT DISTINCT link_id FROM `' . $wpdb->prefix . 'blc_instances` WHERE instance_id IN (\'' . implode("', '", $current_instance_ids) . '\')';

            $q_current_link_ids_results = $wpdb->get_results($q_current_link_ids, ARRAY_A);

            $current_link_ids = wp_list_pluck($q_current_link_ids_results, 'link_id');

            // Go ahead and remove blc_instances for this container, blc_cleanup_links( $current_link_ids ) will find and remove any dangling links in the blc_links table
            $wpdb->query('DELETE FROM `' . $wpdb->prefix . 'blc_instances` WHERE instance_id IN (\'' . implode("', '", $current_instance_ids) . '\')');

            // Clean up any dangling links
            Utility::blc_cleanup_links($current_link_ids);
        }
    }

    /**
     * When a post is saved or modified, mark it as unparsed.
     *
     * @param int $post_id
     * @return void
     */
    function post_saved($post_id)
    {

        global $blclog;
        $blclog->log(__CLASS__ . '::' . __FUNCTION__);
        // Get the container type matching the type of the deleted post
        $post = get_post($post_id);
        if (! $post) {
            return;
        }

        // Only check links in currently enabled post types
        // Only check posts that have one of the allowed statuses
        //let the work figure this out
        /* if (! in_array($post->post_type, $this->enabled_post_types)) {
            //delete the instances etc in case the type changed
            $this->post_deleted($post_id);
            return;
        }

        // Only check posts that have one of the allowed statuses
        if (! in_array($post->post_status, $this->enabled_post_statuses)) {
            //delete the instances etc in case the status changed
            $this->post_deleted($post_id);
            return;
        }
*/
        // Get the container & mark it as unparsed
        $args           = array($post->post_type, intval($post_id));
        $post_container = ContainerHelper::get_container($args);
        $post_container->mark_as_unsynched(); // let Brokenlinkchecker::work take core of the rest
    }


    /**
     * Create or update synchronization records for all posts.
     *
     * @param string $container_type
     * @param bool   $forced If true, assume that all synch. records are gone and will need to be recreated from scratch.
     * @return int
     */
    function resynch($container_type = '', $forced = false): int
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        global $blclog;
        $changed = 0;
        // Resynch is expensive in terms of DB performance. Thus we only do it once, processing
        // all post types in one go and ignoring any further resynch requests during this pageload.
        // BUG: This might be a problem if there ever is an actual need to run resynch twice or
        // more per pageload.
        if ($this->resynch_already_done) {
            $blclog->log(sprintf('...... Skipping "%s" resyncyh since all post types were already synched.', $container_type));
            return 0;
        }

        if (empty($this->enabled_post_types)) {
            $blclog->warn(sprintf('...... Skipping "%s" resyncyh since no post types are enabled.', $container_type));
            return 0;
        }

        $escaped_post_types    = array_map('esc_sql', $this->enabled_post_types);
        $escaped_post_statuses = array_map('esc_sql', $this->enabled_post_statuses);

        if ($forced) {
            // Create new synchronization records for all posts.
            $blclog->log('...... Creating synch records for these post types: ' . implode(', ', $escaped_post_types) . ' that have one of these statuses: ' . implode(', ', $escaped_post_statuses));
            $start = microtime(true);
            $q     = "INSERT INTO {$wpdb->prefix}blc_synch(container_id, container_type, synched)
				  SELECT posts.id, posts.post_type, 0
				  FROM {$wpdb->posts} AS posts
				  WHERE
				  	posts.post_status IN (%s)
	 				AND posts.post_type IN (%s)";
            $q     = sprintf(
                $q,
                "'" . implode("', '", $escaped_post_statuses) . "'",
                "'" . implode("', '", $escaped_post_types) . "'"
            );
            $wpdb->query($q);
            $blclog->log(sprintf('...... %d rows inserted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
            $changed += $wpdb->rows_affected;
        } else {
            // Delete synch records corresponding to posts that no longer exist.
            // Also delete posts that don't have enabled post status
            $blclog->log('...... Deleting synch records for removed posts & post with invalid status');
            $start        = microtime(true);
            $all_posts_id = get_posts(
                array(
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'post_type'      => $this->enabled_post_types,
                    'post_status'    => $this->enabled_post_statuses,
                )
            );

            $q = "DELETE synch.* FROM {$wpdb->prefix}blc_synch AS synch WHERE synch.container_id NOT IN (%s)";

            $q = sprintf(
                $q,
                "'" . implode("', '", $all_posts_id) . "'"
            );

            $wpdb->query($q);
            $elapsed = microtime(true) - $start;
            $blclog->debug($q);
            $blclog->log(sprintf('...... %d rows deleted in %.3f seconds', $wpdb->rows_affected, $elapsed));
            $changed += $wpdb->rows_affected;
            // //Delete records where the post status is not one of the enabled statuses.
            // $blclog->log( '...... Deleting synch records for posts that have a disallowed status' );
            // $start = microtime( true );
            // $all_posts_status     = get_posts(
            // array(
            // 'posts_per_page' => -1,
            // 'fields'         => 'ids',
            // 'post_type'      => $this->enabled_post_types,
            // 'post_status'    => $this->enabled_post_statuses,
            // )
            // );

            // $q     = "DELETE synch.*
            // FROM
            // {$wpdb->prefix}blc_synch AS synch
            // WHERE
            // posts.post_status NOT IN (%s)";
            // $q     = sprintf(
            // $q,
            // "'" . implode( "', '", $escaped_post_statuses ) . "'",
            // "'" . implode( "', '", wp_list_pluck( $all_posts, 'post_status' ) ) . "'",
            // );
            // $wpdb->query( $q );
            // $elapsed = microtime( true ) - $start;
            // $blclog->debug( $q );
            // $blclog->log( sprintf( '...... %d rows deleted in %.3f seconds', $wpdb->rows_affected, $elapsed ) );

            // Remove the 'synched' flag from all posts that have been updated
            // since the last time they were parsed/synchronized.
            $blclog->log('...... Marking changed posts as unsynched');
            $start = microtime(true);
            $q     = "UPDATE
					{$wpdb->prefix}blc_synch AS synch
					JOIN {$wpdb->posts} AS posts ON (synch.container_id = posts.ID and synch.container_type=posts.post_type)
				  SET
					synched = 0
				  WHERE
					synch.last_synch < posts.post_modified";
            $wpdb->query($q);
            $elapsed = microtime(true) - $start;
            $blclog->debug($q);
            $blclog->log(sprintf('...... %d rows updated in %.3f seconds', $wpdb->rows_affected, $elapsed));
            $changed += $wpdb->rows_affected;
            // Create synch. records for posts that don't have them.
            $blclog->log('...... Creating synch records for new posts');
            $start = microtime(true);
            $q     = "INSERT INTO {$wpdb->prefix}blc_synch(container_id, container_type, synched)
				  SELECT posts.id, posts.post_type, 0
				  FROM
				    {$wpdb->posts} AS posts LEFT JOIN {$wpdb->prefix}blc_synch AS synch
					ON (synch.container_id = posts.ID and synch.container_type=posts.post_type)
				  WHERE
				  	posts.post_status IN (%s)
	 				AND posts.post_type IN (%s)
					AND synch.container_id IS NULL";
            $q     = sprintf(
                $q,
                "'" . implode("', '", $escaped_post_statuses) . "'",
                "'" . implode("', '", $escaped_post_types) . "'"
            );
            $wpdb->query($q);
            $elapsed = microtime(true) - $start;
            $blclog->debug($q);
            $blclog->log(sprintf('...... %d rows inserted in %.3f seconds', $wpdb->rows_affected, $elapsed));
            $changed += $wpdb->rows_affected;
        }

        $this->resynch_already_done = true;
        return $changed;
    }
}




/**
 * Universal container item class used for all post types.
 *
 * @package Broken Link Checker
 * @author Janis Elsts
 * @access public
 */
class blcAnyPostContainer extends Container
{
    var $default_field = 'post_content';

    public $updating_urls = null;

    /**
     * Get action links for this post.
     *
     * @param string $container_field Ignored.
     * @return array of action link HTML.
     */
    function ui_get_action_links($container_field = '')
    {
        $actions = array();

        // Fetch the post (it should be cached already)
        $post = $this->get_wrapped_object();
        if (! $post) {
            return $actions;
        }

        $post_type_object = get_post_type_object($post->post_type);

        //2.4.3
        if (! $post_type_object) {
            return $actions;
        }


        // Each post type can have its own cap requirements
        if (current_user_can($post_type_object->cap->edit_post, $this->container_id)) {
            $actions['edit'] = sprintf(
                '<span class="edit"><a href="%s" title="%s">%s</a>',
                $this->get_edit_url(),
                $post_type_object->labels->edit_item,
                __('Edit')
            );
        }

        // View/Preview link
        $title = get_the_title($this->container_id);
        if (in_array($post->post_status, array('pending', 'draft'))) {
            if (current_user_can($post_type_object->cap->edit_post, $this->container_id)) {
                $actions['view'] = sprintf(
                    '<span class="view"><a href="%s" title="%s" rel="permalink">%s</a>',
                    esc_url(add_query_arg('preview', 'true', get_permalink($this->container_id))),
                    esc_attr(sprintf(__('Preview &#8220;%s&#8221;'), $title)),
                    __('Preview')
                );
            }
        } elseif ('trash' != $post->post_status) {
            $actions['view'] = sprintf(
                '<span class="view"><a href="%s" title="%s" rel="permalink">%s</a>',
                esc_url(get_permalink($this->container_id)),
                esc_attr(sprintf(__('View &#8220;%s&#8221;'), $title)),
                __('View')
            );
        }

        return $actions;
    }

    /**
     * Get the HTML for displaying the post title in the "Source" column.
     *
     * @param string $container_field Ignored.
     * @param string $context How to filter the output. Optional, defaults to 'display'.
     * @return string HTML
     */
    function ui_get_source($container_field = '', $context = 'display')
    {
        $source = '<a class="row-title" href="%s" title="%s">%s</a>';
        $source = sprintf(
            $source,
            $this->get_edit_url(),
            esc_attr(__('Edit this item')),
            get_the_title($this->container_id)
        );

        return $source;
    }

    /**
     * Get edit URL for this container. Returns the URL of the Dashboard page where the item
     * associated with this container can be edited.
     *
     * @access protected
     *
     * @return string
     */
    function get_edit_url()
    {
        /*
        The below is a near-exact copy of the get_post_edit_link() function.
        Unfortunately we can't just call that function because it has a hardcoded
        caps-check which fails when called from the email notification script
        executed by Cron.
        */
        $post = $this->get_wrapped_object();
        if (! $post) {
            return '';
        }

        $context = 'display';
        $action  = '&amp;action=edit';

        $post_type_object = get_post_type_object($post->post_type);
        if (! $post_type_object) {
            return '';
        }

        //2.4.3
        if ('wp_template' === $post->post_type || 'wp_template_part' === $post->post_type) {
            $slug = urlencode(get_stylesheet() . '//' . $post->post_name);
            $link = admin_url(sprintf($post_type_object->_edit_link, $post->post_type, $slug));
        } elseif ('wp_navigation' === $post->post_type) {
            $link = admin_url(sprintf($post_type_object->_edit_link, (string) $post->ID));
        } elseif ($post_type_object->_edit_link) {
            $link = admin_url(sprintf($post_type_object->_edit_link . $action, $post->ID));
        }

        $post_type_object->_edit_link = str_replace('postType=%s&', '', $post_type_object->_edit_link);

        return apply_filters('get_edit_post_link', $link, $post->ID, $context);
    }

    /**
     * Retrieve the post associated with this container.
     *
     * @access protected
     *
     * @param bool $ensure_consistency Set this to true to ignore the cached $wrapped_object value and retrieve an up-to-date copy of the wrapped object from the DB (or WP's internal cache).
     * @return object Post data.
     */
    function get_wrapped_object($ensure_consistency = false)
    {
        if ($ensure_consistency || is_null($this->wrapped_object)) {
            $this->wrapped_object = get_post($this->container_id);
        }
        return $this->wrapped_object;
    }

    /**
     * Update the post associated with this container.
     *
     * @access protected
     *
     * @return bool|\WP_Error True on success, an error if something went wrong.
     */
    function update_wrapped_object()
    {
        if (is_null($this->wrapped_object)) {
            return new \WP_Error(
                'no_wrapped_object',
                __('Nothing to update', 'broken-link-checker')
            );
        }

        $post                        = $this->wrapped_object;
        $post->blc_post_modified     = $post->post_modified;
        $post->blc_post_modified_gmt = $post->post_modified_gmt;
        kses_remove_filters();
        $post_id                     = wp_update_post($post, true);
        kses_init_filters();

        if (is_wp_error($post_id)) {
            return $post_id;
        } elseif ($post_id == 0) {
            return new \WP_Error(
                'update_failed',
                sprintf(__('Updating post %d failed', 'broken-link-checker'), $this->container_id)
            );
        } else {
            $this->update_pagebuilders($post_id);
            return true;
        }
    }

    /**
     * Update the the links on pagebuilders
     *
     * @param int $post_id  Post ID of whose content to update.
     * updated 2.4.3
     */
    function update_pagebuilders($post_id)
    {
        if (! $post_id) {
            return;
        }

        // support for elementor page builder.
        if (class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->db->is_built_with_elementor($post_id)) {
            if (is_array($this->updating_urls)) {
                if (isset($this->updating_urls['old_url']) && isset($this->updating_urls['new_url'])) {
                    // Editing case.
                    $old_url = $this->updating_urls['old_url'];
                    $new_url = $this->updating_urls['new_url'];
                    Elementor::instance()->update_blc_links($old_url, $new_url, $post_id);
                } else { // Unlinking case.
                    foreach ($this->updating_urls as $key => $link_info) {
                        $old_url = $link_info['#raw'];
                        $new_url = $link_info['#new_raw'];

                        if ($old_url === $new_url) {
                            continue;
                        }

                        Elementor::instance()->update_blc_links($old_url, $new_url, $post_id);
                    }
                }
            }
        }

        // Support for Page Builder by SiteOrigin.
        if (class_exists('\SiteOrigin_Panels') && get_post_meta($post_id, 'panels_data', true)) {
            if (is_array($this->updating_urls)) {
                if (isset($this->updating_urls['old_url']) && isset($this->updating_urls['new_url'])) {
                    // Editing case.
                    $old_url = $this->updating_urls['old_url'];
                    $new_url = $this->updating_urls['new_url'];
                    SiteOrigin::instance()->update_blc_links($old_url, $new_url, $post_id);
                } else { // Unlinking case.
                    foreach ($this->updating_urls as $key => $link_info) {
                        $old_url = $link_info['#raw'];
                        $new_url = $link_info['#new_raw'];

                        if ($old_url === $new_url) {
                            continue;
                        }

                        SiteOrigin::instance()->update_blc_links($old_url, $new_url, $post_id);
                    }
                }
            }
        }
    }

    /**
     * Get the base URL of the container. For posts, the post permalink is used
     * as the base URL when normalizing relative links.
     *
     * @return string
     */
    function base_url()
    {
        return get_permalink($this->container_id);
    }
}

/**
 * Universal manager usable for most post types.
 *
 * @package Broken Link Checker
 * @access public
 */
class blcAnyPostContainerManager extends ContainerManager
{
    var $container_class_name = 'blcAnyPostContainer';
    var $fields               = array(
        'post_content' => 'html',
        'post_excerpt' => 'html',
    );

    function init()
    {
        parent::init();

        // Notify the overlord that the post/container type that this instance is
        // responsible for is enabled.
        $overlord = blcPostTypeOverlord::getInstance();
        $overlord->post_type_enabled($this->container_type);
    }

    /**
     * Instantiate multiple containers of the container type managed by this class.
     *
     * @param array  $containers Array of assoc. arrays containing container data.
     * @param string $purpose An optional code indicating how the retrieved containers will be used.
     * @param bool   $load_wrapped_objects Preload wrapped objects regardless of purpose.
     *
     * @return array of blcPostContainer indexed by "container_type|container_id"
     */
    function get_containers($containers, $purpose = '', $load_wrapped_objects = false)
    {
        global $blclog;
        $containers = $this->make_containers($containers);

        // Preload post data if it is likely to be useful later
        $preload = $load_wrapped_objects || in_array($purpose, array(BLC_FOR_DISPLAY, BLC_FOR_PARSING));
        if ($preload) {
            $post_ids = array();
            foreach ($containers as $container) {
                $post_ids[] = $container->container_id;
            }

            $args  = array('include' => implode(',', $post_ids));
            $posts = get_posts($args);

            foreach ($posts as $post) {
                $key = $this->container_type . '|' . $post->ID;
                if (isset($containers[$key])) {
                    $containers[$key]->wrapped_object = $post;
                }
            }
        }

        return $containers;
    }

    /**
     * Create or update synchronization records for all posts.
     *
     * @param bool $forced If true, assume that all synch. records are gone and will need to be recreated from scratch.
     * @return int
     */
    function resynch($forced = false): int
    {
        $overlord = blcPostTypeOverlord::getInstance();
        return $overlord->resynch($this->container_type, $forced);
    }
}
