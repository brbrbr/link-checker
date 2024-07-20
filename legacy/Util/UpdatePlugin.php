<?php

namespace Blc\Util;

class UpdatePlugin
{
    /**
     * Cache expiration in seconds.
     */
    protected int $expiration = HOUR_IN_SECONDS;

    /**
     * Constructor.
     */
    public function __construct()
    {

        add_filter('update_plugins_downloads.brokenlinkchecker.dev', array( $this, 'checkUpdate' ), accepted_args: 2);
    
    }

    public function checkUpdate($update, $plugin_data)
    {

        $update = $this->fetchUpdate($plugin_data['UpdateURI']);
        return $update;
    }

    /**
     * Fetch details on the latest updates.
     */
    protected function fetchUpdate(string $url): object
    {
        $transient = "{$url}_plugin_update"; // .microtime();
        if (! is_object($data = get_transient($transient))) {
            $res  = wp_remote_get($url);
            $body = wp_remote_retrieve_body($res);
            $code = wp_remote_retrieve_response_code($res);
            if ($code === 200 && ( $data = json_decode($body, false) )) {
                set_transient($transient, $data, $this->expiration);
            }
        }

        return $data ?: new \stdClass();
    }
}
