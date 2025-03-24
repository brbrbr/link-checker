<?php

/*
Plugin Name: Comments
Description:
Version: 1.0
Author: Janis Elsts

ModuleID: comment
ModuleCategory: container
ModuleClassName: blcCommentManager
*/

use Blc\Util\Utility;
use Blc\Helper\ContainerHelper;
use Blc\Abstract\ContainerManager;
use Blc\Abstract\Container;

class blcComment extends Container
{
    /**
     * Retrieve the comment wrapped by this container.
     * The fetched object will also be cached in the $wrapped_object variable.
     *
     * @access protected
     *
     * @param bool $ensure_consistency
     * @return object The comment.
     */
    function get_wrapped_object($ensure_consistency = false)
    {
        if ($ensure_consistency || is_null($this->wrapped_object)) {
            $this->wrapped_object = get_comment($this->container_id);
        }
        return $this->wrapped_object;
    }

    /**
     * Update the comment wrapped by the container with values currently in the $wrapped_object.
     *
     * @access protected
     *
     * @return bool|WP_Error True on success, an error if something went wrong.
     */
    function update_wrapped_object()
    {
        if (is_null($this->wrapped_object)) {
            return new WP_Error(
                'no_wrapped_object',
                __('Nothing to update', 'broken-link-checker')
            );
        }

        $data = (array) $this->wrapped_object;
        if (wp_update_comment($data)) {
            return true;
        }
        return new WP_Error(
            'update_failed',
            sprintf(__('Updating comment %d failed', 'broken-link-checker'), $this->container_id)
        );
    }




    /**
     * Get the default link text to use for links found in a specific container field.
     * For links in the comment body there is no default link text. For author links,
     * the link text will be equal to the author name + comment type (if any).
     *
     * @param string $field
     * @return string
     */
    function default_link_text($field = '')
    {

        if ('comment_author_url' == $field) {
            $w = $this->get_wrapped_object();
            if (! is_null($w)) {
                $text = $w->comment_author;

                // This lets us identify pingbacks & trackbacks.
                if (! empty($w->comment_type)) {
                    $text .= sprintf(' [%s]', $w->comment_type);
                }

                return $text;
            }
        }

        return '';
    }

    function ui_get_action_links($container_field)
    {
        $actions = array();

        $comment = $this->get_wrapped_object();
        $post    = get_post($comment->comment_post_ID); /* @var StdClass $post */

        // If the post type no longer exists, we can't really do anything with this comment.
        // WordPress will just throw errors if we try.
        if (! post_type_exists(get_post_type($post))) {
            return $actions;
        }


        $user_can = current_user_can('edit_post', $comment->comment_post_ID);
        if ($user_can) {
            $actions['edit'] = "<a href='" . $this->get_edit_url() . "' title='" . esc_attr__('Edit comment') . "'>" . __('Edit') . '</a>';
        }

        $actions['view'] = '<span class="view"><a href="' . get_comment_link($this->container_id) . '" title="' . esc_attr(__('View comment', 'broken-link-checker')) . '" rel="permalink">' . __('View') . '</a>';

        return $actions;
    }

    function ui_get_source($container_field = '', $context = 'display')
    {
        // Display a comment icon.
        if ('comment_author_url' == $container_field) {
            $image =  '/images/font-awesome/font-awesome-user.png';
        } else {
            $image = '/images/font-awesome/font-awesome-comment-alt.png';
        }

        $image = sprintf(
            '<img src="%s/legacy/images/%s" class="blc-small-image" title="%3$s" alt="%3$s"> ',
            esc_attr(plugins_url($image, BLC_PLUGIN_FILE_LEGACY)),
            $image,
            __('Comment', 'broken-link-checker')
        );

        $comment = $this->get_wrapped_object();
        if (! $comment) {
            $html  = __('Comment not found', 'broken-link-checker');
        } else {

            // Display a small text sample from the comment
            $text_sample = strip_tags($comment->comment_content);
            $text_sample = Utility::truncate($text_sample, 65);

            $html = sprintf(
                '<a href="%s" title="%s"><b>%s</b> &mdash; %s</a>',
                $this->get_edit_url(),
                esc_attr__('Edit comment'),
                esc_attr($comment->comment_author),
                $text_sample
            );
        }

        // Don't show the image in email notifications.
        if ('email' != $context) {
            $html = $image . $html;
        }

        return $html;
    }

    function get_edit_url()
    {
        return esc_url(admin_url("comment.php?action=editcomment&c={$this->container_id}"));
    }

    function base_url()
    {
        $comment_permalink = get_comment_link($this->container_id);
        return substr($comment_permalink, 0, strpos($comment_permalink, '#'));
    }
}

class blcCommentManager extends ContainerManager
{
    var $container_class_name = 'blcComment';

    var $fields = array(
        'comment_author_url' => 'url_field',
        'comment_content'    => 'html',
    );

    function init()
    {
        parent::init();

        add_action('post_comment', array($this, 'hook_post_comment'), 10, 2);
        add_action('edit_comment', array($this, 'hook_edit_comment'));
        add_action('transition_comment_status', array($this, 'hook_comment_status'), 10, 3);

        add_action('trashed_post_comments', array($this, 'hook_trashed_post_comments'), 10, 2);
        add_action('untrash_post_comments', array($this, 'hook_untrash_post_comments'));
    }

