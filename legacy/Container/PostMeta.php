<?php



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

namespace Blc\Container;

/**
 * A "dummy" container class that can be used as a fallback when the real container class can't be found.
 *
 * @package Broken Link Checker
 * @access public
 */



use Blc\Abstract\Parser;
use Blc\Abstract\Container;


class PostMeta extends Container
{
    var $meta_type = 'post';

    /**
     * Retrieve all metadata fields of the post associated with this container.
     * The results are cached in the internal $wrapped_object variable.
     *
     * @param bool $ensure_consistency
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
        trigger_error('Function PostMeta::update_wrapped_object() does nothing and should not be used.', E_USER_WARNING);
        return false;
    }

    /**
     * Get the value of the specified metadata field of the object wrapped by this container.
     *
     * @access protected
     *
     * @param string $field Field name. If omitted, the value of the default field will be returned.
     * @return array
     */
    function get_field($field = '', $single = false)
    {

        $get_only_first_field = ('metadata' !== $this->fields[$field]);

        // override the get only first by a param
        if ($single) {
            $get_only_first_field = true;
        }

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
     * @return bool|\WP_Error True on success, an error object if something went wrong.
     */
    function update_field($field, $new_value, $old_value = '')
    {

        // necessary for metas that store more than one value in a key
        $meta_value     = $this->get_field($field, true);
        $new_meta_value = $meta_value;
        if (is_array($meta_value)) {
            foreach ($meta_value as $key => $meta) {
                if ($meta === $old_value) {
                    $new_meta_value[$key] = $new_value;
                }
            }
            $new_value = $new_meta_value;
            $old_value = $meta_value;
        }

        // update the medatadata
        $rez = update_metadata($this->meta_type, $this->container_id, $field, $new_value, $old_value);
        if ($rez) {
            return true;
        } else {
            return new \WP_Error(
                'metadata_update_failed',
                sprintf(
                    __("Failed to update the meta field '%1\$s' on %2\$s [%3\$d]", 'link-checker'),
                    $field,
                    $this->meta_type,
                    $this->container_id
                )
            );
        }
    }

    /**
     * "Unlink"-ing a custom fields removes all metadata fields that contain the specified URL.
     *
     * @param string    $field_name
     * @param Parser $parser
     * @param string    $url
     * @param string    $raw_url
     * @return bool|\WP_Error True on success, or an error object if something went wrong.
     */
    function unlink($field_name, $parser, $url, $raw_url = '')
    {
        if ('metadata' !== $this->fields[$field_name]) {
            return parent::unlink($field_name, $parser, $url, $raw_url);
        }

        $rez = delete_metadata($this->meta_type, $this->container_id, $field_name, $raw_url);
        if ($rez) {
            return true;
        } else {
            return new \WP_Error(
                'metadata_delete_failed',
                sprintf(
                    __("Failed to delete the meta field '%1\$s' on %2\$s [%3\$d]", 'link-checker'),
                    $field_name,
                    $this->meta_type,
                    $this->container_id
                )
            );
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
     * @return string|\WP_Error The new value of raw_url on success, or an error object if something went wrong.
     */
    function edit_link($field_name, $parser, $new_url, $old_url = '', $old_raw_url = '', $new_text = null)
    {
        /*
        FB::log(sprintf(
            'Editing %s[%d]:%s - %s to %s',
            $this->container_type,
            $this->container_id,
            $field_name,
            $old_url,
            $new_url
        ));
        */

        if ('metadata' !== $this->fields[$field_name]) {
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
                $post_html .= sprintf(
                    '<span class="row-title">%s</span><br>',
                    get_the_title($post)
                );
            }
            $post_html .= sprintf(
                'Invalid post type "%s"',
                htmlentities($this->container_type)
            );

            return $post_html;
        }

        $post_html = sprintf(
            '<a class="row-title" href="%s" title="%s">%s</a>',
            esc_url($this->get_edit_url()),
            esc_attr(__('Edit this post')),
            get_the_title($this->container_id)
        );

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
