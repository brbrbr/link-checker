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

        add_filter('update_plugins_downloads.brokenlinkchecker.dev', array($this, 'checkUpdate'), accepted_args: 2);
    }

    public function checkUpdate($update, $plugin_data)
    {
        if ($update) {
            return $update;
        }

        $url = $plugin_data['UpdateURI'];
        $thisPlugin = get_plugin_data(WPMUDEV_BLC_PLUGIN_FILE);
        if ($url == $thisPlugin['UpdateURI']) {
            return $this->fetchUpdate($url);
        }
        return $update;
    }

    /**
     * Fetch details on the latest updates.
     */
    protected function fetchUpdate(string $url): array
    {
        $transient = "{$url}_plugin_update"; // .microtime();
        if (! is_array($data = get_transient($transient))) {
            $res  = wp_remote_get($url);
            $body = wp_remote_retrieve_body($res);
            $code = wp_remote_retrieve_response_code($res);
            if ($code === 200 && ($data = json_decode($body, true))) {
                error_log(var_export($data, true), 3, '/tmp/d');
                set_transient($transient, $data, $this->expiration);
            }
        }
        return $data ?: [];
    }

}