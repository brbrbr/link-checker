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
ModuleClassName: blcHttpChecker
ModulePriority: -1
*/

namespace Blc\Transport;

use Blc\Abstract\HttpCheckerBase;
use Blc\Controller\Link;


class WPHttp extends HttpCheckerBase
{
    function check($url)
    {

        $result = array(
            'broken'  => false,
            'timeout' => false,
        );
        $log    = '';

        // Get the timeout setting from the BLC configuration.
        $conf    = $this->plugin_conf->options;
        $timeout = $conf['timeout'];

        // Fetch the URL with Snoopy
        $request_args = array(
            'timeout'    => $timeout,
            'user-agent' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1)', // masquerade as IE 7
            'aa'         => 1024 * 5,
        );
        $request      = wp_safe_remote_get($this->urlencodefix($url), $request_args);
        $request['http_response']->set_data('');

        // request timeout results in WP ERROR
        if (is_wp_error($request)) {
            $result['http_code'] = 0;
            $result['timeout']   = true;
            $result['message']   = $request->get_error_message();
        } else {
            //  $http_resp           = wp_remote_retrieve_body( $request ); from broken-link-checker - failty or unused??
            $result['http_code'] = wp_remote_retrieve_response_code($request); // HTTP status code
            $result['message']   = wp_remote_retrieve_response_message($request);
        }

        // Build the log
        $log .= '=== ';
        if ($result['http_code']) {
            $log .= sprintf(__('HTTP code : %d', 'link-checker'), $result['http_code']);
        } else {
            $log .= __('(No response)', 'link-checker');
        }
        $log .= " ===\n\n";

        if ($result['message']) {
            $log .= $result['message'] . "\n";
        }

        if (is_wp_error($request)) {
            $log              .= __('Request timed out.', 'link-checker') . "\n";
            $result['timeout'] = true;
        }

        // Determine if the link counts as "broken"
        $result['broken'] = self::is_error_code($result['http_code']) || $result['timeout'];

        $log          .= '<em>(' . __('Using WP HTTP', 'link-checker') . ')</em>';
        $result['log'] = $log;

        $result['final_url'] = $url;

        // The hash should contain info about all pieces of data that pertain to determining if the
        // link is working.
        $result['result_hash'] = implode(
            '|',
            array(
                $result['http_code'],
                Link::remove_query_string($result['final_url']),
            )
        );

        return $result;
    }
}
