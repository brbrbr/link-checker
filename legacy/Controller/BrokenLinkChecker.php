<?php

namespace Blc\Controller;
/**
 * Simple function to replicate PHP 5 behaviour
 *
 * @link    https://wordpress.org/plugins/broken-link-checker/
 * @since   1.0.0
 * @package broken-link-checker
 */

use Blc\Includes\WPMutex;
use Blc\Utils\UpdatePlugin;
use Blc\Includes\TransactionManager;
use Blc\Includes\blcUtility;
use Blc\Utils\ConfigurationManager;

require_once BLC_DIRECTORY_LEGACY . '/includes/screen-options/screen-options.php';
require_once BLC_DIRECTORY_LEGACY . '/includes/screen-meta-links.php';





require_once BLC_DIRECTORY_LEGACY . '/includes/link-query.php';


/**
 * Broken Link Checker core
 */
class BrokenLinkChecker
{
    /**
     * Plugin configuration.
     *
     * @var object
     */
    protected $conf;

    /**
     * Loader script path.
     *
     * @var string
     */


    protected $update;

    /**
     * Loader basename.
     *
     * @var string
     */
    public $my_basename = '';
    private $loader     = '';


    /**
     * Execution start time.
     *
     * @var string
     */
    public $execution_start_time;

    /**
     * Text domain status.
     *
     * @var string
     */
    public $is_textdomain_loaded = false;

    protected $is_settings_tab = false;

    public const BLC_PARKED_UNCHECKED = 0;
    public const BLC_PARKED_PARKED    = 1;
    public const BLC_PARKED_CHECKED   = 2;

    public const DOMAINPARKINGSQL = array(
        'sedo.com'          => " `final_url` like '%sedo.com%'",
        'buy-domain'        => "`final_url` like '%buy-domain%'",
        '(sedo)parking'     => "`final_url` REGEXP( 'https?://www?[0-9]')",
        'dan.com'           => "`log` like '%dan.com%'",
        'domein.link'       => "`final_url` like '%domein.link%'",
        'gopremium.net'     => "`final_url` like '%gopremium.net%'",
        'koopdomeinnaam.nl' => "`final_url` like '%koopdomeinnaam.nl%'",


    );
    protected $plugin_config;

    /**
     * Class constructor
     *
     */
    public function __construct()
    {

        $this->acquire_lock();
        static $method_called = false;

        $this->plugin_config = ConfigurationManager::getInstance();

        $this->loader = BLC_PLUGIN_FILE_LEGACY;

        $this->load_language();
        if ($method_called) {
            return;
        }
        $method_called = true;

        // Unlike the activation hook, the deactivation callback *can* be registered in this file.

        // because deactivation happens after this class has already been instantiated (during the 'init' action).

        register_deactivation_hook(WPMUDEV_BLC_PLUGIN_FILE, array( $this, 'deactivation' ));

        add_action('admin_menu', array( $this, 'admin_menu' ));

        $this->update = new UpdatePlugin(WPMUDEV_BLC_PLUGIN_FILE);

        $this->is_settings_tab = $this->is_settings_tab();

        // Load jQuery on Dashboard pages (probably redundant as WP already does that).
        // add_action( 'admin_print_scripts', array( $this, 'admin_print_scripts' ) );.

        // The dashboard widget.
        add_action('wp_dashboard_setup', array( $this, 'hook_wp_dashboard_setup' ));

        // AJAXy hooks.
        add_action('wp_ajax_blc_full_status', array( $this, 'ajax_full_status' ));
        add_action('wp_ajax_blc_work', array( $this, 'ajax_work' ));
        add_action('wp_ajax_blc_discard', array( $this, 'ajax_discard' ));
        add_action('wp_ajax_blc_edit', array( $this, 'ajax_edit' ));
        add_action('wp_ajax_blc_link_details', array( $this, 'ajax_link_details' ));
        add_action('wp_ajax_blc_unlink', array( $this, 'ajax_unlink' ));
        add_action('wp_ajax_blc_recheck', array( $this, 'ajax_recheck' ));
        add_action('wp_ajax_blc_deredirect', array( $this, 'ajax_deredirect' ));
        add_action('wp_ajax_blc_current_load', array( $this, 'ajax_current_load' ));

        add_action('wp_ajax_blc_dismiss', array( $this, 'ajax_dismiss' ));
        add_action('wp_ajax_blc_undismiss', array( $this, 'ajax_undismiss' ));

        // Add/remove Cron events.
        $this->setup_cron_events();

        // Set hooks that listen for our Cron actions.
        add_action('blc_cron_email_notifications', array( $this, 'maybe_send_email_notifications' ));
        add_action('blc_cron_check_links', array( $this, 'cron_check_links' ));
        add_action('blc_cron_database_maintenance', array( $this, 'database_maintenance' ));
        add_action('blc_corn_clear_log_file', array( $this, 'clear_log_file' ));

        // Set the footer hook that will call the worker function via AJAX.
        add_action('admin_footer', array( $this, 'admin_footer' ));

        if (empty($_GET['local-settings'])) {
            // Add a "Screen Options" panel to the "Broken Links" page.
            add_screen_options_panel(
                'blc-screen-options',
                '',
                array( $this, 'screen_options_html' ),
                'toplevel_page_blc_local',
                array( $this, 'ajax_save_screen_options' ),
                true
            );
        }

        // Display an explanatory note on the "Tools -> Broken Links -> Warnings" page.
        add_action('admin_notices', array( $this, 'show_warnings_section_notice' ));

        // Restore post date updated with the update link.
        add_filter('wp_insert_post_data', array( $this, 'disable_post_date_update' ), 10, 2);
    }


    /**
     * Does a check if current page is settings tab or not in Local admin page.
     *
     * @return bool
     */
    protected function is_settings_tab(): bool
    {
        return current_user_can('manage_options') && ! empty($_GET['local-settings']);
    }

