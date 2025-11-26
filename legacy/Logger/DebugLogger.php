<?php

namespace Blc\Logger;

use Blc\Abstract\Logger;

/**
 * A dummy logger that doesn't log anything.
 */
class DebugLogger extends FileLogger
{
    private $logs = [];
    public function log($message, $object = null, $level = self::BLC_LEVEL_DEBUG)
    {
        parent::log($message, $object, $level);
        $this->logs[$level] ??= [];
        $this->logs[$level][] = [$message, $object];
    }

    public function get_logs(?int $level = null)
    {
        if ($level === null) {
            return array_merge(...$this->logs);
        }
        return  $this->logs[$level] ?? [];
    }
    public function clear_logs()
    {
        $this->logs = [];
    }
}
