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

    protected function getAPost($type = 'post')
    {
        $args = array(
            'numberposts'      => 1,
            'category'         => 0,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'include'          => array(),
            'exclude'          => array(),
            'meta_key'         => '',
            'meta_value'       => '',
            'post_type'        => $type,
            'suppress_filters' => true,
        );
        $posts = get_posts($args);
        return $posts[0];
    }
}
