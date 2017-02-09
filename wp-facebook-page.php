<?php
/*
  Plugin Name: Tribalpixel WP Facebook Page
  GitHub Plugin URI: https://github.com/<owner>/<repo>
  Description: Facebook Page Integration
  Version: 1.0.0
  Author: Ludovic Bortolotti
  Author URI: http://www.tribalpixel.ch
  License: MIT
 */

if (!defined('ABSPATH'))
    exit; // Exit if accessed directly

    
// Include SDK
require_once 'vendor/autoload.php';

class Tribalpixel_WP_Facebook_Page {

    private $plugin_name = 'Facebook Page';
    private $plugin_prefix = 'tplwpfp';
    private $plugin_version = '1.0.0';
    private $fb;
    private $cache_cleared = false;

    /** The settings object */
    private $settings = [
        'max_feed' => 20,
        'fetch_interval' => 3600, // in seconds
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
     * Plugin constructor
     *
     * @return void
     */
    public function __construct() {

        // Get instance of Facebook object
        $this->fb = self::getFacebook();

        // Admin
        add_action('admin_menu', array(&$this, 'add_settings_page'));
        add_action('admin_init', array(&$this, 'register_settings'));

        // Shortcodes
        add_action('init', array(&$this, 'register_shortcodes'));

        // Clear cache transients
        add_action('init', array(&$this, 'clear_cache'));

        // Add script
        add_action('admin_enqueue_scripts', array(&$this, 'enqueue_font_awesome'));
        //add_action('wp_enqueue_scripts', array(&$this, 'enqueue_font_awesome'));
        add_action('admin_enqueue_scripts', array(&$this, 'enqueue_css'));
        add_action('wp_enqueue_scripts', array(&$this, 'enqueue_css'));
    }

    /**
     * Register all shortcode to init hook
     */
    public function register_shortcodes() {
        add_shortcode("{$this->plugin_prefix}-summary", array(&$this, 'showPageSummmary'));
        add_shortcode("{$this->plugin_prefix}-album", array(&$this, 'showAlbum'));
    }

    public function clear_cache() {
        if (isset($_POST[$this->plugin_prefix . '-clear-cache'])) {

            delete_transient($this->plugin_prefix . '_page_summary');
            delete_transient($this->plugin_prefix . '_fb_albums');

            $this->cache_cleared = true;
        }
    }

    private function getPluginTransient() {
        
    }

    /**
     * Render HTML for shortcode for {$this->plugin_prefix}-summary
     * 
     * @param array $args
     */
    public function showAlbum($args) {
        $transID = $this->plugin_prefix . '_album_' . $args['id'];
        $transient = get_transient($transID);
        if (false == $transient) {

            $params = ['created_time', 'name', 'photos{webp_images,name}'];
            $infos = self::getFacebookResponse($params, $args['id']);
            $images = $infos['photos']['data'];
            $id = $infos['id'];
            //var_dump($infos);
            $output = '<h3>' . $infos['name'] . '</h3>';
            $output .= '<div class="home-slideshow">';
            foreach ($images as $v) {
                $output .= '<div class="slide">';
                $output .= '<a href="' . $v['webp_images'][1]['source'] . '" rel="gallery-'.$id.'" class="fancybox" title="'.$v['name'].'">';
                $output .= '<img src="' . $v['webp_images'][3]['source'] . '" style="width:100%;" alt="'.$v['name'].'" />';
                //$output .= '<div style="background: url(\''.$v['webp_images'][1]['source'].'\') no-repeat center center; width:100%; height:auto;"></div>';
                $output .= '<div class="slide-txt">'.$v['name'].'</div>';
                $output .= '</a></div>';
            }
            $output .= '</div>';
            //set_transient($transID, $output, $this->settings['fetch_interval']);
            return $output;
        } else {
            return $transient;
        }
    }

    /**
     * Render HTML for shortcode for {$this->plugin_prefix}-summary
     */
    public function showPageSummmary() {
        $transient = get_transient($this->plugin_prefix . '_fb_page_summary');
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
            set_transient($this->plugin_prefix . '_fb_page_summary', $output, $this->settings['fetch_interval']);
            return $output;
        } else {
            return $transient;
        }
    }

