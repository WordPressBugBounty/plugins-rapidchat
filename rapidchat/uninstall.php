<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

if (get_option('rapidchat_db_version')) {
	$rapidchat_tables = array(
		'rapidchat_messages',
		'rapidchat_participants',
		'rapidchat_conversations',
		'rapidchat_accounts',
		'rapidchat_canned_responses',
	);

	foreach ($rapidchat_tables as $rapidchat_table) {
		$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $rapidchat_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}

$wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM %i
	  WHERE option_name LIKE %s
	     OR option_name LIKE %s
	     OR option_name LIKE %s",
	$wpdb->options,
	$wpdb->esc_like('rapidchat_') . '%',
	$wpdb->esc_like('_transient_rapidchat_') . '%',
	$wpdb->esc_like('_transient_timeout_rapidchat_') . '%'
));
wp_cache_delete('alloptions', 'options');
wp_cache_delete('notoptions', 'options');

$rapidchat_caps = array(
	'rapidchat_operate',
	'rapidchat_manage',
	'rapidchat_view',
	'rapidchat_reply',
	'rapidchat_assign',
	'rapidchat_manage_agents',
	'rapidchat_manage_teams',
	'rapidchat_manage_flows',
	'rapidchat_manage_settings',
	'rapidchat_view_analytics',
	'rapidchat_manage_integrations',
);

foreach (array_keys(wp_roles()->get_names()) as $rapidchat_role_slug) {
	$rapidchat_role = get_role($rapidchat_role_slug);
	if ($rapidchat_role) {
		foreach ($rapidchat_caps as $rapidchat_cap) {
			$rapidchat_role->remove_cap($rapidchat_cap);
		}
	}
}

wp_unschedule_hook('rapidchat_resolve_country');
