<?php

namespace Blc\Logger;

use Blc\Abstract\Logger;

/**
 * A dummy logger that doesn't log anything.
 */
class DummyLogger extends Logger
{
    function log($message, $object = null, $level = self::BLC_LEVEL_DEBUG) {}
}
