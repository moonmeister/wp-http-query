<?php
/**
 * Removes the plugin's data on uninstall.
 *
 * The plugin stores one option, the enabled presets. Deactivation leaves it in
 * place; uninstalling deletes it. On multisite this deletes it for the current
 * site only.
 *
 * @package WPHttpQuery
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wp_http_query_presets' );