    /**
     * Output the script that runs the link monitor while the Dashboard is open.
     *
     * @return void
     */
    public function admin_footer()
    {
        $fix = filter_input(INPUT_GET, 'fix-install-button', FILTER_VALIDATE_BOOLEAN);
        $tab = ! empty($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';

        if (true === $fix && 'plugin-information' === $tab) {
            echo '<script>';
            echo "jQuery('#plugin_install_from_iframe').on('click', function() { window.location.href = jQuery(this).attr('href'); return false;});";
            echo '</script>';
        }

        if (! $this->plugin_config->options['run_in_dashboard']) {
            return;
        }
        $nonce = wp_create_nonce('blc_work');
        ?>
        <!-- wsblc admin footer -->
        <script type='text/javascript'>
            (function($) {

                //(Re)starts the background worker thread
                function blcDoWork() {
                    $.post(
                        ajaxurl, {
                            'action': 'blc_work',
                            '_ajax_nonce': '<?php echo esc_js($nonce); ?>'
                        }
                    );
                }

                //Call it the first time
                blcDoWork();

                //Then call it periodically every X seconds
                setInterval(blcDoWork, <?php echo ( intval($this->max_execution_time_option()) + 1 ) * 1000; ?>);

            })(jQuery);
        </script>
        <!-- /wsblc admin footer -->
        <?php
    }

    /**
     * Check if an URL matches the exclusion list.
     *
     * @param string $url The url to exclude.
     *
     * @return bool
     */
    public function is_excluded($url)
    {
        if (! is_array($this->plugin_config->options['exclusion_list'])) {
            return false;
        }
        foreach ($this->plugin_config->options['exclusion_list'] as $excluded_word) {
            if (stristr($url, $excluded_word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get dashboard_widget
     */
    public function dashboard_widget()
    {
        if ($this->plugin_config->options['show_widget_count_bubble']) {
            $this->addStatusAssets();
        }
        ?>
        <p id="wsblc_activity_box" class="blc_full_status"><?php esc_html_e('Loading...', 'broken-link-checker'); ?></p>

        <?php
    }

    /**
     * Dashboard widget controls.
     *
     * @param int   $widget_id The widget ID.
     * @param array $form_inputs The form inputs.
     */
    public function dashboard_widget_control($widget_id, $form_inputs = array())
    {
        if (isset($_POST['blc_update_widget_nonce'])) :
            // Ignore sanitization field for nonce.
            $nonce = sanitize_text_field(wp_unslash($_POST['blc_update_widget_nonce']));

            if (wp_verify_nonce($nonce, 'blc_update_widget') && isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['widget_id']) && 'blc_dashboard_widget' === $_POST['widget_id']) {
                // It appears $form_inputs isn't used in the current WP version, so lets just use $_POST.
                $this->plugin_config->options['show_widget_count_bubble'] = ! empty($_POST['blc-showcounter']);
                $this->plugin_config->save_options();
            }
        endif;

        ?>
        <p><label for="blc-showcounter">
                <input id="blc-showcounter" name="blc-showcounter" type="checkbox" value="1" 
                <?php
                if ($this->plugin_config->options['show_widget_count_bubble']) {
                    echo 'checked="checked"';
                }
                ?>
                                                                                                />
                <?php esc_html_e('Show a badge with the number of broken links in the title', 'broken-link-checker'); ?>
            </label></p>
        <?php

        wp_nonce_field('blc_update_widget', 'blc_update_widget_nonce');
    }

    /**
     * Enqueue settings script.
     */
    public function enqueue_settings_scripts()
    {
        // jQuery UI is used on the settings page.
        wp_enqueue_script('jquery-ui-core');   // Used for background color animation.
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_script('jquery-ui-tabs');
        wp_enqueue_script('jquery-cookie', plugins_url('js/jquery.cookie.js', BLC_PLUGIN_FILE_LEGACY), array(), '1.0.0', false); // Used for storing last widget states, etc.
        wp_enqueue_script('options-page-js', plugins_url('js/options-page.js', BLC_PLUGIN_FILE_LEGACY), array( 'jquery' ), '2.3.0.6337', false);
        wp_set_script_translations('options-page-js', 'broken-link-checker');
    }

    /**
     * Enqueue linkpage script.
     */
    public function enqueue_link_page_scripts()
    {
        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-ui-dialog'); // Used for the search form.
        wp_enqueue_script('jquery-color');     // Used for background color animation.
        wp_enqueue_script('sprintf', plugins_url('js/sprintf.js', BLC_PLUGIN_FILE_LEGACY), array(), '1.0.0', false); // Used in error messages.
    }

    /**
     * Initiate a full recheck - reparse everything and check all links anew.
     *
     * @return void
     */
    public function initiate_recheck()
    {
        global $wpdb; // wpdb.

        // Delete all discovered instances.
		$wpdb->query("TRUNCATE {$wpdb->prefix}blc_instances"); //phpcs:ignore

        // Delete all discovered links.
		$wpdb->query("TRUNCATE {$wpdb->prefix}blc_links"); //phpcs:ignore

        // Mark all posts, custom fields and bookmarks for processing.
        $this->blc_resynch(true);
    }


    /**
     * (Re)create synchronization records for all containers and mark them all as unparsed.
     *
     * @param bool $forced If true, the plugin will recreate all synch. records from scratch.
     * @return void
     */
    function blc_resynch($forced = false)
    {
        global $wpdb, $blclog;

        if ($forced) {
            $blclog->info('... Forced resynchronization initiated');

            // Drop all synchronization records
            $wpdb->query("TRUNCATE {$wpdb->prefix}blc_synch");
        } else {
            $blclog->info('... Resynchronization initiated');
        }

        // Remove invalid DB entries
       blcUtility::blc_cleanup_database();

        // (Re)create and update synch. records for all container types.
        $blclog->info('... (Re)creating container records');
        \blcContainerHelper::resynch($forced);

        $blclog->info('... Setting resync. flags');
        blcUtility::blc_got_unsynched_items();

        // All done.
        $blclog->info('Database resynchronization complete.');
    }

    /**
     * A hook executed when the plugin is deactivated.
     *
     * @return void
     */
    public function deactivation()
    {
        global $wpdb, $blclog;
        // Remove our Cron events.
        wp_clear_scheduled_hook('blc_cron_check_links');
        wp_clear_scheduled_hook('blc_cron_email_notifications');
        wp_clear_scheduled_hook('blc_cron_database_maintenance');
        wp_clear_scheduled_hook('blc_corn_clear_log_file');
        wp_clear_scheduled_hook('blc_cron_check_news'); // Unused event.
        // Note the deactivation time for each module. This will help them
        // synch up propely if/when the plugin is reactivated.
        $moduleManager = \blcModuleManager::getInstance();
        $the_time      = current_time('timestamp');
        foreach ($moduleManager->get_active_modules() as $module_id => $module) {
            $this->plugin_config->options['module_deactivated_when'][ $module_id ] = $the_time;
        }
        delete_option('blc_activation_enabled');
        $this->plugin_config->save_options();
        $blclog->info('... deactivated');
    }

    /**
     * Perform various database maintenance tasks on the plugin's tables.
     *
     * Removes records that reference disabled containers and parsers,
     * deletes invalid instances and links, optimizes tables, etc.
     *
     * @return void
     */
    public function database_maintenance()
    {
        \blcContainerHelper::cleanup_containers();
        blc_cleanup_instances();
        blc_cleanup_links();

        blcUtility::optimize_database();
    }

    /**
     * Create the plugin's menu items and enqueue their scripts and CSS.
     * Callback for the 'admin_menu' action.
     *
     * @return void
     */
    protected function icon_url()
    {

        return 'data:image/svg+xml;base64,' . base64_encode(
            '
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <g clip-path="url(#clip0_1245_1418)">
                                        <path d="M14.0179 20.0001C13.5043 20.0001 12.9908 19.9302 12.4773 19.7906L11.5196 19.5252C10.9922 19.3855 10.673 18.8408 10.8118 18.2962C10.9506 17.7654 11.4919 17.4442 12.0331 17.5839L12.9908 17.8492C15.1005 18.4219 17.2934 17.1509 17.8624 15.0001C18.14 13.9665 18.0012 12.8772 17.4738 11.9554C16.9464 11.0336 16.0859 10.3632 15.0588 10.0839L12.6993 9.49727C12.1719 9.35761 11.8388 8.82688 11.9776 8.28219C12.1164 7.75146 12.6438 7.41627 13.1851 7.55593L15.5585 8.15649C17.1129 8.57548 18.4037 9.5671 19.1948 10.9638C19.986 12.3604 20.1941 13.9805 19.7916 15.5308C19.0699 18.2263 16.6549 20.0001 14.0179 20.0001Z" fill="white"/>
                                        <path d="M5.98202 20.0001C3.34496 20.0001 0.929973 18.2264 0.208252 15.5308C-0.208126 13.9806 6.31477e-05 12.3605 0.80506 10.9638C1.59618 9.56716 2.88695 8.57554 4.42754 8.15655L6.78702 7.45822C7.31443 7.30459 7.8696 7.59789 8.02227 8.12861C8.17494 8.65934 7.88348 9.218 7.35607 9.37163L4.96884 10.0839C3.91401 10.3632 3.06738 11.0336 2.52609 11.9554C1.99868 12.8772 1.85988 13.9666 2.13747 15.0001C2.70652 17.137 4.89944 18.4219 7.00909 17.8493L7.96675 17.5839C8.49417 17.4443 9.03546 17.7515 9.18813 18.2962C9.32692 18.8269 9.02158 19.3716 8.48029 19.5253L7.52262 19.7906C6.99521 19.9303 6.48167 20.0001 5.98202 20.0001Z" fill="white"/>
                                        <path d="M4.06657 14.4972C3.92778 13.9665 4.23313 13.4218 4.77442 13.2681L7.43923 12.5419C7.96664 12.4022 8.50793 12.7095 8.66061 13.2542C8.7994 13.7849 8.49405 14.3296 7.95276 14.4832L5.28795 15.2095C4.74666 15.3492 4.20537 15.0279 4.06657 14.4972Z" fill="white"/>
                                        <path d="M14.7118 15.1956L12.047 14.4694C11.5195 14.3297 11.2003 13.785 11.3391 13.2403C11.4779 12.7096 12.0192 12.3884 12.5605 12.528L15.2253 13.2543C15.7527 13.394 16.0719 13.9387 15.9331 14.4833C15.7944 15.028 15.2392 15.3493 14.7118 15.1956Z" fill="white"/>
                                        <path d="M9.99295 4.77654C9.43779 4.77654 8.99365 4.32961 8.99365 3.77095V1.00559C8.99365 0.446927 9.43779 0 9.99295 0C10.5481 0 10.9923 0.446927 10.9923 1.00559V3.78492C10.9923 4.32961 10.5481 4.77654 9.99295 4.77654Z" fill="white"/>
                                        <path d="M13.6435 5.81015C13.1716 5.53082 13.0051 4.9163 13.2826 4.44144L14.6706 2.0392C14.9482 1.56434 15.5588 1.39674 16.0307 1.67607C16.5026 1.9554 16.6692 2.56993 16.3916 3.04479L14.9898 5.44702C14.7261 5.92188 14.1154 6.08948 13.6435 5.81015Z" fill="white"/>
                                        <path d="M5.05192 5.44702L3.664 3.04479C3.38641 2.56993 3.55296 1.9554 4.02486 1.67607C4.49675 1.39674 5.10744 1.56434 5.38502 2.0392L6.77295 4.44144C7.05054 4.9163 6.88398 5.53082 6.41209 5.81015C5.94019 6.08948 5.32951 5.92188 5.05192 5.44702Z" fill="white"/>
                                </g>
                                <defs>
                                        <clipPath id="clip0_1245_1418">
                                                <rect width="20" height="20" fill="white"/>
                                        </clipPath>
                                </defs>
                        </svg>'
        );
    }
    public function admin_menu()
    {

        if (current_user_can('manage_options')) {
            add_filter('plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2);
        }

        $menu_title = __('Broken Links', 'broken-link-checker');
        if ($this->plugin_config->options['show_link_count_bubble']) {
            $this->addStatusAssets();
            $menu_title .= ' <span class="blc-broken-count"></span>';
        }

        $links_page_hook = add_menu_page(
            __('Broken Links', 'broken-link-checker'),
            $menu_title,
            'edit_others_posts',
            'blc_local',
            array( $this, 'render' ),
            $this->icon_url()
        );

        // Add plugin-specific scripts and CSS only to its own pages.
        add_action('admin_print_styles-' . $links_page_hook, array( $this, 'options_page_css' ));
        add_action('admin_print_styles-' . $links_page_hook, array( $this, 'links_page_css' ));

        add_action('admin_print_scripts-' . $links_page_hook, array( $this, 'enqueue_settings_scripts' ));
        add_action('admin_print_scripts-' . $links_page_hook, array( $this, 'enqueue_link_page_scripts' ));
    }

    private function addStatusAssets()
    {
        wp_enqueue_style('blc-status', plugins_url('css/status.css', BLC_PLUGIN_FILE_LEGACY), array(), '20141113');
        wp_enqueue_script('blc-status', plugins_url('js/status.js', BLC_PLUGIN_FILE_LEGACY), array(), '20141113');
    }

    /**
     * Function plugin_action_links()
     * Handler for the 'plugin_action_links' hook. Adds a "Settings" link to this plugin's entry
     * on the plugin list.
     *
     * @param array  $links
     * @param string $file
     *
     * @return array
     */
    public function plugin_action_links($links, $file)
    {

        if ($file === WPMUDEV_BLC_BASENAME) {
            // $links[] = "<a href='admin.php?page=link-checker-settings'>" . __( 'Settings' ) . '</a>';
            $links[] = "<a href='admin.php?page=blc_local&local-settings=true'>" . __('Settings') . '</a>';
            $links[] = "<a href='admin.php?page=blc_local'>" . __('Broken Links') . '</a>';
        }

        return $links;
    }

    public function local_nav()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        do_action('wpmudev-blc-local-nav-before');
        ?>
        <div id="wpmudev-blc-local-nav-wrap" class="_notice">
            <nav class="wpmudev-blc-local-nav">
                <a href="<?php echo admin_url('admin.php?page=blc_local'); ?>" class="wpmudev-blc-local-nav-item blc-local-links 
                                    <?php
                                    if (! $this->is_settings_tab) {
                                        echo 'active';
                                    }
                                    ?>
                    " aria-current="true"><?php echo esc_html__('Broken Links', 'broken-link-checker'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=blc_local&local-settings=true'); ?>" class="wpmudev-blc-local-nav-item blc-local-settings 
                                    <?php
                                    if ($this->is_settings_tab) {
                                        echo 'active';
                                    }
                                    ?>
                    " aria-current="true"><?php echo esc_html__('Settings', 'broken-link-checker'); ?></a>
            </nav>
        </div>
        <?php
        do_action('wpmudev-blc-local-nav-after');
    }

    public function local_header()
    {
        // Following h2 tag is added on purpose so that WP admin notices (that are automatically pushed right after first h tag) won't break ui.
        ?>
        <h2></h2>
        <div class="sui-wrap wrap-blc wrap-blc-local-page wrap-blc_local">
            <div class="sui-box local-blc-header-wrap">
                <div class="sui-box-header">
                    <h2 class="local-blc-heading"><?php echo esc_html__('Broken Link Checker', 'broken-link-checker'); ?></h2>
                </div>
            </div>
        </div>

        <?php
        // Following Admin Notice is going to be removed.
        // \WPMUDEV_BLC\App\Admin_Notices\Local\View::instance()->render();
    }

    /**
     * Function to show options page
     */
    public function options_page()
    {
        $moduleManager = \blcModuleManager::getInstance();

        if (isset($_POST['recheck']) && ! empty($_POST['recheck'])) {
            $this->initiate_recheck();

            // Redirect back to the settings page.
            $base_url = remove_query_arg(
                array(
                    '_wpnonce',
                    'noheader',
                    'updated',
                    'error',
                    'action',
                    'message',
                )
            );
            wp_redirect(
                add_query_arg(
                    array(
                        'recheck-initiated' => true,
                    ),
                    $base_url
                )
            );
            die();
        }

        $available_link_actions = array(
            'edit'                  => __('Edit URL', 'broken-link-checker'),
            'delete'                => __('Unlink', 'broken-link-checker'),
            'blc-discard-action'    => __('Not broken', 'broken-link-checker'),
            'blc-dismiss-action'    => __('Dismiss', 'broken-link-checker'),
            'blc-recheck-action'    => __('Recheck', 'broken-link-checker'),
            'blc-deredirect-action' => _x('Fix redirect', 'link action; replace one redirect with a direct link', 'broken-link-checker'),
        );

        if (isset($_POST['submit'])) {
            check_admin_referer('link-checker-options');

            $cleanPost = $_POST;

            if (function_exists('wp_magic_quotes')) {
                $cleanPost = stripslashes_deep($cleanPost); // Ceterum censeo, WP shouldn't mangle superglobals.
            }

            // Activate/deactivate modules.
            if (! empty($_POST['module'])) {
                $active = array_keys($_POST['module']);
                $moduleManager->set_active_modules($active);
            }

            // Only post statuses that actually exist can be selected.
            if (isset($_POST['enabled_post_statuses']) && is_array($_POST['enabled_post_statuses'])) {
                $available_statuses    = get_post_stati();
                $enabled_post_statuses = array_intersect($_POST['enabled_post_statuses'], $available_statuses);
            } else {
                $enabled_post_statuses = array();
            }
            // At least one status must be enabled; defaults to "Published".
            if (empty($enabled_post_statuses)) {
                $enabled_post_statuses = array( 'publish' );
            }

            // Did the user add/remove any post statuses?
            $same_statuses         = array_intersect($enabled_post_statuses, $this->plugin_config->options['enabled_post_statuses']);
            $post_statuses_changed = ( count($same_statuses) != count($enabled_post_statuses) )
                || ( count($same_statuses) !== count($this->plugin_config->options['enabled_post_statuses']) );

            $this->plugin_config->options['enabled_post_statuses'] = $enabled_post_statuses;

            // The execution time limit must be above zero
            $new_execution_time = ( $this->is_host_wp_engine() || $this->is_host_flywheel() ) ? 60 : intval($_POST['max_execution_time']);

            if ($new_execution_time > 0) {
                $this->plugin_config->options['max_execution_time'] = $new_execution_time;
            }

            // The check threshold also must be > 0
            $new_check_threshold = intval($_POST['check_threshold']);

            if ($new_check_threshold > 0) {
                $this->plugin_config->options['check_threshold'] = $new_check_threshold;
            }

            $this->plugin_config->options['mark_broken_links'] = ! empty($_POST['mark_broken_links']);

            $new_broken_link_css = trim($cleanPost['broken_link_css']);

            $this->plugin_config->options['mark_removed_links'] = ! empty($_POST['mark_removed_links']);
            $new_removed_link_css                      = trim($cleanPost['removed_link_css']);

            if (current_user_can('unfiltered_html')) {
                $this->plugin_config->options['broken_link_css']  = $new_broken_link_css;
                $this->plugin_config->options['removed_link_css'] = $new_removed_link_css;
            }

            $this->plugin_config->options['nofollow_broken_links'] = ! empty($_POST['nofollow_broken_links']);

            $this->plugin_config->options['show_link_count_bubble']   = ! empty($_POST['show_link_count_bubble']);
            $this->plugin_config->options['show_widget_count_bubble'] = ! empty($_POST['show_widget_count_bubble']);

            $this->plugin_config->options['exclusion_list'] = array_filter(
                preg_split(
                    '/[\s\r\n]+/', // split on newlines and whitespace
                    $cleanPost['exclusion_list'],
                    -1,
                    PREG_SPLIT_NO_EMPTY // skip empty values
                )
            );

            // Parse the custom field list
            $new_custom_fields = array_filter(
                preg_split(
                    '/[\r\n]+/',
                    $cleanPost['blc_custom_fields'],
                    -1,
                    PREG_SPLIT_NO_EMPTY
                )
            );

            // Calculate the difference between the old custom field list and the new one (used later)
            $diff1                                = array_diff($new_custom_fields, $this->plugin_config->options['custom_fields']);
            $diff2                                = array_diff($this->plugin_config->options['custom_fields'], $new_custom_fields);
            $this->plugin_config->options['custom_fields'] = $new_custom_fields;

            // Parse the custom field list
            $new_acf_fields = array_filter(preg_split('/[\r\n]+/', $cleanPost['blc_acf_fields'], -1, PREG_SPLIT_NO_EMPTY));

            // Calculate the difference between the old custom field list and the new one (used later)
            $acf_fields_diff1                  = array_diff($new_acf_fields, $this->plugin_config->options['acf_fields']);
            $acf_fields_diff2                  = array_diff($this->plugin_config->options['acf_fields'], $new_acf_fields);
            $this->plugin_config->options['acf_fields'] = $new_acf_fields;

            // Turning off warnings turns existing warnings into "broken" links.
            $this->plugin_config->options['blc_post_modified'] = ! empty($_POST['blc_post_modified']);

            // Turning off warnings turns existing warnings into "broken" links.
            $warnings_enabled = ! empty($_POST['warnings_enabled']);
            if ($this->plugin_config->get('warnings_enabled') && ! $warnings_enabled) {
                $this->promote_warnings_to_broken();
            }
            $this->plugin_config->options['warnings_enabled'] = $warnings_enabled;

            // HTTP timeout
            $new_timeout = intval($_POST['timeout']);
            if ($new_timeout > 0) {
                $this->plugin_config->options['timeout'] = $new_timeout;
            }

            // Server load limit
            if (isset($_POST['server_load_limit'])) {
                $this->plugin_config->options['server_load_limit'] = floatval($_POST['server_load_limit']);
                if ($this->plugin_config->options['server_load_limit'] < 0) {
                    $this->plugin_config->options['server_load_limit'] = 0;
                }
                $this->plugin_config->options['enable_load_limit'] = $this->plugin_config->options['server_load_limit'] > 0;
            }

            // Target resource usage (1% to 100%)
            if (isset($_POST['target_resource_usage'])) {
                $usage                                        = floatval($_POST['target_resource_usage']);
                $usage                                        = max(min($usage / 100, 1), 0.01);
                $this->plugin_config->options['target_resource_usage'] = $usage;
            }

            // When to run the checker
            $this->plugin_config->options['run_in_dashboard'] = ! empty($_POST['run_in_dashboard']);
            $this->plugin_config->options['run_via_cron']     = ! empty($_POST['run_via_cron']);

            // youtube api
            $this->plugin_config->options['youtube_api_key'] = ! empty($_POST['youtube_api_key']) ? sanitize_text_field(wp_unslash($_POST['youtube_api_key'])) : '';

            // Email notifications on/off
            $email_notifications              = ! empty($_POST['send_email_notifications']);
            $send_authors_email_notifications = ! empty($_POST['send_authors_email_notifications']);

            if (
                ( $email_notifications && ! $this->plugin_config->options['send_email_notifications'] )
                || ( $send_authors_email_notifications && ! $this->plugin_config->options['send_authors_email_notifications'] )
            ) {
                /*
                    The plugin should only send notifications about links that have become broken
                    since the time when email notifications were turned on. If we don't do this,
                    the first email notification will be sent nigh-immediately and list *all* broken
                    links that the plugin currently knows about.
                    */
                $this->plugin_config->options['last_notification_sent'] = time();
            }
            $this->plugin_config->options['send_email_notifications']         = $email_notifications;
            $this->plugin_config->options['send_authors_email_notifications'] = $send_authors_email_notifications;
            $this->plugin_config->options['notification_email_address']       = strval($_POST['notification_email_address']);

            if (! filter_var($this->plugin_config->options['notification_email_address'], FILTER_VALIDATE_EMAIL)) {
                $this->plugin_config->options['notification_email_address'] = '';
            }

            $widget_cap = sanitize_text_field(wp_unslash(strval($_POST['dashboard_widget_capability'])));
            if (! empty($widget_cap)) {
                $this->plugin_config->options['dashboard_widget_capability'] = $widget_cap;
            }

            // Link actions. The user can hide some of them to reduce UI clutter.
            $show_link_actions = array();
            foreach (array_keys($available_link_actions) as $action) {
                $show_link_actions[ $action ] = isset($_POST['show_link_actions']) && ! empty($_POST['show_link_actions'][ $action ]);
            }
            $this->plugin_config->set('show_link_actions', $show_link_actions);

            // Logging. The plugin can log various events and results for debugging purposes.
            $this->plugin_config->options['logging_enabled'] = ! empty($_POST['logging_enabled']);
            $this->plugin_config->options['cookies_enabled'] = ! empty($_POST['cookies_enabled']);
            $this->plugin_config->options['clear_log_on']    = strval($cleanPost['clear_log_on']);

            // process the log snd cookie file option even if logging_enabled is false
            // if the value is changed and then logging_enabled is unchecked the change wouldn't be saved.
            $log_file = self::checkAndCreateFile($cleanPost['log_file']);

            if (! $log_file) {
                $log_file = self::checkAndCreateFile(ConfigurationManager::get_default_log_directory() . '/' . ConfigurationManager::get_default_log_basename());
            }
            if (! $log_file) {
                $this->plugin_config->options['logging_enabled'] = false;
            } else {
                $this->plugin_config->options['log_file'] = $log_file;
            }

            $cookie_jar = self::checkAndCreateFile($cleanPost['cookie_jar']);

            if (! $cookie_jar) {
                $cookie_jar = self::checkAndCreateFile(ConfigurationManager::get_default_log_directory() . '/' . ConfigurationManager::get_default_cookie_basename());
            }

            if (! $cookie_jar) {
                $this->plugin_config->options['cookies_enabled'] = false;
            } else {
                $this->plugin_config->options['cookie_jar'] = $cookie_jar;
            }

            // Make settings that affect our Cron events take effect immediately
            $this->setup_cron_events();
            $this->plugin_config->save_options();

            /*
                If the list of custom fields was modified then we MUST resynchronize or
                custom fields linked with existing posts may not be detected. This is somewhat
                inefficient.
                */
            if (( count($diff1) > 0 ) || ( count($diff2) > 0 )) {
                $manager = \blcContainerHelper::get_manager('custom_field');
                if (! is_null($manager)) {
                    $manager->resynch();
                    blcUtility::blc_got_unsynched_items();
                }
            }

            /*
                If the list of acf fields was modified then we MUST resynchronize or
                acf fields linked with existing posts may not be detected. This is somewhat
                inefficient.
                */
            if (( count($acf_fields_diff1) > 0 ) || ( count($acf_fields_diff2) > 0 )) {
                $manager = \blcContainerHelper::get_manager('acf_field');
                if (! is_null($manager)) {
                    $manager->resynch();
                    blcUtility::blc_got_unsynched_items();
                }
            }

            // Resynchronize posts when the user enables or disables post statuses.
            if ($post_statuses_changed) {
                $overlord                        = \blcPostTypeOverlord::getInstance();
                $overlord->enabled_post_statuses = $this->plugin_config->get('enabled_post_statuses', array());
                $overlord->resynch('wsh_status_resynch_trigger');

                blcUtility::blc_got_unsynched_items();
                blc_cleanup_instances();
                blc_cleanup_links();
            }

            // Redirect back to the settings page
            $base_url = remove_query_arg(
                array(
                    '_wpnonce',
                    'noheader',
                    'updated',
                    'error',
                    'action',
                    'message',
                )
            );
            wp_redirect(
                add_query_arg(
                    array(
                        'settings-updated' => true,
                    ),
                    $base_url
                )
            );
        }

        // Show a confirmation message when settings are saved.
        if (! empty($_GET['settings-updated'])) {
            echo '<div id="message" class="updated fade"><p><strong>', __('Settings saved.', 'broken-link-checker'), '</strong></p></div>';
        }

        // Show one when recheck is started, too.
        if (! empty($_GET['recheck-initiated'])) {
            echo '<div id="message" class="updated fade"><p><strong>',
            __('Complete site recheck started.', 'broken-link-checker'), // -- Yoda
            '</strong></p></div>';
        }

        // Cull invalid and missing modules
        $moduleManager->validate_active_modules();

        $debug = $this->get_debug_info();

        add_filter('blc-module-settings-custom_field', array( $this, 'make_custom_field_input' ), 10, 2);
        add_filter('blc-module-settings-acf_field', array( $this, 'make_acf_field_input' ), 10, 2);

        // Translate and markup-ify module headers for display
        $modules = $moduleManager->get_modules_by_category('', true, true);

        // Output the custom broken link/removed link styles for example links
        printf(
            '<style type="text/css">%s %s</style>',
            $this->plugin_config->options['broken_link_css'],
            $this->plugin_config->options['removed_link_css']
        );

        $section_names = array(
            'general'  => __('General', 'broken-link-checker'),
            'where'    => __('Look For Links In', 'broken-link-checker'),
            'which'    => __('Which Links To Check', 'broken-link-checker'),
            'how'      => __('Protocols & APIs', 'broken-link-checker'),
            'advanced' => __('Advanced', 'broken-link-checker'),
        );

        ?>

        <div class="wrap" id="blc-settings-wrap">
            <!-- <h2>
            <?php
            // _e( 'Broken Link Checker Options', 'broken-link-checker' );
            ?>
                        </h2>-->

            <?php $this->local_header(); ?>
            <?php $this->local_nav(); ?>

            <div id="blc-admin-content">
                <form name="link_checker_options" id="link_checker_options" method="post" action="
                <?php
                // echo admin_url( 'admin.php?page=link-checker-settings&noheader=1' );
                echo admin_url('admin.php?page=blc_local&local-settings=true&noheader=1');
                ?>
            ">
                    <?php
                    wp_nonce_field('link-checker-options');
                    ?>

                    <div id="blc-tabs">

                        <ul class="hide-if-no-js">
                            <?php
                            foreach ($section_names as $section_id => $section_name) {
                                printf(
                                    '<li id="tab-button-%s"><a href="#section-%s" title="%s">%s</a></li>',
                                    esc_attr($section_id),
                                    esc_attr($section_id),
                                    esc_attr($section_name),
                                    $section_name
                                );
                            }
                            ?>
                        </ul>

                        <div id="section-general" class="blc-section">
                            <h3 class="hide-if-js"><?php echo $section_names['general']; ?></h3>

                            <table class="form-table">

                                <tr valign="top">
                                    <th scope="row">
                                        <?php _e('Status', 'broken-link-checker'); ?>
                                        <br>
                                        <a href="javascript:void(0)" id="blc-debug-info-toggle"><?php _e('Show debug info', 'broken-link-checker'); ?></a>
                                    </th>
                                    <td>

                                        <div id='wsblc_full_status' class="blc_full_status">

                                        </div>

                                        <table id="blc-debug-info">
                                            <?php

                                            // Output the debug info in a table
                                            foreach ($debug as $key => $value) {
                                                printf(
                                                    '<tr valign="top" class="blc-debug-item-%s"><th scope="row">%s</th><td>%s<div class="blc-debug-message">%s</div></td></tr>',
                                                    $value['state'],
                                                    $key,
                                                    $value['value'],
                                                    ( array_key_exists('message', $value) ? $value['message'] : '' )
                                                );
                                            }
                                            ?>
                                        </table>

                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Check each link', 'broken-link-checker'); ?></th>
                                    <td>

                                        <?php
                                        printf(
                                            __('Every %s hours', 'broken-link-checker'),
                                            sprintf(
                                                '<input type="text" name="check_threshold" id="check_threshold" value="%d" size="5" maxlength="5" />',
                                                $this->plugin_config->options['check_threshold']
                                            )
                                        );
                                        ?>
                                        <br />
                                        <span class="description">
                                            <?php _e('Existing links will be checked this often. New links will usually be checked ASAP.', 'broken-link-checker'); ?>
                                        </span>

                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('E-mail notifications', 'broken-link-checker'); ?></th>
                                    <td>
                                        <p style="margin-top: 0;">
                                            <label for='send_email_notifications'>
                                                <input type="checkbox" name="send_email_notifications" id="send_email_notifications" 
                                                <?php
                                                if ($this->plugin_config->options['send_email_notifications']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                                        />
                                                <?php _e('Send me e-mail notifications about newly detected broken links', 'broken-link-checker'); ?>
                                            </label><br />
                                        </p>

                                        <p>
                                            <label for='send_authors_email_notifications'>
                                                <input type="checkbox" name="send_authors_email_notifications" id="send_authors_email_notifications" 
                                                <?php
                                                if ($this->plugin_config->options['send_authors_email_notifications']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                                                        />
                                                <?php _e('Send authors e-mail notifications about broken links in their posts', 'broken-link-checker'); ?>
                                            </label><br />
                                        </p>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Count bubbles', 'broken-link-checker'); ?></th>
                                    <td>
                                        <p style="margin-top: 0;">
                                            <label for='show_link_count_bubble'>
                                                <input type="checkbox" name="show_link_count_bubble" id="show_link_count_bubble" 
                                                <?php
                                                if ($this->plugin_config->options['show_link_count_bubble']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                                    />
                                                <?php _e('Show a bubble with number of found broken links in the menu bar', 'broken-link-checker'); ?>
                                            </label><br />
                                        </p>

                                        <p>
                                            <label for='show_widget_count_bubble'>
                                                <input type="checkbox" name="show_widget_count_bubble" id="show_widget_count_bubble" 
                                                <?php
                                                if ($this->plugin_config->options['show_widget_count_bubble']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                                        />
                                                <?php _e('Show a bubble with number of found broken links in the dashboard widget', 'broken-link-checker'); ?>
                                            </label><br />
                                        </p>
                                    </td>
                                </tr>






                                <tr valign="top">
                                    <th scope="row"><?php echo __('Notification e-mail address', 'broken-link-checker'); ?></th>
                                    <td>
                                        <p>
                                            <label>
                                                <input type="text" name="notification_email_address" id="notification_email_address" value="<?php echo esc_attr($this->plugin_config->get('notification_email_address', '')); ?>" class="regular-text ltr">
                                            </label><br>
                                            <span class="description">
                                                <?php echo __('Leave empty to use the e-mail address specified in Settings &rarr; General.', 'broken-link-checker'); ?>
                                            </span>
                                        </p>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Link tweaks', 'broken-link-checker'); ?></th>
                                    <td>
                                        <p style="margin-top: 0; margin-bottom: 0.5em;">
                                            <label for='mark_broken_links'>
                                                <input type="checkbox" name="mark_broken_links" id="mark_broken_links" 
                                                <?php
                                                if ($this->plugin_config->options['mark_broken_links']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                        />
                                                <?php _e('Apply custom formatting to broken links', 'broken-link-checker'); ?>
                                            </label>
                                            |
                                            <a id="toggle-broken-link-css-editor" href="#" class="blc-toggle-link">
                                                <?php
                                                _e('Edit CSS', 'broken-link-checker');
                                                ?>
                                            </a>
                                        </p>

                                        <div id="broken-link-css-wrap" 
                                        <?php
                                        if (! blcUtility::get_cookie('broken-link-css-wrap', false)) {
                                            echo ' class="hidden"';
                                        }
                                        ?>
                                                                        >
                                            <textarea name="broken_link_css" id="broken_link_css" cols='45' rows='4'>
                                            <?php
                                            if (isset($this->plugin_config->options['broken_link_css']) && current_user_can('unfiltered_html')) {
                                                echo $this->plugin_config->options['broken_link_css'];
                                            }
                                            ?>
                    </textarea>
                                            <p class="description">
                                                <?php
                                                printf(
                                                    __('Example : Lorem ipsum <a %s>broken link</a>, dolor sit amet.', 'broken-link-checker'),
                                                    ' href="#" class="broken_link" onclick="return false;"'
                                                );
                                                echo ' ', __('Click "Save Changes" to update example output.', 'broken-link-checker');
                                                ?>
                                            </p>
                                        </div>

                                        <p style="margin-bottom: 0.5em;">
                                            <label for='mark_removed_links'>
                                                <input type="checkbox" name="mark_removed_links" id="mark_removed_links" 
                                                <?php
                                                if ($this->plugin_config->options['mark_removed_links']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                            />
                                                <?php _e('Apply custom formatting to removed links', 'broken-link-checker'); ?>
                                            </label>
                                            |
                                            <a id="toggle-removed-link-css-editor" href="#" class="blc-toggle-link">
                                                <?php
                                                _e('Edit CSS', 'broken-link-checker');
                                                ?>
                                            </a>
                                        </p>

                                        <div id="removed-link-css-wrap" 
                                        <?php
                                        if (! blcUtility::get_cookie('removed-link-css-wrap', false)) {
                                            echo ' class="hidden"';
                                        }
                                        ?>
                                                                        >
                                            <textarea name="removed_link_css" id="removed_link_css" cols='45' rows='4'>
                                            <?php
                                            if (isset($this->plugin_config->options['removed_link_css']) && current_user_can('unfiltered_html')) {
                                                echo $this->plugin_config->options['removed_link_css'];
                                            }
                                            ?>
                    </textarea>

                                            <p class="description">
                                                <?php
                                                printf(
                                                    __('Example : Lorem ipsum <span %s>removed link</span>, dolor sit amet.', 'broken-link-checker'),
                                                    ' class="removed_link"'
                                                );
                                                echo ' ', __('Click "Save Changes" to update example output.', 'broken-link-checker');
                                                ?>

                                            </p>
                                        </div>

                                        <p>
                                            <label for='nofollow_broken_links'>
                                                <input type="checkbox" name="nofollow_broken_links" id="nofollow_broken_links" 
                                                <?php
                                                if ($this->plugin_config->options['nofollow_broken_links']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                                />
                                                <?php _e('Stop search engines from following broken links', 'broken-link-checker'); ?>
                                            </label>
                                        </p>

                                        <p class="description">
                                            <?php
                                            echo _x(
                                                'These settings only apply to the content of posts, not comments or custom fields.',
                                                '"Link tweaks" settings',
                                                'broken-link-checker'
                                            );
                                            ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php echo _x('Warnings', 'settings page', 'broken-link-checker'); ?></th>
                                    <td id="blc_warning_settings">
                                        <label>
                                            <input type="checkbox" name="warnings_enabled" id="warnings_enabled" <?php checked($this->plugin_config->options['warnings_enabled']); ?> />
                                            <?php _e('Show uncertain or minor problems as "warnings" instead of "broken"', 'broken-link-checker'); ?>
                                        </label>
                                        <p class="description">
                                            <?php
                                            _e('Turning off this option will make the plugin report all problems as broken links.', 'broken-link-checker');
                                            ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php echo __('YouTube API Key', 'broken-link-checker'); ?></th>
                                    <td>
                                        <p>
                                            <label>
                                                <input type="text" name="youtube_api_key" id="youtube_api_key" value="<?php echo esc_html($this->plugin_config->options['youtube_api_key']); ?>" class="regular-text ltr">
                                            </label><br>
                                            <span class="description">
                                                <?php printf(__('Use your own %1$sapi key%2$s for checking youtube links.', 'broken-link-checker'), '<a href="https://developers.google.com/youtube/v3/getting-started">', '</a>'); ?>
                                            </span>
                                        </p>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php echo esc_html__('Post Modified Date', 'broken-link-checker'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="blc_post_modified" id="blc_post_modified" <?php checked($this->plugin_config->options['blc_post_modified']); ?> />
                                            <?php esc_html_e('Disable post modified date change when link is edited', 'broken-link-checker'); ?>
                                        </label>
                                    </td>
                                </tr>

                            </table>

                        </div>

                        <div id="section-where" class="blc-section">
                            <h3 class="hide-if-js"><?php echo $section_names['where']; ?></h3>

                            <table class="form-table">

                                <tr valign="top">
                                    <th scope="row"><?php _e('Look for links in', 'broken-link-checker'); ?></th>
                                    <td>
                                        <?php
                                        if (! empty($modules['container'])) {
                                            uasort(
                                                $modules['container'],
                                                function ($a, $b) {
                                                    return strcasecmp($a['Name'], $b['Name']);
                                                }
                                            );
                                            $this->print_module_list($modules['container'], $this->plugin_config->options);
                                        }
                                        ?>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Post statuses', 'broken-link-checker'); ?></th>
                                    <td>
                                        <?php
                                        $available_statuses = get_post_stati(array( 'internal' => false ), 'objects');

                                        if (isset($this->plugin_config->options['enabled_post_statuses'])) {
                                            $enabled_post_statuses = $this->plugin_config->options['enabled_post_statuses'];
                                        } else {
                                            $enabled_post_statuses = array();
                                        }

                                        foreach ($available_statuses as $status => $status_object) {
                                            printf(
                                                '<p><label><input type="checkbox" name="enabled_post_statuses[]" value="%s"%s> %s</label></p>',
                                                esc_attr($status),
                                                in_array($status, $enabled_post_statuses) ? ' checked="checked"' : '',
                                                $status_object->label
                                            );
                                        }
                                        ?>
                                    </td>
                                </tr>

                            </table>

                        </div>


                        <div id="section-which" class="blc-section">
                            <h3 class="hide-if-js"><?php echo $section_names['which']; ?></h3>

                            <table class="form-table">

                                <tr valign="top">
                                    <th scope="row"><?php _e('Link types', 'broken-link-checker'); ?></th>
                                    <td>
                                        <?php
                                        if (! empty($modules['parser'])) {
                                            $this->print_module_list($modules['parser'], $this->plugin_config->options);
                                        } else {
                                            echo __('Error : All link parsers missing!', 'broken-link-checker');
                                        }
                                        ?>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Exclusion list', 'broken-link-checker'); ?></th>
                                    <td><?php _e("Don't check links where the URL contains any of these words (one per line) :", 'broken-link-checker'); ?>
                                        <br />
                                        <textarea name="exclusion_list" id="exclusion_list" cols='45' rows='4'>
                                        <?php
                                        if (isset($this->plugin_config->options['exclusion_list'])) {
                                            echo esc_textarea(implode("\n", $this->plugin_config->options['exclusion_list']));
                                        }
                                        ?>
                                                                                                                </textarea>

                                    </td>
                                </tr>

                            </table>
                        </div>

                        <div id="section-how" class="blc-section">
                            <h3 class="hide-if-js"><?php echo $section_names['how']; ?></h3>

                            <table class="form-table">

                                <tr valign="top">
                                    <th scope="row"><?php _e('Check links using', 'broken-link-checker'); ?></th>
                                    <td>
                                        <?php
                                        if (! empty($modules['checker'])) {
                                            $modules['checker'] = array_reverse($modules['checker']);
                                            $this->print_module_list($modules['checker'], $this->plugin_config->options);
                                        }
                                        ?>
                                    </td>
                                </tr>

                            </table>
                        </div>

                        <div id="section-advanced" class="blc-section">
                            <h3 class="hide-if-js"><?php echo $section_names['advanced']; ?></h3>

                            <table class="form-table">

                                <tr valign="top">
                                    <th scope="row"><?php _e('Timeout', 'broken-link-checker'); ?></th>
                                    <td>

                                        <?php

                                        printf(
                                            __('%s seconds', 'broken-link-checker'),
                                            sprintf(
                                                '<input type="text" name="timeout" id="blc_timeout" value="%d" size="5" maxlength="3" />',
                                                $this->plugin_config->options['timeout']
                                            )
                                        );

                                        ?>
                                        <br /><span class="description">
                                            <?php _e('Links that take longer than this to load will be marked as broken.', 'broken-link-checker'); ?>
                                        </span>

                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Link monitor', 'broken-link-checker'); ?></th>
                                    <td>

                                        <p>
                                            <label for='run_in_dashboard'>

                                                <input type="checkbox" name="run_in_dashboard" id="run_in_dashboard" 
                                                <?php
                                                if ($this->plugin_config->options['run_in_dashboard']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                        />
                                                <?php _e('Run continuously while the Dashboard is open', 'broken-link-checker'); ?>
                                            </label>
                                        </p>

                                        <p>
                                            <label for='run_via_cron'>
                                                <input type="checkbox" name="run_via_cron" id="run_via_cron" 
                                                <?php
                                                if ($this->plugin_config->options['run_via_cron']) {
                                                    echo ' checked="checked"';
                                                }
                                                ?>
                                                                                                                />
                                                <?php _e('Run hourly in the background', 'broken-link-checker'); ?>
                                            </label>
                                        </p>

                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Show the dashboard widget for', 'broken-link-checker'); ?></th>
                                    <td>

                                        <?php
                                        $widget_caps = array(
                                            _x('Administrator', 'dashboard widget visibility', 'broken-link-checker')                => 'manage_options',
                                            _x('Editor and above', 'dashboard widget visibility', 'broken-link-checker')             => 'edit_others_posts',
                                            _x('Nobody (disables the widget)', 'dashboard widget visibility', 'broken-link-checker') => 'do_not_allow',
                                        );

                                        foreach ($widget_caps as $title => $capability) {
                                            printf(
                                                '<p><label><input type="radio" name="dashboard_widget_capability" value="%s"%s> %s</label></p>',
                                                esc_attr($capability),
                                                checked($capability, $this->plugin_config->get('dashboard_widget_capability'), false),
                                                $title
                                            );
                                        }
                                        ?>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php echo _x('Show link actions', 'settings page', 'broken-link-checker'); ?></th>
                                    <td>
                                        <?php
                                        $show_link_actions = $this->plugin_config->get('show_link_actions', array());
                                        foreach ($available_link_actions as $action => $text) {
                                            $enabled = isset($show_link_actions[ $action ]) ? (bool) ( $show_link_actions[ $action ] ) : true;
                                            printf(
                                                '<p><label><input type="checkbox" name="show_link_actions[%1$s]" %3$s> %2$s</label></p>',
                                                $action,
                                                $text,
                                                checked($enabled, true, false)
                                            );
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php if (! $this->is_host_wp_engine() && ! $this->is_host_flywheel()) : ?>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Max. execution time', 'broken-link-checker'); ?></th>
                                        <td>

                                            <?php

                                            printf(
                                                __('%s seconds', 'broken-link-checker'),
                                                sprintf(
                                                    '<input type="text" name="max_execution_time" id="max_execution_time" value="%d" size="5" maxlength="5" />',
                                                    // $this->plugin_config->options['max_execution_time']
                                                    $this->max_execution_time_option()
                                                )
                                            );

                                            ?>
                                            <br /><span class="description">
                                                <?php

                                                _e('The plugin works by periodically launching a background job that parses your posts for links, checks the discovered URLs, and performs other time-consuming tasks. Here you can set for how long, at most, the link monitor may run each time before stopping.', 'broken-link-checker');

                                                ?>
                                            </span>

                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr valign="top">
                                    <th scope="row"><?php _e('Server load limit', 'broken-link-checker'); ?></th>
                                    <td>
                                        <?php

                                        $load      = blcUtility::get_server_load();
                                        $available = ! empty($load);

                                        if ($available) {
                                            $value = ! empty($this->plugin_config->options['server_load_limit']) ? sprintf('%.2f', $this->plugin_config->options['server_load_limit']) : '';
                                            printf(
                                                '<input type="text" name="server_load_limit" id="server_load_limit" value="%s" size="5" maxlength="5"/> ',
                                                $value
                                            );

                                            printf(
                                                __('Current load : %s', 'broken-link-checker'),
                                                '<span id="wsblc_current_load">...</span>'
                                            );
                                            echo '<br/><span class="description">';
                                            printf(
                                                __(
                                                    'Link checking will be suspended if the average <a href="%s">server load</a> rises above this number. Leave this field blank to disable load limiting.',
                                                    'broken-link-checker'
                                                ),
                                                'http://en.wikipedia.org/wiki/Load_(computing)'
                                            );
                                            echo '</span>';
                                        } else {
                                            echo '<input type="text" disabled="disabled" value="', esc_attr(__('Not available', 'broken-link-checker')), '" size="13"/><br>';
                                            echo '<span class="description">';
                                            _e('Load limiting only works on Linux-like systems where <code>/proc/loadavg</code> is present and accessible.', 'broken-link-checker');
                                            echo '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Target resource usage', 'broken-link-checker'); ?></th>
                                    <td>
                                        <?php
                                        $target_resource_usage = $this->plugin_config->get('target_resource_usage', 0.25);
                                        printf(
                                            '<input name="target_resource_usage" value="%d"
						type="range" min="1" max="100" id="target_resource_usage">',
                                            $target_resource_usage * 100
                                        );
                                        ?>

                                        <span id="target_resource_usage_percent">
                                            <?php
                                            printf('%.0f%%', $target_resource_usage * 100);
                                            ?>
                                        </span>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Logging', 'broken-link-checker'); ?></th>
                                    <td>
                                        <p>
                                            <label for='logging_enabled'>
                                                <input type="checkbox" name="logging_enabled" id="logging_enabled" <?php checked($this->plugin_config->options['logging_enabled']); ?> />
                                                <?php _e('Enable logging', 'broken-link-checker'); ?>
                                            </label>
                                        </p>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php _e('Log file location', 'broken-link-checker'); ?></th>
                                    <td>
                                        <div class="blc-logging-options">
                                            <p>
                                                <input type="text" name="log_file" id="log_file" size="90" value="<?php echo esc_attr($this->plugin_config->options['log_file']); ?>">
                                                <br /><span class="description">
                                                    <?php
                                                    _e('Leave blank for default location: ', 'broken-link-checker');
                                                    echo ConfigurationManager::get_default_log_directory() . '/' . ConfigurationManager::get_default_log_basename();
                                                    ?>
                                                </span>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php _e('Log file clear schedule', 'broken-link-checker'); ?></th>
                                    <td>
                                        <div class="blc-logging-options">
                                            <p>
                                                <?php $schedules = wp_get_schedules(); ?>
                                                <select name="clear_log_on">
                                                    <option value=""> <?php esc_html_e('Never', 'wpmudev'); ?></option>
                                                    <?php
                                                    foreach ($schedules as $key => $schedule) {
                                                        $selected = selected(
                                                            $this->plugin_config->options['clear_log_on'],
                                                            $key,
                                                            false
                                                        );
                                                        ?>
                                                        <option <?php echo $selected; ?>value="<?php echo esc_attr($key); ?>"> <?php echo esc_html($schedule['display']); ?></option>
                                                        <?php
                                                    }
                                                    ?>

                                                </select>
                                            </p>
                                        </div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php _e('Cookies', 'broken-link-checker'); ?></th>
                                    <td>
                                        <p>
                                            <label for='cookies_enabled'>
                                                <input type="checkbox" name="cookies_enabled" id="cookies_enabled" <?php checked($this->plugin_config->options['cookies_enabled']); ?> />
                                                <?php _e('Enable Cookies', 'broken-link-checker'); ?>
                                            </label>
                                        </p>
                                    </td>
                                </tr>


                                <tr valign="top">
                                    <th scope="row"><?php _e('Cookie file location', 'broken-link-checker'); ?></th>
                                    <td>

                                        <div class="blc-cookie-options">
                                            <p>
                                                <input type="text" name="cookie_jar" id="cookie_jar" size="90" value="<?php echo esc_attr($this->plugin_config->options['cookie_jar']); ?>">
                                                <br /><span class="description">
                                                    <?php
                                                    _e('Leave blank for default location: ', 'broken-link-checker');
                                                    echo ConfigurationManager::get_default_log_directory() . '/' . ConfigurationManager::get_default_cookie_basename();
                                                    ?>
                                                </span>
                                            </p>
                                        </div>
                                    </td>
                                </tr>


                                <tr valign="top">
                                    <th scope="row"><?php _e('Forced recheck', 'broken-link-checker'); ?></th>
                                    <td>
                                        <input class="button" type="button" name="start-recheck" id="start-recheck" value="<?php _e('Re-check all pages', 'broken-link-checker'); ?>" />
                                        <input type="hidden" name="recheck" value="" id="recheck" />
                                        <br />
                                        <span class="description">
                                            <?php
                                            _e('The "Nuclear Option". Click this button to make the plugin empty its link database and recheck the entire site from scratch.', 'broken-link-checker');

                                            ?>
                                        </span>
                                    </td>
                                </tr>

                            </table>
                        </div>

                    </div>

                    <p class="submit"><input type="submit" name="submit" class='button-primary' value="<?php _e('Save Changes'); ?>" /></p>
                </form>

            </div> <!-- First postbox-container -->


        </div>

        <?php
    }
    private function checkAndCreateFile($input)
    {

        $log_file       = esc_url_raw(strval($input));
        $file_type_data = wp_check_filetype($log_file);

        if (substr($log_file, 0, 7) === 'phar://' || ! isset($file_type_data['type']) || empty($file_type_data['type'])) {
            $log_file = '';
        }

        if (! empty($log_file) && ! file_exists($log_file)) {
            if (! file_exists(dirname($log_file))) {
                mkdir(dirname($log_file), 0750, true);
            }
            // Attempt to create the log file if not already there.
            if (! is_file($log_file)) {
                // Add a .htaccess to hide the log file from site visitors.
                file_put_contents(dirname($log_file) . '/.htaccess', 'Deny from all');
                file_put_contents($log_file, '');
            }
        }

        // revert to default
        if (! is_writable($log_file) || ! is_file($log_file)) {
            return false;
        }
        return $log_file;
    }


    /**
     * Output a list of modules and their settings.
     *
     * Each list entry will contain a checkbox that is checked if the module is
     * currently active.
     *
     * @param array $modules Array of modules to display
     * @param array $current_settings
     *
     * @return void
     */
    function print_module_list($modules, $current_settings)
    {
        $moduleManager = \blcModuleManager::getInstance();

        foreach ($modules as $module_id => $module_data) {
            $module_id = $module_data['ModuleID'];

            $style = $module_data['ModuleHidden'] ? ' style="display:none;"' : '';

            printf(
                '<div class="module-container" id="module-container-%s"%s>',
                $module_id,
                $style
            );
            $this->print_module_checkbox($module_id, $module_data, $moduleManager->is_active($module_id));

            $extra_settings = apply_filters('blc-module-settings-' . $module_id, '', $current_settings);

            if (! empty($extra_settings)) {
                printf(
                    ' | <a class="blc-toggle-link toggle-module-settings" id="toggle-module-settings-%s" href="#">%s</a>',
                    esc_attr($module_id),
                    __('Configure', 'broken-link-checker')
                );

                // The plugin remembers the last open/closed state of module configuration boxes
                $box_id = 'module-extra-settings-' . $module_id;
                $show   = blcUtility::get_cookie(
                    $box_id,
                    $moduleManager->is_active($module_id)
                );

                printf(
                    '<div class="module-extra-settings%s" id="%s">%s</div>',
                    $show ? '' : ' hidden',
                    $box_id,
                    $extra_settings
                );
            }

            echo '</div>';
        }
    }

    protected function max_execution_time_option()
    {
        // It's safe to return the conf property as it is set in constructor.
        if ($this->is_host_wp_engine() || $this->is_host_flywheel()) {
            $this->plugin_config->options['max_execution_time'] = 60;
        }

        return apply_filters('wpmudev_blc_max_execution_time', $this->plugin_config->options['max_execution_time']);
    }

    protected function is_host_wp_engine()
    {
        // return ( function_exists( 'is_wpe' ) && is_wpe() ) || ( defined( 'IS_WPE' ) && IS_WPE );
        return blcUtility::is_host_wp_engine();
    }

    protected function is_host_flywheel()
    {
        return blcUtility::is_host_flywheel();
        /*
            $host_name = 'flywheel';

            return ! empty( $_SERVER['SERVER_SOFTWARE'] ) &&
                    substr( strtolower( $_SERVER['SERVER_SOFTWARE'] ), 0, strlen( $host_name ) ) === strtolower( $host_name );
            */
    }

    /**
     * Output a checkbox for a module.
     *
     * Generates a simple checkbox that can be used to mark a module as active/inactive.
     * If the specified module can't be deactivated (ModuleAlwaysActive = true), the checkbox
     * will be displayed in a disabled state and a hidden field will be created to make
     * form submissions work correctly.
     *
     * @param string $module_id Module ID.
     * @param array  $module_data Associative array of module data.
     * @param bool   $active If true, the newly created checkbox will start out checked.
     *
     * @return void
     */
    function print_module_checkbox($module_id, $module_data, $active = false)
    {
        $disabled    = false;
        $name_prefix = 'module';
        $label_class = '';
        $active      = $active || $module_data['ModuleAlwaysActive'];

        if ($module_data['ModuleAlwaysActive']) {
            $disabled    = true;
            $name_prefix = 'module-always-active';
        }

        $checked = $active ? ' checked="checked"' : '';
        if ($disabled) {
            $checked .= ' disabled="disabled"';
        }

        printf(
            '<label class="%s">
					<input type="checkbox" name="%s[%s]" id="module-checkbox-%s"%s /> %s
				</label>',
            esc_attr($label_class),
            $name_prefix,
            esc_attr($module_id),
            esc_attr($module_id),
            $checked,
            $module_data['Name']
        );

        if ($module_data['ModuleAlwaysActive']) {
            printf(
                '<input type="hidden" name="module[%s]" value="on">',
                esc_attr($module_id)
            );
        }
    }

    /**
     * Add extra settings to the "Custom fields" entry on the plugin's config. page.
     *
     * Callback for the 'blc-module-settings-custom_field' filter.
     *
     * @param string $html Current extra HTML
     * @param array  $current_settings The current plugin configuration.
     *
     * @return string New extra HTML.
     */
    function make_custom_field_input($html, $current_settings)
    {
        $html .= '<span class="description">' .
            __(
                'Enter the names of custom fields you want to check (one per line). If a field contains HTML code, prefix its name with <code>html:</code>. For example, <code>html:field_name</code>.',
                'broken-link-checker'
            ) .
            '</span>';
        $html .= '<br><textarea name="blc_custom_fields" id="blc_custom_fields" cols="45" rows="4">';
        if (isset($current_settings['custom_fields'])) {
            $html .= esc_textarea(implode("\n", $current_settings['custom_fields']));
        }
        $html .= '</textarea>';

        return $html;
    }

    function make_acf_field_input($html, $current_settings)
    {
        $html .= '<span class="description">' . __('Enter the keys of acf fields you want to check (one per line). If a field contains HTML code, prefix its name with <code>html:</code>. For example, <code>html:field_586a3eaa4091b</code>.', 'broken-link-checker') . '</span>';
        $html .= '<br><textarea name="blc_acf_fields" id="blc_acf_fields" cols="45" rows="4">';
        if (isset($current_settings['acf_fields'])) {
            $html .= esc_textarea(implode("\n", $current_settings['acf_fields']));
        }
        $html .= '</textarea>';

        return $html;
    }

    /**
     * Enqueue CSS file for the plugin's Settings page.
     *
     * @return void
     */
    function options_page_css()
    {
        wp_enqueue_style('blc-options-page', plugins_url('css/options-page.css', BLC_PLUGIN_FILE_LEGACY), array(), '20141113');
        wp_enqueue_style('dashboard');
        wp_enqueue_style('plugin-install');
        wp_enqueue_script('plugin-install');
        add_thickbox();
    }

    public function render()
    {

        if ($this->is_settings_tab) {
            // When links tab is checked:

            $this->options_page();
        } else {
            $this->links_page();
        }
    }


    /**
     * Display the "Broken Links" page, listing links detected by the plugin and their status.
     *
     * @return void
     */
    function links_page()
    {
        global $wpdb;
        /* @var wpdb $wpdb */

        $blc_link_query = \blcLinkQuery::getInstance();

        // Cull invalid and missing modules so that we don't get dummy links/instances showing up.
        $moduleManager = \blcModuleManager::getInstance();
        $moduleManager->validate_active_modules();

        if (defined('BLC_DEBUG') && constant('BLC_DEBUG')) {
            // Make module headers translatable. They need to be formatted corrrectly and
            // placed in a .php file to be visible to the script(s) that generate .pot files.
            $code = $moduleManager->_build_header_translation_code();
            file_put_contents(dirname($this->loader) . '/includes/extra-strings.php', $code);
        }

        $action = ! empty($_POST['action']) ? $_POST['action'] : '';
        if (intval($action) == -1) {
            // Try the second bulk actions box
            $action = ! empty($_POST['action2']) ? $_POST['action2'] : '';
        }

        // Get the list of link IDs selected via checkboxes
        $selected_links = array();
        if (isset($_POST['selected_links']) && is_array($_POST['selected_links'])) {
            // Convert all link IDs to integers (non-numeric entries are converted to zero)
            $selected_links = array_map('intval', $_POST['selected_links']);
            // Remove all zeroes
            $selected_links = array_filter($selected_links);
        }

        $message   = '';
        $msg_class = 'updated';

        // Run the selected bulk action, if any
        $force_delete = false;
        switch ($action) {
            case 'create-custom-filter':
                list($message, $msg_class) = $this->do_create_custom_filter();
                break;

            case 'delete-custom-filter':
                list($message, $msg_class) = $this->do_delete_custom_filter();
                break;

                // @noinspection PhpMissingBreakStatementInspection Deliberate fall-through.

            case 'bulk-unlink':
                list($message, $msg_class) = $this->do_bulk_unlink($selected_links);
                break;

            case 'bulk-deredirect':
                list($message, $msg_class) = $this->do_bulk_deredirect($selected_links);
                break;

            case 'bulk-recheck':
                list($message, $msg_class) = $this->do_bulk_recheck($selected_links);
                break;

            case 'bulk-not-broken':
                list($message, $msg_class) = $this->do_bulk_discard($selected_links);
                break;

            case 'bulk-dismiss':
                list($message, $msg_class) = $this->do_bulk_dismiss($selected_links);
                break;

            case 'bulk-edit':
                list($message, $msg_class) = $this->do_bulk_edit($selected_links);
                break;
        }

        if (! empty($message)) {
            echo '<div id="message" class="' . $msg_class . ' fade"><p>' . $message . '</p></div>';
        }

        $start_time = microtime(true);

        // Load custom filters, if any
        $blc_link_query->load_custom_filters();

        // Calculate the number of links matching each filter
        $blc_link_query->count_filter_results();

        // Run the selected filter (defaults to displaying broken links)
        $selected_filter_id = $_GET['filter_id'] ?? 'broken';
        $current_filter     = $blc_link_query->exec_filter(
            $selected_filter_id,
            intval($_GET['paged'] ?? 1),
            $this->plugin_config->options['table_links_per_page'],
            'broken',
            $_GET['orderby'] ?? '',
            $_GET['order'] ?? ''
        );

        // exec_filter() returns an array with filter data, including the actual filter ID that was used.
        $filter_id = $current_filter['filter_id'];

        // Error?
        if (empty($current_filter['links']) && ! empty($wpdb->last_error)) {
            printf(__('Database error : %s', 'broken-link-checker'), $wpdb->last_error);
        }
        ?>

        <script type='text/javascript'>
            var blc_current_filter = '<?php echo $filter_id; ?>';
            var blc_is_broken_filter = <?php echo $current_filter['is_broken_filter'] ? 'true' : 'false'; ?>;
            var blc_current_base_filter = '<?php echo esc_js($current_filter['base_filter']); ?>';
        </script>

        <div class="wrap">
            <?php

            $this->local_header();
            $blc_link_query->print_filter_heading($current_filter);
            $this->local_nav();
            $blc_link_query->print_filter_menu($filter_id);

            // Display the "Search" form and associated buttons.
            // The form requires the $filter_id and $current_filter variables to be set.
            require_once dirname($this->loader) . '/includes/admin/search-form.php';

            // If the user has decided to switch the table to a different mode (compact/full),
            // save the new setting.
            if (isset($_GET['compact'])) {
                $this->plugin_config->options['table_compact'] = (bool) $_GET['compact'];
                $this->plugin_config->save_options();
            }

            // Display the links, if any
            if ($current_filter['links'] && ( count($current_filter['links']) > 0 )) {
                require_once dirname($this->loader) . '/includes/admin/table-printer.php';
                $table = new \blcTablePrinter($this);
                $table->print_table(
                    $current_filter,
                    $this->plugin_config->options['table_layout'],
                    $this->plugin_config->options['table_visible_columns'],
                    $this->plugin_config->options['table_compact']
                );
            }
            printf('<!-- Total elapsed : %.4f seconds -->', microtime(true) - $start_time);

            // Load assorted JS event handlers and other shinies
            require_once dirname($this->loader) . '/includes/admin/links-page-js.php';

            ?>
        </div>
        <?php
    }

    /**
     * Create a custom link filter using params passed in $_POST.
     *
     * @return array Message and the CSS class to apply to the message.
     * @uses $_GET to replace the current filter ID (if any) with that of the newly created filter.
     *
     * @uses $_POST
     */
    function do_create_custom_filter()
    {
        global $wpdb;

        // Create a custom filter!
        check_admin_referer('create-custom-filter');
        $msg_class = 'updated';

        // Filter name must be set
        if (empty($_POST['name'])) {
            $message   = __('You must enter a filter name!', 'broken-link-checker');
            $msg_class = 'error';
            // Filter parameters (a search query) must also be set
        } elseif (empty($_POST['params'])) {
            $message   = __('Invalid search query.', 'broken-link-checker');
            $msg_class = 'error';
        } else {
            // Save the new filter
            $name           = strip_tags(strval($_POST['name']));
            $blc_link_query = \blcLinkQuery::getInstance();
            $filter_id      = $blc_link_query->create_custom_filter($name, $_POST['params']);

            if ($filter_id) {
                // Saved
                $message = sprintf(__('Filter "%s" created', 'broken-link-checker'), $name);
                // A little hack to make the filter active immediately
                $_GET['filter_id'] = $filter_id;
            } else {
                // Error
                $message   = sprintf(__('Database error : %s', 'broken-link-checker'), $wpdb->last_error);
                $msg_class = 'error';
            }
        }

        return array( $message, $msg_class );
    }

    /**
     * Delete a custom link filter.
     *
     * @return array Message and a CSS class to apply to the message.
     * @uses $_POST
     */
    function do_delete_custom_filter()
    {
        // Delete an existing custom filter!
        check_admin_referer('delete-custom-filter');
        $msg_class = 'updated';

        // Filter ID must be set
        if (empty($_POST['filter_id'])) {
            $message   = __('Filter ID not specified.', 'broken-link-checker');
            $msg_class = 'error';
        } else {
            // Try to delete the filter
            $blc_link_query = \blcLinkQuery::getInstance();
            if ($blc_link_query->delete_custom_filter($_POST['filter_id'])) {
                // Success
                $message = __('Filter deleted', 'broken-link-checker');
            } else {
                // Either the ID is wrong or there was some other error
                $message   = __('Database error : %s', 'broken-link-checker');
                $msg_class = 'error';
            }
        }

        return array( $message, $msg_class );
    }

    /**
     * Modify multiple links to point to their target URLs.
     *
     * @param array $selected_links
     *
     * @return array The message to display and its CSS class.
     */
    function do_bulk_deredirect($selected_links)
    {
        // For all selected links, replace the URL with the final URL that it redirects to.

        $message   = '';
        $msg_class = 'updated';

        check_admin_referer('bulk-action');

        if (count($selected_links) > 0) {
            // Fetch all the selected links
            $links = blc_get_links(
                array(
                    'link_ids' => $selected_links,
                    'purpose'  => BLC_FOR_EDITING,
                )
            );

            if (count($links) > 0) {
                $processed_links = 0;
                $failed_links    = 0;

                // Deredirect all selected links
                foreach ($links as $link) {
                    $rez = $link->deredirect();
                    if (! is_wp_error($rez) && empty($rez['errors'])) {
                        ++$processed_links;
                    } else {
                        ++$failed_links;
                    }
                }

                $message = sprintf(
                    _n(
                        'Replaced %d redirect with a direct link',
                        'Replaced %d redirects with direct links',
                        $processed_links,
                        'broken-link-checker'
                    ),
                    $processed_links
                );

                if ($failed_links > 0) {
                    $message  .= '<br>' . sprintf(
                        _n(
                            'Failed to fix %d redirect',
                            'Failed to fix %d redirects',
                            $failed_links,
                            'broken-link-checker'
                        ),
                        $failed_links
                    );
                    $msg_class = 'error';
                }
            } else {
                $message = __('None of the selected links are redirects!', 'broken-link-checker');
            }
        }

        return array( $message, $msg_class );
    }

    /**
     * Edit multiple links in one go.
     *
     * @param array $selected_links
     *
     * @return array The message to display and its CSS class.
     */
    function do_bulk_edit($selected_links)
    {
        $message   = '';
        $msg_class = 'updated';

        check_admin_referer('bulk-action');

        $post = $_POST;
        if (function_exists('wp_magic_quotes')) {
            $post = stripslashes_deep($post); // Ceterum censeo, WP shouldn't mangle superglobals.
        }

        $search         = isset($post['search']) ? esc_attr($post['search']) : '';
        $replace        = isset($post['replace']) ? esc_attr($post['replace']) : '';
        $use_regex      = ! empty($post['regex']);
        $case_sensitive = ! empty($post['case_sensitive']);

        $delimiter = '`'; // Pick a char that's uncommon in URLs so that escaping won't usually be a problem
        if ($use_regex) {
            $search = $delimiter . $this->escape_regex_delimiter($search, $delimiter) . $delimiter;
            if (! $case_sensitive) {
                $search .= 'i';
            }
        } elseif (! $case_sensitive) {
            // str_ireplace() would be more appropriate for case-insensitive, non-regexp replacement,
            // but that's only available in PHP5.
            $search    = $delimiter . preg_quote($search, $delimiter) . $delimiter . 'i';
            $use_regex = true;
        }

        if (count($selected_links) > 0) {
            // In case the user decides to edit hundreds of links at once
            if ($this->is_host_wp_engine() || $this->is_host_flywheel()) {
                set_time_limit(60);
            } else {
                set_time_limit(300);
            }

            // Fetch all the selected links
            $links = blc_get_links(
                array(
                    'link_ids' => $selected_links,
                    'purpose'  => BLC_FOR_EDITING,
                )
            );

            if (count($links) > 0) {
                $processed_links = 0;
                $failed_links    = 0;
                $skipped_links   = 0;

                // Edit the links
                foreach ($links as $link) {
                    if ($use_regex) {
                        $new_url = preg_replace($search, $replace, $link->url);
                    } else {
                        $new_url = str_replace($search, $replace, $link->url);
                    }

                    if ($new_url == $link->url) {
                        ++$skipped_links;
                        continue;
                    }

                    $rez = $link->edit($new_url);
                    if (! is_wp_error($rez) && empty($rez['errors'])) {
                        ++$processed_links;
                    } else {
                        ++$failed_links;
                    }
                }

                $message .= sprintf(
                    _n(
                        '%d link updated.',
                        '%d links updated.',
                        $processed_links,
                        'broken-link-checker'
                    ),
                    $processed_links
                );

                if ($failed_links > 0) {
                    $message  .= '<br>' . sprintf(
                        _n(
                            'Failed to update %d link.',
                            'Failed to update %d links.',
                            $failed_links,
                            'broken-link-checker'
                        ),
                        $failed_links
                    );
                    $msg_class = 'error';
                }
            }
        }

        return array( $message, $msg_class );
    }

    /**
     * Escape all instances of the $delimiter character with a backslash (unless already escaped).
     *
     * @param string $pattern
     * @param string $delimiter
     *
     * @return string
     */
    private function escape_regex_delimiter($pattern, $delimiter)
    {
        if (empty($pattern)) {
            return '';
        }

        $output  = '';
        $length  = strlen($pattern);
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[ $i ];

            if ($escaped) {
                $escaped = false;
            } elseif ('\\' == $char) {
                    $escaped = true;
            } elseif ($char == $delimiter) {
                $char = '\\' . $char;
            }

            $output .= $char;
        }

        return $output;
    }

    /**
     * Unlink multiple links.
     *
     * @param array $selected_links
     *
     * @return array Message and a CSS classname.
     */
    function do_bulk_unlink($selected_links)
    {
        // Unlink all selected links.
        $message   = '';
        $msg_class = 'updated';

        check_admin_referer('bulk-action');

        if (count($selected_links) > 0) {
            // Fetch all the selected links
            $links = blc_get_links(
                array(
                    'link_ids' => $selected_links,
                    'purpose'  => BLC_FOR_EDITING,
                )
            );

            if (count($links) > 0) {
                $processed_links = 0;
                $failed_links    = 0;

                // Unlink (delete) each one
                foreach ($links as $link) {
                    $rez = $link->unlink();
                    if (( false == $rez ) || is_wp_error($rez)) {
                        ++$failed_links;
                    } else {
                        ++$processed_links;
                    }
                }

                // This message is slightly misleading - it doesn't account for the fact that
                // a link can be present in more than one post.
                $message = sprintf(
                    _n(
                        '%d link removed',
                        '%d links removed',
                        $processed_links,
                        'broken-link-checker'
                    ),
                    $processed_links
                );

                if ($failed_links > 0) {
                    $message  .= '<br>' . sprintf(
                        _n(
                            'Failed to remove %d link',
                            'Failed to remove %d links',
                            $failed_links,
                            'broken-link-checker'
                        ),
                        $failed_links
                    );
                    $msg_class = 'error';
                }
            }
        }

        return array( $message, $msg_class );
    }



    /**
     * Mark multiple links as unchecked.
     *
     * @param array $selected_links An array of link IDs
     *
     * @return array Confirmation nessage and the CSS class to use with that message.
     */
    function do_bulk_recheck($selected_links)
    {
        /** @var wpdb $wpdb */
        global $wpdb;

        $message     = '';
        $msg_class   = 'updated';
        $total_links = count($selected_links);
        check_admin_referer('bulk-action');

        if ($total_links > 0) {
            $placeholders = array_fill(0, $total_links, '%d');
            $format       = implode(', ', $placeholders);
            $query        = "UPDATE {$wpdb->prefix}blc_links
				SET last_check_attempt = '0000-00-00 00:00:00'
				WHERE link_id IN ( $format )";

            $changes = $wpdb->query(
                $wpdb->prepare(
                    $query, //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $selected_links
                )
            );

            $message = sprintf(
                _n(
                    '%d link scheduled for rechecking',
                    '%d links scheduled for rechecking',
                    $changes,
                    'broken-link-checker'
                ),
                $changes
            );
        }

        return array( $message, $msg_class );
    }


    /**
     * Mark multiple links as not broken.
     *
     * @param array $selected_links An array of link IDs
     *
     * @return array Confirmation nessage and the CSS class to use with that message.
     */
    function do_bulk_discard($selected_links)
    {
        check_admin_referer('bulk-action');

        $messages        = array();
        $msg_class       = 'updated';
        $processed_links = 0;

        if (count($selected_links) > 0) {
            $transactionManager = TransactionManager::getInstance();
            $transactionManager->start();
            foreach ($selected_links as $link_id) {
                // Load the link
                $link = new \blcLink(intval($link_id));

                // Skip links that don't actually exist
                if (! $link->valid()) {
                    continue;
                }

                // Skip links that weren't actually detected as broken
                if (! $link->broken && ! $link->warning) {
                    continue;
                }

                // Make it appear "not broken"
                $link->broken             = false;
                $link->warning            = false;
                $link->false_positive     = true;
                $link->last_check_attempt = time();
                $link->log                = __('This link was manually marked as working by the user.', 'broken-link-checker');

                $link->isOptionLinkChanged = true;
                // Save the changes
                if ($link->save()) {
                    ++$processed_links;
                } else {
                    $messages[] = sprintf(
                        __("Couldn't modify link %d", 'broken-link-checker'),
                        $link_id
                    );
                    $msg_class  = 'error';
                }
            }
        }

        if ($processed_links > 0) {
            $transactionManager->commit();
            $messages[] = sprintf(
                _n(
                    '%d link marked as not broken',
                    '%d links marked as not broken',
                    $processed_links,
                    'broken-link-checker'
                ),
                $processed_links
            );
        } else {
            $messages[] = __('No links marked as not broken');
        }

        return array( implode('<br>', $messages), $msg_class );
    }

    /**
     * Dismiss multiple links.
     *
     * @param array $selected_links An array of link IDs
     *
     * @return array Confirmation message and the CSS class to use with that message.
     */
    function do_bulk_dismiss($selected_links)
    {
        check_admin_referer('bulk-action');

        $messages        = array();
        $msg_class       = 'updated';
        $processed_links = 0;

        if (count($selected_links) > 0) {
            $transactionManager = TransactionManager::getInstance();
            $transactionManager->start();
            foreach ($selected_links as $link_id) {
                // Load the link
                $link = new \blcLink(intval($link_id));

                // Skip links that don't actually exist
                if (! $link->valid()) {
                    continue;
                }

                // We can only dismiss broken links and redirects.
                if (! ( $link->broken || $link->warning || ( $link->redirect_count > 0 ) )) {
                    continue;
                }

                $link->dismissed = true;

                $link->isOptionLinkChanged = true;

                // Save the changes
                if ($link->save()) {
                    ++$processed_links;
                } else {
                    $messages[] = sprintf(
                        __("Couldn't modify link %d", 'broken-link-checker'),
                        $link_id
                    );
                    $msg_class  = 'error';
                }
            }
        }

        if ($processed_links > 0) {
            $transactionManager->commit();
            $messages[] = sprintf(
                _n(
                    '%d link dismissed',
                    '%d links dismissed',
                    $processed_links,
                    'broken-link-checker'
                ),
                $processed_links
            );
        }

        return array( implode('<br>', $messages), $msg_class );
    }


    /**
     * Enqueue CSS files for the "Broken Links" page
     *
     * @return void
     */
    function links_page_css()
    {
        wp_enqueue_style('blc-links-page', plugins_url('css/links-page.css', $this->loader), array(), WPMUDEV_BLC_SCIPTS_VERSION);
        wp_enqueue_style('blc_local_style', plugins_url('css/style-local-nav.css', $this->loader), array(), WPMUDEV_BLC_SCIPTS_VERSION);
    }

    /**
     * Show an admin notice that explains what the "Warnings" section under "Tools -> Broken Links" does.
     * The user can hide the notice.
     */
    public function show_warnings_section_notice()
    {
        $is_warnings_section = isset($_GET['filter_id'])
            && ( 'warnings' === $_GET['filter_id'] )
            && isset($_GET['page'])
            && ( 'blc_local' === $_GET['page'] );

        if (! ( $is_warnings_section && current_user_can('edit_others_posts') )) {
            return;
        }

        // Let the user hide the notice.
      
        $notice_name = 'show_warnings_section_hint';

        if (isset($_GET[ $notice_name ]) && is_numeric($_GET[ $notice_name ])) {
            $this->plugin_config->set($notice_name, (bool) $_GET[ $notice_name ]);
            $this->plugin_config->save_options();
        }
        if (! $this->plugin_config->get($notice_name, true)) {
            return;
        }

        printf(
            '<div class="updated">
						<p>%1$s</p>
						<p>
							<a href="%2$s">%3$s</a> |
							<a href="%4$s">%5$s</a>
						<p>
					</div>',
            __(
                'The "Warnings" page lists problems that are probably temporary or suspected to be false positives.<br> Warnings that persist for a long time will usually be reclassified as broken links.',
                'broken-link-checker'
            ),
            esc_attr(add_query_arg($notice_name, '0')),
            _x(
                'Hide notice',
                'admin notice under Tools - Broken links - Warnings',
                'broken-link-checker'
            ),
            esc_attr(admin_url('admin.php?page=link-checker-settings#blc_warning_settings')),
            _x(
                'Change warning settings',
                'a link from the admin notice under Tools - Broken links - Warnings',
                'broken-link-checker'
            )
        );
    }

    /**
     * Generate the HTML for the plugin's Screen Options panel.
     *
     * @return string
     */
    function screen_options_html()
    {
        // Update the links-per-page setting when "Apply" is clicked
        if (isset($_POST['per_page']) && is_numeric($_POST['per_page'])) {
            check_admin_referer('screen-options-nonce', 'screenoptionnonce');
            $per_page = intval($_POST['per_page']);
            if (( $per_page >= 1 ) && ( $per_page <= 500 )) {
                $this->plugin_config->options['table_links_per_page'] = $per_page;
                $this->plugin_config->save_options();
            }
        }

        // Let the user show/hide individual table columns
        $html = '<h5>' . __('Table columns', 'broken-link-checker') . '</h5>';

        require_once dirname($this->loader) . '/includes/admin/table-printer.php';
        $table             = new \blcTablePrinter($this);
        $available_columns = $table->get_layout_columns($this->plugin_config->options['table_layout']);

        $html .= '<div id="blc-column-selector" class="metabox-prefs">';

        foreach ($available_columns as $column_id => $data) {
            $html .= sprintf(
                '<label><input type="checkbox" name="visible_columns[%s]"%s>%s</label>',
                esc_attr($column_id),
                in_array($column_id, $this->plugin_config->options['table_visible_columns']) ? ' checked="checked"' : '',
                $data['heading']
            );
        }

        $html .= '</div>';

        $html .= '<h5>' . __('Show on screen', 'broken-link-checker') . '</h5>';
        $html .= '<div class="screen-options">';
        $html .= sprintf(
            '<input type="text" name="per_page" maxlength="3" value="%d" class="screen-per-page" id="blc_links_per_page" />
				<label for="blc_links_per_page">%s</label>
				<input type="button" class="button" value="%s" id="blc-per-page-apply-button" /><br />',
            $this->plugin_config->options['table_links_per_page'],
            __('links', 'broken-link-checker'),
            __('Apply')
        );
        $html .= '</div>';

        $html .= '<h5>' . __('Misc', 'broken-link-checker') . '</h5>';
        $html .= '<div class="screen-options">';
        /*
            Display a checkbox in "Screen Options" that lets the user highlight links that
            have been broken for at least X days.
            */
        $html     .= sprintf(
            '<label><input type="checkbox" id="highlight_permanent_failures" name="highlight_permanent_failures"%s> ',
            $this->plugin_config->options['highlight_permanent_failures'] ? ' checked="checked"' : ''
        );
        $input_box = sprintf(
            '</label><input type="text" name="failure_duration_threshold" id="failure_duration_threshold" value="%d" size="2"><label for="highlight_permanent_failures">',
            $this->plugin_config->options['failure_duration_threshold']
        );
        $html     .= sprintf(
            __('Highlight links broken for at least %s days', 'broken-link-checker'),
            $input_box
        );
        $html     .= '</label>';

        // Display a checkbox for turning colourful link status messages on/off
        $html .= sprintf(
            '<br/><label><input type="checkbox" id="table_color_code_status" name="table_color_code_status"%s> %s</label>',
            $this->plugin_config->options['table_color_code_status'] ? ' checked="checked"' : '',
            __('Color-code status codes', 'broken-link-checker')
        );

        $html .= '</div>';

        return $html;
    }

    /**
     * AJAX callback for saving the "Screen Options" panel settings
     *
     * @param array $form
     *
     * @return void
     */
    function ajax_save_screen_options($form)
    {
        if (! current_user_can('edit_others_posts')) {
            die(
                json_encode(
                    array(
                        'error' => __("You're not allowed to do that!", 'broken-link-checker'),
                    )
                )
            );
        }

        $this->plugin_config->options['highlight_permanent_failures'] = ! empty($form['highlight_permanent_failures']);
        $this->plugin_config->options['table_color_code_status']      = ! empty($form['table_color_code_status']);

        $failure_duration_threshold = intval($form['failure_duration_threshold']);
        if ($failure_duration_threshold >= 1) {
            $this->plugin_config->options['failure_duration_threshold'] = $failure_duration_threshold;
        }

        if (isset($form['visible_columns']) && is_array($form['visible_columns'])) {
            $this->plugin_config->options['table_visible_columns'] = array_keys($form['visible_columns']);
        }

        $this->plugin_config->save_options();
        die('1');
    }

    function start_timer()
    {
        $this->execution_start_time = microtime(true);
    }

    function execution_time()
    {
        return microtime(true) - $this->execution_start_time;
    }

    /**
     * The main worker function that does all kinds of things.
     *
     * @return void
     */
    function work()
    {
        global $blclog;

        // Close the session to prevent lock-ups.
        // PHP sessions are blocking. session_start() will wait until all other scripts that are using the same session
        // are finished. As a result, a long-running script that unintentionally keeps the session open can cause
        // the entire site to "lock up" for the current user/browser. WordPress itself doesn't use sessions, but some
        // plugins do, so we should explicitly close the session (if any) before starting the worker.
        if (session_id() != '') {
            session_write_close();
        }

        if (! $this->acquire_lock()) {
            // FB::warn("Another instance of BLC is already working. Stop.");
            $blclog->info('Another instance of BLC is already working. Stop.');
            return;
        }

        if ($this->server_too_busy()) {
            // FB::warn("Server is too busy. Stop.");
            $blclog->warn('Server load is too high, stopping.');

            return;
        }

        $this->start_timer();
        $blclog->info('work() starts');

        // $max_execution_time = $this->plugin_config->options['max_execution_time'];
        $max_execution_time = $this->max_execution_time_option();

        /*****************************************
         * Preparation
         */

        if ($this->is_host_wp_engine() || $this->is_host_flywheel()) {
            @set_time_limit($max_execution_time);
        } else {
            // Do it the regular way
            @set_time_limit($max_execution_time * 2); // x2 should be plenty, running any longer would mean a glitch.
        }

        // Don't stop the script when the connection is closed
        ignore_user_abort(true);

        // Close the connection as per http://www.php.net/manual/en/features.connection-handling.php#71172
        // This reduces resource usage.
        // (Disable when debugging or you won't get the FirePHP output)
        if (
            false &&
            ! headers_sent() &&
            ( defined('DOING_AJAX') &&
            constant('DOING_AJAX') ) &&
            ( ! defined('BLC_DEBUG') ||
            ! constant('BLC_DEBUG') )
        ) {
            @ob_end_clean(); // Discard the existing buffer, if any
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
            header('Connection: close');
            ob_start();
            echo ( 'Connection closed' ); // This could be anything
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush(); // Strange behaviour, will not work
            flush();        // Unless both are called !

            session_write_close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
        }
        // Load modules for this context
        $moduleManager = \blcModuleManager::getInstance();
        $moduleManager->load_modules('work');

        $target_usage_fraction = $this->plugin_config->get('target_resource_usage', 0.25);
        // Target usage must be between 1% and 100%.
        $target_usage_fraction = max(min($target_usage_fraction, 1), 0.01);

        /*****************************************
         * Parse posts and bookmarks
         */

        $orphans_possible   = false;
        $still_need_resynch = $this->plugin_config->options['need_resynch'];
        if ($still_need_resynch) {
            // FB::log("Looking for containers that need parsing...");
            $max_containers_per_query = 50;

            $start               = microtime(true);
            $containers          = \blcContainerHelper::get_unsynched_containers($max_containers_per_query);
            $get_containers_time = microtime(true) - $start;

            while (! empty($containers)) {
                // FB::log($containers, 'Found containers');
                $this->sleep_to_maintain_ratio($get_containers_time, $target_usage_fraction);

                foreach ($containers as $container) {
                    $synch_start_time = microtime(true);

                    // FB::log($container, "Parsing container");
                    $container->synch();

                    $synch_elapsed_time = microtime(true) - $synch_start_time;
                    $blclog->info(
                        sprintf(
                            'Parsed container %s[%s] in %.2f ms',
                            $container->container_type,
                            $container->container_id,
                            $synch_elapsed_time * 1000
                        )
                    );

                    // Check if we still have some execution time left
                    if ($this->execution_time() > $max_execution_time) {
                        // FB::log('The allotted execution time has run out');
                        blc_cleanup_links();
                        $this->release_lock();

                        return;
                    }

                    // Check if the server isn't overloaded
                    if ($this->server_too_busy()) {
                        // FB::log('Server overloaded, bailing out.');
                        blc_cleanup_links();
                        $this->release_lock();

                        return;
                    }

                    // Intentionally slow down parsing to reduce the load on the server. Basically,
                    // we work $target_usage_fraction of the time and sleep the rest of the time.
                    $this->sleep_to_maintain_ratio($synch_elapsed_time, $target_usage_fraction);
                }
                $orphans_possible = true;

                $start               = microtime(true);
                $containers          = \blcContainerHelper::get_unsynched_containers($max_containers_per_query);
                $get_containers_time = microtime(true) - $start;
            }

            // FB::log('No unparsed items found.');
            $still_need_resynch = false;
        } else {
            // FB::log('Resynch not required.');
        }

        /******************************************
         * Resynch done?
         */
        if ($this->plugin_config->options['need_resynch'] && ! $still_need_resynch) {
            $this->plugin_config->options['need_resynch'] = $still_need_resynch;
            $this->plugin_config->save_options();
        }

        /******************************************
         * Remove orphaned links
         */

        if ($orphans_possible) {
            $start = microtime(true);

            $blclog->info('Removing orphaned links.');
            blc_cleanup_links();

            $get_links_time = microtime(true) - $start;
            $this->sleep_to_maintain_ratio($get_links_time, $target_usage_fraction);
        }

        // Check if we still have some execution time left
        if ($this->execution_time() > $max_execution_time) {
            // FB::log('The allotted execution time has run out');
            $blclog->info('The allotted execution time has run out.');
            $this->release_lock();

            return;
        }

        if ($this->server_too_busy()) {
            // FB::log('Server overloaded, bailing out.');
            $blclog->info('Server load too high, stopping.');
            $this->release_lock();

            return;
        }

        /*****************************************
         * Check links
         */
        $max_links_per_query = 30;

        $start          = microtime(true);
        $links          = $this->get_links_to_check($max_links_per_query);
        $get_links_time = microtime(true) - $start;

        while ($links) {
            $this->sleep_to_maintain_ratio($get_links_time, $target_usage_fraction);

            // Some unchecked links found
            // FB::log("Checking ".count($links)." link(s)");
            $blclog->info('Checking ' . count($links) . ' link(s)');

            // Randomizing the array reduces the chances that we'll get several links to the same domain in a row.
            shuffle($links);

            $transactionManager = TransactionManager::getInstance();
            $transactionManager->start();

            foreach ($links as $link) {
                // Does this link need to be checked? Excluded links aren't checked, but their URLs are still
                // tested periodically to see if they're still on the exclusion list.
                if (! $this->is_excluded($link->url)) {
                    // Check the link.
                    // FB::log($link->url, "Checking link {$link->link_id}");
                    $link->check(true);
                } else {
                    // FB::info("The URL {$link->url} is excluded, skipping link {$link->link_id}.");
                    $link->last_check_attempt = time();
                    $link->save();
                }

                // Check if we still have some execution time left
                if ($this->execution_time() > $max_execution_time) {
                    $transactionManager->commit();
                    // FB::log('The allotted execution time has run out');
                    $blclog->info('The allotted execution time has run out.');
                    $this->release_lock();

                    return;
                }

                // Check if the server isn't overloaded
                if ($this->server_too_busy()) {
                    $transactionManager->commit();
                    // FB::log('Server overloaded, bailing out.');
                    $blclog->info('Server load too high, stopping.');
                    $this->release_lock();

                    return;
                }
            }
            $transactionManager->commit();

            $start          = microtime(true);
            $links          = $this->get_links_to_check($max_links_per_query);
            $get_links_time = microtime(true) - $start;
        }
        // FB::log('No links need to be checked right now.');
        $this->updateParking();
        $this->release_lock();
        $blclog->info('work(): All done.');
        // FB::log('All done.');
    }

    private function updateParking($reset = false)
    {
        global $wpdb;

        if ($reset) {
            $q = "UPDATE `{$wpdb->prefix}blc_links`SET `parked` = %d";
            $wpdb->query(
                $wpdb->prepare(
                    $q, //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    self::BLC_PARKED_UNCHECKED
                )
            );
        }
        $parked = str_replace('%', '%%', join(' OR ', self::DOMAINPARKINGSQL));
        $q      = "UPDATE `{$wpdb->prefix}blc_links`
            SET `parked` = IF ($parked,%d,%d)
            WHERE  `being_checked` = 0 AND `broken` = 0 AND `warning` = 0 AND  `parked` = %d";

        $wpdb->query(
            $wpdb->prepare(
                $q, //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                array(
                    self::BLC_PARKED_PARKED,
                    self::BLC_PARKED_CHECKED,
                    self::BLC_PARKED_UNCHECKED,
                )
            )
        );
        return $wpdb->rows_affected;
    }

    /**
     * Sleep long enough to maintain the required $ratio between $elapsed_time and total runtime.
     *
     * For example, if $ratio is 0.25 and $elapsed_time is 1 second, this method will sleep for 3 seconds.
     * Total runtime = 1 + 3 = 4, ratio = 1 / 4 = 0.25.
     *
     * @param float $elapsed_time
     * @param float $ratio
     */
    private function sleep_to_maintain_ratio($elapsed_time, $ratio)
    {
        if (( $ratio <= 0 ) || ( $ratio > 1 )) {
            return;
        }
        $sleep_time = $elapsed_time * ( ( 1 / $ratio ) - 1 );
        if ($sleep_time > 0.0001) {
            /*
            global $blclog;
                $blclog->debug(sprintf(
                    'Task took %.2f ms, sleeping for %.2f ms',
                    $elapsed_time * 1000,
                    $sleep_time * 1000
                ));*/
            usleep(intval($sleep_time * 1000000));
        }
    }

    /**
     * This function is called when the plugin's cron hook executes.
     * Its only purpose is to invoke the worker function.
     *
     * @return void
     * @uses wsBrokenLinkChecker::work()
     */
    function cron_check_links()
    {
        $this->work();
    }

    /**
     * Retrieve links that need to be checked or re-checked.
     *
     * @param integer $max_results The maximum number of links to return. Defaults to 0 = no limit.
     * @param bool    $count_only If true, only the number of found links will be returned, not the links themselves.
     *
     * @return int|\blcLink[]
     */
    function get_links_to_check($max_results = 0, $count_only = false)
    {
        global $wpdb;
        /* @var wpdb $wpdb */

        $check_threshold   = date('Y-m-d H:i:s', strtotime('-' . $this->plugin_config->options['check_threshold'] . ' hours')); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $recheck_threshold = date('Y-m-d H:i:s', time() - $this->plugin_config->options['recheck_threshold']); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        // FB::log('Looking for links to check (threshold : '.$check_threshold.', recheck_threshold : '.$recheck_threshold.')...');

        // Select some links that haven't been checked for a long time or
        // that are broken and need to be re-checked again. Links that are
        // marked as "being checked" and have been that way for several minutes
        // can also be considered broken/buggy, so those will be selected
        // as well.

        // Only check links that have at least one valid instance (i.e. an instance exists and
        // it corresponds to one of the currently loaded container/parser types).
        $manager           = \blcModuleManager::getInstance();
        $loaded_containers = $manager->get_escaped_ids('container');
        $loaded_parsers    = $manager->get_escaped_ids('parser');

        // Note : This is a slow query, but AFAIK there is no way to speed it up.
        // I could put an index on last_check_attempt, but that value is almost
        // certainly unique for each row so it wouldn't be much better than a full table scan.
        if ($count_only) {
            $q = "SELECT COUNT(DISTINCT links.link_id)\n";
        } else {
            $q = "SELECT DISTINCT links.*\n";
        }
        $q .= "FROM {$wpdb->prefix}blc_links AS links
				INNER JOIN {$wpdb->prefix}blc_instances AS instances USING (link_id)
				WHERE
					(
						( last_check_attempt < %s )
						OR
						(
							(broken = 1 OR being_checked = 1)
							AND may_recheck = 1
							AND check_count < %d
							AND last_check_attempt < %s
						)
					)

				AND
					( instances.container_type IN ({$loaded_containers}) )
					AND ( instances.parser_type IN ({$loaded_parsers}) )
				";
        if (! $count_only) {
            $q .= "\nORDER BY last_check_attempt ASC\n";
            if (! empty($max_results)) {
                $q .= 'LIMIT ' . intval($max_results);
            }
        }

        $link_q = $wpdb->prepare(
            $q, //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $check_threshold,
            $this->plugin_config->options['recheck_count'],
            $recheck_threshold
        );

        // FB::log($link_q, "Find links to check");
        // $blclog->debug("Find links to check: \n" . $link_q);

        // If we just need the number of links, retrieve it and return
        if ($count_only) {
            return $wpdb->get_var($link_q); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // Fetch the link data
        $link_data = $wpdb->get_results($link_q, ARRAY_A); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (empty($link_data)) {
            return array();
        }

        // Instantiate \blcLink objects for all fetched links
        $links = array();
        foreach ($link_data as $data) {
            $links[] = new \blcLink($data);
        }

        return $links;
    }

    /**
     * Output the current link checker status in JSON format.
     * Ajax hook for the 'blc_full_status' action.
     *
     * @return void
     */
    function ajax_full_status()
    {
        $status = $this->get_status();
        $text   = $this->status_text($status);

        echo json_encode(
            array(
                'text'   => $text,
                'status' => $status,
            )
        );

        die();
    }

    /**
     * Generates a status message based on the status info in $status
     *
     * @param array $status
     *
     * @return string
     */
    function status_text($status)
    {
        $text = '';

        if ($status['broken_links'] > 0) {
            $text .= sprintf(
                "<a href='%s' title='" . __('View broken links', 'broken-link-checker') . "'><strong>" .
                    _n('Found %d broken link', 'Found %d broken links', $status['broken_links'], 'broken-link-checker') .
                    '</strong></a>',
                esc_attr(admin_url('admin.php?page=blc_local')),
                $status['broken_links']
            );
        } else {
            $text .= __('No broken links found.', 'broken-link-checker');
        }

        $text .= '<br/>';

        if ($status['unchecked_links'] > 0) {
            $text .= sprintf(
                _n('%d URL in the work queue', '%d URLs in the work queue', $status['unchecked_links'], 'broken-link-checker'),
                $status['unchecked_links']
            );
        } else {
            $text .= __('No URLs in the work queue.', 'broken-link-checker');
        }

        $text .= '<br/>';
        if ($status['known_links'] > 0) {
            $url_count  = sprintf(
                _nx('%d unique URL', '%d unique URLs', $status['known_links'], 'for the "Detected X unique URLs in Y links" message', 'broken-link-checker'),
                $status['known_links']
            );
            $link_count = sprintf(
                _nx('%d link', '%d links', $status['known_instances'], 'for the "Detected X unique URLs in Y links" message', 'broken-link-checker'),
                $status['known_instances']
            );

            if ($this->plugin_config->options['need_resynch']) {
                $text .= sprintf(
                    __('Detected %1$s in %2$s and still searching...', 'broken-link-checker'),
                    $url_count,
                    $link_count
                );
            } else {
                $text .= sprintf(
                    __('Detected %1$s in %2$s.', 'broken-link-checker'),
                    $url_count,
                    $link_count
                );
            }
        } elseif ($this->plugin_config->options['need_resynch']) {
                $text .= __('Searching your blog for links...', 'broken-link-checker');
        } else {
            $text .= __('No links detected.', 'broken-link-checker');
        }

        return $text;
    }



    /**
     * Output the current average server load (over the last one-minute period).
     * Called via AJAX.
     *
     * @return void
     */
    function ajax_current_load()
    {
        $load = blcUtility::get_server_load();
        if (empty($load)) {
            die(_x('Unknown', 'current load', 'broken-link-checker'));
        }

        $one_minute = reset($load);
        printf('%.2f', $one_minute);
        die();
    }

    /**
     * Returns an array with various status information about the plugin. Array key reference:
     *  check_threshold     - date/time; links checked before this threshold should be checked again.
     *   recheck_threshold   - date/time; broken links checked before this threshold should be re-checked.
     *   known_links         - the number of detected unique URLs (a misleading name, yes).
     *   known_instances     - the number of detected link instances, i.e. actual link elements in posts and other places.
     *   broken_links        - the number of detected broken links.
     *   unchecked_links     - the number of URLs that need to be checked ASAP; based on check_threshold and recheck_threshold.
     *
     * @return array
     */
    function get_status()
    {
        $blc_link_query = \blcLinkQuery::getInstance();

        $check_threshold   = date('Y-m-d H:i:s', strtotime('-' . $this->plugin_config->options['check_threshold'] . ' hours')); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $recheck_threshold = date('Y-m-d H:i:s', time() - $this->plugin_config->options['recheck_threshold']); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $known_links     = blc_get_links(array( 'count_only' => true ));
        $known_instances = blc_get_usable_instance_count();

        $broken_links = $blc_link_query->get_filter_links('broken', array( 'count_only' => true ));

        $unchecked_links = $this->get_links_to_check(0, true);

        return array(
            'check_threshold'   => $check_threshold,
            'recheck_threshold' => $recheck_threshold,
            'known_links'       => $known_links,
            'known_instances'   => $known_instances,
            'broken_links'      => $broken_links,
            'unchecked_links'   => $unchecked_links,
        );
    }

    function ajax_work()
    {

        check_ajax_referer('blc_work');

        // Run the worker function
        $this->work();
        die();
    }

    /**
     * AJAX hook for the "Not broken" button. Marks a link as broken and as a likely false positive.
     *
     * @return void
     */
    function ajax_discard()
    {
        if (! current_user_can('edit_others_posts') || ! check_ajax_referer('blc_discard', false, false)) {
            die(__("You're not allowed to do that!", 'broken-link-checker'));
        }

        if (isset($_POST['link_id'])) {
            // Load the link
            $link = new \blcLink(intval($_POST['link_id']));

            if (! $link->valid()) {
                printf(__("Oops, I can't find the link %d", 'broken-link-checker'), intval($_POST['link_id']));
                die();
            }
            // Make it appear "not broken"
            $link->broken             = false;
            $link->warning            = false;
            $link->false_positive     = true;
            $link->last_check_attempt = time();
            $link->log                = __('This link was manually marked as working by the user.', 'broken-link-checker');

            $link->isOptionLinkChanged = true;

            $transactionManager = TransactionManager::getInstance();
            $transactionManager->start();

            // Save the changes
            if ($link->save()) {
                $transactionManager->commit();
                die('OK');
            } else {
                die(__("Oops, couldn't modify the link!", 'broken-link-checker'));
            }
        } else {
            die(__('Error : link_id not specified', 'broken-link-checker'));
        }
    }

    public function ajax_dismiss()
    {
        $this->ajax_set_link_dismissed(true);
    }

    public function ajax_undismiss()
    {
        $this->ajax_set_link_dismissed(false);
    }

    private function ajax_set_link_dismissed($dismiss)
    {
        $action = $dismiss ? 'blc_dismiss' : 'blc_undismiss';

        if (! current_user_can('edit_others_posts') || ! check_ajax_referer($action, false, false)) {
            die(__("You're not allowed to do that!", 'broken-link-checker'));
        }

        if (isset($_POST['link_id'])) {
            // Load the link
            $link = new \blcLink(intval($_POST['link_id']));

            if (! $link->valid()) {
                printf(__("Oops, I can't find the link %d", 'broken-link-checker'), intval($_POST['link_id']));
                die();
            }

            $link->dismissed = $dismiss;

            // Save the changes
            $link->isOptionLinkChanged = true;
            $transactionManager        = TransactionManager::getInstance();
            $transactionManager->start();
            if ($link->save()) {
                $transactionManager->commit();
                die('OK');
            } else {
                die(__("Oops, couldn't modify the link!", 'broken-link-checker'));
            }
        } else {
            die(__('Error : link_id not specified', 'broken-link-checker'));
        }
    }

    /**
     * AJAX hook for the inline link editor on Tools -> Broken Links.
     *
     * @return void
     */
    function ajax_edit()
    {
        if (! current_user_can('edit_others_posts') || ! check_ajax_referer('blc_edit', false, false)) {
            die(
                json_encode(
                    array(
                        'error' => __("You're not allowed to do that!", 'broken-link-checker'),
                    )
                )
            );
        }

        if (empty($_POST['link_id']) || empty($_POST['new_url']) || ! is_numeric($_POST['link_id'])) {
            die(
                json_encode(
                    array(
                        'error' => __('Error : link_id or new_url not specified', 'broken-link-checker'),
                    )
                )
            );
        }

        // Load the link
        $link = new \blcLink(intval($_POST['link_id']));

        if (! $link->valid()) {
            die(
                json_encode(
                    array(
                        'error' => sprintf(__("Oops, I can't find the link %d", 'broken-link-checker'), intval($_POST['link_id'])),
                    )
                )
            );
        }

        // Validate the new URL.
        $new_url = stripslashes($_POST['new_url']);
        $parsed  = @parse_url($new_url);
        if (! $parsed) {
            die(
                json_encode(
                    array(
                        'error' => __('Oops, the new URL is invalid!', 'broken-link-checker'),
                    )
                )
            );
        }

        if (! current_user_can('unfiltered_html')) {
            // Disallow potentially dangerous URLs like "javascript:...".
            $protocols         = wp_allowed_protocols();
            $good_protocol_url = wp_kses_bad_protocol($new_url, $protocols);
            if ($new_url != $good_protocol_url) {
                die(
                    json_encode(
                        array(
                            'error' => __('Oops, the new URL is invalid!', 'broken-link-checker'),
                        )
                    )
                );
            }
        }

        $new_text = ( isset($_POST['new_text']) && is_string($_POST['new_text']) ) ? stripslashes($_POST['new_text']) : null;
        if ('' === $new_text) {
            $new_text = null;
        }
        if (! empty($new_text) && ! current_user_can('unfiltered_html')) {
            $new_text = stripslashes(wp_filter_post_kses(addslashes($new_text))); // wp_filter_post_kses expects slashed data.
        }

        $rez = $link->edit($new_url, $new_text);
        if (false === $rez) {
            die(
                json_encode(
                    array(
                        'error' => __('An unexpected error occurred!', 'broken-link-checker'),
                    )
                )
            );
        } else {
            $new_link = $rez['new_link'];
            /** @var \blcLink $new_link */
            $new_status   = $new_link->analyse_status();
            $ui_link_text = null;
            if (isset($new_text)) {
                $instances = $new_link->get_instances();
                if (! empty($instances)) {
                    $first_instance = reset($instances);
                    $ui_link_text   = $first_instance->ui_get_link_text();
                }
            }

            $response = array(
                'new_link_id'    => $rez['new_link_id'],
                'cnt_okay'       => $rez['cnt_okay'],
                'cnt_error'      => $rez['cnt_error'],

                'status_text'    => $new_status['text'],
                'status_code'    => $new_status['code'],
                'http_code'      => empty($new_link->http_code) ? '' : $new_link->http_code,
                'redirect_count' => $new_link->redirect_count,

                'url'            => $new_link->url,
                'escaped_url'    => esc_url_raw($new_link->url),
                'final_url'      => $new_link->final_url,
                'link_text'      => isset($new_text) ? $new_text : null,
                'ui_link_text'   => isset($new_text) ? $ui_link_text : null,

                'errors'         => array(),
            );
            // url, status text, status code, link text, editable link text

            foreach ($rez['errors'] as $error) {
                /** @var $error WP_Error */
                array_push($response['errors'], implode(', ', $error->get_error_messages()));
            }
            die(json_encode($response));
        }
    }

    /**
     * AJAX hook for the "Unlink" action links in Tools -> Broken Links.
     * Removes the specified link from all posts and other supported items.
     *
     * @return void
     */
    function ajax_unlink()
    {
        if (! current_user_can('edit_others_posts') || ! check_ajax_referer('blc_unlink', false, false)) {
            die(
                json_encode(
                    array(
                        'error' => __("You're not allowed to do that!", 'broken-link-checker'),
                    )
                )
            );
        }

        if (isset($_POST['link_id'])) {
            // Load the link
            $link = new \blcLink(intval($_POST['link_id']));

            if (! $link->valid()) {
                die(
                    json_encode(
                        array(
                            'error' => sprintf(__("Oops, I can't find the link %d", 'broken-link-checker'), intval($_POST['link_id'])),
                        )
                    )
                );
            }

            // Try and unlink it
            $rez = $link->unlink();

            if (false === $rez) {
                die(
                    json_encode(
                        array(
                            'error' => __('An unexpected error occured!', 'broken-link-checker'),
                        )
                    )
                );
            } else {
                $response = array(
                    'cnt_okay'  => $rez['cnt_okay'],
                    'cnt_error' => $rez['cnt_error'],
                    'errors'    => array(),
                );
                foreach ($rez['errors'] as $error) {
                    /** @var WP_Error $error */
                    array_push($response['errors'], implode(', ', $error->get_error_messages()));
                }

                die(json_encode($response));
            }
        } else {
            die(
                json_encode(
                    array(
                        'error' => __('Error : link_id not specified', 'broken-link-checker'),
                    )
                )
            );
        }
    }

    public function ajax_deredirect()
    {
        if (! current_user_can('edit_others_posts') || ! check_ajax_referer('blc_deredirect', false, false)) {
            die(
                json_encode(
                    array(
                        'error' => __("You're not allowed to do that!", 'broken-link-checker'),
                    )
                )
            );
        }

        if (! isset($_POST['link_id']) || ! is_numeric($_POST['link_id'])) {
            die(
                json_encode(
                    array(
                        'error' => __('Error : link_id not specified', 'broken-link-checker'),
                    )
                )
            );
        }

        $id   = intval($_POST['link_id']);
        $link = new \blcLink($id);

        if (! $link->valid()) {
            die(
                json_encode(
                    array(
                        'error' => sprintf(__("Oops, I can't find the link %d", 'broken-link-checker'), $id),
                    )
                )
            );
        }

        // The actual task is simple; it's error handling that's complicated.
        $result = $link->deredirect();
        if (is_wp_error($result)) {
            die(
                json_encode(
                    array(
                        'error' => sprintf('%s [%s]', $result->get_error_message(), $result->get_error_code()),
                    )
                )
            );
        }

        $link = $result['new_link'];
        /** @var \blcLink $link */

        $status   = $link->analyse_status();
        $response = array(
            'url'            => $link->url,
            'escaped_url'    => esc_url_raw($link->url),
            'new_link_id'    => $result['new_link_id'],

            'status_text'    => $status['text'],
            'status_code'    => $status['code'],
            'http_code'      => empty($link->http_code) ? '' : $link->http_code,
            'redirect_count' => $link->redirect_count,
            'final_url'      => $link->final_url,

            'cnt_okay'       => $result['cnt_okay'],
            'cnt_error'      => $result['cnt_error'],
            'errors'         => array(),
        );

        // Convert WP_Error's to simple strings.
        if (! empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                /** @var WP_Error $error */
                $response['errors'][] = $error->get_error_message();
            }
        }

        die(json_encode($response));
    }

    /**
     * AJAX hook for the "Recheck" action.
     */
    public function ajax_recheck()
    {
        if (! current_user_can('edit_others_posts') || ! check_ajax_referer('blc_recheck', false, false)) {
            die(
                json_encode(
                    array(
                        'error' => __("You're not allowed to do that!", 'broken-link-checker'),
                    )
                )
            );
        }

        if (! isset($_POST['link_id']) || ! is_numeric($_POST['link_id'])) {
            die(
                json_encode(
                    array(
                        'error' => __('Error : link_id not specified', 'broken-link-checker'),
                    )
                )
            );
        }

        $id   = intval($_POST['link_id']);
        $link = new \blcLink($id);

        if (! $link->valid()) {
            die(
                json_encode(
                    array(
                        'error' => sprintf(__("Oops, I can't find the link %d", 'broken-link-checker'), $id),
                    )
                )
            );
        }

        $transactionManager = TransactionManager::getInstance();
        $transactionManager->start();

        // In case the immediate check fails, this will ensure the link is checked during the next work() run.
        $link->last_check_attempt  = 0;
        $link->isOptionLinkChanged = true;
        $link->save();

        // Check the link and save the results.
        $link->check(true);

        $transactionManager->commit();

        $status   = $link->analyse_status();
        $count    = $this->updateParking();
        $response = array(
            'status_text'    => $status['text'],
            'status_code'    => $status['code'],
            'http_code'      => empty($link->http_code) ? '' : $link->http_code,
            'redirect_count' => $link->redirect_count,
            'final_url'      => $link->final_url,
            'parking'        => $count,
        );

        die(json_encode($response));
    }

    function ajax_link_details()
    {
        global $wpdb;
        /* @var wpdb $wpdb */

        if (! current_user_can('edit_others_posts')) {
            die(__("You don't have sufficient privileges to access this information!", 'broken-link-checker'));
        }

        // FB::log("Loading link details via AJAX");

        if (isset($_GET['link_id'])) {
            // FB::info("Link ID found in GET");
            $link_id = intval($_GET['link_id']);
        } elseif (isset($_POST['link_id'])) {
            // FB::info("Link ID found in POST");
            $link_id = intval($_POST['link_id']);
        } else {
            // FB::error('Link ID not specified, you hacking bastard.');
            die(__('Error : link ID not specified', 'broken-link-checker'));
        }

        // Load the link.
        $link = new \blcLink($link_id);

        if (! $link->is_new) {
            // FB::info($link, 'Link loaded');

            require_once dirname($this->loader) . '/includes/admin/table-printer.php';

            \blcTablePrinter::details_row_contents($link);
            die();
        } else {
            printf(__('Failed to load link details (%s)', 'broken-link-checker'), $wpdb->last_error);
            die();
        }
    }

    /**
     * Acquire an exclusive lock.
     * If we already hold a lock, it will be released and a new one will be acquired.
     *
     * @return bool
     */
    function acquire_lock()
    {
        return WPMutex::acquire('blc_lock');
    }

    /**
     * Relese our exclusive lock.
     * Does nothing if the lock has already been released.
     *
     * @return bool
     */
    function release_lock()
    {
        return WPMutex::release('blc_lock');
    }

    /**
     * Check if server is currently too overloaded to run the link checker.
     *
     * @return bool
     */
    function server_too_busy()
    {
        if (! $this->plugin_config->options['enable_load_limit'] || ! isset($this->plugin_config->options['server_load_limit'])) {
            return false;
        }

        $loads = blcUtility::get_server_load();
        if (empty($loads)) {
            return false;
        }
        $one_minute = floatval(reset($loads));

        return $one_minute > $this->plugin_config->options['server_load_limit'];
    }

    /**
     * Register BLC's Dashboard widget
     *
     * @return void
     */
    function hook_wp_dashboard_setup()
    {
        $show_widget = current_user_can($this->plugin_config->get('dashboard_widget_capability', 'edit_others_posts'));
        if (function_exists('wp_add_dashboard_widget') && $show_widget) {
            $title = __('Broken Link Checker', 'broken-link-checker');
            if ($this->plugin_config->options['show_widget_count_bubble']) {
                $title .= ' <span class="blc-broken-count"></span>';
            }

            wp_add_dashboard_widget(
                'blc_dashboard_widget',
                $title,
                array( $this, 'dashboard_widget' ),
                array( $this, 'dashboard_widget_control' )
            );
        }
    }

    /**
     * Collect various debugging information and return it in an associative array
     *
     * @return array
     */
    function get_debug_info()
    {
        /** @var wpdb $wpdb */
        global $wpdb;

        // Collect some information that's useful for debugging
        $debug = array();

        // PHP version. Any one is fine as long as WP supports it.
        $debug[ __('PHP version', 'broken-link-checker') ] = array(
            'state' => 'ok',
            'value' => phpversion(),
        );

        // MySQL version
        $debug[ __('MySQL version', 'broken-link-checker') ] = array(
            'state' => 'ok',
            'value' => $wpdb->db_version(),
        );

        // CURL presence and version
        if (function_exists('curl_version')) {
            $version = curl_version();

            if (version_compare($version['version'], '7.16.0', '<=')) {
                $data = array(
                    'state'   => 'warning',
                    'value'   => $version['version'],
                    'message' => __('You have an old version of CURL. Redirect detection may not work properly.', 'broken-link-checker'),
                );
            } else {
                $data = array(
                    'state' => 'ok',
                    'value' => $version['version'],
                );
            }
        } else {
            $data = array(
                'state' => 'warning',
                'value' => __('Not installed', 'broken-link-checker'),
            );
        }
        $debug[ __('CURL version', 'broken-link-checker') ] = $data;

        // Open_basedir status
        if (blcUtility::is_open_basedir()) {
            $debug['open_basedir'] = array(
                'state'   => 'warning',
                'value'   => sprintf(__('On ( %s )', 'broken-link-checker'), ini_get('open_basedir')),
                'message' => __('Redirects may be detected as broken links when open_basedir is on.', 'broken-link-checker'),
            );
        } else {
            $debug['open_basedir'] = array(
                'state' => 'ok',
                'value' => __('Off', 'broken-link-checker'),
            );
        }

        // Default PHP execution time limit
        $debug['Default PHP execution time limit'] = array(
            'state' => 'ok',
            'value' => sprintf(__('%s seconds'), ini_get('max_execution_time')),
        );

        // Database character set. Usually it's UTF-8. Setting it to something else can cause problems
        // unless the site owner really knows what they're doing.
        $charset = $wpdb->get_charset_collate();
        $debug[ __('Database character set', 'broken-link-checker') ] = array(
            'state' => 'ok',
            'value' => ! empty($charset) ? $charset : '-',
        );

        // Resynch flag.
        $debug['Resynch. flag'] = array(
            'state' => 'ok',
            'value' => sprintf('%d', $this->plugin_config->options['need_resynch'] ? '1 (resynch. required)' : '0 (resynch. not required)'),
        );

        // Synch records
        $synch_records = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}blc_synch"));
        $data          = array(
            'state' => 'ok',
            'value' => sprintf('%d', $synch_records),
        );
        if (0 === $synch_records) {
            $data['state']   = 'warning';
            $data['message'] = __('If this value is zero even after several page reloads you have probably encountered a bug.', 'broken-link-checker');
        }
        $debug['Synch. records'] = $data;

        // Total links and instances (including invalid ones)
        $all_links     = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}blc_links"));
        $all_instances = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}blc_instances"));

        // Show the number of unparsed containers. Useful for debugging. For performance,
        // this is only shown when we have no links/instances yet.
        if (( 0 == $all_links ) && ( 0 == $all_instances )) {
            $unparsed_items          = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}blc_synch WHERE synched=0"));
            $debug['Unparsed items'] = array(
                'state' => 'warning',
                'value' => $unparsed_items,
            );
        }

        // Links & instances
        if (( $all_links > 0 ) && ( $all_instances > 0 )) {
            $debug['Link records'] = array(
                'state' => 'ok',
                'value' => sprintf('%d (%d)', $all_links, $all_instances),
            );
        } else {
            $debug['Link records'] = array(
                'state' => 'warning',
                'value' => sprintf('%d (%d)', $all_links, $all_instances),
            );
        }

        // Email notifications.
        if ($this->plugin_config->options['last_notification_sent']) {
            $notificationDebug = array(
                'value' => date('Y-m-d H:i:s T', $this->plugin_config->options['last_notification_sent']),
				//phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                'state' => 'ok',
            );
        } else {
            $notificationDebug = array(
                'value' => 'Never',
                'state' => $this->plugin_config->options['send_email_notifications'] ? 'ok' : 'warning',
            );
        }
        $debug['Last email notification'] = $notificationDebug;

        if (isset($this->plugin_config->options['last_email'])) {
            $email                    = $this->plugin_config->options['last_email'];
            $debug['Last email sent'] = array(
                'state' => 'ok',
                'value' => sprintf(
                    '"%s" on %s (%s)',
                    htmlentities($email['subject']),
                    date('Y-m-d H:i:s T', $email['timestamp']), //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $email['success'] ? 'success' : 'failure'
                ),
            );
        }

        // Installation log
        $logger           = new \blcCachedOptionLogger('blc_installation_log');
        $installation_log = $logger->get_messages();
        if (! empty($installation_log)) {
            $debug['Installation log'] = array(
                'state' => $this->plugin_config->options['installation_complete'] ? 'ok' : 'error',
                'value' => implode("<br>\n", $installation_log),
            );
        } else {
            $debug['Installation log'] = array(
                'state' => 'warning',
                'value' => 'No installation log found found.',
            );
        }

        return $debug;
    }

    function maybe_send_email_notifications()
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        // email notificaiton.
        $send_notification = apply_filters('blc_allow_send_email_notification', $this->plugin_config->options['send_email_notifications']);

        $send_authors_notifications = apply_filters('blc_allow_send_author_email_notification', $this->plugin_config->options['send_authors_email_notifications']);

        if (! ( $send_notification || $send_authors_notifications )) {
            return;
        }

        // Find links that have been detected as broken since the last sent notification.
        $last_notification = date('Y-m-d H:i:s', $this->plugin_config->options['last_notification_sent']); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $where             = $wpdb->prepare('( first_failure >= %s )', $last_notification);
        $links             = blc_get_links(
            array(
                's_filter'             => 'broken',
                'where_expr'           => $where,
                'load_instances'       => true,
                'load_containers'      => true,
                'load_wrapped_objects' => $this->plugin_config->options['send_authors_email_notifications'],
                'max_results'          => 0,
            )
        );

        if (empty($links)) {
            return;
        }

        // Send the admin/maintainer an email notification.
        $email = $this->plugin_config->get('notification_email_address');
        if (empty($email)) {
            // Default to the admin email.
            $email = get_option('admin_email');
        }
        if ($this->plugin_config->options['send_email_notifications'] && ! empty($email)) {
            $this->send_admin_notification($links, $email);
        }

        // Send notifications to post authors
        if ($this->plugin_config->options['send_authors_email_notifications']) {
            $this->send_authors_notifications($links);
        }

        $this->plugin_config->options['last_notification_sent'] = time();
        $this->plugin_config->save_options();
    }

    function send_admin_notification($links, $email)
    {
        // Prepare email message
        $subject = sprintf(
            __('[%s] Broken links detected', 'broken-link-checker'),
            html_entity_decode(get_option('blogname'), ENT_QUOTES)
        );

        $body  = sprintf(
            _n(
                'Broken Link Checker has detected %d new broken link on your site.',
                'Broken Link Checker has detected %d new broken links on your site.',
                count($links),
                'broken-link-checker'
            ),
            count($links)
        );
        $body .= '<br>';

        $instances = array();
        foreach ($links as $link) {
            /* @var \blcLink $link */
            $instances = array_merge($instances, $link->get_instances());
        }
        $body .= $this->build_instance_list_for_email($instances);

        if ($this->is_textdomain_loaded && is_rtl()) {
            $body = '<div dir="rtl">' . $body . '</div>';
        }

        $this->send_html_email($email, $subject, $body);
    }

    function build_instance_list_for_email($instances, $max_displayed_links = 5, $add_admin_link = true)
    {
        if (null === $max_displayed_links) {
            $max_displayed_links = 5;
        }

        $result = '';
        if (count($instances) > $max_displayed_links) {
            $line = sprintf(
                _n(
                    "Here's a list of the first %d broken links:",
                    "Here's a list of the first %d broken links:",
                    $max_displayed_links,
                    'broken-link-checker'
                ),
                $max_displayed_links
            );
        } else {
            $line = __("Here's a list of the new broken links: ", 'broken-link-checker');
        }

        $result .= "<p>$line</p>";

        // Show up to $max_displayed_links broken link instances right in the email.
        $displayed = 0;
        foreach ($instances as $instance) {
            /* @var \blcLinkInstance $instance */
            $pieces = array(
                sprintf(__('Link text : %s', 'broken-link-checker'), $instance->ui_get_link_text('email')),
                sprintf(__('Link URL : <a href="%1$s">%2$s</a>', 'broken-link-checker'), htmlentities($instance->get_url()), blcUtility::truncate($instance->get_url(), 70, '')),
                sprintf(__('Source : %s', 'broken-link-checker'), $instance->ui_get_source('email')),
            );

            $link_entry = implode('<br>', $pieces);
            $result    .= "$link_entry<br><br>";

            ++$displayed;
            if ($displayed >= $max_displayed_links) {
                break;
            }
        }

        // Add a link to the "Broken Links" tab.
        if ($add_admin_link) {
            $result .= __('You can see all broken links here:', 'broken-link-checker') . '<br>';
            $result .= sprintf('<a href="%1$s">%1$s</a>', admin_url('admin.php?page=blc_local'));
        }

        return $result;
    }

    function send_html_email($email_address, $subject, $body)
    {
        // Need to override the default 'text/plain' content type to send a HTML email.
        add_filter('wp_mail_content_type', array( $this, 'override_mail_content_type' ));

        // Let auto-responders and similar software know this is an auto-generated email
        // that they shouldn't respond to.
        $headers = array( 'Auto-Submitted: auto-generated' );

        $success = wp_mail($email_address, $subject, $body, $headers);

        // Remove the override so that it doesn't interfere with other plugins that might
        // want to send normal plaintext emails.
        remove_filter('wp_mail_content_type', array( $this, 'override_mail_content_type' ));

        $this->plugin_config->options['last_email'] = array(
            'subject'   => $subject,
            'timestamp' => time(),
            'success'   => $success,
        );
        $this->plugin_config->save_options();

        return $success;
    }

    function send_authors_notifications($links)
    {
        $authorInstances = array();
        foreach ($links as $link) {
            /* @var \blcLink $link */
            foreach ($link->get_instances() as $instance) {
                /* @var \blcLinkInstance $instance */
                $container = $instance->get_container();
                /** @var \blcContainer $container */
                if (empty($container) || ! ( $container instanceof \blcAnyPostContainer )) {
                    continue;
                }
                $post = $container->get_wrapped_object();
                /** @var \StdClass $post */
                if (! array_key_exists($post->post_author, $authorInstances)) {
                    $authorInstances[ $post->post_author ] = array();
                }
                $authorInstances[ $post->post_author ][] = $instance;
            }
        }

        foreach ($authorInstances as $author_id => $instances) {
            $subject = sprintf(
                __('[%s] Broken links detected', 'broken-link-checker'),
                html_entity_decode(get_option('blogname'), ENT_QUOTES)
            );

            $body  = sprintf(
                _n(
                    'Broken Link Checker has detected %d new broken link in your posts.',
                    'Broken Link Checker has detected %d new broken links in your posts.',
                    count($instances),
                    'broken-link-checker'
                ),
                count($instances)
            );
            $body .= '<br>';

            $author = get_user_by('id', $author_id);
            /** @var WP_User $author */
            $body .= $this->build_instance_list_for_email($instances, null, $author->has_cap('edit_others_posts'));

            if ($this->is_textdomain_loaded && is_rtl()) {
                $body = '<div dir="rtl">' . $body . '</div>';
            }

            $this->send_html_email($author->user_email, $subject, $body);
        }
    }

    function override_mail_content_type(
        /** @noinspection PhpUnusedParameterInspection */
        $content_type
    ) {
        return 'text/html';
    }

    /**
     * Promote all links with the "warning" status to "broken".
     */
    private function promote_warnings_to_broken()
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        $wpdb->update(
            $wpdb->prefix . 'blc_links',
            array(
                'broken'  => 1,
                'warning' => 0,
            ),
            array(
                'warning' => 1,
            ),
            '%d'
        );
    }

    /**
     * Install or uninstall the plugin's Cron events based on current settings.
     *
     * @return void
     * @uses wsBrokenLinkChecker::$conf Uses $conf->options to determine if events need to be (un)installed.
     */
    function setup_cron_events()
    {

        // Link monitor
        if ($this->plugin_config->options['run_via_cron']) {
            if (! wp_next_scheduled('blc_cron_check_links')) {
                wp_schedule_event(time(), '10min', 'blc_cron_check_links');
            }
        } else {
            wp_clear_scheduled_hook('blc_cron_check_links');
        }

        // Email notifications about broken links
        if ($this->plugin_config->options['send_email_notifications'] || $this->plugin_config->options['send_authors_email_notifications']) {
            if (! wp_next_scheduled('blc_cron_email_notifications')) {
                wp_schedule_event(time(), $this->plugin_config->options['notification_schedule'], 'blc_cron_email_notifications');
            }
        } else {
            wp_clear_scheduled_hook('blc_cron_email_notifications');
        }

        // Run database maintenance every two weeks or so
        if (! wp_next_scheduled('blc_cron_database_maintenance')) {
            wp_schedule_event(time(), 'daily', 'blc_cron_database_maintenance');
        }

        $clear_log = $this->plugin_config->options['clear_log_on'];
        if (! wp_next_scheduled('blc_corn_clear_log_file') && ! empty($clear_log)) {
            wp_schedule_event(time(), $clear_log, 'blc_corn_clear_log_file');
        }

        if (empty($clear_log)) {
            wp_clear_scheduled_hook('blc_corn_clear_log_file');
        }
    }

    /**
     * Clear blc log file
     *
     * @return void
     */
    function clear_log_file()
    {
        $log_file = $this->plugin_config->options['log_file'];

        // clear log file
        if (is_writable($log_file) && is_file($log_file)) {
            $handle = fopen($log_file, 'w');
            fclose($handle);
        }
    }

    /**
     * Don't update the last updated date of a post
     *
     * @param array $data An array of slashed post data.
     * @param array $postarr An array of sanitized, but otherwise unmodified post data.
     *
     * @return array $data Resulting array of slashed post data.
     */
    public function disable_post_date_update($data, $postarr)
    {

        $last_modified = isset($postarr['blc_post_modified']) ? $postarr['blc_post_modified'] : '';

        $last_modified_gmt = isset($postarr['blc_post_modified_gmt']) ? $postarr['blc_post_modified_gmt'] : '';

        // if is not enabled bail!
        if (! $this->plugin_config->options['blc_post_modified']) {
            return $data;
        }

        // only restore the post modified for BLC links
        if (empty($last_modified) || empty($last_modified_gmt)) {
            return $data;
        }

        // modify the post modified date.
        $data['post_modified'] = $last_modified;

        // modify the post modified gmt
        $data['post_modified_gmt'] = $last_modified_gmt;

        return $data;
    }


    /**
     * Load the plugin's textdomain.
     *
     * @return void
     */
    function load_language()
    {
        $this->is_textdomain_loaded = load_plugin_textdomain('broken-link-checker', false, basename(dirname($this->loader)) . '/languages');
    }
} //class ends here
