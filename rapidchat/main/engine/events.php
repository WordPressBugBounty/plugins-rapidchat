<?php
/** Event dispatcher. */

defined('ABSPATH') || exit;

function rapidchat_transport() {
	if (defined('REST_REQUEST') && REST_REQUEST) {
		return 'rest';
	}
	if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
		return 'ajax';
	}
	if (function_exists('wp_doing_cron') && wp_doing_cron()) {
		return 'cron';
	}
	if (defined('WP_CLI') && WP_CLI) {
		return 'cli';
	}
	if (function_exists('is_admin') && is_admin()) {
		return 'admin';
	}
	return 'web';
}

/** Fire a domain event. */
function rapidchat_event($name, ...$args) {
	$args[] = rapidchat_transport();

	do_action('rapidchat_' . $name, ...$args);
}

/** Filter a value under the `rapidchat_` prefix. */
function rapidchat_filter($name, $value, ...$args) {
	return apply_filters('rapidchat_' . $name, $value, ...$args);
}
