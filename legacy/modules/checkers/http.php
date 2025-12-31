<?php

/*
Plugin Name: Basic HTTP
Description: Check all links that have the HTTP/HTTPS protocol.
Version: 1.0
Author: Janis Elsts

ModuleID: http
ModuleCategory: checker
ModuleContext: on-demand
ModuleLazyInit: true
ModuleClassName: Blc\Module\Checker\HttpChecker
ModulePriority: -1
*/

namespace Blc\Module\Checker;
use Blc\Util\TokenBucketList;
use Blc\Abstract\Checker;

use Blc\Transport;


// TODO: Rewrite sub-classes as transports, not stand-alone checkers
class HttpChecker extends Checker
{
    /* @var Checker */
    var $implementation = null;

    /** @var  TokenBucketList */
    private TokenBucketList $token_bucket_list;

    function init()
    {
        parent::init();

        $conf                    = $this->plugin_conf;
        $this->token_bucket_list = new TokenBucketList(
            $conf->get('http_throttle_rate', 3),
            $conf->get('http_throttle_period', 15),
            $conf->get('http_throttle_min_interval', 2)
        );
        if (apply_filters('wpmudev_blc_local_use_curl', function_exists('curl_init') || is_callable('curl_init'))) {
            $this->implementation = new Transport\CurlHttp(
                $this->module_id,
                $this->cached_header,
                $this->plugin_conf,
                $this->module_manager
            );
        } else {
            // try and use wp request method
            $this->implementation = new Transport\WPHttp(
                $this->module_id,
                $this->cached_header,
                $this->plugin_conf,
                $this->module_manager
            );
        }
    }


    function can_check($url, $parsed)
    {
        if (isset($this->implementation)) {
            return $this->implementation->can_check($url, $parsed);
        } else {
            return false;
        }
    }

    function check($url, $use_get = false)
    {
        global $blclog;

        // Throttle requests based on the domain name.
        $domain = @parse_url($url, PHP_URL_HOST);
        if ($domain) {
            $this->token_bucket_list->takeToken($domain);
        }
        //this makes the module name translatable
        $blclog->debug(_x("Basic HTTP", "module name", "broken-link-checker") . ' checking "' . $url . '"');
        return $this->implementation->check($url, $use_get);
    }
}



