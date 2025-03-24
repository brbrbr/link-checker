<?php

namespace Blc\Logger;

use Blc\Abstract\Logger;

/**
 * A basic logger that uses WP options for permanent storage.
 *
 * Log entries are initially stored in memory and need to explicitly
 * flushed to the database by calling CachedOptionLogger::save().
 *
 * @package Broken Link Checker
 * @author Janis Elsts
 */
class CachedOptionLogger extends Logger
{

    protected $log;
    protected $filter_level = self::BLC_LEVEL_DEBUG;

    function __construct(protected $option_name = '')
    {

        $oldLog            = get_option($this->option_name);
        if (is_array($oldLog) && ! empty($oldLog)) {
            $this->log = $oldLog;
        } else {
            $this->log = array();
        }
    }

    function log($message, $object = null, $level = self::BLC_LEVEL_DEBUG)
    {

        $new_entry = array($level, $message, $object);
        array_push($this->log, $new_entry);
    }

    function get_log($min_level = self::BLC_LEVEL_DEBUG)
    {
        $this->filter_level = $min_level;
        return array_filter($this->log, array($this, '_filter_log'));
    }

    function _filter_log($entry)
    {
        return ($entry[0] >= $this->filter_level);
    }

    function get_messages($min_level = self::BLC_LEVEL_DEBUG)
    {
        $messages = $this->get_log($min_level);
        return array_map(array($this, '_get_log_message'), $messages);
    }

    function _get_log_message($entry)
    {
        return $entry[1];
    }

    function clear()
    {
        $this->log = array();
        delete_option($this->option_name);
    }

    function save()
    {
        update_option($this->option_name, $this->log);
    }
}
