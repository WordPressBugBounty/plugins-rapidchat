<?php
/** Thread engine: rendering a conversation's message history for one viewer. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

require_once RAPIDCHAT_DIR . '/main/engine/events.php';

/** Build the visitor-side view of a conversation: its messages plus the composer. */
function rapidchat_thread_view(array $args) {
	global $wpdb;

	$conversation_id = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
	$actor           = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id        = isset($actor['id']) ? (int) $actor['id'] : 0;

	if ($conversation_id <= 0 || $actor_id <= 0) {
		return new WP_Error('rapidchat_invalid_request', __('Conversation not found.', 'rapidchat'), array('status' => 400));
	}

	if (!rapidchat_can_access_conversation($actor_id, $conversation_id)) {
		return new WP_Error('rapidchat_forbidden', __('Conversation not found.', 'rapidchat'), array('status' => 403));
	}

	$conversation = $wpdb->get_row($wpdb->prepare(
		'SELECT * FROM %i WHERE id = %d',
		$wpdb->rapidchat_conversations,
		$conversation_id
	));

	$wpdb->update(
		$wpdb->rapidchat_accounts,
		array('status' => 'Occupied', 'last_seen' => current_time('mysql')),
		array('id' => $actor_id),
		array('%s', '%s'),
		array('%d')
	);

	$wpdb->query($wpdb->prepare(
		'UPDATE %i
		 SET is_read = 1 WHERE conversation_id = %d AND account_id <> %d AND is_read = 0',
		$wpdb->rapidchat_messages,
		$conversation_id,
		$actor_id
	));

	$messages = $wpdb->get_results($wpdb->prepare(
		"SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, msg_type, submit_date
		 FROM %i
		 WHERE conversation_id = %d AND (msg_type IS NULL OR msg_type NOT IN ('note', 'system')) ORDER BY id ASC LIMIT 500",
		$wpdb->rapidchat_messages,
		$conversation_id
	));

	$names        = rapidchat_account_names(wp_list_pluck($messages, 'account_id'));
	$state        = $conversation ? $conversation->state : 'closed';
	$is_open      = ($state === 'open');
	$read_through = rapidchat_conversation_read_through($conversation_id, $actor_id);

	$html  = rapidchat_render_messages($messages, $names, $actor_id, array(), 'guest', $read_through);
	$html .= rapidchat_render_composer($conversation_id, $is_open);

	return array('html' => $html, 'state' => $state, 'read_through' => $read_through);
}
