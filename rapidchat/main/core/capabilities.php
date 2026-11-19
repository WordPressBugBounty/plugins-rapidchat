<?php
/** Capabilities. */

defined('ABSPATH') || exit;

/** Every capability the plugin defines, mapped to its human-readable purpose. */
function rapidchat_capabilities() {
	return array(
		'rapidchat_view'                => __('View conversations', 'rapidchat'),
		'rapidchat_reply'               => __('Reply to conversations', 'rapidchat'),
		'rapidchat_assign'              => __('Assign and transfer conversations', 'rapidchat'),
		'rapidchat_manage_agents'       => __('Manage agents', 'rapidchat'),
		'rapidchat_manage_teams'        => __('Manage teams', 'rapidchat'),
		'rapidchat_manage_flows'        => __('Manage chat flows', 'rapidchat'),
		'rapidchat_manage_settings'     => __('Manage settings', 'rapidchat'),
		'rapidchat_view_analytics'      => __('View analytics', 'rapidchat'),
		'rapidchat_manage_integrations' => __('Manage integrations', 'rapidchat'),
	);
}

function rapidchat_caps_for_base_cap($cap) {
	if ($cap === RAPIDCHAT_CAP_MANAGE) {
		return rapidchat_all_capability_names();
	}

	return array('rapidchat_view', 'rapidchat_reply', RAPIDCHAT_CAP_OPERATE);
}

function rapidchat_register_capabilities() {
	$admin = get_role('administrator');
	if ($admin) {
		foreach (rapidchat_caps_for_base_cap(RAPIDCHAT_CAP_MANAGE) as $cap) {
			$admin->add_cap($cap);
		}
	}

	do_action('rapidchat_register_capabilities');

	if (function_exists('rapidchat_load')) {
		rapidchat_load('auth');
	}
	if (function_exists('rapidchat_sync_all_account_roles')) {
		rapidchat_sync_all_account_roles();
	}
}

/** Every capability the plugin manages: the granular set plus the two umbrellas. */
function rapidchat_all_capability_names() {
	return array_merge(array_keys(rapidchat_capabilities()), array(RAPIDCHAT_CAP_OPERATE, RAPIDCHAT_CAP_MANAGE));
}

/** Remove every RapidChat capability from every role. */
function rapidchat_remove_capabilities() {
	$caps = rapidchat_all_capability_names();

	foreach (wp_roles()->get_names() as $slug => $unused_label) {
		$role = get_role($slug);
		if (!$role) {
			continue;
		}

		foreach ($caps as $cap) {
			$role->remove_cap($cap);
		}
	}
}
