<?php

/**
 * @author W-Shadow
 * @copyright 2010
 */

namespace Blc\Util;


use Blc\Util\ConfigurationManager;
use Blc\Helper\ContainerHelper;
use Blc\Controller\LinkInstance;
use Blc\Controller\ModuleManager;

// Include the internationalized domain name converter (requires PHP 5)

//worpdress has its own idn converter. //use WpOrg\Requests\IdnaEncoder;
//however only one direction



class Utility
{
    public static function is_host_wp_engine()
    {
        return (function_exists('is_wpe') && is_wpe()) || (defined('IS_WPE') && IS_WPE);
    }

    public static function is_host_flywheel()
    {
        $host_name = 'flywheel';

        return !empty($_SERVER['SERVER_SOFTWARE']) &&
            substr(strtolower($_SERVER['SERVER_SOFTWARE']), 0, strlen($host_name)) === strtolower($host_name);
    }

    /**
     * Utility::is_open_basedir()
     * Checks if open_basedir is enabled
     *
     * @return bool
     */
    static function is_open_basedir()
    {
        $open_basedir = ini_get('open_basedir');
        return $open_basedir && (strtolower($open_basedir) != 'none');
    }

    /**
     * Truncate a string on a specified boundary character.
     *
     * @param string  $text The text to truncate.
     * @param integer $max_characters Return no more than $max_characters
     * @param string  $break Break on this character. Defaults to space.
     * @param string  $pad Pad the truncated string with this string. Defaults to an HTML ellipsis.
     * @return string
     */
    static function truncate($text, $max_characters = 0, $break = ' ', $pad = '&hellip;')
    {
        if (strlen($text) <= $max_characters) {
            return $text;
        }

        $text      = substr($text, 0, $max_characters);
        $break_pos = strrpos($text, $break);
        if (false !== $break_pos) {
            $text = substr($text, 0, $break_pos);
        }

        return $text . $pad;
    }

