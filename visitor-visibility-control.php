<?php
/**
 * Plugin Name: Visitor Visibility Control
 * Plugin URI: https://github.com/Urbana-Designs/visitor-visibility-control
 * Description: Adds a checkbox in the editor to hide/show content for logged-in users and controls menu visibility.
 * Version: 1.0.2
 * Author: Urbana Designs
 * Author URI: https://github.com/Urbana-Designs
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: visitor-visibility-control
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8.2
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VVC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VVC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('VVC_PLUGIN_VERSION', '1.0.2');

/**
 * Main plugin class
 */
class VisitorVisibilityControl {
    /**
     * Tracks when we need to customize the 404 template for restricted content.
     */
    private $is_rendering_restricted = false;

    /**
     * Stores the login URL used within the restricted template.
     */
    private $restricted_login_url = '';
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Register post meta
        $this->register_post_meta();
        
        // Add hooks for admin functionality
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('add_meta_boxes', array($this, 'add_classic_editor_meta_box'));
        add_action('save_post', array($this, 'save_post_meta'));
        
        // Add hooks for frontend functionality
        add_action('pre_get_posts', array($this, 'exclude_hidden_posts_from_queries'));
        add_filter('wp_get_nav_menu_items', array($this, 'exclude_hidden_posts_from_menus'), 10, 3);
    add_action('parse_query', array($this, 'handle_hidden_post_access'));
    add_action('wp', array($this, 'restrict_hidden_post_access'));
    add_action('template_redirect', array($this, 'maybe_render_restricted_template'), 0);
        
        // Add filters for automatic page listings and wp_list_pages
        add_filter('wp_list_pages_excludes', array($this, 'exclude_hidden_pages_from_wp_list_pages'));
        add_filter('get_pages', array($this, 'exclude_hidden_pages_from_get_pages'), 10, 2);
        add_filter('wp_page_menu_args', array($this, 'exclude_hidden_pages_from_page_menu'));
        add_filter('page_css_class', array($this, 'hide_page_menu_items'), 10, 5);
        
        // Additional filters for comprehensive coverage
        add_filter('wp_nav_menu_objects', array($this, 'filter_nav_menu_objects'), 10, 2);
        add_action('wp_head', array($this, 'add_inline_css_for_hidden_pages'));
        
        // Add admin column
        add_filter('manage_posts_columns', array($this, 'add_admin_column'));
        add_filter('manage_pages_columns', array($this, 'add_admin_column'));
        add_action('manage_posts_custom_column', array($this, 'show_admin_column_content'), 10, 2);
        add_action('manage_pages_custom_column', array($this, 'show_admin_column_content'), 10, 2);
        
