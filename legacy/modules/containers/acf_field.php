<?php
/*
Plugin Name: Acf fields
Description: Container module for acf fields.
Version: 1.0
Author: Janne Aalto

ModuleID: acf_field
ModuleCategory: container
ModuleClassName: blcAcfMetaManager
*/

use Blc\Abstract\Parser;
use Blc\Helper\ContainerHelper;
use Blc\Abstract\ContainerManager;
use Blc\Abstract\Container;
use Blc\Util\Utility;




// Note : If it ever becomes necessary to check metadata on objects other than posts, it will
// be fairly easy to extract a more general metadata container class from blcAcfMeta.

/**
 * blcAcfMeta - A link container class for post metadata (AKA custom fields).
 *
 * Due to the way metadata works, this container differs significantly from other containers :
 *    - container_field is equal to meta name, and container_id holds the ID of the post.
 *  - There is one synch. record per post that determines the synch. state of all metadata fields of that post.
 *    - Unlinking simply deletes the meta entry in question without involving the parser.
 *  - The list of parse-able $fields is not fixed. Instead, it's initialized based on the
 *    custom field list defined in Settings -> Link Checker.
 *  - The $wrapped_object is an array (and isn't really used for anything).
 *    - update_wrapped_object() does nothing.
 *
 * @package Broken Link Checker
 * @access public
 */
class blcAcfMeta extends Container
{
    var $meta_type = 'post';

    /**
     * Retrieve all metadata fields of the post associated with this container.
     * The results are cached in the internal $wrapped_object variable.
     *
     * @param bool $ensure_consistency
     *
     * @return object The wrapped object.
     */
    function get_wrapped_object($ensure_consistency = false)
    {
        if (is_null($this->wrapped_object) || $ensure_consistency) {
            $this->wrapped_object = get_metadata($this->meta_type, $this->container_id);
        }

        return $this->wrapped_object;
    }

    function update_wrapped_object()
    {
        trigger_error('Function blcAcfMeta::update_wrapped_object() does nothing and should not be used.', E_USER_WARNING);
        return false;
    }

    /**
     * Get the value of the specified metadata field of the object wrapped by this container.
     *
     * @access protected
     *
     * @param string $field Field name. If omitted, the value of the default field will be returned.
     *
     * @return array
     */
    function get_field($field = '')
    {
        global $wpdb;

        $meta = get_metadata('post', $this->container_id, '_' . $field, true);
        $key  = explode('|', str_replace('_field', '|field', $meta));

        if (is_array($key)) {
            $key = $key[count($key) - 1];
        } else {
            $key = $meta;
        }

        if (! isset($this->fields[$key])) {
            $key = $field;
        }

        $get_only_first_field = ('acf_field' !== $this->fields[$key]);

        return get_metadata($this->meta_type, $this->container_id, $field, $get_only_first_field);
    }

    /**
     * Update the value of the specified metadata field of the object wrapped by this container.
     *
     * @access protected
     *
     * @param string $field Meta name.
     * @param string $new_value New meta value.
     * @param string $old_value old meta value.
     *
     * @return bool|\WP_Error True on success, an error object if something went wrong.
     */
    function update_field($field, $new_value, $old_value = '')
    {
        $rez = update_metadata($this->meta_type, $this->container_id, $field, $new_value, $old_value);
        if ($rez) {
            return true;
        } else {
            return new \WP_Error('metadata_update_failed', sprintf(__("Failed to update the meta field '%1\$s' on %2\$s [%3\$d]", 'link-checker'), $field, $this->meta_type, $this->container_id));
        }
    }

    /**
     * "Unlink"-ing a custom fields removes all metadata fields that contain the specified URL.
     *
     * @param string    $field_name
     * @param Parser $parser
     * @param string    $url
     * @param string    $raw_url
     *
     * @return bool|\WP_Error True on success, or an error object if something went wrong.
     */
    function unlink($field_name, $parser, $url, $raw_url = '')
    {

        $meta = get_metadata('post', $this->container_id, '_' . $field_name, true);
        $key  = explode('|', str_replace('_field', '|field', $meta));

        if (is_array($key)) {
            $key = $key[count($key) - 1];
        }
        if ('acf_field' !== $this->fields[$key]) {
            return parent::unlink($field_name, $parser, $url, $raw_url);
        }

        $rez = update_metadata($this->meta_type, $this->container_id, $field_name, '');
        if ($rez) {
            return true;
        } else {
            return new \WP_Error('metadata_delete_failed', sprintf(__("Failed to delete the meta field '%1\$s' on %2\$s [%3\$d]", 'link-checker'), $field_name, $this->meta_type, $this->container_id));
        }
    }

