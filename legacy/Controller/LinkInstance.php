<?php

/**
 */

namespace Blc\Controller;

use Blc\Abstract\Parser;
use Blc\Controller\ModuleManager;
use Blc\Helper\ContainerHelper;

class LinkInstance
{
    // Object state
    var $is_new = false;

    // DB fields
    var $instance_id = 0;
    var $link_id     = 0;

    var $container_id    = 0;
    var $container_type  = '';
    var $container_field = '';

    var $parser_type = '';

    var $link_text    = '';
    var $link_context = '';
    var $raw_url      = '';

    /** @var Container */
    var $_container = null;
    var $_parser    = null;
    /** @var Link|null */
    var $_link = null;

    /**
     * LinkInstance::__construct()
     * Class constructor
     *
     * @param int|array $arg Either the instance ID or an associate array representing the instance's DB record. Should be NULL for new instances.
     */
    function __construct($arg = null)
    {
        global $wpdb; /** @var wpdb $wpdb */

        if (is_int($arg)) {
            // Load an instance with ID = $arg from the DB.
            $arr = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}blc_instances WHERE instance_id=%d LIMIT 1",
                    $arg
                ),
                ARRAY_A
            );

            if (is_array($arr)) { // Loaded successfully
                $this->set_values($arr);
            } else {
                // Link instance not found. The object is invalid.
            }
        } elseif (is_array($arg)) {
            $this->set_values($arg);

            // Is this a new instance?
            $this->is_new = empty($this->instance_id);
        } else {
            $this->is_new = true;
        }
    }



    /**
     * 
     * Set property values to the ones provided in an array (doesn't sanitize).
     *
     * @param array $arr An associative array
     * @return void
     */
    function set_values($arr)
    {
        foreach ($arr as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Replace this instance's URL with a new one.
     * Warning : this shouldn't be called directly. Use Link->edit() instead.
     *
     * @param string $new_url
     * @param string $old_url
     * @param string $new_text
     * @return bool|\WP_Error True on success, or an instance of \WP_Error if something went wrong.
     */
    function edit($new_url, $old_url = '', $new_text = null)
    {

        // Get the container that contains this link
        $container = $this->get_container();
        if (is_null($container)) {
            return new \WP_Error(
                'container_not_found',
                sprintf(__('Container %1$s[%2$d] not found', 'broken-link-checker'), $this->container_type, $this->container_id)
            );
        }

        // Get the parser.
        $parser = $this->get_parser();
        if (is_null($parser)) {
            return new \WP_Error(
                'parser_not_found',
                sprintf(__("Parser '%s' not found.", 'broken-link-checker'), $this->parser_type)
            );
        }

        // If the old URL isn't specified get it from the link record
        if (empty($old_url)) {
            $old_url = $this->get_url();
        }

        // Attempt to modify the link(s)
        $result = $container->edit_link($this->container_field, $parser, $new_url, $old_url, $this->raw_url, $new_text);
        if (is_string($result)) {
            // If the modification was successful, the container will return
            // the new raw_url for the instance. Save the URL and return true,
            // indicating success.
            $this->raw_url = $result;
            return true;
        } elseif (is_array($result)) {
            // More advanced containers/parsers may return an array of values to
            // modify several fields at once.
            $allowed_fields = array( 'raw_url', 'link_text', 'link_context' );
            foreach ($result as $key => $value) {
                if (in_array($key, $allowed_fields)) {
                    $this->$key = $value;
                }
            }
            return true;
        } else {
            // Otherwise, it will return an error object. In this case we'll
            // just pass it back to the caller and let them sort it out.
            return $result;
        }
    }

    /**
     * Remove this instance from the post/blogroll/etc. Also deletes the appropriate DB record(s).
     *
     * @return bool|\WP_Error
     */
    function unlink($url = null)
    {

        // Get the container that contains this link
        $container = $this->get_container();
        if (is_null($container)) {
            return new \WP_Error(
                'container_not_found',
                sprintf(__('Container %1$s[%2$d] not found', 'broken-link-checker'), $this->container_type, $this->container_id)
            );
        }

        // Get the parser.
        $parser = $this->get_parser();
        if (is_null($parser)) {
            return new \WP_Error(
                'parser_not_found',
                sprintf(__("Parser '%s' not found.", 'broken-link-checker'), $this->parser_type)
            );
        }

        // If the old URL isn't specified get it from the link record
        if (empty($url)) {
            $url = $this->get_url();
        }

        // Attempt to remove the link(s)
        return $container->unlink($this->container_field, $parser, $url, $this->raw_url);
    }

    /**
     * Remove the link instance record from database. Doesn't affect the thing that contains the link.
     *
     * @return mixed 1 on success, 0 if the instance wasn't found, false on error
     */
    function forget()
    {
        global $wpdb; /** @var wpdb $wpdb */

        if (! empty($this->instance_id)) {
            $rez = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}blc_instances WHERE instance_id=%d", $this->instance_id));
            return $rez;
        } else {
            return false;
        }
    }

    /**
     * Store the link instance in the database.
     * Saving the instance will also implicitly save the link record associated with it, if it wasn't already saved.
     *
     * @return bool TRUE on success, FALSE on error
     */
    function save()
    {
        global $wpdb; /** @var wpdb $wpdb */

        // Refresh the locally cached link & container properties, in case
        // the objects have changed since they were set.

        if (! is_null($this->_link)) {
            // If we have a link object assigned, but it's new, it won't have a DB ID yet.
            // We need to save the link to get the ID and be able to maintain the link <-> instance
            // association.
            if ($this->_link->is_new) {
                $rez = $this->_link->save();
                if (! $rez) {
                    return false;
                }
            }

            $this->link_id = $this->_link->link_id;
        }

        if (! is_null($this->_container)) {
            $this->container_type = $this->_container->container_type;
            $this->container_id   = $this->_container->container_id;
        }

        // If the link is new, insert a new row into the DB. Otherwise update the existing row.
        if ($this->is_new) {
            $rez = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}blc_instances
						( link_id, container_type, container_id, container_field, parser_type, link_text, link_context, raw_url )
						VALUES( %d, %s, %d, %s, %s, %s, %s, %s )",
                    $this->link_id,
                    $this->container_type,
                    $this->container_id,
                    $this->container_field,
                    $this->parser_type,
                    $this->link_text,
                    $this->link_context,
                    $this->raw_url
                )
            );

            $rez = false !== $rez;

            if ($rez) {
                $this->instance_id = $wpdb->insert_id;
                // If the instance was successfully saved then it's no longer "new".
                $this->is_new = ! $rez;
            }

            return $rez;
        } else {
            $rez = false !== $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}blc_instances
						SET
						link_id = %d,
						container_type = %s,
						container_id = %d,
						container_field = %s,
						parser_type = %s,
						link_text = %s,
						link_context = %s,
						raw_url = %s
						WHERE instance_id = %d",
                    $this->link_id,
                    $this->container_type,
                    $this->container_id,
                    $this->container_field,
                    $this->parser_type,
                    $this->link_text,
                    $this->link_context,
                    $this->raw_url,
                    $this->instance_id
                )
            );

            if ($rez) {
                // FB::info($this, "Instance updated");
            } else {
                // FB::error("DB error while updating instance {$this->instance_id} : {$wpdb->last_error}");
            }

            return $rez;
        }
    }

    /**
     * Get the URL associated with this instance.
     *
     * @return string The associated URL, or an empty string if the instance is currently not assigned to any link.
     */
    function get_url()
    {
        $link = $this->get_link();

        if (! is_null($link)) {
            return $link->url;
        } else {
            return '';
        }
    }

    /**
     * Get the container object associated with this link instance
     *
     * @return Container|null
     */
    function get_container()
    {
        if (is_null($this->_container)) {
            $this->_container = ContainerHelper::get_container(array( $this->container_type, $this->container_id ));
        }

        return $this->_container;
    }

    /**
     * Set a new container for the link instance.
     *
     * @param Container $new_container
     * @param string       $field
     * @return void
     */
    function set_container(&$new_container, $field = '')
    {
        $this->_container = &$new_container;

        $this->container_field = $field;

        if (! is_null($new_container)) {
            $this->container_type = $new_container->container_type;
            $this->container_id   = $new_container->container_id;
        } else {
            $this->container_type = '';
            $this->container_id   = 0;
        }
    }

    /**
     * Get the parser associated with this link instance.
     *
     * @return Parser|null
     */
    function get_parser()
    {
        if (is_null($this->_parser)) {
            $this->_parser = ModuleManager::getInstance()->get_parser($this->parser_type);
        }

        return $this->_parser;
    }

    /**
     * Set a new parser fo this link instance.
     *
     * @param Parser|null $new_parser
     * @return void
     */
    function set_parser(&$new_parser)
    {
        $this->_parser = &$new_parser;

        if (is_null($new_parser)) {
            $this->parser_type = '';
        } else {
            $this->parser_type = $new_parser->parser_type;
        }
    }

    /**
     * Get the link object associated with this link intance.
     *
     * @return Link|null
     */
    function get_link() : Link | Null
    {
        if (! is_null($this->_link)) {
            return $this->_link;
        }

        if (empty($this->link_id)) {
            return null;
        }

        $this->_link = new Link($this->link_id);
        return $this->_link;
    }

    /**
     * Set the link associated with this link instance.
     *
     * @param Link $new_link
     * @return void
     */
    function set_link($new_link)
    {
        $this->_link = $new_link;

        if (is_null($new_link)) {
            $this->link_id = 0;
        } else {
            $this->link_id = $new_link->link_id;
        }
    }

    /**
     * Get the link text for printing in the "Broken Links" table.
     *
     * @param string $context How to filter the link text. Optional, defaults to 'display'.
     * @return string HTML
     */
    function ui_get_link_text($context = 'display')
    {
        $parser = $this->get_parser();

        if (! is_null($parser)) {
            $text = $parser->ui_get_link_text($this, $context);
        } else {
            $text = strip_tags($this->link_text);
        }

        if (empty($text)) {
            $text = '<em>(None)</em>';
        }

        return $text;
    }

    /**
     * Get action links that should be displayed in the "Source" column of the "Broken Links" table.
     *
     * @return array An array of HTML links.
     */
    function ui_get_action_links()
    {
        // The container is responsible for generating the links.
        $container = $this->get_container();
        if (! is_null($container)) {
            return $container->ui_get_action_links($this->container_field);
        } else {
            // No valid container = no links.
            return array();
        }
    }

    /**
     * Get the HTML describing the "source" of the instance. For example, for links found in posts,
     * this could be the post title.
     *
     * @param string $context How to filter the output. Optional, defaults to 'display'.
     * @return string HTML
     */
    function ui_get_source($context = 'display')
    {
        // The container is also responsible for generating the "Source" column HTML.
        $container = $this->get_container();
        if (! is_null($container)) {
            return $container->ui_get_source($this->container_field, $context);
        } else {
            // No valid container = generate some bare-bones debug output.
            return sprintf('%s[%d] : %s', $this->container_type, $this->container_id, $this->container_field);
        }
    }

    /**
     * Check if the link text associated with this instance can be edited.
     *
     * @return bool
     */
    public function is_link_text_editable()
    {
        $parser = $this->get_parser();
        if (null === $parser) {
            return false;
        }
        return $parser->is_link_text_editable();
    }

    /**
     * Check if the URL of this instance can be edited.
     *
     * @return bool
     */
    public function is_url_editable()
    {
        $parser = $this->get_parser();
        if (null === $parser) {
            return false;
        }
        return $parser->is_url_editable();
    }

}