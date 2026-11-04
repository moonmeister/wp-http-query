<?php
/**
 * Removes the plugin's data on uninstall.
 *
 * The plugin stores one option, the enabled presets. Deactivation leaves it in
 * place; uninstalling deletes it. On multisite, uninstall runs once for the
 * whole network while each site keeps its own copy of the option, so every
 * site is cleaned up, in batches so a large network isn't loaded into memory.
 *
 * @package WPHttpQuery
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! is_multisite() ) {
	delete_option( 'wp_http_query_presets' );
	return;
}

$wp_http_query_batch  = 100;
$wp_http_query_offset = 0;

do {
	$wp_http_query_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => $wp_http_query_batch,
			'offset'                 => $wp_http_query_offset,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $wp_http_query_site_ids as $wp_http_query_site_id ) {
		delete_blog_option( (int) $wp_http_query_site_id, 'wp_http_query_presets' );
	}

	$wp_http_query_offset += $wp_http_query_batch;
	$wp_http_query_full    = count( $wp_http_query_site_ids ) === $wp_http_query_batch;
} while ( $wp_http_query_full );
