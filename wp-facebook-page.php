<?php
/*
  Plugin Name: Tribalpixel WP Facebook Page
  GitHub Plugin URI: https://github.com/<owner>/<repo>
  Description: Facebook Page Integration
  Version: 1.0.0
  Author: Tribalpixel
  Author URI: http://www.tribalpixel.ch
  License: GPLv2
  License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace Tribalpixel;

if (!defined('ABSPATH'))
    exit; // Exit if accessed directly

require_once 'vendor/autoload.php';

class WP_Facebook_Page {

    private $plugin_name = 'Facebook Page';
    private $plugin_prefix = 'tplwpfp';
    private $fb;

    /** The settings object */
    private $settings = [
        'max_feed' => 5,
        'fetch_interval' => 7200, // in seconds
    ];

    /** Static property to hold our singleton instance */
    static $_instance = false;

    /**
     * If an instance exists, this returns it.  
     * If not, it creates one and retuns it.
     *
     * @return object
     */
    public static function getInstance() {
        if (!self::$_instance)
            self::$_instance = new self;
        return self::$_instance;
    }

    /**
     * The constructor
     *
     * @return void
     */
    public function __construct() {

        $this->fb = self::getFacebook();

        // Admin
        add_action('admin_menu', array(&$this, 'add_settings_page'));
        add_action('admin_init', array(&$this, 'register_settings'));

        // Shortcodes
        add_action('init', array(&$this, 'register_shortcodes'));

        // Add script
        add_action('admin_enqueue_scripts', array(&$this, 'enqueue_font_awesome'));
        add_action('wp_enqueue_scripts', array(&$this, 'enqueue_font_awesome'));
        add_action('admin_enqueue_scripts', array(&$this, 'enqueue_css'));
        add_action('wp_enqueue_scripts', array(&$this, 'enqueue_css'));
    }

    /**
     * Get instance of facebook object for the page
     * 
     * @return \Facebook\Facebook
     */
    public function getFacebook() {
        $id = get_option($this->plugin_prefix . '_app_id');
        $secret = get_option($this->plugin_prefix . '_app_secret');

        $fb = new \Facebook\Facebook([
            'app_id' => $id,
            'app_secret' => $secret,
        ]);
        $fb->setDefaultAccessToken("{$id}|{$secret}");
        return $fb;
    }

    /**
     * Make call to facebook graph api
     * 
     * @param array $params
     * @return array
     */
    public function getFacebookResponse($params) {

        $endpoint = get_option($this->plugin_prefix . '_app_page_id');

        try {
            $response = $this->fb->get($endpoint . "?fields=" . implode(",", $params));
            return $response->getDecodedBody();
        } catch (Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage();
            exit;
        } catch (Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage();
            exit;
        }
    }

    /**
     * Register all shortcode to init hook
     */
    public function register_shortcodes() {
        add_shortcode("{$this->plugin_prefix}-summary", array(&$this, 'showPageSummmary'));
        add_shortcode("{$this->plugin_prefix}-albums", array(&$this, 'showPageAlbums'));
        add_shortcode("{$this->plugin_prefix}-feed", array(&$this, 'showPageFeed'));
    }

    /**
     * Render HTML for shortcode for {$this->plugin_prefix}-summary
     */
    public function showPageSummmary() {

        $transient = get_transient($this->plugin_prefix . '_page_summary');

        if (false == $transient) {

            $params = ['name', 'about', 'category', 'fan_count', 'founded', 'website', 'cover'];
            $infos = self::getFacebookResponse($params);

            $output = '<div class="thumbnail"><div class="centered"><img src="' . $infos['cover']['source'] . '" width="150"></div></div>';
            $output .= '<ul>';
            foreach ($infos as $k => $v) {
                if ($k !== 'cover') {
                    $output .= '<li><strong>' . strtoupper($k) . ':</strong> ' . $v . '</li>';
                }
            }
            $output .= '</ul>';
            set_transient($this->plugin_prefix . '_page_summary', $output, $this->settings['fetch_interval']);
            echo $output;
        } else {
            echo $transient;
        }
    }

    /**
     * Render HTML for shortcode for {$this->plugin_prefix}-albums
     */
    public function showPageAlbums() {

        $params = ['albums.limit(10){count,created_time,updated_time,id,name,type,description,cover_photo}'];
        $infos = self::getFacebookResponse($params);

        echo '<ul>';
        foreach ($infos['albums']['data'] as $album) {
            echo '<li><i class="fa fa-picture fa-5x"></i>';
            if ('normal' === $album['type'])
                foreach ($album as $k => $v) {
                    //var_dump($album);
                    if (!is_array($v)) {
                        echo '<strong>' . strtoupper($k) . ':</strong> ' . $v . '<br>';
                    }
                }
            echo '</li>';
        }
        echo '</ul>';

        //var_dump($infos['albums']['data']);
    }

    /**
     * Render HTML for shortcode for {$this->plugin_prefix}-feed
     */
    public function showPageFeed() {

        $transient = get_transient($this->plugin_prefix . '_page_feed');

        if (false == $transient) {

            $params = ['feed.limit(' . $this->settings['max_feed'] . '){created_time,updated_time,type,permalink_url,picture,timeline_visibility,story,description,status_type,name}'];
            $infos = self::getFacebookResponse($params);
            $output = '';
            $output .= '<div class="wp-page-feed">';
            foreach ($infos['feed']['data'] as $k => $v) {
                $output .= '<div class="item">';
                $output .= '<div class="thumbnail"><img src="' . $v['picture'] . '"></div>';
                $output .= '<a href="' . $v['permalink_url'] . '">';
                $output .= '<div class="date">' . mysql2date('l, F j, Y', $v['updated_time']) . '</div>';
                $output .= '<div class="title">' . $v['name'] . '</div>';
                $output .= '</a>';
                $output .= '</div>';
                //var_dump($v);
            }
            $output .= '</div>';
            set_transient($this->plugin_prefix . '_page_feed', $output, $this->settings['fetch_interval']);
            echo $output;
        } else {
            echo $transient;
        }
    }

    /**
     * Add menu link to wordpress for option page
     */
    public function add_settings_page() {
        //add_menu_page($page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position)
        add_menu_page(__($this->plugin_name), __($this->plugin_name), 'manage_categories', $this->plugin_prefix, array(&$this, 'render_config_page'), 'dashicons-facebook');

        //add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function)
        add_submenu_page($this->plugin_prefix, __($this->plugin_name . ' Configuration'), 'Configuration', 'manage_categories', $this->plugin_prefix, array(&$this, 'render_config_page'));
        add_submenu_page($this->plugin_prefix, __($this->plugin_name . ' Shortcodes'), 'Shortcodes', 'manage_categories', $this->plugin_prefix . '_shortcodes', array(&$this, 'render_preview_page'));
    }

    /**
     * Register options for DB and page form
     */
    public function register_settings() {
        //register_setting($option_group, $option_name, $args)
        register_setting($this->plugin_prefix . '-config-group', $this->plugin_prefix . '_app_id');
        register_setting($this->plugin_prefix . '-config-group', $this->plugin_prefix . '_app_secret');
        register_setting($this->plugin_prefix . '-config-group', $this->plugin_prefix . '_app_page_id');

        //add_settings_section($id, $title, $callback, $page)
        add_settings_section($this->plugin_prefix . '-config-section', __('Configuration'), NULL, $this->plugin_prefix . '_config');

        //add_settings_field($id, $title, $callback, $page, $section, $args)
        add_settings_field($this->plugin_prefix . '_app_id', 'Facebook App ID', array(&$this, 'render_field'), $this->plugin_prefix . '_config', $this->plugin_prefix . '-config-section', array('id' => $this->plugin_prefix . '_app_id'));
        add_settings_field($this->plugin_prefix . '_app_secret', 'Facebook App Secret', array(&$this, 'render_field'), $this->plugin_prefix . '_config', $this->plugin_prefix . '-config-section', array('id' => $this->plugin_prefix . '_app_secret'));
        add_settings_field($this->plugin_prefix . '_app_page_id', 'Facebook Page ID', array(&$this, 'render_field'), $this->plugin_prefix . '_config', $this->plugin_prefix . '-config-section', array('id' => $this->plugin_prefix . '_app_page_id'));
    }

    /**
     * Render form fields for options
     * 
     * @param array $args
     */
    public function render_field($args) {
        $option = get_option($args['id']);
        echo '<input type="text" name="' . $args['id'] . '" value="' . $option . '" class="regular-text" />';
    }

    /**
     * Render HTML for plugin page
     */
    public function render_preview_page() {
        ?>        
        <div class="wrap">
            <h1><?php _e($this->plugin_name); ?></h1>
            <div class="column">
                <h2>[tplwpfp-summary]</h2>
                <?php echo do_shortcode('[tplwpfp-summary]'); ?>
                <hr>
            </div>
            <div>
                <h2>[tplwpfp-feed]</h2>
                <?php echo do_shortcode('[tplwpfp-feed]'); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render HTML for plugin/config page
     */
    public function render_config_page() {
        ?>        
        <div class="wrap">
            <h1><?php _e($this->plugin_name); ?></h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields($this->plugin_prefix . '-config-group') ?>
                <?php do_settings_sections($this->plugin_prefix . '_config') ?>
                <?php submit_button(); ?>
            </form>
            <div><?php var_dump($this); ?></div>
        </div>
        <?php
    }

    /**
     * Add Font Awesome
     */
    public function enqueue_font_awesome() {
        wp_enqueue_style(
                'font-awesome', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.6.3/css/font-awesome.min.css', FALSE, NULL
        );
    }

    /**
     * Add Plugin CSS
     */
    public function enqueue_css() {
        wp_enqueue_style(
                sanitize_title($this->plugin_name), plugins_url('css/wp-facebook-page.css', __FILE__), FALSE, NULL
        );
    }

/// end class
}

// Instantce
$WP_Facebook_Page = WP_Facebook_Page::getInstance();