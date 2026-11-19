<?php
/** Conversation engine: creating and finding a visitor's live conversation. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

require_once RAPIDCHAT_DIR . '/main/engine/events.php';
require_once RAPIDCHAT_DIR . '/main/engine/routing.php';

function rapidchat_sanitize_context(array $context) {
	$clean = array();
	foreach (array('url', 'referrer') as $key) {
		$clean[$key] = isset($context[$key]) && is_string($context[$key]) ? esc_url_raw($context[$key]) : '';
	}
	foreach (array('title', 'browser', 'os', 'device', 'locale', 'timezone') as $key) {
		$clean[$key] = isset($context[$key]) && is_string($context[$key]) ? sanitize_text_field($context[$key]) : '';
	}

	return array(
		'url'      => $clean['url'],
		'title'    => $clean['title'],
		'referrer' => $clean['referrer'],
		'browser'  => $clean['browser'],
		'os'       => $clean['os'],
		'device'   => $clean['device'],
		'locale'   => $clean['locale'],
		'timezone' => $clean['timezone'],
	);
}

/** Return the visitor's open conversation, creating one if there is none. */
function rapidchat_conversation_find_or_create(array $args) {
	global $wpdb;

	$actor    = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;

	if ($actor_id <= 0) {
		return new WP_Error('rapidchat_invalid_request', __('Invalid request.', 'rapidchat'), array('status' => 400));
	}

	if (!rapidchat_rate_limit('find_' . $actor_id, 30, MINUTE_IN_SECONDS)) {
		return new WP_Error('rapidchat_rate_limited', __('Please slow down.', 'rapidchat'), array('status' => 429));
	}

	$wpdb->update(
		$wpdb->rapidchat_accounts,
		array('status' => 'Waiting', 'last_seen' => current_time('mysql')),
		array('id' => $actor_id),
		array('%s', '%s'),
		array('%d')
	);

	$force_new = !empty($args['force_new']);

	if ($force_new) {
		$wpdb->query($wpdb->prepare(
			'UPDATE %i' . "
			 SET state = 'closed', open_sender_id = NULL, closed_at = %s
			 WHERE account_sender_id = %d AND state = 'open'",
			$wpdb->rapidchat_conversations,
			current_time('mysql'),
			$actor_id
		));
	} else {
		$existing = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT id FROM %i
			 WHERE (account_sender_id = %d OR account_receiver_id = %d) AND state = %s
			 ORDER BY id DESC LIMIT 1',
			$wpdb->rapidchat_conversations,
			$actor_id,
			$actor_id,
			'open'
		));

		if ($existing) {
			return array('id' => $existing, 'created' => false);
		}
	}

	if (!empty($args['find_only'])) {
		return new WP_Error('rapidchat_no_conversation', __('No active conversation.', 'rapidchat'), array('status' => 404));
	}

	$team_id = (int) apply_filters('rapidchat_new_conversation_team', 0, isset($args['team_id']) ? (int) $args['team_id'] : 0, $args);

	$operator_id = rapidchat_pick_operator($team_id);
	if (!$operator_id) {
		return new WP_Error(
			'rapidchat_no_operators',
			__('No operators are configured. Please try again later.', 'rapidchat'),
			array('status' => 503)
		);
	}

	$custom_fields = array(
		'context' => rapidchat_sanitize_context(isset($args['context']) && is_array($args['context']) ? $args['context'] : array()),
		'tags'    => array(),
	);

	$country   = isset($actor['country']) ? (string) $actor['country'] : '';
	$hint_code = '';
	if ($country === '') {
		$hint_code = rapidchat_country_from_hint(array('timezone' => $custom_fields['context']['timezone']));
		if ($hint_code !== '') {
			$country = $hint_code;
		}
	}

	$now = current_time('mysql');

	$inserted = $wpdb->insert(
		$wpdb->rapidchat_conversations,
		array(
			'account_sender_id'   => $actor_id,
			'account_receiver_id' => $operator_id,
			'country'             => $country,
			'state'               => 'open',
			'open_sender_id'      => $actor_id,
			'team_id'             => $team_id,
			'custom_fields'       => wp_json_encode($custom_fields),
			'submit_date'         => $now,
			'last_activity'       => $now,
		),
		array('%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
	);

	if (!$inserted) {
		if (strpos((string) $wpdb->last_error, 'open_conversation_per_sender') !== false) {
			$raced = (int) $wpdb->get_var($wpdb->prepare(
				'SELECT id FROM %i
				 WHERE account_sender_id = %d AND state = %s ORDER BY id DESC LIMIT 1',
				$wpdb->rapidchat_conversations,
				$actor_id,
				'open'
			));

			if ($raced) {
				return array('id' => $raced, 'created' => false);
			}
		}

		return new WP_Error(
			'rapidchat_create_failed',
			__('Could not start a conversation. Please try again.', 'rapidchat'),
			array('status' => 500)
		);
	}

	$conversation_id = (int) $wpdb->insert_id;

	if ($hint_code !== '') {
		$wpdb->query($wpdb->prepare(
			'UPDATE %i' . "
			 SET country = %s WHERE id = %d AND (country IS NULL OR country = '')",
			$wpdb->rapidchat_accounts,
			$hint_code,
			$actor_id
		));
		rapidchat_event('customer_geolocated', $actor_id, $hint_code, 'hint');
	}

	$operator_ids = $wpdb->get_col($wpdb->prepare(
		"SELECT id FROM %i WHERE role IN ('Operator','Agent','Manager')",
		$wpdb->rapidchat_accounts
	));

	foreach ($operator_ids as $id) {
		$wpdb->query($wpdb->prepare(
			'INSERT IGNORE INTO %i
				(conversation_id, operator_id, is_available) VALUES (%d, %d, 1)',
			$wpdb->rapidchat_participants,
			$conversation_id,
			(int) $id
		));
	}

	$wpdb->insert(
		$wpdb->rapidchat_messages,
		array(
			'conversation_id' => $conversation_id,
			'account_id'      => $actor_id,
			'msg'             => sprintf(
				"Name: %s\nEmail: %s",
				isset($actor['full_name']) ? $actor['full_name'] : '',
				isset($actor['email']) ? $actor['email'] : ''
			),
			'submit_date'     => $now,
			'is_read'         => 0,
		),
		array('%d', '%d', '%s', '%s', '%d')
	);

	rapidchat_event('conversation_created', $conversation_id, $actor_id, (int) $operator_id);

	return array('id' => $conversation_id, 'created' => true);
}

