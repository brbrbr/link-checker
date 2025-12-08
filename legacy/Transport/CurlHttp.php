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

use Blc\Util\Utility;

use Blc\Abstract\HttpCheckerBase;
use Blc\Controller\Link;





class CurlHttp extends HttpCheckerBase
{
    var $last_headers = '';


    function check($url, $use_get = false)
    {
        global $blclog;
        $blclog->info(__CLASS__ . ' Checking link', $url);

        $log                = '';
        $this->last_headers = '';


        if (!$this->has_valid_dns($url)) {
            $blclog->error(__CLASS__ . ' Invalid URL DNS Failed:', $url);

            $result = array(
                'error'     => true,
                'broken' => true,
                'warning'     => false,
                'log'         => "Invalid URL.\nUnable to look up DNS",
                'status_text' => __('Invalid DNS', 'link-checker'),
                'error_code'  => 'invalid_url',
                'status_code' => BLC_LINK_STATUS_ERROR,
                'http_code' => Link::BLC_DNS_HTTP_CODE
            );

            return $result;
        }
        $url = $this->clean_url($url);

        $cleaned_url = wp_kses_bad_protocol($url, ['http', 'https']);


        if (! $url || strtolower($url) !== strtolower($cleaned_url)) {

            $blclog->error(__CLASS__ . ' Invalid Protocol:', $url);

            $result = array(
                'warning'     => true,
                'log'         => "Invalid Protokol.\nOnly http and https links are checked",
                'status_text' => __('Invalid URL', 'link-checker'),
                'error_code'  => 'invalid_url',
                'status_code' => BLC_LINK_STATUS_WARNING,
            );

            return $result;
        }



        $result             = array(
            'broken'  => false,
            'timeout' => false,
            'warning' => false,
        );

        $blclog->debug(__CLASS__ . ' Clean URL:', $url);

        $options = array();

        // Get the BLC configuration. It's used below to set the right timeout values and such.
        $conf = $this->plugin_conf->options;




        // Init curl.
        $ch = curl_init();

        $this->clearHeaders();
        $this->setSignature('firefox');
        // Masquerade as a recent version of Firefox
        $this->replaceHeader('Accept-Language: ' . $this->getLanguage());
        // Override the Expect header to prevent cURL from confusing itself in its own stupidity.
        // Link: http://the-stickman.com/web-development/php-and-curl-disabling-100-continue-header/
        $this->replaceHeader('Expect:');

        $options = array(

            CURLOPT_URL            => $this->urlencodefix($url),
            CURLOPT_RETURNTRANSFER => true,
            // Set maximum redirects
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => $this->userAgent,
            // Set the timeout
            CURLOPT_TIMEOUT        => $conf['timeout'],
            CURLOPT_CONNECTTIMEOUT => $conf['timeout'],
            // Register a callback function which will process the HTTP header(s).
            // It can be called multiple times if the remote server performs a redirect.
            CURLOPT_HEADERFUNCTION => $this->read_header(...),

            // Add a semi-plausible referer header to avoid tripping up some bot traps
            CURLOPT_REFERER        => home_url(),
            CURLOPT_FAILONERROR    => false,
            CURLOPT_FORBID_REUSE   => true,

            // Record request headers.
            CURLINFO_HEADER_OUT    => true,
        );



        if ($conf['cookies_enabled']) {
            $options[CURLOPT_COOKIEFILE] = $conf['cookie_jar'];
            $options[CURLOPT_COOKIEJAR]  = $conf['cookie_jar'];
        }

        // Close the connection after the request (disables keep-alive). The plugin rate-limits requests,
        // so it's likely we'd overrun the keep-alive timeout anyway.
        $this->addHeader('Connection: close');

        // Redirects don't work when safe mode or open_basedir is enabled.
        if (!Utility::is_open_basedir()) {
            $options[CURLOPT_FOLLOWLOCATION] = true;
        } else {
            $log .= "[Warning] Could't follow the redirect URL (if any) because safemode or open base dir enabled\n";
        }



        // Set the proxy configuration. The user can provide this in wp-config.php
        if (defined('WP_PROXY_HOST')) {
            $options[CURLOPT_PROXY] = WP_PROXY_HOST;

            if (defined('WP_PROXY_PORT')) {
                $options[CURLOPT_PROXYPORT] = WP_PROXY_PORT;
            }
            if (defined('WP_PROXY_USERNAME')) {
                $auth = WP_PROXY_USERNAME;
                if (defined('WP_PROXY_PASSWORD')) {
                    $auth .= ':' . WP_PROXY_PASSWORD;
                }
                $options[CURLOPT_PROXYUSERPWD] = $auth;
            }
        }

        // Make CURL return a valid result even if it gets a 404 or other error.

        $nobody = !$use_get; // Whether to send a HEAD request (the default) or a GET request

        if ('https' === strtolower(parse_url($url, PHP_URL_SCHEME) ?? '')) {
            // Require valid ssl
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_DEFAULT; // | CURL_SSLVERSION_MAX_DEFAULT;
        }

        if ($nobody) {
            // If possible, use HEAD requests for speed.
            $options[CURLOPT_NOBODY] = true;
        } else {
            // If we must use GET at least limit the amount of downloaded data.
            $this->addHeader('Range: bytes=0-2048'); // 2 KB
        }

        // Set request headers.
        $this->headers = array_filter($this->headers);

        if (!empty($this->headers)) {
            $options[CURLOPT_HTTPHEADER] =   $this->headers;
        }
        //2.4.3 - CURLOPT_ENCODING was already in the fork. addepted for >7.21.6
        if (apply_filters('wpmudev_blc_local_accept_encoding_header', true)) {
            $version = curl_version();
            if (version_compare($version['version'], '7.21.6', '<')) {
                curl_setopt($ch, CURLOPT_ENCODING, '');
            } else {
                curl_setopt($ch, CURLOPT_ACCEPT_ENCODING, '');
            }
        }

        // Apply filter for additional options

        curl_setopt_array($ch, apply_filters('link-checker-curl-options', $options));

        // Execute the request
        $start_time                = microtime(true);
        $content                   = curl_exec($ch);
        $measured_request_duration = microtime(true) - $start_time;
        $blclog->debug(sprintf('HTTP request took %.3f seconds', $measured_request_duration));

        $info = curl_getinfo($ch);


        // Store the results
        $result['http_code']        = intval($info['http_code']);
        $result['final_url']        = $info['url'];
        $result['request_duration'] = $info['total_time'];
        $result['redirect_count']   = $info['redirect_count'];

        // CURL doesn't return a request duration when a timeout happens, so we measure it ourselves.
        // It is useful to see how long the plugin waited for the server to respond before assuming it timed out.
        if (empty($result['request_duration'])) {
            $result['request_duration'] = $measured_request_duration;
        }

        if (isset($info['request_header'])) {
            $blclog->info(
                $info['request_header']
            );
            $log .= "Request headers\n" . str_repeat('=', 16) . "\n";
            $log .= htmlentities($info['request_header']);
        }

        // Determine if the link counts as "broken"
        if (0 === absint($result['http_code'])) {
            $result['broken'] = true;

            $error_code = curl_errno($ch);
            $log       .= sprintf("%s [Error #%d]\n", curl_error($ch), $error_code);

            // We only handle a couple of CURL error codes; most are highly esoteric.
            // libcurl "CURLE_" constants can't be used here because some of them have
            // different names or values in PHP.
            switch ($error_code) {
                case 6: // CURLE_COULDNT_RESOLVE_HOST
                    $result['status_code'] = BLC_LINK_STATUS_WARNING;
                    $result['status_text'] = __('Server Not Found', 'link-checker');
                    $result['error_code']  = 'couldnt_resolve_host';
                    break;

                case 28: // CURLE_OPERATION_TIMEDOUT
                    $result['timeout'] = true;
                    break;

                case 7: // CURLE_COULDNT_CONNECT
                    // More often than not, this error code indicates that the connection attempt
                    // timed out. This heuristic tries to distinguish between connections that fail
                    // due to timeouts and those that fail due to other causes.
                    if ($result['request_duration'] >= 0.9 * $conf['timeout']) {
                        $result['timeout'] = true;
                    } else {
                        $result['status_code'] = BLC_LINK_STATUS_WARNING;
                        $result['status_text'] = __('Connection Failed', 'link-checker');
                        $result['error_code']  = 'connection_failed';
                    }
                    break;

                case 58:
                case 59:
                case 60:   //SSL Errors
                    $result['http_code']      = 606; //pseudo error code for SSL
                    $result['broken'] = 1;
                    break;

                default:
                    $result['status_code'] = BLC_LINK_STATUS_WARNING;
                    $result['status_text'] = __('Unknown Error', 'link-checker');
            }
        } elseif (999 === $result['http_code']) {
            $result['status_code'] = BLC_LINK_STATUS_WARNING;
            $result['status_text'] = __('Unknown Error', 'link-checker');
            $result['warning']     = true;
        } else {
            $result['broken'] = self::is_error_code($result['http_code']);
        }

        // Apply filter before curl closes
        apply_filters('broken-link-checker-curl-before-close', $ch, $content, $this->last_headers);

        curl_close($ch);

        $blclog->info(
            sprintf(
                'HTTP response: %d, duration: %.2f seconds, status text: "%s"',
                $result['http_code'],
                $result['request_duration'],
                isset($result['status_text']) ? $result['status_text'] : 'N/A'
            )
        );


        $retryGet = apply_filters('link-checker-retry-with-get-after-head', true, $result);

        if ($nobody && !$result['timeout'] && $retryGet && ($result['broken'] || $result['redirect_count'] == 1)) {
            // The site in question might be expecting GET instead of HEAD, so lets retry the request
            // using the GET verb...but not in cases of timeout, or where we've already done it.
            return $this->check($url, true);

            // Note : normally a server that doesn't allow HEAD requests on a specific resource *should*
            // return "405 Method Not Allowed". Unfortunately, there are sites that return 404 or
            // another, even more general, error code instead. So just checking for 405 wouldn't be enough.
        }

        // When safe_mode or open_basedir is enabled CURL will be forbidden from following redirects,
        // so redirect_count will be 0 for all URLs. As a workaround, set it to 1 when the HTTP
        // response codes indicates a redirect but redirect_count is zero.
        // Note to self : Extracting the Location header might also be helpful.
        if ((0 === absint($result['redirect_count'])) && (in_array($result['http_code'], array(301, 302, 303, 307)))) {
            $result['redirect_count'] = 1;
        }

        // Build the log from HTTP code and headers.
        $log .= '=== ';
        if ($result['http_code']) {
            $log .= sprintf(__('HTTP code : %d', 'link-checker'), $result['http_code']);
        } else {
            $log .= __('(No response)', 'link-checker');
        }
        $log .= " ===\n\n";

        $log .= "Response headers\n" . str_repeat('=', 16) . "\n";
        $log .= htmlentities($this->last_headers);


        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if (!$nobody && (false !== $content) && $result['broken']) {
            if (str_contains($contentType, 'text')) {
                // in case the returned stuff is gzipped or what
                // technically we should check against the database collation
                if (!mb_detect_encoding($content, strict: true)) {
                    $content = 'Not Valid MultiByte content';
                }
                $log .= "Response HTML\n" . str_repeat('=', 16) . "\n";
                $log .= htmlentities(substr($content, 0, 2048));
            }
        }

        if (!empty($result['broken']) && !empty($result['timeout'])) {
            $log .= "\n(" . __("Most likely the connection timed out or the domain doesn't exist.", 'link-checker') . ')';
        }
        $result['log'] = $log;

        // The hash should contain info about all pieces of data that pertain to determining if the
        // link is working.
        $result['result_hash'] = implode(
            '|',
            array(
                $result['http_code'],
                ($result['redirect_count'] > 0 ? 'redirect' : 'final'),
            )
        );


        return $result;
    }

    function read_header(
        /** @noinspection PhpUnusedParameterInspection */
        $ch,
        $header
    ) {
        $this->last_headers .= $header;
        return strlen($header);
    }
}


