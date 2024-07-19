<?php

namespace Blc\Component\Blc\Administrator\Blc\Includes;

use Blc\Component\Blc\Administrator\Blc\Abstract\Logger;

/**
 * A basic logger that uses WP options for permanent storage.
 *
 * Log entries are initially stored in memory and need to explicitly
 * flushed to the database by calling blcCachedOptionLogger::save().
 *
 * @package Broken Link Checker
 * @author Janis Elsts
 */
class blcCachedOptionLogger extends Logger
{
    var $option_name = '';
    var $log;
    var $filter_level = self::BLC_LEVEL_DEBUG;

    function __construct($option_name = '')
    {
        $this->option_name = $option_name;
        $oldLog            = get_option($this->option_name);
        if (is_array($oldLog) && ! empty($oldLog)) {
            $this->log = $oldLog;
        } else {
            $this->log = array();
        }
    }

    function log($message, $object = null, $level = self::BLC_LEVEL_DEBUG)
    {

        $new_entry = array( $level, $message, $object );
        array_push($this->log, $new_entry);
    }

    function get_log($min_level = self::BLC_LEVEL_DEBUG)
    {
        $this->filter_level = $min_level;
        return array_filter($this->log, array( $this, '_filter_log' ));
    }

    function _filter_log($entry)
    {
        return ( $entry[0] >= $this->filter_level );
    }

    function get_messages($min_level = self::BLC_LEVEL_DEBUG)
    {
        $messages = $this->get_log($min_level);
        return array_map(array( $this, '_get_log_message' ), $messages);
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

/**
 * A dummy logger that doesn't log anything.
 */
class blcDummyLogger extends Logger
{
}

/**
 * A basic logger that logs messages to a file.
 */
class blcFileLogger extends Logger
{
    protected $fileName; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

    public function __construct($fileName = '')
    {
        $this->fileName = $fileName;
    }

    function log($message, $object = null, $level = self::BLC_LEVEL_INFO)
    {
        if ($level < $this->log_level) {
            return;
        }

        $line = sprintf(
            '[%1$s] %2$s %3$s',
            date('Y-m-d H:i:s P'), //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            $this->get_level_string($level),
            $message
        );

        if (isset($object)) {
            $line .= ' ' . var_export($object, true);
        }

        $line .= "\n";

        error_log($line, 3, $this->fileName);
    }

    function get_messages($min_level = self::BLC_LEVEL_DEBUG)
    {
        return array( __CLASS__ . ':get_messages() is not implemented' );
    }

    function get_log($min_level = self::BLC_LEVEL_DEBUG)
    {
        return array( __CLASS__ . ':get_log() is not implemented' );
    }

    public function clear()
    {
        if (is_file($this->fileName) && is_writable($this->fileName)) {
            $handle = fopen($this->fileName, 'w');
            fclose($handle);
        }
    }
}