    /**
     * extract_tags()
     * Extract specific HTML tags and their attributes from a string.
     *
     * You can either specify one tag, an array of tag names, or a regular expression that matches the tag name(s).
     * If multiple tags are specified you must also set the $selfclosing parameter and it must be the same for
     * all specified tags (so you can't extract both normal and self-closing tags in one go).
     *
     * The function returns a numerically indexed array of extracted tags. Each entry is an associative array
     * with these keys :
     *   tag_name    - the name of the extracted tag, e.g. "a" or "img".
     *   offset      - the numberic offset of the first character of the tag within the HTML source.
     *   contents    - the inner HTML of the tag. This is always empty for self-closing tags.
     *   attributes  - a name -> value array of the tag's attributes, or an empty array if the tag has none.
     *   full_tag    - the entire matched tag, e.g. '<a href="http://example.com">example.com</a>'. This key
     *                 will only be present if you set $return_the_entire_tag to true.
     *
     * @param string       $html The HTML code to search for tags.
     * @param string|array $tag The tag(s) to extract.
     * @param bool         $selfclosing  Whether the tag is self-closing or not. Setting it to null will force the script to try and make an educated guess.
     * @param bool         $return_the_entire_tag Return the entire matched tag in 'full_tag' key of the results array.
     * @param string       $charset The character set of the HTML code. Defaults to ISO-8859-1.
     *
     * @return array An array of extracted tags, or an empty array if no matching tags were found.
     */
    static function extract_tags($html, $tag, $selfclosing = null, $return_the_entire_tag = false, $charset = 'ISO-8859-1')
    {

        if (is_array($tag)) {
            $tag = implode('|', $tag);
        }

        // If the user didn't specify if $tag is a self-closing tag we try to auto-detect it
        // by checking against a list of known self-closing tags.
        $selfclosing_tags = array('area', 'base', 'basefont', 'br', 'hr', 'input', 'img', 'link', 'meta', 'col', 'param');
        if (is_null($selfclosing)) {
            $selfclosing = in_array($tag, $selfclosing_tags);
        }

        // The regexp is different for normal and self-closing tags because I can't figure out
        // how to make a sufficiently robust unified one.
        if ($selfclosing) {
            $tag_pattern =
                '@<(?P<tag>' . $tag . ')			# <tag
					(?P<attributes>\s[^>]+)?			# attributes, if any
					\s*/?>								# /> or just >, being lenient here
					@xsi';
        } else {
            $tag_pattern =
                '@<(?P<tag>' . $tag . ')			# <tag
					(?P<attributes>\s[^>]+)?	 		# attributes, if any
					\s*>						 		# >
					(?P<contents>.*?)			 		# tag contents
					</(?P=tag)>					 		# the closing </tag>
					@xsi';
        }

        $attribute_pattern =
            '@
				(?P<name>\w+)											# attribute name
				\s*=\s*
				(
					(?P<quote>[\"\'])(?P<value_quoted>.*?)(?P=quote)	# a quoted value
					|							# or
					(?P<value_unquoted>[^\s"\']+?)(?:\s+|$)				# an unquoted value (terminated by whitespace or EOF)
				)
				@xsi';

        // Find all tags
        if (!preg_match_all($tag_pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            // Return an empty array if we didn't find anything
            return array();
        }

        $tags = array();
        foreach ($matches as $match) {
            // Parse tag attributes, if any.
            $attributes = array();
            if (!empty($match['attributes'][0])) {
                if (preg_match_all($attribute_pattern, $match['attributes'][0], $attribute_data, PREG_SET_ORDER)) {
                    // Turn the attribute data into a name->value array
                    foreach ($attribute_data as $attr) {
                        if (!empty($attr['value_quoted'])) {
                            $value = $attr['value_quoted'];
                        } elseif (!empty($attr['value_unquoted'])) {
                            $value = $attr['value_unquoted'];
                        } else {
                            $value = '';
                        }

                        // Passing the value through html_entity_decode is handy when you want
                        // to extract link URLs or something like that. You might want to remove
                        // or modify this call if it doesn't fit your situation.
                        $value = html_entity_decode($value, ENT_QUOTES, $charset);

                        $attributes[$attr['name']] = $value;
                    }
                }
            }

            $tag = array(
                'tag_name'   => $match['tag'][0],
                'offset'     => $match[0][1],
                'contents'   => !empty($match['contents']) ? $match['contents'][0] : '', // Empty for self-closing tags.
                'attributes' => $attributes,
            );
            if ($return_the_entire_tag) {
                $tag['full_tag'] = $match[0][0];
            }

            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * Get the value of a cookie.
     *
     * @param string $cookie_name The name of the cookie to return.
     * @param string $default_value Optional. If the cookie is not set, this value will be returned instead. Defaults to an empty string.
     * @return mixed Either the value of the requested cookie, or $default_value.
     */
    static function get_cookie($cookie_name, $default_value = '')
    {
        if (isset($_COOKIE[$cookie_name])) {
            return $_COOKIE[$cookie_name];
        } else {
            return $default_value;
        }
    }

    /**
     * Format a time delta using a fuzzy format, e.g. '2 minutes ago', '2 days', etc.
     *
     * @param int    $delta Time period in seconds.
     * @param string $type Optional. The output template to use.
     * @return string
     */
    static function fuzzy_delta($delta, $template = 'default')
    {

        $templates = array(
            'seconds' => array(
                'default' => _n_noop('%d second', '%d seconds'),
                'ago'     => _n_noop('%d second ago', '%d seconds ago'),
            ),
            'minutes' => array(
                'default' => _n_noop('%d minute', '%d minutes'),
                'ago'     => _n_noop('%d minute ago', '%d minutes ago'),
            ),
            'hours'   => array(
                'default' => _n_noop('%d hour', '%d hours'),
                'ago'     => _n_noop('%d hour ago', '%d hours ago'),
            ),
            'days'    => array(
                'default' => _n_noop('%d day', '%d days'),
                'ago'     => _n_noop('%d day ago', '%d days ago'),
            ),
            'months'  => array(
                'default' => _n_noop('%d month', '%d months'),
                'ago'     => _n_noop('%d month ago', '%d months ago'),
            ),
        );

        if ($delta < 1) {
            $delta = 1;
        }

        if ($delta < MINUTE_IN_SECONDS) {
            $units = 'seconds';
        } elseif ($delta < HOUR_IN_SECONDS) {
            $delta = intval($delta / MINUTE_IN_SECONDS);
            $units = 'minutes';
        } elseif ($delta < DAY_IN_SECONDS) {
            $delta = intval($delta / HOUR_IN_SECONDS);
            $units = 'hours';
        } elseif ($delta < MONTH_IN_SECONDS) {
            $delta = intval($delta / DAY_IN_SECONDS);
            $units = 'days';
        } else {
            $delta = intval($delta / MONTH_IN_SECONDS);
            $units = 'months';
        }

        return sprintf(
            _n(
                $templates[$units][$template][0], //phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingle
                $templates[$units][$template][1], //phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralPlural
                $delta,
                'broken-link-checker'
            ),
            $delta
        );
    }

    /**
     * Optimize the plugin's tables
     *
     * @return void
     */
    static function optimize_database()
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        $wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}blc_links, {$wpdb->prefix}blc_instances, {$wpdb->prefix}blc_synch");
    }

    /**
     * Delete synch. records, instances and links that refer to missing or invalid items.
     *
     * @return void
     */
    static function blc_cleanup_database()
    {
        global $blclog;

        // Delete synch. records for container types that don't exist
        $blclog->info('... Deleting invalid container records');
        ContainerHelper::cleanup_containers();

        // Delete invalid instances
        $blclog->info('... Deleting invalid link instances');
        self::blc_cleanup_instances();

        // Delete orphaned links
        $blclog->info('... Deleting orphaned links');
        self::blc_cleanup_links();
    }

    /**
     * Remove orphaned links that have no corresponding instances.
     *
     * @param int|array $link_id (optional) Only check these links
     * @return bool
     */
    static function blc_cleanup_links($link_id = null)
    {
        global $wpdb; /* @var wpdb $wpdb */
        global $blclog;

        $start = microtime(true);
        $q     = "DELETE FROM {$wpdb->prefix}blc_links
			USING {$wpdb->prefix}blc_links LEFT JOIN {$wpdb->prefix}blc_instances
				ON {$wpdb->prefix}blc_instances.link_id = {$wpdb->prefix}blc_links.link_id
			WHERE
				{$wpdb->prefix}blc_instances.link_id IS NULL";

        if (null !== $link_id) {
            if (!is_array($link_id)) {
                $link_id = array(intval($link_id));
            }
            $q .= " AND {$wpdb->prefix}blc_links.link_id IN (" . implode(', ', $link_id) . ')';
        }

        $rez     = $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $elapsed = microtime(true) - $start;
        $blclog->log(sprintf('... %d links deleted in %.3f seconds', $wpdb->rows_affected, $elapsed));

        return false !== $rez;
    }

    /**
     * Notify the link checker that there are unsynched items
     * that might contain links (e.g. a new or edited post).
     *
     * @return void
     */
    static function blc_got_unsynched_items()
    {
        $plugin_conf            = ConfigurationManager::getInstance();
        $plugin_conf->need_resynch = true;
        $plugin_conf->save_options();
    }

    /**
     * Get the server's load averages.
     *
     * Returns an array with three samples - the 1 minute avg, the 5 minute avg, and the 15 minute avg.
     *
     * @param integer $cache How long the load averages may be cached, in seconds. Set to 0 to get maximally up-to-date data.
     * @return array|null Array, or NULL if retrieving load data is impossible (e.g. when running on a Windows box).
     */
    static function get_server_load($cache = 5)
    {
        static $cached_load = null;
        static $cached_when = 0;

        if (!empty($cache) && ((time() - $cached_when) <= $cache)) {
            return $cached_load;
        }

        $load = null;

        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
        } else {
            $loadavg_file = '/proc/loadavg';
            if (@is_readable($loadavg_file)) {
                $load = explode(' ', file_get_contents($loadavg_file));
                $load = array_map('floatval', $load);
            }
        }

        $cached_load = $load;
        $cached_when = time();
        return $load;
    }



    /**
     * Generate a numeric hash from a string. The result will be constrained to the specified interval.
     *
     * @static
     * @param string $input
     * @param int    $min
     * @param int    $max
     * @return float
     */
    public static function constrained_hash($input, $min = 0, $max = 1)
    {
        $bytes_to_use   = 3;
        $md5_char_count = 32;
        $hash           = substr(md5($input), $md5_char_count - $bytes_to_use * 2);
        $hash           = intval(hexdec($hash));
        return $min + (($max - $min) * ($hash / (pow(2, $bytes_to_use * 8) - 1)));
    }


    /**
     * Get all link instances associated with one or more links.
     *
     * @param array  $link_ids Array of link IDs.
     * @param string $purpose An optional code indicating how the instances will be used. Available predefined constants : BLC_FOR_DISPLAY, BLC_FOR_EDITING
     * @param bool   $load_containers Preload containers regardless of purpose. Defaults to false.
     * @param bool   $load_wrapped_objects Preload wrapped objects regardless of purpose. Defaults to false.
     * @param bool   $include_invalid Include instances that refer to not-loaded containers or parsers. Defaults to false.
     * @return LinkInstance[] An array indexed by link ID. Each item of the array will be an array of LinkInstance objects.
     */
    static public function blc_get_instances($link_ids, $purpose = '', $load_containers = false, $load_wrapped_objects = false, $include_invalid = false)
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        if (empty($link_ids)) {
            return array();
        }

        $link_ids_in = implode(', ', $link_ids);

        $q = "SELECT * FROM {$wpdb->prefix}blc_instances WHERE link_id IN ($link_ids_in)";

        // Skip instances that reference containers or parsers that aren't currently loaded
        if (! $include_invalid) {
            $manager           = ModuleManager::getInstance();
            $active_containers = $manager->get_escaped_ids('container');
            $active_parsers    = $manager->get_escaped_ids('parser');

            $q .= " AND container_type IN ({$active_containers}) ";
            $q .= " AND parser_type IN ({$active_parsers}) ";
        }

        $results = $wpdb->get_results($q, ARRAY_A); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if (empty($results)) {
            return array();
        }

        // Also retrieve the containers, if it could be useful.
        $load_containers = $load_containers || in_array($purpose, array(BLC_FOR_DISPLAY, BLC_FOR_EDITING));
        if ($load_containers) {
            // Collect a list of (container_type, container_id) pairs
            $container_ids = array();

            foreach ($results as $result) {
                array_push(
                    $container_ids,
                    array($result['container_type'], intval($result['container_id']))
                );
            }
            $containers = ContainerHelper::get_containers($container_ids, $purpose, '', $load_wrapped_objects);
        }

        // Create an object for each instance and group them by link ID
        $instances = array();
        foreach ($results as $result) {
            $instance = new LinkInstance($result);

            // Assign a container to the link instance, if available
            if ($load_containers && ! empty($containers)) {
                $key = $instance->container_type . '|' . $instance->container_id;
                if (isset($containers[$key])) {
                    $instance->_container = $containers[$key];
                }
            }

            if (isset($instances[$instance->link_id])) {
                array_push($instances[$instance->link_id], $instance);
            } else {
                $instances[$instance->link_id] = array($instance);
            }
        }

        return $instances;
    }

    /**
     * Get the number of instances that reference only currently loaded containers and parsers.
     *
     * @return int
     */
    static public  function blc_get_usable_instance_count()
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        $q = "SELECT COUNT(instance_id) FROM {$wpdb->prefix}blc_instances WHERE 1";

        // Skip instances that reference containers or parsers that aren't currently loaded
        $manager           = ModuleManager::getInstance();
        $active_containers = $manager->get_escaped_ids('container');
        $active_parsers    = $manager->get_escaped_ids('parser');

        $q .= " AND container_type IN ({$active_containers}) ";
        $q .= " AND parser_type IN ({$active_parsers}) ";

        return $wpdb->get_var($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Remove instances that reference invalid containers or containers/parsers that are not currently loaded
     *
     * @return bool
     */
    static public function blc_cleanup_instances()
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        global $blclog;

        // Delete all instances that reference non-existent containers
        $start   = microtime(true);
        $q       = "DELETE instances.*
		  FROM
  			{$wpdb->prefix}blc_instances AS instances LEFT JOIN {$wpdb->prefix}blc_synch AS synch
  			ON instances.container_type = synch.container_type AND instances.container_id = synch.container_id
		  WHERE
 			synch.container_id IS NULL";
        $rez     = $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $elapsed = microtime(true) - $start;
        $blclog->log(sprintf('... %d instances deleted in %.3f seconds', $wpdb->rows_affected, $elapsed));

        // Delete instances that reference containers and parsers that are no longer active
        $start             = microtime(true);
        $manager           = ModuleManager::getInstance();
        $active_containers = $manager->get_escaped_ids('container');
        $active_parsers    = $manager->get_escaped_ids('parser');

        $q       = "DELETE instances.*
	      FROM {$wpdb->prefix}blc_instances AS instances
	      WHERE
	        instances.container_type NOT IN ({$active_containers}) OR
	        instances.parser_type NOT IN ({$active_parsers})";
        $rez2    = $wpdb->query($q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $elapsed = microtime(true) - $start;
        $blclog->log(sprintf('... %d more instances deleted in %.3f seconds', $wpdb->rows_affected, $elapsed));

        return (false !== $rez) && (false !== $rez2);
    }
}
