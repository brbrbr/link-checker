<?php



/**
 * Plugin Name: JSON -> content
 * Description: Parses for JSON arrays with html in 'content' field. (footnotes)
 * Version: __DEPLOY_VERSION__
 * Author: Bram Brambring
 * ModuleID: json_content
 * ModuleCategory: parser
 * ModuleClassName: JsonContentParser
 * ModuleContext: on-demand
 * ModuleLazyInit: true
 * ModuleAlwaysActive: false
 * ModuleHidden: true
 */

use Blc\Abstract\Parser;
use Blc\Controller\LinkInstance;
use Blc\Controller\ModuleManager;

class JsonContentParser extends Parser
{
    var $supported_formats    = array('json_content');


    /**
     * Parse a metadata value.
     *
     * @param string|array $content Metadata value(s).
     * @param string       $base_url The base URL to use for normalizing relative URLs. If ommitted, the blog's root URL will be used.
     * @param string       $default_link_text
     * @return array An array of new LinkInstance objects.
     * @since __DEPLOY_VERSION__
     */
    function parse($content, $base_url = '', $default_link_text = '')
    {
        $instances = [];
        $contentDecoded = json_decode($content, true);
        if (empty($contentDecoded)) {
            return $instances;
        };

        $parsers =  ModuleManager::getInstance()->get_parsers('html', '');

        foreach ($contentDecoded as $value) {
            $content = $value['content'] ?? '';
            if (!str_contains($content, '<')) {
                continue;
            }

            foreach ($parsers as $parser) {
                // FB::log("Parsing $name with '{$parser->parser_type}' parser");
                $found_instances = $parser->parse($content, $base_url, $default_link_text);
                $instances = array_merge($instances, $found_instances);
            }
        }

        $instances = array_values(array_filter($instances));
        foreach ($instances as &$instance) {
            $instance->set_parser($this);
        }
        return  $instances;
    }



    /**
     * Change the URL in a metadata field to another one.
     *
     * This is tricky because there can be multiple metadata fields with the same name
     * but different values. So we ignore $content (which might be an array of multiple
     * metadata values) and use the old raw_url that we stored when parsing the field(s)
     * instead.
     *
     * @see blcMetadataParser::parse()
     *
     * @param string $content Ignored.
     * @param string $new_url The new URL.
     * @param string $old_url Ignored.
     * @param string $old_raw_url The current meta value.
     *
     * @return array|\WP_Error
     * 
     * @since __DEPLOY_VERSION__
     * 
     */
    function edit($content, $new_url, $old_url, $old_raw_url)
    {


        $contentDecoded = json_decode($content, true);

        $parsers =  ModuleManager::getInstance()->get_parsers('html', '');

        foreach ($contentDecoded as $k => $value) {
            $content = $value['content'] ?? '';
            if (!str_contains($content, '<')) {
                continue;
            }

            foreach ($parsers as $parser) {

                // function edit($content, $new_url, $old_url, $old_raw_url)
                if ($parser->is_url_editable()) {
                    $result = $parser->edit($content, $new_url, $old_url, $old_raw_url);
                    $content = $result['content'];
                }
            }

            $contentDecoded[$k]['content'] = $content;
        }

        return array(
            'content' => wp_slash(json_encode($contentDecoded, JSON_UNESCAPED_SLASHES)),
            'raw_url' => $new_url,
        );
    }

    /**
     * Get the link text for printing in the "Broken Links" table.
     *
     * @param LinkInstance $instance
     * @param string          $context
     * @return string HTML
     */
    function ui_get_link_text($instance, $context = 'display')
    {

        $image_html = sprintf(
            '<img src="%s" class="blc-small-image" title="%2$s" alt="%2$s"> ',
            esc_attr(plugins_url('/images/font-awesome/font-awesome-code.png', BLC_PLUGIN_FILE_LEGACY)),
            __('Footnotes', 'link-checker')
        );

        $field_html = sprintf(
            '<code>%s</code>',
            $instance->container_field
        );

        if ('email' !== $context) {
            $field_html = $image_html . $field_html;
        }

        return $field_html;
    }
}
