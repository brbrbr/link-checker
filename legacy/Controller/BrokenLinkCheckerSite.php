<?php

namespace Blc\Controller;

/**
 * Simple function to replicate PHP 5 behaviour
 *
 * @link    https://wordpress.org/plugins/broken-link-checker/
 * @since   1.0.0
 * @package broken-link-checker
 */


use Blc\Util\ConfigurationManager;




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
            add_action('wp_footer', [$this, 'blc_print_removed_link_css']);
        }

        // Highlight and nofollow broken links in posts & pages
        if ($this->plugin_config->options['mark_broken_links'] || $this->plugin_config->options['nofollow_broken_links']) {
            add_filter('the_content', array($this, 'hook_the_content'));

            if ($this->plugin_config->options['mark_broken_links'] && ! empty($this->plugin_config->options['broken_link_css'])) {
                add_action('wp_footer', array($this, 'blc_print_broken_link_css'));
            }
        }
    }



    private function load_broken_links()
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

        $this->load_broken_links();
        if (empty($this->current_broken_links)) {
            return;
        }
        $tags = new \WP_HTML_Tag_Processor($content);
        //no point in checking images
        while ($tags->next_tag('a')) {
            $href = $tags->get_attribute('href');
            if (!$href) {
                continue;
            }
            if (!in_array($href, $this->current_broken_links)) {
                continue;
            }
            if ($this->plugin_config->options['mark_broken_links']) {
                $tags->add_class('broken_link');
            }
            if ($this->plugin_config->options['nofollow_broken_links']) {
                $rel = $tags->get_attribute('rel') ?? '';
                $rels = explode(' ', $rel);
                $rels[] = 'nofollow';
                $rel = join(' ', array_filter(array_unique($rels)));
                $tags->set_attribute('rel', $rel);
            }
        }
        $content = (string)$tags;
        return $content;
    }
    private function remove_selectors($css)
    {
        if (preg_match_all('#{([^}]+)}#ms', $css, $m)) {
            $css = trim(join("\n", $m[1]));
        }
        return $css;
    }

    private function add_css($selector, $css)
    {
        $css = $this->remove_selectors($css);
        $css = trim($css);
        if ($css == '') {
            return;
        }
        $style = "$selector { $css }";

        echo "<style>$style</style>";
    }

    public   function blc_print_removed_link_css()
    {
        $this->add_css('.removed_link', $this->plugin_config->options['removed_link_css']);
    }

    /**
     * A hook for the 'wp_head' action. Outputs the user-defined broken link CSS.
     *
     * @return void
     */
    function blc_print_broken_link_css()
    {
        $this->load_broken_links();
        if (empty($this->current_broken_links)) {
            return;
        }

        $this->add_css('.broken_link', $this->plugin_config->options['broken_link_css']);
    }
} //class ends here
