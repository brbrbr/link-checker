<?php

namespace Blc\Logger;

use Blc\Abstract\Logger;


/**
 * A basic logger that logs messages to a file.
 */
class FileLogger extends Logger
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