    /**
     * Change a meta field containing the specified URL to a new URL.
     *
     * @param string    $field_name Meta name
     * @param Parser $parser
     * @param string    $new_url New URL.
     * @param string    $old_url
     * @param string    $old_raw_url Old meta value.
     * @param null      $new_text
     *
     * @return string|\WP_Error The new value of raw_url on success, or an error object if something went wrong.
     */
    function edit_link($field_name, $parser, $new_url, $old_url = '', $old_raw_url = '', $new_text = null)
    {

        $meta = get_metadata('post', $this->container_id, '_' . $field_name, true);
        $key  = explode('|', str_replace('_field', '|field', $meta));

        if (is_array($key)) {
            $key = $key[count($key) - 1];
        }

        if ('acf_field' !== $this->fields[$key]) {
            return parent::edit_link($field_name, $parser, $new_url, $old_url, $old_raw_url, $new_text);
        }

        if (empty($old_raw_url)) {
            $old_raw_url = $old_url;
        }

        // Get the current values of the field that needs to be edited.
        // The default metadata parser ignores them, but we're still going
        // to set this argument to a valid value in case someone writes a
        // custom meta parser that needs it.
        $old_value = $this->get_field($field_name);

        // Get the new field value (a string).
        $edit_result = $parser->edit($old_value, $new_url, $old_url, $old_raw_url);
        if (is_wp_error($edit_result)) {
            return $edit_result;
        }

        // Update the field with the new value returned by the parser.
        // Notice how $old_raw_url is used instead of $old_value. $old_raw_url contains the entire old
        // value of the metadata field (see blcMetadataParser::parse()) and thus can be used to
        // differentiate between multiple meta fields with identical names.
        $update_result = $this->update_field($field_name, $edit_result['content'], $old_raw_url);
        if (is_wp_error($update_result)) {
            return $update_result;
        }

        // Return the new "raw" URL.
        return $edit_result['raw_url'];
    }

    /**
     * Get the default link text to use for links found in a specific container field.
     *
     * @param string $field
     *
     * @return string
     */
    function default_link_text($field = '')
    {
        // Just use the field name. There's no way to know how the links inside custom fields are
        // used, so no way to know the "real" link text. Displaying the field name at least gives
        // the user a clue where to look if they want to find/modify the field.
        return $field;
    }

    function ui_get_source($container_field = '', $context = 'display')
    {

        if (! post_type_exists(get_post_type($this->container_id))) {
            // Error: Invalid post type. The user probably removed a CPT without removing the actual posts.
            $post_html = '';

            $post = get_post($this->container_id);
            if ($post) {
                $post_html .= sprintf('<span class="row-title">%s</span><br>', get_the_title($post));
            }
            $post_html .= sprintf('Invalid post type "%s"', htmlentities($this->container_type));

            return $post_html;
        }

        $post_html = sprintf('<a class="row-title" href="%s" title="%s">%s</a>', esc_url($this->get_edit_url()), esc_attr(__('Edit this post')), get_the_title($this->container_id));

        return $post_html;
    }

    function ui_get_action_links($container_field)
    {

        $actions = array();
        if (! post_type_exists(get_post_type($this->container_id))) {
            return $actions;
        }

        if (current_user_can('edit_post', $this->container_id)) {
            $actions['edit'] = '<span class="edit"><a href="' . $this->get_edit_url() . '" title="' . esc_attr(__('Edit this item')) . '">' . __('Edit') . '</a>';
        }
        $actions['view'] = '<span class="view"><a href="' . esc_url(get_permalink($this->container_id)) . '" title="' . esc_attr(sprintf(__('View "%s"', 'link-checker'), get_the_title($this->container_id))) . '" rel="permalink">' . __('View') . '</a>';

        return $actions;
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
        $post = get_post($this->container_id);
        if (! $post) {
            return '';
        }

        $context = 'display';

        // WP 3.0
        if ('display' === $context) {
            $action = '&amp;action=edit';
        } else {
            $action = '&action=edit';
        }

        $post_type_object = get_post_type_object($post->post_type);
        if (! $post_type_object) {
            return '';
        }

        return apply_filters('get_edit_post_link', admin_url(sprintf($post_type_object->_edit_link . $action, $post->ID)), $post->ID, $context);
    }

    /**
     * Get the base URL of the container. For custom fields, the base URL is the permalink of
     * the post that the field is attached to.
     *
     * @return string
     */
    function base_url()
    {
        return get_permalink($this->container_id);
    }
}

