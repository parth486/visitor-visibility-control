<?php
/**
 * Uninstall script for Visitor Visibility Control plugin
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Get all posts with the meta key and delete the meta
// Note: meta_key usage is necessary for cleanup - this only runs during plugin uninstall
// Performance impact is acceptable as this is a one-time operation during uninstall
// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup; acceptable to query by meta_key
$posts = get_posts(array(
    'post_type' => array('post', 'page'),
    'post_status' => 'any',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'meta_key' => '_show_to_visitor' // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup; acceptable to query by meta_key
));

foreach ($posts as $post_id) {
    delete_post_meta($post_id, '_show_to_visitor');
}

// Clear any cached data
wp_cache_flush();
