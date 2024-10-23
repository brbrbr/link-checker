<?php

/**
* By default only one instance of the broken link checker runs per database server.
* this to avoid to many outgoing http requests.
* with this filter the behaviour can be changed'.
* currently there are two names
* blc_lock - when the checker wants to start working
* blc_dbupdate - for a database upgrade
* be friendly to your neighbours and leave it on server
*/

add_filter('broken-link-checker-acquire-lock-name', 'broken_link_checker_acquire_lock_name_example', accepted_args : 1);
function broken_link_checker_acquire_lock_name_example($name)
{
    return $name; //default server (database) wide
    //the crc32 is to ensure the $name is not to long
    return $name . crc32(DB_NAME); //per database ( multisite)
    return $name . crc32(DB_NAME . get_current_blog_id()); //per (sub)site  -assuming that not multiple single sites run in the same database
    return $name . crc32(home_url()); //should have the same effect as above if multiple single sites run in the same database
    return $name . crc32(uniqid()); //per call. This would allow multiple instances running per site!!
}

/**
 * Options passed to curl_setopt_array
 * if you  manipulate  them wrong you will break the checker :)
 *
 */
apply_filters('link-checker-curl-options', $options);

/**
 * Retry with GET after HEAD request
 * To save bandwidth request are made as a HEAD request: 'is the resouce on your server'
 * not all servers response well to a HEAD request, so in case of a broken (or redirecting) link
 * the request is re-tried with a proper GET request.
 */
apply_filters('link-checker-retry-with-get-after-head', true, $result);

apply_filters('broken-link-checker-curl-before-close', $ch, $content, $this->last_headers);
apply_filters('blc_youtube_api_key', $conf->options['youtube_api_key']);
apply_filters('blc-parser-html-link-content', $content);

/**
 * How often (at most) notifications will be sent. Possible values : 'daily', 'weekly'. There is no option for this so we've added a fitler for it.
 */
apply_filters('blc_notification_schedule_filter', 'daily');
apply_filters('blc-module-settings-' . $module_id, '', $current_settings);
apply_filters('wpmudev_blc_max_execution_time', $this->conf->options['max_execution_time']);
apply_filters('blc_allow_send_email_notification', $this->conf->options['send_email_notifications']);
apply_filters('blc_allow_send_author_email_notification', $this->conf->options['send_authors_email_notifications']);

do_action('blc_register_modules', $blc_module_manager);
do_action('wpmudev-blc-local-nav-before');
do_action('wpmudev-blc-local-nav-after');