class blcAcfExtractedFieldWalker
{
    public function __construct(private $keys, private $selected_fields, private $url_fields, private $html_fields) {}

    public function walk_function($item, $key)
    {
        $key = explode('|', str_replace('_field', '|field', $key));

        if (is_array($key)) {
            $key = $key[count($key) - 1];
        }

        if (in_array($key, $this->url_fields)) {
            if (! filter_var($item, FILTER_VALIDATE_URL) === false) {
                $this->keys[] = $key;
            }
        }

        if (in_array($key, $this->html_fields)) {
            if ('' !== $item) {
                $this->keys[] = $key;
            }
        }
    }
}

class blcAcfMetaManager extends ContainerManager
{
    var $container_class_name = 'blcAcfMeta';

    protected $selected_fields = array();

    function init()
    {
        parent::init();

        // Figure out which custom fields we're interested in.
        if (is_array($this->plugin_conf->options['acf_fields'])) {
            $prefix_formats = array(
                'html'      => 'html',
                'acf_field' => 'acf_field',
            );
            foreach ($this->plugin_conf->options['acf_fields'] as $meta_name) {
                // The user can add an optional "format:" prefix to specify the format of the custom field.
                $parts = explode(':', $meta_name, 2);
                if ((count($parts) == 2) && in_array($parts[0], $prefix_formats)) {
                    $this->selected_fields[$parts[1]] = $prefix_formats[$parts[0]];
                } else {
                    $this->selected_fields[$meta_name] = 'acf_field';
                }
            }
        }

        // Intercept 2.9+ style metadata modification actions
        add_action('acf/save_post', $this->acf_save(...), 20, 1);

        // When a post is deleted, also delete the custom field container associated with it.
        add_action('delete_post', $this->post_deleted(...));
        add_action('trash_post', $this->post_deleted(...));

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

    function filterHtmlExtract($value)
    {
        if ('html' === $value) {
            return true;
        }
        return false;
    }

    /**
     * Instantiate multiple containers of the container type managed by this class.
     *
     * @param array  $containers Array of assoc. arrays containing container data.
     * @param string $purpose An optional code indicating how the retrieved containers will be used.
     * @param bool   $load_wrapped_objects Preload wrapped objects regardless of purpose.
     *
     * @return array of blcAcfMeta indexed by "container_type|container_id"
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

        $selected_fields = $this->selected_fields;

        $html_fields = array_filter($selected_fields, $this->filterHtmlExtract(...));

        $url_fields  = array_keys(array_diff($selected_fields, $html_fields));
        $html_fields = array_keys($html_fields);

        foreach ($containers as $key => $container) {
            $meta   = get_metadata('post', $container->container_id);
            $fields = array();

            foreach ($meta as $field => $value) {
                $value = explode('|', str_replace('_field', '|field', $value[0]));

                if (! is_array($value)) {
                    continue;
                } else {
                    $value = $value[count($value) - 1];

                    if (in_array($value, $url_fields)) {
                        $field = ltrim($field, '_');
                        if (! filter_var($meta[$field][0], FILTER_VALIDATE_URL) === false) {
                            $fields[$field] = 'acf_field';
                        }
                    }

                    if (in_array($value, $html_fields)) {
                        $field = ltrim($field, '_');
                        if ('' !== $meta[$field][0]) {
                            $fields[$field] = 'html';
                        }
                    }
                }
            }

            $containers[$key]->fields = $fields;
        }

        return $containers;
    }

    /**
     * Create or update synchronization records for all containers managed by this class.
     *
     * @param bool $forced If true, assume that all synch. records are gone and will need to be recreated from scratch.
     *
     * @return int
     */
    function resynch($forced = false): int
    {

        global $wpdb;
        /** @var wpdb $wpdb */
        global $blclog;
        $changed = 0;
        // Only check custom fields on selected post types. By default, that's "post" and "page".
        $post_types = array('post', 'page');

        $overlord   = \blcPostTypeOverlord::getInstance();
        $post_types = array_merge($post_types, $overlord->enabled_post_types);
        $post_types = array_unique($post_types);


        $escaped_post_types = "'" . implode("', '", array_map('esc_sql', $post_types)) . "'";

        if ($forced) {
            // Create new synchronization records for all posts.
            $blclog->log('...... Creating synch records for all custom fields on ' . $escaped_post_types);
            $start = microtime(true);
            $q     = "INSERT INTO {$wpdb->prefix}blc_synch(container_id, container_type, synched)
				  SELECT id, '{$this->container_type}', 0
				  FROM {$wpdb->posts}
				  WHERE
				  	{$wpdb->posts}.post_status = 'publish'
	 				AND {$wpdb->posts}.post_type IN ({$escaped_post_types})";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows inserted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
            $changed +=  $wpdb->rows_affected;
        } else {
            // Delete synch records corresponding to posts that no longer exist.
            $blclog->log('...... Deleting custom field synch records corresponding to deleted posts');
            $start = microtime(true);
            $q     = "DELETE synch.*
				  FROM
					 {$wpdb->prefix}blc_synch AS synch LEFT JOIN {$wpdb->posts} AS posts
					 ON posts.ID = synch.container_id
				  WHERE
					 synch.container_type = '{$this->container_type}' AND posts.ID IS NULL";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows deleted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
            $changed +=  $wpdb->rows_affected;
            // Remove the 'synched' flag from all posts that have been updated
            // since the last time they were parsed/synchronized.
            $blclog->log('...... Marking custom fields on changed posts as unsynched');
            $start = microtime(true);
            $q     = "UPDATE
					{$wpdb->prefix}blc_synch AS synch
					JOIN {$wpdb->posts} AS posts ON (synch.container_id = posts.ID and synch.container_type='{$this->container_type}')
				  SET
					synched = 0
				  WHERE
					synch.last_synch < posts.post_modified";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows updated in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
            $changed +=  $wpdb->rows_affected;
            // Create synch. records for posts that don't have them.
            $blclog->log('...... Creating custom field synch records for new ' . $escaped_post_types);
            $start = microtime(true);
            $q     = "INSERT INTO {$wpdb->prefix}blc_synch(container_id, container_type, synched)
				  SELECT id, '{$this->container_type}', 0
				  FROM
				    {$wpdb->posts} AS posts LEFT JOIN {$wpdb->prefix}blc_synch AS synch
					ON (synch.container_id = posts.ID and synch.container_type='{$this->container_type}')
				  WHERE
				  	posts.post_status = 'publish'
	 				AND posts.post_type IN ({$escaped_post_types})
					AND synch.container_id IS NULL";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows inserted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
            $changed +=  $wpdb->rows_affected;
        }
        return  $changed;
    }

    /**
     * Mark custom fields as unsynched when they're modified or deleted.
     *
     * @param $post_id
     *
     * @return void
     */
    function acf_save($post_id)
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        if (empty($this->selected_fields)) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || ! is_int($post_id)) {
            return;
        }