function rapidchat_conversation_set_state($conversation_id, $state = 'closed') {
	global $wpdb;

	$wpdb->update(
		$wpdb->rapidchat_conversations,
		array('state' => $state, 'open_sender_id' => null, 'closed_at' => current_time('mysql')),
		array('id' => (int) $conversation_id),
		array('%s', '%s', '%s'),
		array('%d')
	);
}

/** Has a conversation the actor can see already been closed? */
function rapidchat_conversation_already_ended($conversation_id, array $actor) {
	$ctx = rapidchat_operator_context(isset($actor['id']) ? (int) $actor['id'] : 0, (int) $conversation_id);

	return $ctx && $ctx['state'] !== 'open' && rapidchat_operator_can('view', $conversation_id, $actor);
}

/** Close one conversation on an operator's instruction. */
function rapidchat_conversation_close($conversation_id, array $args) {
	$conversation_id = (int) $conversation_id;
	$actor           = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id        = isset($actor['id']) ? (int) $actor['id'] : 0;

	if ($conversation_id <= 0 || $actor_id <= 0) {
		return new WP_Error('rapidchat_invalid_request', __('Conversation not found.', 'rapidchat'), array('status' => 400));
	}

	if (rapidchat_conversation_already_ended($conversation_id, $actor)) {
		return new WP_Error('rapidchat_conversation_closed', __('This chat has already ended.', 'rapidchat'), array('status' => 409));
	}

	if (!rapidchat_operator_can('close', $conversation_id, $actor)) {
		return new WP_Error('rapidchat_forbidden', __('Only the agent handling this chat, or a manager, can end it.', 'rapidchat'), array('status' => 403));
	}

	rapidchat_conversation_set_state($conversation_id, 'closed');
	rapidchat_event('conversation_closed', $conversation_id, $actor_id);

	return array('id' => $conversation_id);
}