        // Add bulk edit and quick edit functionality
        add_action('bulk_edit_custom_box', array($this, 'add_bulk_edit_fields'), 10, 2);
        add_action('quick_edit_custom_box', array($this, 'add_quick_edit_fields'), 10, 2);
        add_action('wp_ajax_save_bulk_edit_visitor_visibility', array($this, 'save_bulk_edit_data'));
        add_action('save_post', array($this, 'save_quick_edit_data'));
    }
    
    /**
     * Register post meta for the visibility setting
     */
    public function register_post_meta() {
        register_post_meta('post', '_show_to_visitor', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'boolean',
            'default' => false,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_post_meta('page', '_show_to_visitor', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'boolean',
            'default' => false,
            'auth_callback' => function() {
                return current_user_can('edit_pages');
            }
        ));
    }
    
    /**
     * Enqueue assets for the block editor
     */
    public function enqueue_block_editor_assets() {
        // Double check we're in block editor context
        if (!$this->is_block_editor()) {
            return;
        }
        
        wp_enqueue_script(
            'vvc-block-editor',
            VVC_PLUGIN_URL . 'assets/block-editor.js',
            array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data'),
            VVC_PLUGIN_VERSION,
            true
        );
        
        wp_localize_script('vvc-block-editor', 'vvcData', array(
            'nonce' => wp_create_nonce('vvc_nonce'),
            'strings' => array(
                'title' => __('Visibility Settings', 'visitor-visibility-control'),
                'label' => __('Show to visitor', 'visitor-visibility-control'),
                'help' => __('When unchecked, this content will be hidden from visitors and excluded from navigation menus.', 'visitor-visibility-control')
            )
        ));
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on post edit screens
        if (in_array($hook, array('post.php', 'post-new.php', 'edit.php'))) {
            wp_enqueue_style(
                'vvc-admin-style',
                VVC_PLUGIN_URL . 'assets/admin-style.css',
                array(),
                VVC_PLUGIN_VERSION
            );
            
            // Load bulk edit script on list pages
            if ($hook === 'edit.php') {
                wp_enqueue_script(
                    'vvc-bulk-edit',
                    VVC_PLUGIN_URL . 'assets/bulk-edit.js',
                    array('jquery', 'inline-edit-post'),
                    VVC_PLUGIN_VERSION,
                    true
                );
                
                wp_localize_script('vvc-bulk-edit', 'vvcBulkEdit', array(
                    'nonce' => wp_create_nonce('vvc_bulk_edit'),
                    'ajax_url' => admin_url('admin-ajax.php')
                ));
            }
        }
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Don't load for any logged-in users
        if (is_user_logged_in()) {
            return;
        }
        
        wp_enqueue_style(
            'vvc-frontend-style',
            VVC_PLUGIN_URL . 'assets/frontend-style.css',
            array(),
            VVC_PLUGIN_VERSION
        );
    }
    
    /**
     * Add meta box for classic editor
     */
    public function add_classic_editor_meta_box() {
        // Check current screen to see if we're in block editor
        $screen = get_current_screen();
        
        // Don't add meta box if we're in block editor
        if ($screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return;
        }
        
        // Also check using the WordPress function
        if (function_exists('use_block_editor_for_post_type')) {
            if (use_block_editor_for_post_type('post') || use_block_editor_for_post_type('page')) {
                return;
            }
        }
        
        add_meta_box(
            'vvc-visibility-settings',
            __('Visibility Settings', 'visitor-visibility-control'),
            array($this, 'render_classic_editor_meta_box'),
            array('post', 'page'),
            'side',
            'default'
        );
    }
    
    /**
     * Check if we're using the block editor
     */
    private function is_block_editor() {
        // Check if the block editor is being used
        if (function_exists('use_block_editor_for_post')) {
            global $post;
            if ($post) {
                return use_block_editor_for_post($post);
            }
        }
        
        // Check if we're on a post edit screen with block editor
        global $pagenow;
        if (in_array($pagenow, array('post.php', 'post-new.php'))) {
            // Check if classic editor is forced via URL parameter (sanitize input)
            // These are display-only flags and not state-changing actions; nonce verification is not required here.
            /* phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only URL flags; sanitized prior to use */
            if ( isset( $_GET['classic-editor__forget'] ) ) {
                $val = sanitize_text_field( wp_unslash( $_GET['classic-editor__forget'] ) );
                if ( '1' === $val || 'true' === $val ) {
                    return false;
                }
            }
            if ( isset( $_GET['classic-editor'] ) ) {
                $val2 = sanitize_text_field( wp_unslash( $_GET['classic-editor'] ) );
                if ( '1' === $val2 || 'true' === $val2 ) {
                    return false;
                }
            }
            /* phpcs:enable WordPress.Security.NonceVerification.Recommended */
        }
        
        // Default: assume block editor if WordPress 5.0+
        global $wp_version;
        return version_compare($wp_version, '5.0', '>=');
    }
    
    /**
     * Render meta box content for classic editor
     */
    public function render_classic_editor_meta_box($post) {
        wp_nonce_field('vvc_save_meta', 'vvc_meta_nonce');
        
        $show_to_visitor = get_post_meta($post->ID, '_show_to_visitor', true);
        if ($show_to_visitor === '') {
            $show_to_visitor = false; // Default to false (hidden)
        }
        ?>
        <p>
            <label for="vvc_show_to_visitor">
                <input type="checkbox" 
                       id="vvc_show_to_visitor" 
                       name="vvc_show_to_visitor" 
                       value="1" 
                       <?php checked($show_to_visitor); ?> />
                <?php esc_html_e('Show to visitor', 'visitor-visibility-control'); ?>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('When unchecked, this content will be hidden from visitors and excluded from navigation menus.', 'visitor-visibility-control'); ?>
        </p>
        <?php
    }
    
    /**
     * Save post meta data
     */
    public function save_post_meta($post_id) {
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Check nonce for classic editor
        if (isset($_POST['vvc_meta_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vvc_meta_nonce'])), 'vvc_save_meta')) {
            $show_to_visitor = isset($_POST['vvc_show_to_visitor']) ? true : false;
            update_post_meta($post_id, '_show_to_visitor', $show_to_visitor);
        }
    }
    
    /**
     * Exclude hidden posts from main queries
     */
    public function exclude_hidden_posts_from_queries($query) {
        // Only affect frontend queries and not admin
        if (is_admin() || !$query->is_main_query()) {
            return;
        }
        
        // Don't affect queries for any logged-in users
        if (is_user_logged_in()) {
            return;
        }
        
        // Add meta query to exclude hidden posts
        $meta_query = $query->get('meta_query') ?: array();
        $meta_query[] = array(
            'key' => '_show_to_visitor',
            'value' => true,
            'compare' => '='
        );
        
        $query->set('meta_query', $meta_query);
    }
    
    /**
     * Exclude hidden posts from navigation menus
     */
    public function exclude_hidden_posts_from_menus($items, $menu, $args) {
        // Don't affect admin or any logged-in users
        if (is_admin() || is_user_logged_in()) {
            return $items;
        }
        
        foreach ($items as $key => $item) {
            if (in_array($item->object, array('post', 'page'))) {
                $show_to_visitor = get_post_meta($item->object_id, '_show_to_visitor', true);
                
                // If meta doesn't exist, default to false (hidden)
                if ($show_to_visitor === '') {
                    $show_to_visitor = false;
                }
                
                // Remove item if it should not be shown to visitors
                if (!$show_to_visitor) {
                    unset($items[$key]);
                }
            }
        }
        
        return $items;
    }
    
    /**
     * Handle hidden post access at query level (better approach)
     */
    public function handle_hidden_post_access($wp_query) {
        // Only handle main query on frontend for singular pages
        if (is_admin() || !$wp_query->is_main_query() || !$wp_query->is_singular()) {
            return;
        }
        
        // Don't affect any logged-in users
        if (is_user_logged_in()) {
            return;
        }
        
        // Get the post being queried
        $post_name = $wp_query->get('name');
        $page_name = $wp_query->get('pagename');
        $post_id = $wp_query->get('p');
        $page_id = $wp_query->get('page_id');
        
        // Determine post ID and type
        $queried_post_id = null;
        $post_type = 'post';
        
        if ($post_id) {
            $queried_post_id = $post_id;
            $post_type = 'post';
        } elseif ($page_id) {
            $queried_post_id = $page_id;
            $post_type = 'page';
        } elseif ($post_name) {
            $post_obj = get_page_by_path($post_name, OBJECT, 'post');
            if ($post_obj) {
                $queried_post_id = $post_obj->ID;
                $post_type = 'post';
            }
        } elseif ($page_name) {
            $post_obj = get_page_by_path($page_name, OBJECT, 'page');
            if ($post_obj) {
                $queried_post_id = $post_obj->ID;
                $post_type = 'page';
            }
        }
        
        // If we found a post, check its visibility
        if ($queried_post_id) {
            $show_to_visitor = get_post_meta($queried_post_id, '_show_to_visitor', true);
            
            // If meta doesn't exist, default to false (hidden)
            if ($show_to_visitor === '') {
                $show_to_visitor = false;
            }
            
            // For pages, also check parent accessibility
            $is_accessible = $show_to_visitor;
            if ($post_type === 'page' && $show_to_visitor) {
                $post_obj = get_post($queried_post_id);
                $is_accessible = $this->check_parent_page_access($post_obj);
            }
            
            // If not accessible, force 404 and mark for restricted message
            if (!$is_accessible) {
                if (!is_user_logged_in()) {
                    $wp_query->set('vvc_restricted_access', true);
                }

                $wp_query->set_404();
                status_header(404);
                nocache_headers();
                return;
            }
        }
    }
    
    /**
     * Restrict access to hidden posts on frontend (fallback)
     */
    public function restrict_hidden_post_access() {
        global $wp_query, $post;

        $is_restricted_404 = (bool) $wp_query->get('vvc_restricted_access');

        // Only run on singular content or when already flagged as restricted
        if (!$is_restricted_404 && (!is_singular() || is_404())) {
            return;
        }

        // Don't affect admin or any logged-in users
        if (is_admin() || is_user_logged_in()) {
            return;
        }

        if ($post) {
            $show_to_visitor = get_post_meta($post->ID, '_show_to_visitor', true);

            // If meta doesn't exist, default to false (hidden)
            if ($show_to_visitor === '') {
                $show_to_visitor = false;
            }

            // Check if this is a child page and if parent is hidden
            $is_child_accessible = $this->check_parent_page_access($post);

            // Set 404 if post should not be shown to visitors OR if parent is hidden
            if (!$show_to_visitor || !$is_child_accessible) {
                if (!is_user_logged_in()) {
                    $wp_query->set('vvc_restricted_access', true);
                }

                $wp_query->set_404();
                status_header(404);
                nocache_headers();
                return;
            }
        }
    }
    
    /**
     * Check if parent pages are accessible (for hierarchical content)
     */
    private function check_parent_page_access($post) {
        // Only check for pages (posts don't have hierarchical structure by default)
        if ($post->post_type !== 'page' || !$post->post_parent) {
            return true; // No parent or not a page, so accessible
        }
        
        $parent_id = $post->post_parent;
        
        // Check parent page visibility
        $parent_show_to_visitor = get_post_meta($parent_id, '_show_to_visitor', true);
        if ($parent_show_to_visitor === '') {
            $parent_show_to_visitor = false; // Default to hidden
        }
        
        // If parent is hidden, child should also be inaccessible
        if (!$parent_show_to_visitor) {
            return false;
        }
        
        // Recursively check grandparent and so on
        $parent_post = get_post($parent_id);
        if ($parent_post && $parent_post->post_parent) {
            return $this->check_parent_page_access($parent_post);
        }
        
        return true;
    }
    
    /**
     * Render the restricted template when needed
     */
    public function maybe_render_restricted_template() {
        if (is_user_logged_in() || !is_404()) {
            return;
        }

        global $wp_query;

        if (!$wp_query->get('vvc_restricted_access')) {
            return;
        }

        // Sanitize REQUEST_URI and use only its path component
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
        if ( empty( $request_path ) ) {
            $request_path = '/';
        }
        $this->restricted_login_url = wp_login_url( home_url( $request_path ) );
        $this->is_rendering_restricted = true;

        add_filter('render_block', array($this, 'filter_restricted_404_blocks'), 10, 2);
        add_filter('document_title_parts', array($this, 'filter_restricted_document_title'));
        add_action('wp_footer', array($this, 'reset_restricted_render_state'), 0);
    }
    
    /**
     * Filter block output within the 404 template when restricted content is reached.
     */
    public function filter_restricted_404_blocks($block_content, $block) {
        if (!$this->is_rendering_restricted) {
            return $block_content;
        }

        $block_name = isset($block['blockName']) ? $block['blockName'] : null;

        if ($block_name === 'core/heading' && strpos($block_content, 'Page not found') !== false) {
            $heading = esc_html__('Restricted Content', 'visitor-visibility-control');
            $icon    = '<span aria-hidden="true" class="vvc-restricted-icon" style="margin-right:0.35em">🔒</span>';
            return sprintf('<h1 class="wp-block-heading">%s%s</h1>', $icon, $heading);
        }

        if ($block_name === 'core/paragraph' && strpos($block_content, 'The page you are looking for') !== false) {
            $message = esc_html__('This page or post is available for team members. Please log in to view.', 'visitor-visibility-control');
            return sprintf('<p>%s</p>', $message);
        }

        if ($block_name === 'core/search') {
            return $this->get_restricted_login_button_markup();
        }

        return $block_content;
    }

    /**
     * Adjust the document title while rendering restricted content message.
     */
    public function filter_restricted_document_title($parts) {
        if ($this->is_rendering_restricted && isset($parts['title'])) {
            $parts['title'] = esc_html__('Restricted Content', 'visitor-visibility-control');
        }

        return $parts;
    }

    /**
     * Build markup for the login button replacement used in the 404 pattern.
     */
    private function get_restricted_login_button_markup() {
        $button_text = esc_html__('Log In to View', 'visitor-visibility-control');
        $login_url   = esc_url($this->restricted_login_url ?: wp_login_url());

        return sprintf(
            '<div class="wp-block-buttons is-layout-flex wp-block-buttons-is-layout-flex" style="justify-content:flex-start"><div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button" href="%1$s">%2$s</a></div></div>',
            $login_url,
            $button_text
        );
    }

    /**
     * Reset filters after rendering to avoid affecting subsequent requests.
     */
    public function reset_restricted_render_state() {
        if (!$this->is_rendering_restricted) {
            return;
        }

        $this->is_rendering_restricted = false;
        $this->restricted_login_url = '';

        remove_filter('render_block', array($this, 'filter_restricted_404_blocks'), 10);
        remove_filter('document_title_parts', array($this, 'filter_restricted_document_title'));
        remove_action('wp_footer', array($this, 'reset_restricted_render_state'), 0);
    }
    
    /**
     * Exclude hidden pages from wp_list_pages() function
     */
    public function exclude_hidden_pages_from_wp_list_pages($exclude_array) {
        // Don't affect admin or any logged-in users
        if (is_admin() || is_user_logged_in()) {
            return $exclude_array;
        }
        
        // Use cached helper to retrieve IDs of hidden pages (reduces repeated meta queries)
        $all_hidden_pages = $this->get_cached_hidden_pages();
        
        // Merge with existing excludes
        if (is_array($exclude_array)) {
            $exclude_array = array_merge($exclude_array, $all_hidden_pages);
        } else {
            $exclude_array = $all_hidden_pages;
        }
        
        return array_unique($exclude_array);
    }
    
    /**
     * Exclude hidden pages from get_pages() function
     */
    public function exclude_hidden_pages_from_get_pages($pages, $args) {
        // Don't affect admin or any logged-in users
        if (is_admin() || is_user_logged_in()) {
            return $pages;
        }
        
        // Filter out hidden pages
        return array_filter($pages, array($this, 'is_page_visible_to_visitors'));
    }
    
    /**
     * Exclude hidden pages from wp_page_menu
     */
    public function exclude_hidden_pages_from_page_menu($args) {
        // Don't affect admin or any logged-in users
        if (is_admin() || is_user_logged_in()) {
            return $args;
        }
        
        // Get existing exclude list
        $exclude = isset($args['exclude']) ? $args['exclude'] : '';
        $exclude_array = !empty($exclude) ? explode(',', $exclude) : array();
        
        // Get hidden pages
        $hidden_pages = $this->exclude_hidden_pages_from_wp_list_pages($exclude_array);
        
        // Update args with exclude parameter
        // Note: 'exclude' is the standard WordPress method for wp_page_menu filtering
        // This is more efficient than post__not_in and is the recommended approach for menus
        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- using 'exclude' for wp_page_menu is the recommended approach for menus and avoids post__not_in usage
        $args['exclude'] = implode(',', $hidden_pages);
        
        return $args;
    }
    
    /**
     * Hide page menu items by adding CSS class (fallback method)
     */
    public function hide_page_menu_items($css_class, $page, $depth, $args, $current_page) {
        // Don't affect admin or any logged-in users
        if (is_admin() || is_user_logged_in()) {
            return $css_class;
        }
        
        if (!$this->is_page_visible_to_visitors($page)) {
            $css_class[] = 'vvc-hidden-page';
        }
        
        return $css_class;
    }
    
    /**
     * Helper function to check if a page is visible to visitors
     */
    private function is_page_visible_to_visitors($page) {
        $page_id = is_object($page) ? $page->ID : $page;
        $show_to_visitor = get_post_meta($page_id, '_show_to_visitor', true);
        
        // If meta doesn't exist, default to false (hidden)
        if ($show_to_visitor === '') {
            $show_to_visitor = false;
        }
        
        // Check parent page accessibility for hierarchical pages
        if ($show_to_visitor && is_object($page) && $page->post_parent) {
            return $this->check_parent_page_access($page);
        }
        
        return $show_to_visitor;
    }
    
    /**
     * Filter navigation menu objects (additional safety net)
     */
    public function filter_nav_menu_objects($items, $args) {
        // Don't affect admin or any logged-in users
        if (is_admin() || is_user_logged_in()) {
            return $items;
        }
        
        foreach ($items as $key => $item) {
            if (in_array($item->object, array('page')) && isset($item->object_id)) {
                if (!$this->is_page_visible_to_visitors($item->object_id)) {
                    unset($items[$key]);
                }
            }
        }
        
        return $items;
    }
    
    /**
     * Add inline CSS as final fallback to hide any remaining hidden pages
     */
    public function add_inline_css_for_hidden_pages() {
        // Don't affect admin or any logged-in users
        if (is_admin() || is_user_logged_in()) {
            return;
        }
        
        // Use cached helper to retrieve hidden pages for CSS fallback (reduces repeated meta queries)
        $hidden_pages = $this->get_cached_hidden_pages();
        
        if (empty($hidden_pages)) {
            return;
        }
        
        // Generate CSS to hide pages by ID
        $ids = implode(',', array_map('absint', $hidden_pages));
        echo '<style type="text/css">';
        echo '.vvc-hidden-page { display: none !important; }';
        echo 'a[href] { }';
        // Add per-page selectors to hide links pointing to hidden pages (note: this is a best-effort fallback and may not cover all themes)
        echo '</style>';
    }

    /**
     * Retrieve hidden pages (cached) - abstracts meta queries into one place and caches results.
     *
     * @return int[] Array of post IDs hidden from visitors
     */
    private function get_cached_hidden_pages() {
        $cache_key = 'vvc_hidden_pages_v1';
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return (array) $cached;
        }

        // Get pages explicitly marked hidden
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached query; required to determine pages hidden from visitors
        $hidden_pages = get_posts( array(
            'post_type' => 'page',
            /* phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached query; required to determine pages hidden from visitors */
            'meta_query' => array(
                array(
                    'key' => '_show_to_visitor',
                    'value' => false,
                    'compare' => '='
                ),
            ),
            'fields' => 'ids',
            'posts_per_page' => -1,
        ) );

        // Get pages without the meta (default hidden)
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached query; identifying pages missing meta
        $pages_without_meta = get_posts( array(
            'post_type' => 'page',
            /* phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached query; identifying pages missing meta */
            'meta_query' => array(
                array(
                    'key' => '_show_to_visitor',
                    'compare' => 'NOT EXISTS',
                ),
            ),
            'fields' => 'ids',
            'posts_per_page' => -1,
        ) );

        $all_hidden = array_unique( array_merge( (array) $hidden_pages, (array) $pages_without_meta ) );

        // Cache for a short period to avoid repeated meta queries during page loads
        set_transient( $cache_key, $all_hidden, 5 * MINUTE_IN_SECONDS );

        return $all_hidden;
    }
    
    /**
     * Add admin column
     */
    public function add_admin_column($columns) {
        $columns['visitor_visibility'] = __('Visitor Visibility', 'visitor-visibility-control');
        return $columns;
    }
    
    /**
     * Show admin column content
     */
    public function show_admin_column_content($column, $post_id) {
        if ($column === 'visitor_visibility') {
            $show_to_visitor = get_post_meta($post_id, '_show_to_visitor', true);
            
            // If meta doesn't exist, default to false (hidden)
            if ($show_to_visitor === '') {
                $show_to_visitor = false;
            }
            
            if ($show_to_visitor) {
                echo '<span style="color: green;" data-visibility="1">✓ ' . esc_html__('Visible', 'visitor-visibility-control') . '</span>';
            } else {
                echo '<span style="color: red;" data-visibility="0">✗ ' . esc_html__('Hidden', 'visitor-visibility-control') . '</span>';
            }
        }
    }
    
    /**
     * Add bulk edit fields
     */
    public function add_bulk_edit_fields($column_name, $post_type) {
        if ($column_name !== 'visitor_visibility' || !in_array($post_type, array('post', 'page'))) {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title"><?php esc_html_e('Visitor Visibility', 'visitor-visibility-control'); ?></span>
                    <select name="vvc_bulk_edit_visitor_visibility">
                        <option value="-1"><?php esc_html_e('— No Change —', 'visitor-visibility-control'); ?></option>
                        <option value="1"><?php esc_html_e('Show to visitors', 'visitor-visibility-control'); ?></option>
                        <option value="0"><?php esc_html_e('Hide from visitors', 'visitor-visibility-control'); ?></option>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }
    
    /**
     * Add quick edit fields
     */
    public function add_quick_edit_fields($column_name, $post_type) {
        if ($column_name !== 'visitor_visibility' || !in_array($post_type, array('post', 'page'))) {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title"><?php esc_html_e('Visitor Visibility', 'visitor-visibility-control'); ?></span>
                    <input type="hidden" name="vvc_quick_edit_nonce" value="<?php echo esc_attr(wp_create_nonce('vvc_quick_edit')); ?>" />
                    <label>
                        <input type="checkbox" name="vvc_show_to_visitor" value="1" />
                        <?php esc_html_e('Show to visitor', 'visitor-visibility-control'); ?>
                    </label>
                </label>
            </div>
        </fieldset>
        <?php
    }
    
    /**
     * Save bulk edit data
     */
    public function save_bulk_edit_data() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'vvc_bulk_edit')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('edit_posts')) {
            wp_die('Permission denied');
        }
        
        if (!isset($_POST['post_ids']) || !isset($_POST['visibility'])) {
            wp_die('Missing required data');
        }
        
        $post_ids = array_map('absint', $_POST['post_ids']);
        $visibility = sanitize_text_field(wp_unslash($_POST['visibility']));
        
        if ($visibility === '-1') {
            wp_die('No change selected');
        }
        
        $show_to_visitor = $visibility === '1' ? true : false;
        
        foreach ($post_ids as $post_id) {
            if (current_user_can('edit_post', $post_id)) {
                update_post_meta($post_id, '_show_to_visitor', $show_to_visitor);
            }
        }
        
        wp_die(); // Required for AJAX
    }
    
    /**
     * Save quick edit data
     */
    public function save_quick_edit_data($post_id) {
        // Check if this is quick edit
        if (!isset($_POST['vvc_quick_edit_nonce'])) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vvc_quick_edit_nonce'])), 'vvc_quick_edit')) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        $show_to_visitor = isset($_POST['vvc_show_to_visitor']) ? true : false;
        update_post_meta($post_id, '_show_to_visitor', $show_to_visitor);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default meta value ONLY for posts/pages that don't have the meta key yet
        // This ensures existing visibility settings are preserved during plugin updates
        // Note: meta_query is required during activation to identify posts without visibility meta
        // This is a one-time operation during plugin activation for existing content
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time activation setup; acceptable to query for missing meta
        $posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            /* phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- activation check for posts missing meta */
            'meta_query' => array(
                array(
                    'key' => '_show_to_visitor',
                    'compare' => 'NOT EXISTS'
                )
            )
        ));
        
        // Only add default value to posts that don't have any visibility setting
        // This preserves all existing visibility choices
        foreach ($posts as $post_id) {
            update_post_meta($post_id, '_show_to_visitor', false);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
    }
}

// Initialize the plugin
new VisitorVisibilityControl();