        $selected_fields = $this->selected_fields;

        $html_fields = array_filter($selected_fields, $this->filterHtmlExtract(...));

        $url_fields = array_keys(array_diff($selected_fields, $html_fields));

        $html_fields     = array_keys($html_fields);
        $selected_fields = array_keys($selected_fields);

        $meta   = get_metadata('post', $post_id);
        $fields = array();

        foreach ($meta as $field => $value) {
            $value = explode('|', str_replace('_field', '|field', $value[0]));

            if (! is_array($value)) {
                continue;
            } else {
                $value = $value[count($value) - 1];

                if (in_array($value, $url_fields)) {
                    $field = ltrim($field, '_');
                    if (! filter_var($meta[$field][0], FILTER_VALIDATE_URL) === false) {
                        $fields[$field] = 'acf_field';
                    }
                }

                if (in_array($value, $html_fields)) {
                    $field = ltrim($field, '_');
                    if ('' != $meta[$field][0]) {
                        $fields[$field] = 'html';
                    }
                }
            }
        }

        if (empty($fields)) {
            return;
        }

        $container = ContainerHelper::get_container(array($this->container_type, intval($post_id)));
        $container->mark_as_unsynched();
    }

    /**
     * Delete custom field synch. records when the post that they belong to is deleted.
     *
     * @param int $post_id
     *
     * @return void
     */
    function post_deleted($post_id)
    {
        // Get the associated container object

        $container = ContainerHelper::get_container(array($this->container_type, intval($post_id)));

        if (null != $container) {
            // Delete it
            $container->delete();
            // Clean up any dangling links
            Utility::blc_cleanup_links();
        }
    }

    /**
     * When a post is restored, mark all of its custom fields as unparsed.
     * Called via the 'untrashed_post' action.
     *
     * @param int $post_id
     *
     * @return void
     */
    function post_untrashed($post_id)
    {
        // Get the associated container object
        $container = ContainerHelper::get_container(array($this->container_type, intval($post_id)));
        $container->mark_as_unsynched();
    }
}
