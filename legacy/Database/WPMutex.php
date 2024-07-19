<?php

namespace Blc\Database;

class WPMutex
{
    /**
     * Get an exclusive named lock.
     *
     * @param string  $name
     * @param integer $timeout
     * @param bool    $network_wide
     * @return bool
     */
    static function acquire($name, $timeout = 0)
    {
        global $wpdb; /* @var wpdb $wpdb */
        $name  = apply_filters('broken-link-checker-acquire-lock-name', $name);
        $state = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout));
        return 1 == $state;
    }

    /**
     * Release a named lock.
     *
     * @param string $name
     * @param bool   $network_wide
     * @return bool
     */
    static function release($name)
    {
        global $wpdb; /* @var wpdb $wpdb */
        $name     = apply_filters('broken-link-checker-acquire-lock-name', $name);
        $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        return 1 == $released;
    }
}