    /**
     * Render HTML for admin page "schortcode"
     */
    public function getFacebookAlbumsShortcodes() {
        $transient = get_transient($this->plugin_prefix . '_fb_albums');
        if (false == $transient) {
            $params = ['albums.limit(' . $this->settings['max_feed'] . '){id,created_time,updated_time,type,name,description,photo_count,cover_photo{id}}'];
            $infos = self::getFacebookResponse($params)['albums']['data'];

            foreach ($infos as $k => $v) {
                if (isset($v['cover_photo'])) {
                    $infos[$k]['cover_photo']['src'] = self::getFacebookResponse(['picture'], $v['cover_photo']['id'])['picture'];
                }
            }

            $output = '<div class="cf-facebook-albums">';
            foreach ($infos as $k => $v) {
                $output .= '<div class="cf-facebook-album">';
                if (isset($v['cover_photo']['src'])) {
                    $output .= '<div style="background: url(\'' . $v['cover_photo']['src'] . '\') no-repeat center center; width:100px; height:100px;"></div>';
                } else {
                    $output .= '<div style="background: url(\'' . plugins_url('css/no-cover.jpg', __FILE__) . '\') no-repeat center center; width:100px; height:100px;"></div>';
                }
                $output .= '<div>' . $v['name'] . '<br />';
                $output .= '<i class="fa fa-picture-o"></i> ' . $v['photo_count'] . '<br />';
                $output .= '</div>';
                $output .= '<div><strong>[' . $this->plugin_prefix . '-album id="' . $v['id'] . '"]</strong></div>';
                $output .= '</div>';
            }
            $output .= '</div>';

            set_transient($this->plugin_prefix . '_fb_albums', $output, $this->settings['fetch_interval']);
            return $output;
        } else {
            if (is_admin()) {
                echo "next update: ";
                echo $this->getTransientExpiration($this->plugin_prefix . '_fb_albums');
            }
            return $transient;
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
            <h2>Albums shortcodes</h2>
            <p class="cf-help">Use thoses shortcodes in WordPress Posts</p>
            <?php //echo do_shortcode('[tplwpfp-summary]');   ?>
        <?php echo self::getFacebookAlbumsShortcodes(); ?>
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

            <h2>Clear Cache</h2>
            <p class="description">echo self::getPluginTransient()</p>
            <form action="<?php echo admin_url('admin.php?page=' . $this->plugin_prefix); ?>" method="post">
                <input type="hidden" name="<?php echo $this->plugin_prefix; ?>-clear-cache" value="1" />
        <?php submit_button('Clear Cache'); ?>
            </form>            

            <div class="sidebar">
                <?php
                echo do_shortcode('[tplwpfp-summary]');
                if (is_admin()) {
                    echo "<br />next update: ";
                    echo $this->getTransientExpiration($this->plugin_prefix . '_fb_page_summary');
                }
                ?>
            </div>

        </div>
        <?php
    }

    /**
     * Get Facebook object
     * 
     * @return \Facebook\Facebook
     */
    private function getFacebook() {

        $id = get_option($this->plugin_prefix . '_app_id');
        $secret = get_option($this->plugin_prefix . '_app_secret');

        if (!$id || !$secret) {
            return;
        }

        try {
            $fb = new \Facebook\Facebook([
                'app_id' => $id,
                'app_secret' => $secret,
            ]);
            $fb->setDefaultAccessToken("{$id}|{$secret}");
            return $fb;
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
     * Make call to facebook graph api
     * 
     * @param array $params
     * @return array
     */
    private function getFacebookResponse($params, $src = false) {
        if (!$this->fb) {
            exit;
        }
        try {
            $endpoint = get_option($this->plugin_prefix . '_app_page_id');
            if (!$src) {
                $response = $this->fb->get($endpoint . "?fields=" . implode(",", $params));
            } else {
                $response = $this->fb->get($src . "?fields=" . implode(",", $params));
            }
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
     * Add Plugin CSS
     */
    public function enqueue_css() {
        wp_enqueue_style(
                sanitize_title($this->plugin_name), plugins_url('css/wp-facebook-page.css', __FILE__), FALSE, NULL
        );
    }

    /**
     * Add Font Awesome
     */
    public function enqueue_font_awesome() {
        wp_enqueue_style(
                'font-awesome', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css', FALSE, NULL
        );
    }

    /**
     * Retrieve the human-friendly transient expiration time
     * 
     * @param s $transient
     * @return string
     */
    private function getTransientExpiration($transient) {

        $time_now = time();
        $expiration = get_option('_transient_timeout_' . $transient);

        if (empty($expiration)) {
            return __('Does not expire', 'tplwpfp');
        }

        if ($time_now > $expiration) {
            return __('Expired', 'tplwpfp');
        }

        return human_time_diff($time_now, $expiration);
    }

// end class
}

// Instantiate class
$Tribalpixel_WP_Facebook_Page = Tribalpixel_WP_Facebook_Page::getInstance();
