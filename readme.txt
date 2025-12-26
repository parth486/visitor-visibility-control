=== Visitor Visibility Control ===
Contributors: yourwpusername
Tags: visibility, content, pages, posts, navigation
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.0.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a checkbox in the editor to hide/show content for visitors and controls menu visibility.

== Description ==

A WordPress plugin that adds a "Show to visitor" checkbox in the Page/Post editor sidebar to control content visibility for non-logged-in users.

== Features ==

* **Editor Integration**: Adds a checkbox in both Gutenberg and Classic editor sidebars
* **Bulk Operations**: Change visibility for multiple posts/pages at once via bulk edit
* **Quick Edit**: Modify visibility directly from the post list with quick edit
* **Menu Control**: Automatically excludes hidden content from navigation menus
* **Frontend Protection**: Hidden content returns 404 for visitors
* **Hierarchy Support**: Parent page visibility affects child page accessibility
* **Admin Overview**: Shows visibility status in admin post/page lists
* **Default Hidden**: All content is hidden by default (checkbox unchecked)

== Requirements ==

* WordPress 6.0 or higher
* PHP 7.4 or higher
* Compatible with Gutenberg and Classic editors

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/visitor-visibility-control` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Start using the "Show to visitor" checkbox in your post/page editors.

== Usage ==

= In the Editor =

1. Edit any post or page
2. Look for "Visibility Settings" in the right sidebar
3. Toggle the "Show to visitor" checkbox
4. Save/update your content

= Behavior =

* **Unchecked (default)**: Content is hidden from visitors and excluded from frontend display, navigation menus, search results, and archive pages
* **Checked**: Content is visible to all visitors

= Admin Users =

Logged-in users with edit capabilities can always see all content regardless of the visibility setting.

== Technical Details ==

= Post Meta =

The plugin stores the visibility setting as post meta:
* **Key**: `_show_to_visitor`
* **Type**: Boolean
* **Default**: `false`

== Frequently Asked Questions ==

= Does this work with custom menus? =

Yes, the plugin works with both custom menus and automatically generated page menus.

= Can I change multiple posts at once? =

Yes, use the bulk edit feature to change visibility for multiple posts/pages simultaneously.

= What happens if a parent page is hidden but child page is visible? =

Child pages become inaccessible when their parent pages are hidden, ensuring logical navigation structure.

== Changelog ==

= 1.0.0 =
* Initial release
* Gutenberg and Classic editor support
* Bulk edit and quick edit functionality
* Parent-child page hierarchy support
* Menu integration
* Frontend protection
