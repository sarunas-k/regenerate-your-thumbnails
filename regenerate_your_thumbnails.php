<?php
/**
 * Plugin Name: Regenerate Your Thumbnails
 * Description: You add new image size to Wordpress but can't use it with old images?
 * 							You can have all uploaded images with all registered image sizes by regenerating them.
 * Author: Šar
 * Version: 1.0.0
 * Author URI:
 * Text Domain: regenerate-your-thumbnails
 * Domain Path: /
 * Requires at least: 6.4
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'We\'re sorry, but you can not directly access this file.' );
}

if (!function_exists('wp_crop_image')) {
	include (ABSPATH . 'wp-admin/includes/image.php');
}

// Hook up the plugin.
register_activation_hook(__FILE__, 'regen_activate_plugin');


// Load regeneration function and show result as admin notice.
function regen_activate_plugin() {
	$result = regen_load();
	if (array_key_exists('error', $result)) {
		update_option('regenerate-plugin-notice', '<div class="notice notice-warning is-dismissible"><p>No images uploaded found.</p></div>');
	} else {
		update_option('regenerate-plugin-notice', '<div class="notice notice-success is-dismissible"><p>' . $result['created'] . ' images updated with current image sizes. Plugin is now deactivated.</p></div>');
	}
}

// Remove plugin from active plugins and plugin options from database
function regen_deactivate_plugin() {
	$plugins = get_option('active_plugins');
	$regenerate = plugin_basename(ABSPATH . '/wp-content/plugins/regenerate-your-thumbnails/regenerate_your_thumbnails.php');
	$update = false;
	foreach ($plugins as $i => $plugin) {
		if ($plugin === $regenerate) {
			$plugins[$i] = false;
			$update = true;
		}
	}

	if ($update) {
		update_option('active_plugins', array_filter($plugins));
	}

	delete_option('regenerate-plugin-notice');
}

// Main function for creating thumbnails
function regen_load() {

	$total_found = 0;
	$created_count = 0;

	// Find all image attachments
	$images = new WP_Query(
		array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => -1,
			array('key' => 'post_mime_type', 'operator' => 'LIKE', 'value' => 'image')
		)
	);

	// Stop if there are no images
	if (!$images->have_posts())
		return ['error' => 'There are no images uploaded'];

	$total_found = $images->post_count;

	// Main loop for all images
	while ($images->have_posts()) {
		$images->the_post();
		$imageId = get_the_ID();
		$meta = wp_get_attachment_metadata($imageId);
		$file = wp_get_original_image_path($imageId);

		if ($meta) {
			// Remove intermediate and backup images if there are any.
			regen_remove_all_size_variations($file, $meta);
			wp_update_attachment_metadata($imageId, $meta);
		}

		// Create image variations
		$created = regen_recreate_image_variations($imageId, $file);
		// Add 1 to created images count
		if ($created)
			$created_count++;
	}
	$images->wp_reset_postdata();
	return ['found' => $total_found, 'created' => $created_count];

}

// Create image variations with all registered sizes
function regen_recreate_image_variations($imageId, $filePath) {
	if ($filePath && file_exists($filePath)) {
		wp_generate_attachment_metadata($imageId, $filePath);
		return true;
	}
	return false;
}

// Delete previously created sizes variations
function regen_remove_all_size_variations($originalPath, $meta) {
	if (!isset($meta['sizes']) || !is_array($meta['sizes']))
		return;

	$intermediate_dir = path_join(wp_get_upload_dir()['basedir'], dirname($originalPath));

	foreach ($meta['sizes'] as $size => $sizeinfo) {
		$intermediate_file = str_replace(wp_basename($originalPath), $sizeinfo['file'], $originalPath);

		if (!empty($intermediate_file)) {
			$intermediate_file = path_join(wp_get_upload_dir()['basedir'], $intermediate_file);

			wp_delete_file_from_directory($intermediate_file, $intermediate_dir);

		}
	}
	$meta['sizes'] = [];
}

// Hook up admin notice and deactivate the plugin
function regen_show_plugin_notice_deactivate() {
	if ($notice = get_option('regenerate-plugin-notice')) {
		echo $notice;
	}
	regen_deactivate_plugin();
}

add_action('admin_notices', 'regen_show_plugin_notice_deactivate');