    function hook_post_comment($comment_id, $comment_status)
    {
        if ('1' == $comment_status) {
            $container = ContainerHelper::get_container(array($this->container_type, $comment_id));
            $container->mark_as_unsynched();
        }
    }

    function hook_edit_comment($comment_id)
    {
        if ('approved' == wp_get_comment_status($comment_id)) {
            $container = ContainerHelper::get_container(array($this->container_type, $comment_id));
            $container->mark_as_unsynched();
        }
    }

    function hook_comment_status($new_status, $old_status, $comment)
    {
        // We only care about approved comments.
        if (('approved' == $new_status) || ('approved' == $old_status)) {
            $container = ContainerHelper::get_container(array($this->container_type, $comment->comment_ID));
            if ('approved' == $new_status) {
                $container->mark_as_unsynched();
            } else {
                $container->delete();
                Utility::blc_cleanup_links();
            }
        }
    }

    function hook_trashed_post_comments(
        /** @noinspection PhpUnusedParameterInspection */
        $post_id,
        $statuses
    ) {
        foreach ($statuses as $comment_id => $comment_status) {
            if ('1' == $comment_status) {
                $container = ContainerHelper::get_container(array($this->container_type, $comment_id));
                $container->delete();
            }
        }
        Utility::blc_cleanup_links();
    }

    function hook_untrash_post_comments($post_id)
    {
        // Unlike with the 'trashed_post_comments' hook, WP doesn't pass the list of (un)trashed
        // comments to callbacks assigned to the 'untrash_post_comments' and 'untrashed_post_comments'
        // actions. Therefore, we must read it from the appropriate metadata entry.
        $statuses = get_post_meta($post_id, '_wp_trash_meta_comments_status', true);
        if (empty($statuses) || ! is_array($statuses)) {
            return;
        }

        foreach ($statuses as $comment_id => $comment_status) {
            if ('1' == $comment_status) { // if approved
                $container = ContainerHelper::get_container(array($this->container_type, $comment_id));
                $container->mark_as_unsynched();
            }
        }
    }

    /**
     * Create or update synchronization records for all comments.
     *
     * @param bool $forced If true, assume that all synch. records are gone and will need to be recreated from scratch.
     * @return void
     */
    function resynch($forced = false)
    {
        global $wpdb; /* @var wpdb $wpdb */
        global $blclog;

        if ($forced) {
            // Create new synchronization records for all comments.
            $blclog->log('...... Creating synch. records for comments');
            $start = microtime(true);
            $q     = "INSERT INTO {$wpdb->prefix}blc_synch(container_id, container_type, synched)
				  SELECT comment_ID, '{$this->container_type}', 0
				  FROM {$wpdb->comments}
				  WHERE
				  	{$wpdb->comments}.comment_approved = '1'";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows inserted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));
        } else {
            // Delete synch records corresponding to comments that no longer exist
            // or have been trashed/spammed/unapproved.
            $blclog->log('...... Deleting synch. records for removed comments');
            $start = microtime(true);
            $q     = "DELETE synch.*
				  FROM
					 {$wpdb->prefix}blc_synch AS synch LEFT JOIN {$wpdb->comments} AS comments
					 ON comments.comment_ID = synch.container_id
				  WHERE
					 synch.container_type = '{$this->container_type}'
					 AND (comments.comment_ID IS NULL OR comments.comment_approved <> '1')";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows deleted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));

            // Create synch. records for comments that don't have them.
            $blclog->log('...... Creating synch. records for new comments');
            $start = microtime(true);
            $q     = "INSERT INTO {$wpdb->prefix}blc_synch(container_id, container_type, synched)
				  SELECT comment_ID, '{$this->container_type}', 0
				  FROM
				    {$wpdb->comments} AS comments LEFT JOIN {$wpdb->prefix}blc_synch AS synch
					ON (synch.container_id = comments.comment_ID and synch.container_type='{$this->container_type}')
				  WHERE
				  	comments.comment_approved = '1'
					AND synch.container_id IS NULL";
            $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $blclog->log(sprintf('...... %d rows inserted in %.3f seconds', $wpdb->rows_affected, microtime(true) - $start));

            /*
            Note that there is no way to detect comments that were *edited* (not added - those
            will be caught by the above query) while the plugin was inactive. Unlike with posts,
            WP doesn't track comment modification times.
            */
        }
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
        global $wpdb; /* @var wpdb $wpdb */

        $containers = $this->make_containers($containers);

        // Preload comment data if it is likely to be useful later
        $preload = $load_wrapped_objects || in_array($purpose, array(BLC_FOR_DISPLAY, BLC_FOR_PARSING));
        if ($preload) {
            $comment_ids = array();
            foreach ($containers as $container) { /* @var Container $container */
                $comment_ids[] = $container->container_id;
            }

            // There's no WP function for retrieving multiple comments by their IDs,
            // so we query the DB directly.
            $q        = "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN (" . implode(', ', $comment_ids) . ')';
            $comments = $wpdb->get_results($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            foreach ($comments as $comment) {
                // Cache the comment in the internal WP object cache
                $comment = get_comment($comment); /* @var StdClass $comment */

                // Attach it to the container
                $key = $this->container_type . '|' . $comment->comment_ID;
                if (isset($containers[$key])) {
                    $containers[$key]->wrapped_object = $comment;
                }
            }
        }

        return $containers;
    }
}
