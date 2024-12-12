<?php

namespace Blc\Controller;

/**
 * Simple function to replicate PHP 5 behaviour
 *
 * @link    https://wordpress.org/plugins/broken-link-checker/
 * @since   1.0.0
 * @package broken-link-checker
 */

use Blc\Database\WPMutex;

use Blc\Database\TransactionManager;
use Blc\Util\Utility;
use Blc\Admin\TablePrinter;
use Blc\Admin\ScreenOptions;
use Blc\Util\ConfigurationManager;
use Blc\Logger\CachedOptionLogger;
use Blc\Controller\ModuleManager;
use Blc\Helper\ContainerHelper;
use Blc\Util\UpdatePlugin;




/**
 * Broken Link Checker core
 */
class BrokenLinkCheckerSite
{


    /**
     * Loader basename.
     *
     * @var string
     */
    public $my_basename = '';
    private $loader     = '';
    private $blcPostTypeOverlord;



    /**
     * Text domain status.
     *
     * @var string
     */
    public $is_textdomain_loaded = false;


    private ?array $current_broken_links = Null;

    protected $plugin_config;

    /**
     * Class constructor
     *
     */
    public function __construct()
    {

        static $method_called = false;

        if ($method_called) {
            return;
        }
        $method_called = true;

        $this->plugin_config = ConfigurationManager::getInstance();

        if ($this->plugin_config->options['mark_removed_links'] && !empty($this->plugin_config->options['removed_link_css'])) {
            add_action('wp_head', [$this, 'blc_print_removed_link_css']);
        }

        // Highlight and nofollow broken links in posts & pages
        if ($this->plugin_config->options['mark_broken_links'] || $this->plugin_config->options['nofollow_broken_links']) {
            add_filter('the_content', array($this, 'hook_the_content'));
            if ($this->plugin_config->options['mark_broken_links'] && ! empty($this->plugin_config->options['broken_link_css'])) {
                add_action('wp_head', array($this, 'blc_print_broken_link_css'));
            }
        }
    }

    /**
     * Analyse a link and add 'broken_link' CSS class if the link is broken.
     *
     * @see blcHtmlLink::multi_edit()
     *
     * @param array $link Associative array of link data.
     * @param array $broken_link_urls List of broken link URLs present in the current post.
     * @return array|string The modified link
     */
    function highlight_broken_link($link, $broken_link_urls)
    {
        if (! in_array($link['href'], $broken_link_urls)) {
            // Link not broken = return the original link tag
            return $link['#raw'];
        }

        // Add 'broken_link' to the 'class' attribute (unless already present).
        if ($this->plugin_config->options['mark_broken_links']) {
            if (isset($link['class'])) {
                $classes = explode(' ', $link['class']);
                if (! in_array('broken_link', $classes)) {
                    $classes[]     = 'broken_link';
                    $link['class'] = implode(' ', $classes);
                }
            } else {
                $link['class'] = 'broken_link';
            }
        }

        // Nofollow the link (unless it's already nofollow'ed)
        if ($this->plugin_config->options['nofollow_broken_links']) {
            if (isset($link['rel'])) {
                $relations = explode(' ', $link['rel']);
                if (! in_array('nofollow', $relations)) {
                    $relations[] = 'nofollow';
                    $link['rel'] = implode(' ', $relations);
                }
            } else {
                $link['rel'] = 'nofollow';
            }
        }

        return $link;
    }

    private function get_current_broken_links()
    {
        global  $wpdb;
        /** @var wpdb $wpdb */
        if (is_null($this->current_broken_links)) {
            // Retrieve info about all occurrences of broken links 
            $q     = "SELECT instances.raw_url 
                      FROM {$wpdb->prefix}blc_instances AS instances 
                      JOIN {$wpdb->prefix}blc_links AS links ON instances.link_id = links.link_id
                      WHERE  links.broken = 1
                      AND parser_type = 'link'
                      GROUP BY  `instances`.`raw_url`";

            $this->current_broken_links = $wpdb->get_col($q, 0);
        }
        return $this->current_broken_links;
    }

    /**
     * Hook for the 'the_content' filter. Scans the current post and adds the 'broken_link'
     * CSS class to all links that are known to be broken. Currently works only on standard
     * HTML links (i.e. the '<a href=...' kind).
     *
     * @param string $content Post content
     * @return string Modified post content.
     */
    public function hook_the_content($content)
    {

        $broken_link_urls = $this->get_current_broken_links();
        $tags = new \WP_HTML_Tag_Processor($content);
        //no point in checking images
        while ($tags->next_tag('a')) {
            $href = $tags->get_attribute('href');
            if (!$href) {
                continue;
            }
            if (!in_array($href, $broken_link_urls)) {
                continue;
            }
            if ($this->plugin_config->options['mark_broken_links']) {
                $tags->add_class('broken_link');
            }
            if ($this->plugin_config->options['nofollow_broken_links']) {
                $rel = $tags->get_attribute('rel')??'';
                $rels = explode(' ', $rel);
                $rels[] = 'nofollow';
                $rel = join(' ', array_filter(array_unique($rels)));
                $tags->set_attribute('rel', $rel);
            }
        }
        $content = (string)$tags;
        return $content;
    }

    public   function blc_print_removed_link_css()
    {
        echo '<style type="text/css">', $this->plugin_config->options['removed_link_css'], '</style>';
    }

    /**
     * A hook for the 'wp_head' action. Outputs the user-defined broken link CSS.
     *
     * @return void
     */
    function blc_print_broken_link_css()
    {
        $broken_link_urls = $this->get_current_broken_links();
        if ($broken_link_urls) {
            echo '<style type="text/css">', $this->plugin_config->options['broken_link_css'], '</style>';
        }
    }
} //class ends here
