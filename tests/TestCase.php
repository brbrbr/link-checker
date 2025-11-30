<?php

namespace LinkChecker\Tests;

use PHPUnit\Framework\TestCase as PHPunitTestCase;


require_once BLC_DIRECTORY_LEGACY . '/includes/any-post.php';

class TestCase extends PHPunitTestCase
{
    protected function assertHasLog(?int $level = null)
    {
        global $blclog;
        $logs = $blclog->get_logs($level);
        $this->assertNotEquals(0, \count($logs), 'Log entries expected');
    }

    protected function clearLog()
    {
        global $blclog;
        $blclog->clear_logs();
    }
    protected function getInstance($args,$invalidLink=true)
    {
        global $wpdb;
        $ands = [];
        foreach ($args as $key => $value) {
          $key=esc_sql($key);
             $value=esc_sql($value);
            $ands[]="`i`.`$key` like '$value'"; 
        }
        if ( $invalidLink) {
            $ands[]=" `i`.`raw_url` like '%.invalid%'";

        }
        $where=join(' AND ',$ands);
        $q="SELECT * FROM `{$wpdb->prefix}blc_instances` `i` WHERE $where ORDER BY instance_id DESC LIMIT 1";
     return $wpdb->get_row($q, ARRAY_A);
    }

    protected function getAPost($args = [])
    {
        $args = array_merge(array(
            'numberposts'      => 1,
            'category'         => 0,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'include'          => array(),
            'exclude'          => array(),
            'meta_key'         => '',
            'meta_value'       => '',
            'post_type'        => 'post',
            'suppress_filters' => true,
        ), $args);
        $args['numberposts'] = 1;
        $posts = get_posts($args);
        return $posts[0];
    }
}
