<?php
/** Message engine. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

require_once RAPIDCHAT_DIR . '/main/engine/events.php';
require_once RAPIDCHAT_DIR . '/main/engine/routing.php';

/** Persist a message. */
function rapidchat_message_create(array $args) {
	global $wpdb;

	$conversation_id = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
	$actor           = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id        = isset($actor['id']) ? (int) $actor['id'] : 0;
	$is_operator     = !empty($args['is_operator']);
	$msg_type        = isset($args['msg_type']) ? sanitize_key((string) $args['msg_type']) : 'message';
	if (!in_array($msg_type, array('message', 'note', 'system'), true)) {
		$msg_type = 'message';
	}

	if ($msg_type === 'note' && !$is_operator) {
		return new WP_Error(
			'rapidchat_forbidden',
			__('Only operators can create private notes.', 'rapidchat'),
			array('status' => 403)
		);
	}

	$message         = isset($args['msg']) ? sanitize_textarea_field((string) $args['msg']) : '';
	$attachment_url  = isset($args['attachment_url']) ? esc_url_raw((string) $args['attachment_url']) : '';
	$attachment_type = isset($args['attachment_type']) ? sanitize_key((string) $args['attachment_type']) : '';
	$attachment_name = isset($args['attachment_name']) ? sanitize_text_field((string) $args['attachment_name']) : '';
	$attachment_size = isset($args['attachment_size']) ? absint($args['attachment_size']) : 0;

	// Only files stored by rapidchat_attachment_store() may be attached; outside URLs would render in the inbox.
	$uploads    = wp_upload_dir(null, false);
	$upload_url = trailingslashit($uploads['baseurl']) . 'rapidchat/';
	if ($attachment_url !== '' && (strpos($attachment_url, $upload_url) !== 0 || strpos($attachment_url, '..') !== false)) {
		return new WP_Error(
			'rapidchat_invalid_attachment',
			__('That attachment is not valid. Please upload the file again.', 'rapidchat'),
			array('status' => 422)
		);
	}

	if ($attachment_url === '') {
		$attachment_type = '';
		$attachment_name = '';
		$attachment_size = 0;
	} else {
		$filetype        = wp_check_filetype(wp_basename((string) wp_parse_url($attachment_url, PHP_URL_PATH)));
		$attachment_type = strpos((string) $filetype['type'], 'image/') === 0 ? 'image' : 'file';
	}

	if ($conversation_id <= 0 || $actor_id <= 0) {
		return new WP_Error(
			'rapidchat_invalid_request',
			__('Message text or attachment is required!', 'rapidchat'),
			array('status' => 422)
		);
	}

	if ($message === '' && $attachment_url === '') {
		return new WP_Error(
			'rapidchat_empty_message',
			__('Message text or attachment is required!', 'rapidchat'),
			array('status' => 422)
		);
	}

	if (mb_strlen($message) > RAPIDCHAT_MAX_MESSAGE) {
		return new WP_Error(
			'rapidchat_message_too_long',
			__('That message is too long.', 'rapidchat'),
			array('status' => 422)
		);
	}

	if (!$is_operator && !rapidchat_rate_limit('msg_' . $actor_id, 30, MINUTE_IN_SECONDS)) {
		return new WP_Error(
			'rapidchat_rate_limited',
			__('You are sending messages too quickly.', 'rapidchat'),
			array('status' => 429)
		);
	}

	if (!rapidchat_can_access_conversation($actor_id, $conversation_id, $is_operator)) {
		return new WP_Error(
			'rapidchat_forbidden',
			__('Invalid conversation. You are not a participant.', 'rapidchat'),
			array('status' => 403)
		);
	}

	$state = $wpdb->get_var($wpdb->prepare(
		'SELECT state FROM %i WHERE id = %d',
		$wpdb->rapidchat_conversations,
		$conversation_id
	));

	if ($state !== 'open') {
		return new WP_Error(
			'rapidchat_conversation_closed',
			__('This chat session has ended.', 'rapidchat'),
			array('status' => 422)
		);
	}

	if ($is_operator) {
		if (!rapidchat_operator_can($msg_type === 'note' ? 'note' : 'reply', $conversation_id, $actor)) {
			return new WP_Error(
				'rapidchat_forbidden',
				__('Another agent is handling this chat. Join or take it over to reply.', 'rapidchat'),
				array('status' => 403)
			);
		}

		$context = ($msg_type !== 'note') ? rapidchat_operator_context($actor_id, $conversation_id) : null;
		if ($context && $context['holder_id'] === 0) {
			if (!rapidchat_claim_if_unclaimed($conversation_id, $actor_id)) {
				return new WP_Error(
					'rapidchat_already_claimed',
					__('Another agent has just accepted this chat.', 'rapidchat'),
					array('status' => 409)
				);
			}
			rapidchat_event('operator_assigned', $conversation_id, $actor_id);
		}
	}

	/** Lets integrations rewrite (e.g. */
	$message = rapidchat_filter('before_save_message', $message, $conversation_id, $actor_id);

	$result = $wpdb->insert(
		$wpdb->rapidchat_messages,
		array(
			'conversation_id' => $conversation_id,
			'account_id'      => $actor_id,
			'msg'             => $message,
			'attachment_url'  => $attachment_url,
			'attachment_type' => $attachment_type,
			'attachment_name' => $attachment_name,
			'attachment_size' => $attachment_size,
			'msg_type'        => $msg_type,
			'submit_date'     => current_time('mysql'),
			'is_read'         => ($msg_type === 'note' ? 1 : 0),
		),
		array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d')
	);

	if ($result === false) {
		return new WP_Error(
			'rapidchat_send_failed',
			__('Failed to submit the message.', 'rapidchat'),
			array('status' => 500)
		);
	}

	$message_id = (int) $wpdb->insert_id;
	rapidchat_touch_conversation($conversation_id);

	rapidchat_event('after_save_message', $message_id, $conversation_id, $actor_id);

	return array(
		'id'              => $message_id,
		'msg'             => $message,
		'msg_type'        => $msg_type,
		'conversation_id' => $conversation_id,
	);
}

function rapidchat_system_message($conversation_id, $text) {
	global $wpdb;

	$conversation_id = (int) $conversation_id;
	$text            = sanitize_text_field((string) $text);

	if ($conversation_id <= 0 || $text === '') {
		return new WP_Error('rapidchat_invalid_request', __('Invalid request.', 'rapidchat'), array('status' => 400));
	}

	$result = $wpdb->insert(
		$wpdb->rapidchat_messages,
		array(
			'conversation_id' => $conversation_id,
			'account_id'      => 0,
			'msg'             => $text,
			'msg_type'        => 'system',
			'submit_date'     => current_time('mysql'),
			'is_read'         => 1,
		),
		array('%d', '%d', '%s', '%s', '%s', '%d')
	);

	if ($result === false) {
		return new WP_Error('rapidchat_send_failed', __('Failed to submit the message.', 'rapidchat'), array('status' => 500));
	}

	$message_id = (int) $wpdb->insert_id;
	rapidchat_touch_conversation($conversation_id);
	rapidchat_event('after_save_message', $message_id, $conversation_id, 0);

	return array('id' => $message_id, 'conversation_id' => $conversation_id);
}