/** End a visitor's chat session: close every open conversation they started. */
function rapidchat_conversation_end_session(array $args) {
	global $wpdb;

	$actor    = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;

	if ($actor_id <= 0) {
		return new WP_Error('rapidchat_invalid_request', __('Invalid request.', 'rapidchat'), array('status' => 400));
	}

	$closed = (int) $wpdb->query($wpdb->prepare(
		'UPDATE %i' . "
		 SET state = 'closed', open_sender_id = NULL, closed_at = %s
		 WHERE account_sender_id = %d AND state = 'open'",
		$wpdb->rapidchat_conversations,
		current_time('mysql'),
		$actor_id
	));

	rapidchat_event('chat_ended', $actor_id);

	return array('closed' => $closed);
}

/** Record a visitor's CSAT rating and free-text feedback for a conversation. */
function rapidchat_conversation_rate(array $args) {
	global $wpdb;

	$conversation_id = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
	$actor           = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id        = isset($actor['id']) ? (int) $actor['id'] : 0;
	$rating          = isset($args['rating']) ? (int) $args['rating'] : 0;
	$feedback        = isset($args['feedback']) ? sanitize_textarea_field((string) $args['feedback']) : '';

	if ($conversation_id <= 0 || $actor_id <= 0 || $rating < 1) {
		return new WP_Error('rapidchat_invalid_rating', __('Rating is required.', 'rapidchat'), array('status' => 422));
	}

	$rating = min(5, $rating);

	if (!rapidchat_can_access_conversation($actor_id, $conversation_id)) {
		return new WP_Error('rapidchat_forbidden', __('Access denied.', 'rapidchat'), array('status' => 403));
	}

	$wpdb->update(
		$wpdb->rapidchat_conversations,
		array('rating' => $rating, 'feedback' => $feedback),
		array('id' => $conversation_id),
		array('%d', '%s'),
		array('%d')
	);

	rapidchat_event('conversation_rated', $conversation_id, $rating, $feedback);

	return array('id' => $conversation_id, 'rating' => $rating);
}

/** Update conversation tags. */
function rapidchat_conversation_update_tags(array $args) {
	global $wpdb;

	$conversation_id = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
	$actor           = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id        = isset($actor['id']) ? (int) $actor['id'] : 0;
	$tags            = isset($args['tags']) && is_array($args['tags']) ? array_values(array_unique(array_filter(array_map('sanitize_text_field', $args['tags'])))) : array();

	if ($conversation_id <= 0 || $actor_id <= 0) {
		return new WP_Error('rapidchat_invalid_request', __('Invalid request.', 'rapidchat'), array('status' => 400));
	}

	if (!rapidchat_operator_can('manage', $conversation_id, $actor)) {
		return new WP_Error('rapidchat_forbidden', __('Access denied.', 'rapidchat'), array('status' => 403));
	}

	$custom_fields_raw = $wpdb->get_var($wpdb->prepare(
		'SELECT custom_fields FROM %i WHERE id = %d',
		$wpdb->rapidchat_conversations,
		$conversation_id
	));

	$custom_fields = json_decode((string) $custom_fields_raw, true);
	if (!is_array($custom_fields)) {
		$custom_fields = array();
	}

	$custom_fields['tags'] = $tags;

	$wpdb->update(
		$wpdb->rapidchat_conversations,
		array('custom_fields' => wp_json_encode($custom_fields)),
		array('id' => $conversation_id),
		array('%s'),
		array('%d')
	);

	rapidchat_event('conversation_tags_updated', $conversation_id, $tags, $actor_id);

	return array('id' => $conversation_id, 'tags' => $tags);
}
