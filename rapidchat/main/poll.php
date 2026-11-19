<?php
/** Delta polling. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

add_action('wp_ajax_rapidchat_poll', 'rapidchat_poll');
add_action('wp_ajax_nopriv_rapidchat_poll', 'rapidchat_poll');
function rapidchat_poll() {
	$account = rapidchat_require_guest();

	wp_send_json_success(rapidchat_build_poll_response($account, false, rapidchat_poll_params_from_post('rapidchat_user_nonce')));
}

add_action('wp_ajax_rapidchat_admin_poll', 'rapidchat_admin_poll');
function rapidchat_admin_poll() {
	$account = rapidchat_require_operator();

	wp_send_json_success(rapidchat_build_poll_response($account, true, rapidchat_poll_params_from_post('rapidchat_admin_nonce')));
}

/** Poll parameters from an AJAX request. */
function rapidchat_poll_params_from_post($nonce_action) {
	check_ajax_referer($nonce_action, 'rapidchat_nonce');

	return array(
		'conversation_id' => isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0,
		'since_id'        => isset($_POST['since_id']) ? (int) $_POST['since_id'] : 0,
		'fingerprint'     => isset($_POST['fingerprint']) ? sanitize_text_field(wp_unslash($_POST['fingerprint'])) : '',
		'filter'          => isset($_POST['filter']) ? sanitize_text_field(wp_unslash($_POST['filter'])) : 'all',
		'typing'          => !empty($_POST['typing']),
		'peek'            => isset($_POST['peek']) ? sanitize_text_field(wp_unslash($_POST['peek'])) : '',
	);
}

function rapidchat_build_poll_response(array $account, $is_operator, array $params = array()) {
	global $wpdb;

	$account_id      = (int) $account['id'];
	$conversation_id = isset($params['conversation_id']) ? (int) $params['conversation_id'] : 0;
	$since_id        = isset($params['since_id']) ? (int) $params['since_id'] : 0;
	$client_fp       = isset($params['fingerprint']) ? sanitize_text_field((string) $params['fingerprint']) : '';
	$filter          = isset($params['filter']) ? sanitize_text_field((string) $params['filter']) : 'all';
	$is_typing       = !empty($params['typing']);
	$peek            = isset($params['peek']) ? sanitize_text_field((string) $params['peek']) : '';

	// Typing state is only written or read once the caller is confirmed to belong to the conversation.
	$can_access = $conversation_id > 0 && rapidchat_can_access_conversation($account_id, $conversation_id, $is_operator);

	if ($is_typing && $can_access) {
		rapidchat_set_typing($conversation_id, $account_id, $peek);
	}

	rapidchat_heartbeat($account, $is_operator);

	$typing_info = $can_access
		? rapidchat_check_typing($conversation_id, $account_id)
		: array('typing' => false, 'name' => '', 'peek' => '');

	if (!$is_operator && $typing_info['typing']) {
		$typing_info['name'] = rapidchat_visitor_agent_name($typing_info['name']);
	}

	$response = array(
		'messages'         => array(),
		'last_id'          => $since_id,
		'list'             => null,
		'fingerprint'      => '',
		'state'            => '',
		'read_through'     => 0,
		'typing'           => $typing_info['typing'],
		'typing_user'      => $typing_info['name'],
		'sneak_peek'       => $is_operator ? (!empty($typing_info['peek']) ? $typing_info['peek'] : '') : '',
		'operators_online' => $is_operator ? true : (rapidchat_operators_online_count() > 0),
		'next_delay'       => 30000,
	);

	$latest_activity = null;

	if ($can_access) {
		$conversation = $wpdb->get_row($wpdb->prepare(
			'SELECT id, state, last_activity, account_sender_id FROM %i WHERE id = %d',
			$wpdb->rapidchat_conversations,
			$conversation_id
		));

		if ($conversation) {
			$response['state']        = $conversation->state;
			$response['read_through'] = rapidchat_conversation_read_through($conversation_id, $account_id);
			$latest_activity          = $conversation->last_activity;

			if ($is_operator) {
				$messages = $wpdb->get_results($wpdb->prepare(
					'SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, msg_type, submit_date
					 FROM %i
					 WHERE conversation_id = %d AND id > %d
					 ORDER BY id ASC LIMIT 200',
					$wpdb->rapidchat_messages,
					$conversation_id,
					$since_id
				));
			} else {
				$messages = $wpdb->get_results($wpdb->prepare(
					'SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, msg_type, submit_date
					 FROM %i' . "
					 WHERE conversation_id = %d AND id > %d AND (msg_type IS NULL OR msg_type NOT IN ('note', 'system'))
					 ORDER BY id ASC LIMIT 200",
					$wpdb->rapidchat_messages,
					$conversation_id,
					$since_id
				));
			}

			if ($messages) {
				$names        = rapidchat_account_names(wp_list_pluck($messages, 'account_id'));
				if (!$is_operator) {
					$names = rapidchat_names_for_visitor($names, $account_id);
				}
				$operator_ids = $is_operator ? array_map('intval', array_keys(rapidchat_conversation_assignments($conversation_id))) : array();

				foreach ($messages as $message) {
					$row                  = rapidchat_format_message($message, $names, $account_id, $operator_ids);
					$response['messages'][] = $row;
					$response['last_id']  = max($response['last_id'], $row['id']);
				}

				$wpdb->query($wpdb->prepare(
					'UPDATE %i
					 SET is_read = 1 WHERE conversation_id = %d AND account_id <> %d AND is_read = 0',
					$wpdb->rapidchat_messages,
					$conversation_id,
					$account_id
				));
			}
		}
	}

	$fingerprint             = rapidchat_list_fingerprint($account_id, $is_operator, $filter);
	$response['fingerprint'] = $fingerprint;

	if ($fingerprint !== $client_fp) {
		$response['list'] = $is_operator
			? rapidchat_render_admin_conversations($account, $filter)
			: rapidchat_render_guest_conversations($account_id);
	}

	$response['next_delay'] = rapidchat_next_delay($latest_activity, !empty($response['messages']));

	return $response;
}

/** Cheap change detector for the conversation list. */
function rapidchat_list_fingerprint($account_id, $is_operator, $filter = 'all') {
	global $wpdb;

	if ($is_operator) {
		$visibility = rapidchat_operator_visibility($account_id);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $visibility['sql'] is literal SQL with placeholders only; every value is bound by prepare().
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS convos,
					COALESCE(MAX(c.id), 0) AS max_id,
					COALESCE(MAX(c.last_activity), '') AS latest,
					COALESCE(SUM(c.state = 'open'), 0) AS open_count,
					COALESCE(SUM((SELECT COUNT(*) FROM %i um WHERE um.conversation_id = c.id AND um.is_read = 0)), 0) AS unread,
					COALESCE(SUM((SELECT COALESCE(SUM(pp.operator_id * 4 + pp.is_available), 0) FROM %i pp
								   WHERE pp.conversation_id = c.id AND pp.is_available IN (0, 3))), 0) AS assignments
			 FROM %i c
			 WHERE 1=1" . $visibility['sql'],
			array_merge(array($wpdb->rapidchat_messages, $wpdb->rapidchat_participants, $wpdb->rapidchat_conversations), $visibility['args'])
		), ARRAY_A);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	} else {
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS convos,
					COALESCE(MAX(c.id), 0) AS max_id,
					COALESCE(MAX(c.last_activity), '') AS latest,
					COALESCE(SUM(c.state = 'open'), 0) AS open_count,
					COALESCE(SUM((SELECT COUNT(*) FROM %i um WHERE um.conversation_id = c.id AND um.is_read = 0)), 0) AS unread
			 FROM %i c
			 WHERE c.account_sender_id = %d OR c.account_receiver_id = %d",
			$wpdb->rapidchat_messages,
			$wpdb->rapidchat_conversations,
			(int) $account_id,
			(int) $account_id
		), ARRAY_A);
	}

	if (!$row) {
		return '';
	}

	return substr(md5(implode('|', $row) . '|' . $filter), 0, 16);
}

/** Server-driven backoff: fast while a conversation is live, slow once it goes quiet. */
function rapidchat_next_delay($last_activity, $had_messages) {
	if ($had_messages) {
		return 2000;
	}

	if (!$last_activity) {
		return 15000;
	}

	$idle = time() - (int) mysql2date('U', $last_activity);

	if ($idle < 30) {
		return 2000;
	}
	if ($idle < 2 * MINUTE_IN_SECONDS) {
		return 5000;
	}
	if ($idle < 10 * MINUTE_IN_SECONDS) {
		return 15000;
	}

	return 30000;
}

/** Presence, written at most once a minute rather than on every tick. */
function rapidchat_heartbeat(array $account, $is_operator) {
	global $wpdb;

	$last_seen = !empty($account['last_seen']) ? (int) mysql2date('U', $account['last_seen']) : 0;

	if ((time() - $last_seen) < MINUTE_IN_SECONDS) {
		return;
	}

	$data = array('last_seen' => current_time('mysql'));
	if ($is_operator) {
		$data['status'] = 'Waiting';
	}

	$wpdb->update(
		$wpdb->rapidchat_accounts,
		$data,
		array('id' => (int) $account['id']),
		array_fill(0, count($data), '%s'),
		array('%d')
	);
}
