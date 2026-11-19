<?php
/** Operator-facing AJAX handlers. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// phpcs:disable WordPress.Security.NonceVerification.Missing

add_action('wp_ajax_rapidchat_admin_login_user', 'rapidchat_admin_login_user');
function rapidchat_admin_login_user() {
	$account = rapidchat_require_operator();

	wp_send_json_success(array(
		'status'     => 'success',
		'full_name'  => $account['full_name'],
		'role'       => $account['role'],
		'can_manage' => current_user_can(RAPIDCHAT_CAP_MANAGE),
		'can_assign' => rapidchat_user_can('rapidchat_assign'),
	));
}

add_action('wp_ajax_rapidchat_admin_conversations_user', 'rapidchat_admin_conversations_user');
function rapidchat_admin_conversations_user() {
	global $wpdb;

	$account = rapidchat_require_operator();

	$wpdb->update(
		$wpdb->rapidchat_accounts,
		array('status' => 'Waiting', 'last_seen' => current_time('mysql')),
		array('id' => (int) $account['id']),
		array('%s', '%s'),
		array('%d')
	);

	$filter = isset($_POST['filter']) ? sanitize_text_field(wp_unslash($_POST['filter'])) : 'all';

	echo rapidchat_kses(rapidchat_render_admin_conversations($account, $filter));
	wp_die();
}

/** Fill team_name / team_colour on conversation rows (arrays or objects) from the rapidchat_team_labels filter. */
function rapidchat_add_team_labels($rows) {
	$team_ids = array();
	foreach ($rows as $row) {
		$team_ids[] = (int) (is_object($row) ? $row->team_id : $row['team_id']);
	}
	$team_ids = array_values(array_filter(array_unique($team_ids)));
	$labels   = $team_ids ? (array) apply_filters('rapidchat_team_labels', array(), $team_ids) : array();

	foreach ($rows as $key => $row) {
		$team_id = (int) (is_object($row) ? $row->team_id : $row['team_id']);
		$name    = isset($labels[$team_id]['name']) ? (string) $labels[$team_id]['name'] : '';
		$colour  = isset($labels[$team_id]['colour']) ? (string) $labels[$team_id]['colour'] : '';
		if (is_object($row)) {
			$row->team_name   = $name;
			$row->team_colour = $colour;
		} else {
			$rows[$key]['team_name']   = $name;
			$rows[$key]['team_colour'] = $colour;
		}
	}

	return $rows;
}

/** One row per conversation, filtered and ordered in the database. */
function rapidchat_admin_conversations($operator_id, $limit = RAPIDCHAT_LIST_LIMIT, $filter = 'all') {
	global $wpdb;

	$visibility = rapidchat_operator_visibility($operator_id);

	$filters = array(
		'all'    => array('', array()),
		'closed' => array(" AND c.state = 'closed'", array()),
		'mine'   => array(" AND c.state = 'open' AND EXISTS (SELECT 1 FROM %i cp_mine WHERE cp_mine.conversation_id = c.id AND cp_mine.operator_id = %d AND cp_mine.is_available IN (0, 3))", array($wpdb->rapidchat_participants, (int) $operator_id)),
		'queued' => array(" AND c.state = 'open' AND NOT EXISTS (SELECT 1 FROM %i cp_claimed WHERE cp_claimed.conversation_id = c.id AND cp_claimed.is_available = 0)", array($wpdb->rapidchat_participants)),
		'unread' => array(' AND (SELECT COUNT(*) FROM %i um WHERE um.conversation_id = c.id AND um.is_read = 0 AND um.account_id = c.account_sender_id) > 0', array($wpdb->rapidchat_messages)),
	);
	list($filter_sql, $filter_args) = isset($filters[$filter]) ? $filters[$filter] : $filters['all'];

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $visibility['sql'] and $filter_sql are literal SQL with placeholders only; every value is bound by prepare().
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.id, c.account_sender_id, c.account_receiver_id, c.submit_date, c.last_activity, c.state,
					c.team_id, c.custom_fields, c.rating, c.feedback, c.closed_at,
					a.full_name  AS sender_name,   a.country  AS sender_country,
					a2.full_name AS receiver_name, a2.country AS receiver_country,
					lm.msg AS msg, lm.submit_date AS msg_date, lm.msg_type AS msg_type,
					CASE
						WHEN EXISTS (SELECT 1 FROM %i cp_mine WHERE cp_mine.conversation_id = c.id AND cp_mine.operator_id = %d AND cp_mine.is_available = 0) THEN 0
						WHEN EXISTS (SELECT 1 FROM %i cp_join WHERE cp_join.conversation_id = c.id AND cp_join.operator_id = %d AND cp_join.is_available = 3) THEN 3
						WHEN EXISTS (SELECT 1 FROM %i cp_other WHERE cp_other.conversation_id = c.id AND cp_other.is_available = 0) THEN 2
						ELSE 1
					END AS is_available,
					(SELECT COUNT(*) FROM %i um
					  WHERE um.conversation_id = c.id AND um.is_read = 0
						AND um.account_id = c.account_sender_id) AS unread_count,
					(SELECT a3.full_name
					   FROM %i cp3
					   JOIN %i a3 ON a3.id = cp3.operator_id
					  WHERE cp3.conversation_id = c.id AND cp3.is_available = 0
					  LIMIT 1) AS assigned_name,
					(SELECT cp4.operator_id
					   FROM %i cp4
					  WHERE cp4.conversation_id = c.id AND cp4.is_available = 0
					  LIMIT 1) AS assigned_id
			 FROM %i c
			 JOIN %i a  ON a.id  = c.account_sender_id
			 JOIN %i a2 ON a2.id = c.account_receiver_id
			 LEFT JOIN %i lm
					ON lm.id = (SELECT MAX(m2.id) FROM %i m2 WHERE m2.conversation_id = c.id)
			 WHERE 1=1" . $visibility['sql'] . $filter_sql . '
			 ORDER BY COALESCE(c.last_activity, c.submit_date) DESC
			 LIMIT %d',
			array_merge(
				array(
					$wpdb->rapidchat_participants,
					(int) $operator_id,
					$wpdb->rapidchat_participants,
					(int) $operator_id,
					$wpdb->rapidchat_participants,
					$wpdb->rapidchat_messages,
					$wpdb->rapidchat_participants,
					$wpdb->rapidchat_accounts,
					$wpdb->rapidchat_participants,
					$wpdb->rapidchat_conversations,
					$wpdb->rapidchat_accounts,
					$wpdb->rapidchat_accounts,
					$wpdb->rapidchat_messages,
					$wpdb->rapidchat_messages,
				),
				$visibility['args'],
				$filter_args,
				array((int) $limit)
			)
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	return rapidchat_add_team_labels((array) $rows);
}

function rapidchat_render_admin_conversations(array $account, $filter = 'all') {
	$operator_id   = (int) $account['id'];
	$conversations = rapidchat_admin_conversations($operator_id, RAPIDCHAT_LIST_LIMIT, $filter);
	$can_queue     = rapidchat_user_can('rapidchat_reply');
	$can_assign    = rapidchat_user_can('rapidchat_assign');

	$output  = '<div class="chat-widget-conversations">';
	$output .= '<div class="chat-widget-scrollable">';

	if (empty($conversations)) {
		$empty_label = __('No conversations found.', 'rapidchat');
		if ($filter === 'unread') {
			$empty_label = __('No unread conversations.', 'rapidchat');
		} elseif ($filter === 'mine') {
			$empty_label = __('No active conversations assigned to you.', 'rapidchat');
		} elseif ($filter === 'queued') {
			$empty_label = __('No queued conversations waiting.', 'rapidchat');
		} elseif ($filter === 'closed') {
			$empty_label = __('No closed conversations.', 'rapidchat');
		}
		$output .= '<div class="rc-no-conversations"><p>' . esc_html($empty_label) . '</p></div>';
		$output .= '</div></div>';
		return $output;
	}

	$unread_items = '';
	$mine         = '';
	$queued       = '';
	$others       = '';
	$closed       = '';

	foreach ($conversations as $conversation) {
		$is_mine      = ((int) $conversation['account_sender_id'] === $operator_id);
		$contact_name = $is_mine ? $conversation['receiver_name'] : $conversation['sender_name'];
		$contact_id   = $is_mine ? $conversation['account_receiver_id'] : $conversation['account_sender_id'];
		$country      = $is_mine ? $conversation['receiver_country'] : $conversation['sender_country'];
		$unread       = (int) $conversation['unread_count'];

		$is_closed   = ($conversation['state'] !== 'open');
		$assigned_id = (int) $conversation['assigned_id'];
		$is_joined   = ((int) $conversation['is_available'] === 3);
		$mine_now    = ($assigned_id === $operator_id || (int) $conversation['is_available'] === 0 || $is_joined);
		$is_queued   = (!$assigned_id && !$is_closed && ((int) $conversation['is_available'] === 1 || empty($conversation['assigned_id'])));

		$custom_fields = json_decode((string) $conversation['custom_fields'], true);
		$tags          = (is_array($custom_fields) && !empty($custom_fields['tags'])) ? (array) $custom_fields['tags'] : array();
		$tags_attr     = esc_attr(implode(' ', array_map('strtolower', $tags)));

		$item  = '<div class="chat-widget-conversation' . ($unread ? ' new-message' : '')
			. ($is_closed ? ' rc-row-closed' : '') . '"'
			. ' data-id="' . esc_attr($conversation['id']) . '"'
			. ' data-state="' . esc_attr($conversation['state']) . '"'
			. ' data-is-closed="' . ($is_closed ? '1' : '0') . '"'
			. ' data-mine="' . ($mine_now ? '1' : '0') . '"'
			. ' data-queued="' . ($is_queued ? '1' : '0') . '"'
			. ' data-unread="' . esc_attr($unread) . '"'
			. ' data-tags="' . $tags_attr . '"'
			. ' data-name="' . esc_attr($contact_name) . '">';
		$item .= '<div class="icon">' . rapidchat_flag($country) . '</div>';
		$item .= '<div class="details">';
		$item .= '<div class="title">';
		$item .= '<span class="contact-name">' . esc_html($contact_name) . '</span>';
		$item .= '<span class="rc-chat-ref">#' . esc_html($conversation['id']) . '</span>';
		$item .= '</div>';

		$preview_prefix = '';
		if (!empty($conversation['msg_type']) && $conversation['msg_type'] === 'note') {
			$preview_prefix = '🔒 [Note] ';
		}
		$item .= '<div class="msg">' . esc_html($preview_prefix . rapidchat_preview($conversation['msg'])) . '</div>';

		$meta = array();
		if ($is_closed) {
			$meta[] = '<span class="rc-row-badge rc-row-badge-closed">' . esc_html__('Closed', 'rapidchat') . '</span>';
		}
		$meta = (array) apply_filters('rapidchat_conversation_list_badges', $meta, $conversation);
		if ($assigned_id && !$is_closed) {
			if ($is_joined) {
				/* translators: %s: operator name who owns the chat */
				$meta[] = '<span class="rc-row-badge rc-row-badge-mine">' . esc_html(sprintf(__('Joined · with %s', 'rapidchat'), $conversation['assigned_name'])) . '</span>';
			} else {
				$meta[] = $mine_now
					? '<span class="rc-row-badge rc-row-badge-mine">' . esc_html__('You', 'rapidchat') . '</span>'
					/* translators: %s: operator name currently handling the chat */
					: '<span class="rc-row-badge">' . esc_html(sprintf(__('with %s', 'rapidchat'), $conversation['assigned_name'])) . '</span>';
			}
		}
		if (!empty($tags)) {
			foreach (array_slice($tags, 0, 2) as $t) {
				$meta[] = '<span class="rc-row-badge rc-row-badge-tag">' . esc_html($t) . '</span>';
			}
			if (count($tags) > 2) {
				$meta[] = '<span class="rc-row-badge rc-row-badge-tag">+' . (count($tags) - 2) . '</span>';
			}
		}

		if ($meta) {
			$item .= '<div class="rc-row-meta">' . implode('', $meta) . '</div>';
		}

		$item .= '</div>';
		$item .= '<div class="date">' . esc_html(rapidchat_short_date($conversation['msg_date'] ?: $conversation['submit_date'])) . '</div>';

		if ($unread) {
			$item .= '<div class="icon-unread" title="' . esc_attr__('Unread messages', 'rapidchat') . '">' . esc_html($unread > 99 ? '99+' : $unread) . '</div>';
		}

		$item .= '</div>';

		if ($filter === 'unread') {
			$unread_items .= $item;
		} elseif ($is_closed) {
			$closed .= $item;
		} elseif ($mine_now) {
			$mine .= $item;
		} elseif ($is_queued) {
			$queued .= $item;
		} elseif ($can_assign) {
			$others .= $item;
		}
	}

	if ($filter === 'unread') {
		if ($unread_items !== '') {
			$output .= '<div class="chat-widget-users chat-widget-users-unread">' . rapidchat_icon('bell') . ' ' . esc_html__('Unread Messages', 'rapidchat') . '</div>' . $unread_items;
		}
	} elseif ($filter === 'mine') {
		if ($mine !== '') {
			$output .= '<div class="chat-widget-users">' . rapidchat_icon('comments') . ' ' . esc_html__('My Active Chats', 'rapidchat') . '</div>' . $mine;
		}
	} elseif ($filter === 'queued') {
		if ($queued !== '') {
			$output .= '<div class="chat-widget-users-unassigned">' . rapidchat_icon('clock') . ' ' . esc_html__('Queued / Unassigned', 'rapidchat') . '</div>' . $queued;
		}
	} elseif ($filter === 'closed') {
		if ($closed !== '') {
			$output .= '<div class="chat-widget-users">' . rapidchat_icon('check-double') . ' ' . esc_html__('Closed Conversations', 'rapidchat') . '</div>' . $closed;
		}
	} else {
		if ($mine !== '') {
			$output .= '<div class="chat-widget-users">' . rapidchat_icon('comments') . ' ' . esc_html__('My Active Chats', 'rapidchat') . '</div>' . $mine;
		}

		if ($queued !== '' && $can_queue) {
			$output .= '<div class="chat-widget-users-unassigned">' . rapidchat_icon('clock') . ' ' . esc_html__('Queued / Unassigned', 'rapidchat') . '</div>' . $queued;
		}

		if ($others !== '') {
			$output .= '<div class="chat-widget-users">' . rapidchat_icon('user') . ' ' . esc_html__('Other Agents', 'rapidchat') . '</div>' . $others;
		}

		if ($closed !== '') {
			$output .= '<div class="chat-widget-users">' . rapidchat_icon('check-double') . ' ' . esc_html__('Closed Conversations', 'rapidchat') . '</div>' . $closed;
		}
	}

	$output .= '</div></div>';

	return $output;
}

add_action('wp_ajax_rapidchat_conversation_admin', 'rapidchat_conversation_admin');
function rapidchat_conversation_admin() {
	global $wpdb;

	$account         = rapidchat_require_operator();
	$operator_id     = (int) $account['id'];
	$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

	if (!rapidchat_can_access_conversation($operator_id, $conversation_id, true)) {
		wp_send_json_error(__('Conversation not found.', 'rapidchat'), 403);
	}

	$conversation = $wpdb->get_row($wpdb->prepare(
		'SELECT c.*,
				a.full_name AS sender_name, a.email AS sender_email,
				a.ip_address AS sender_ip, a.country AS sender_country,
				a.wp_user_id AS sender_wp_user_id, a.role AS sender_role,
				a.last_seen AS sender_last_seen, a.status AS sender_status,
				a2.full_name AS receiver_name, a2.email AS receiver_email,
				a2.ip_address AS receiver_ip, a2.country AS receiver_country,
				a2.wp_user_id AS receiver_wp_user_id, a2.role AS receiver_role,
				a2.last_seen AS receiver_last_seen, a2.status AS receiver_status
		 FROM %i c
		 JOIN %i a  ON a.id  = c.account_sender_id
		 JOIN %i a2 ON a2.id = c.account_receiver_id
		 WHERE c.id = %d',
		$wpdb->rapidchat_conversations,
		$wpdb->rapidchat_accounts,
		$wpdb->rapidchat_accounts,
		$conversation_id
	));

	if (!$conversation) {
		wp_send_json_error(__('Conversation not found.', 'rapidchat'), 404);
	}

	list($conversation) = rapidchat_add_team_labels(array($conversation));

	$sender_id = (int) $conversation->account_sender_id;
	$conversation->total_chats = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM %i WHERE account_sender_id = %d",
		$wpdb->rapidchat_conversations,
		$sender_id
	));

	$assignments = rapidchat_conversation_assignments($conversation_id);
	$is_available = isset($assignments[$operator_id])
		? (int) $assignments[$operator_id]->is_available
		: (rapidchat_conversation_is_claimed($assignments) ? 2 : 1);

	if ($is_available === 0 || $is_available === 3) {
		$wpdb->query($wpdb->prepare(
			'UPDATE %i
			 SET is_read = 1 WHERE conversation_id = %d AND account_id <> %d AND is_read = 0',
			$wpdb->rapidchat_messages,
			$conversation_id,
			$operator_id
		));
	}

	$messages = $wpdb->get_results($wpdb->prepare(
		'SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, msg_type, submit_date FROM %i
		 WHERE conversation_id = %d ORDER BY id ASC LIMIT 500',
		$wpdb->rapidchat_messages,
		$conversation_id
	));

	$names        = rapidchat_account_names(wp_list_pluck($messages, 'account_id'));
	$operator_ids = array_map('intval', array_keys($assignments));
	$read_through = rapidchat_conversation_read_through($conversation_id, $operator_id);

	echo rapidchat_kses(rapidchat_render_admin_conversation($conversation, $messages, $names, $account, $assignments, $operator_ids, $is_available, $read_through));
	wp_die();
}

function rapidchat_conversation_is_claimed(array $assignments) {
	foreach ($assignments as $row) {
		if ((int) $row->is_available === 0) {
			return true;
		}
	}

	return false;
}

function rapidchat_conversation_assignments($conversation_id) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT operator_id, is_available FROM %i
			 WHERE conversation_id = %d',
			$wpdb->rapidchat_participants,
			(int) $conversation_id
		),
		OBJECT_K
	);
}

/** Every policy decision the conversation view needs, keyed by action. */
function rapidchat_conversation_permissions($conversation_id, array $account) {
	$perms = array();
	foreach (array('claim', 'reply', 'note', 'manage', 'close', 'transfer', 'assign', 'join', 'invite', 'leave', 'delete') as $action) {
		$perms[$action] = rapidchat_operator_can($action, $conversation_id, $account);
	}

	return $perms;
}

function rapidchat_render_admin_conversation($conversation, $messages, array $names, array $account, array $assignments, array $operator_ids, $is_available, $read_through = 0) {
	$operator_id = (int) $account['id'];
	$perms       = rapidchat_conversation_permissions((int) $conversation->id, $account);
	$chat_team   = rapidchat_conversation_team((int) $conversation->id);
	$holder      = null;
	foreach ($chat_team as $member) {
		if ((int) $member['state'] === 0) {
			$holder = $member;
			break;
		}
	}
	$is_owner = $holder && (int) $holder['id'] === $operator_id;

	$view = array(
		'conversation' => $conversation,
		'account'      => $account,
		'perms'        => $perms,
		'chat_team'    => $chat_team,
		'holder'       => $holder,
		'is_owner'     => $is_owner,
	);

	$is_sender    = ($operator_id === (int) $conversation->account_sender_id);
	$contact_name = $is_sender ? $conversation->receiver_name : $conversation->sender_name;
	$contact_ctry = $is_sender ? $conversation->receiver_country : $conversation->sender_country;

	$output = '<div class="chat-widget-columns"><div class="chat-widget-left-chat">';

	if ((int) $is_available === 1) {
		$output .= '<div class="chat-widget-header-user">';
		$output .= '<div class="chat-header-user-info">';
		$output .= '<div class="chat-user-profile">' . rapidchat_flag($contact_ctry) . '</div>';
		$output .= '<div class="chat-user-meta">';
		$output .= '<span class="chat-user-name">' . esc_html($contact_name) . '</span>';
		$output .= '<span class="rc-state-badge rc-state-queued">' . esc_html__('Queued', 'rapidchat') . '</span>';
		$output .= apply_filters('rapidchat_conversation_header_badges', '', $view);
		$output .= '</div></div>';

		$output .= '<div class="chat-header-actions">';
		$output .= '<button type="button" class="rc-header-action-btn rc-toggle-details-btn active" title="' . esc_attr__('Toggle Customer Details', 'rapidchat') . '">' . rapidchat_icon('user', 14) . ' <span class="rc-btn-text">' . esc_html__('Details', 'rapidchat') . '</span></button>';
		$output .= '</div>';
		$output .= '</div>';

		$output .= '<div class="chat-assignment-popup">';
		$output .= '<div class="rc-claim-icon">' . rapidchat_icon('comments', 48) . '</div>';
		$output .= '<h3>' . esc_html__('Incoming Conversation', 'rapidchat') . '</h3>';
		$output .= '<p>' . esc_html__('This conversation is in the queue. Claim it to start responding or assign to another agent.', 'rapidchat') . '</p>';
		$output .= '<div class="rc-claim-actions">';
		if ($perms['claim']) {
			$output .= '<button class="rc-btn-primary rc-claim-self-btn" data-conversation-id="' . esc_attr($conversation->id) . '">' . esc_html__('👋 Accept & Chat', 'rapidchat') . '</button>';
		}

		$output .= apply_filters('rapidchat_conversation_claim_actions', '', $view);

		$output .= '</div>';
		$output .= '<div id="response-message" role="status"></div>';
		$output .= '</div>';
		$output .= '</div>';
		$output .= rapidchat_render_profile_panel($conversation, $is_sender, $perms, $view);
		$output .= apply_filters('rapidchat_conversation_panels', '', $view);
		$output .= '</div>';

		return $output;
	}

	$output .= '<div class="chat-widget-header-user">';
	$output .= '<div class="chat-header-user-info">';
	$output .= '<div class="chat-user-profile">' . rapidchat_flag($contact_ctry) . '</div>';
	$output .= '<div class="chat-user-meta">';
	$output .= '<span class="chat-user-name">' . esc_html($contact_name) . '</span>';
	$output .= '<span class="rc-state-badge rc-state-' . esc_attr($conversation->state) . '">' . esc_html(ucfirst($conversation->state)) . '</span>';
	$output .= apply_filters('rapidchat_conversation_header_badges', '', $view);
	if ($chat_team) {
		$agent_names = array();
		foreach ($chat_team as $member) {
			$label = ((int) $member['id'] === $operator_id) ? __('You', 'rapidchat') : $member['full_name'];
			$agent_names[] = ((int) $member['state'] === 0)
				? '<strong>' . esc_html($label) . '</strong>'
				: esc_html($label);
		}
		$output .= '<span class="rc-chat-agents" title="' . esc_attr__('Agents in this chat (owner in bold)', 'rapidchat') . '">' . rapidchat_icon('user', 12) . ' ' . implode(', ', $agent_names) . '</span>';
	}
	$output .= '</div></div>';

	$cid = esc_attr($conversation->id);

	$output .= '<div class="chat-header-actions">';
	$output .= '<button type="button" class="rc-header-action-btn rc-toggle-details-btn active" title="' . esc_attr__('Toggle Customer Details', 'rapidchat') . '">' . rapidchat_icon('user', 14) . ' <span class="rc-btn-text">' . esc_html__('Details', 'rapidchat') . '</span></button>';
	$output .= apply_filters('rapidchat_conversation_header_actions', '', $view);
	if ($perms['manage']) {
		$output .= '<button type="button" class="rc-header-action-btn rc-email-transcript-btn" title="' . esc_attr__('Send Email Transcript', 'rapidchat') . '" data-id="' . $cid . '">' . rapidchat_icon('mail', 15) . '</button>';
	}
	if ($perms['close']) {
		$output .= '<button type="button" class="rc-header-action-btn rc-close-conversation-btn rc-action-danger" title="' . esc_attr__('End Conversation', 'rapidchat') . '" data-id="' . $cid . '">' . rapidchat_icon('times', 15) . ' <span class="rc-btn-text">' . esc_html__('End Chat', 'rapidchat') . '</span></button>';
	}
	$output .= '</div>';

	$output .= '</div>';

	$output .= apply_filters('rapidchat_conversation_before_messages', '', $view);

	$output .= '<div class="rc-sneak-peek-box" id="rc-sneak-peek-box" style="display: none;">'
		. '<span class="rc-sneak-icon">' . rapidchat_icon('eye', 14) . '</span>'
		. '<span class="rc-sneak-label">' . esc_html__('Visitor typing sneak-peek:', 'rapidchat') . '</span> '
		. '<span class="rc-sneak-text" id="rc-sneak-text"></span>'
		. '</div>';

	$output .= rapidchat_render_messages($messages, $names, $operator_id, $operator_ids, 'admin', $read_through);

	$output .= '<div class="rc-smart-suggestions" id="rc-smart-suggestions" data-id="' . esc_attr($conversation->id) . '">'
		. '<div class="rc-smart-header">' . rapidchat_icon('sparkles', 13) . ' <span>' . esc_html__('Smart Suggestions', 'rapidchat') . '</span></div>'
		. '<div class="rc-suggestion-chips" id="rc-suggestion-chips"></div>'
		. '</div>';

	$composer_mode = $perms['reply'] ? 'both' : ($perms['note'] ? 'note' : 'none');
	$output .= rapidchat_render_admin_composer((int) $conversation->id, $conversation->state === 'open', $composer_mode);
	$output .= '</div>';

	$output .= rapidchat_render_profile_panel($conversation, $is_sender, $perms, $view);
	$output .= apply_filters('rapidchat_conversation_panels', '', $view);
	$output .= '</div>';

	return $output;
}

function rapidchat_render_admin_composer($conversation_id, $is_open, $mode = 'both') {
	if (!$is_open) {
		return '<div class="chat-session-ended"><p>' . esc_html__('This conversation is closed.', 'rapidchat') . '</p></div>';
	}

	if ($mode === 'none') {
		return '<div class="chat-session-ended rc-readonly-composer"><p>' . esc_html__('Read-only: you are not part of this chat.', 'rapidchat') . '</p></div>';
	}

	$note_only = ($mode === 'note');

	$output  = '<div class="rc-operator-composer-wrap">';
	$output .= '<div class="rc-composer-tabs">';
	if (!$note_only) {
		$output .= '<button type="button" class="rc-composer-tab active" data-mode="message">' . rapidchat_icon('send', 13) . ' ' . esc_html__('Public Reply', 'rapidchat') . '</button>';
	}
	$output .= '<button type="button" class="rc-composer-tab rc-tab-note' . ($note_only ? ' active' : '') . '" data-mode="note">' . rapidchat_icon('lock', 13) . ' ' . esc_html__('Private Note (Staff only)', 'rapidchat') . '</button>';
	$output .= '</div>';

	$output .= '<form method="post" class="chat-widget-input-message rc-admin-composer-form" autocomplete="off">';
	$output .= '<div class="rc-composer-preview" style="display:none;"></div>';
	$output .= '<div class="rc-composer-inner">';
	$output .= '<button type="button" class="rc-composer-btn rc-emoji-btn" title="' . esc_attr__('Add Emoji', 'rapidchat') . '" aria-label="' . esc_attr__('Add Emoji', 'rapidchat') . '">' . rapidchat_icon('smile', 18) . '</button>';
	$output .= '<label class="rc-composer-btn rc-attach-btn" title="' . esc_attr__('Attach File', 'rapidchat') . '" aria-label="' . esc_attr__('Attach File', 'rapidchat') . '">';
	$output .= '<input type="file" class="rc-file-input" style="display:none;" accept="image/*,.pdf,.doc,.docx,.txt">';
	$output .= rapidchat_icon('paperclip', 18);
	$output .= '</label>';
	$output .= '<textarea name="msg" id="rc-composer-input" class="rc-msg-input" rows="1" placeholder="' . esc_attr__('Type a reply (type / for canned responses)…', 'rapidchat') . '" maxlength="' . esc_attr(RAPIDCHAT_MAX_MESSAGE) . '"></textarea>';
	$output .= '<input type="hidden" name="id" value="' . esc_attr($conversation_id) . '">';
	$output .= '<input type="hidden" name="msg_type" id="rc-composer-msg-type" value="' . ($note_only ? 'note' : 'message') . '">';
	$output .= '<input type="hidden" name="attachment_url" value="">';
	$output .= '<input type="hidden" name="attachment_type" value="">';
	$output .= '<input type="hidden" name="attachment_name" value="">';
	$output .= '<input type="hidden" name="attachment_size" value="0">';
	$output .= '<button type="submit" class="send-button" aria-label="' . esc_attr__('Send', 'rapidchat') . '">' . rapidchat_icon('send', 18) . '</button>';
	$output .= '</div>';
	$output .= '<div class="rc-emoji-picker-dropdown" style="display:none;"></div>';
	$output .= '</form>';
	$output .= '</div>';

	return $output;
}

/** $perms: rapidchat_conversation_permissions() for the viewer. */
function rapidchat_render_profile_panel($conversation, $is_sender, array $perms, array $view = array()) {
	$can_delete  = !empty($perms['delete']);
	$can_manage  = !empty($perms['manage']);
	$name        = $is_sender ? $conversation->receiver_name : $conversation->sender_name;
	$email       = $is_sender ? $conversation->receiver_email : $conversation->sender_email;
	$ip          = $is_sender ? $conversation->receiver_ip : $conversation->sender_ip;
	$country     = $is_sender ? $conversation->receiver_country : $conversation->sender_country;
	$wp_user_id  = $is_sender ? (int) ($conversation->receiver_wp_user_id ?? 0) : (int) ($conversation->sender_wp_user_id ?? 0);
	$role        = $is_sender ? ($conversation->receiver_role ?? 'Guest') : ($conversation->sender_role ?? 'Guest');
	$rating      = isset($conversation->rating) ? (int) $conversation->rating : 0;
	$feedback    = isset($conversation->feedback) ? $conversation->feedback : '';
	$total_chats = isset($conversation->total_chats) ? (int) $conversation->total_chats : 1;

	$custom_fields = json_decode((string) $conversation->custom_fields, true);
	$context       = (is_array($custom_fields) && !empty($custom_fields['context'])) ? (array) $custom_fields['context'] : array();
	$tags          = (is_array($custom_fields) && !empty($custom_fields['tags'])) ? (array) $custom_fields['tags'] : array();
	$standard_tags = rapidchat_get_standard_tags();

	$output  = '<div class="chat-widget-right-profile">';
	$output .= '<div class="chat-widget-about-row">';
	$output .= '<span class="chat-widget-about">' . esc_html__('Customer 360 & Details', 'rapidchat') . '</span>';
	$output .= '<button type="button" class="rc-profile-close-btn" title="' . esc_attr__('Hide details', 'rapidchat') . '" aria-label="' . esc_attr__('Hide details', 'rapidchat') . '">&times;</button>';
	$output .= '</div>';

	$output .= '<div class="profile-info"><div class="top-box">';
	$output .= '<div class="user-avatar"><div class="square-avatar">' . esc_html(rapidchat_initial($name)) . '</div></div>';
	$output .= '<div class="delete-account-id">';
	$output .= '<p><strong>' . esc_html__('Chat ID:', 'rapidchat') . '</strong> #' . esc_html($conversation->id) . '</p>';
	if ($role !== 'Guest' || $wp_user_id > 0) {
		$output .= '<span class="rc-customer-badge rc-badge-user">' . esc_html($role) . '</span>';
	} else {
		$output .= '<span class="rc-customer-badge rc-badge-guest">' . esc_html__('Guest', 'rapidchat') . '</span>';
	}
	$output .= '</div>';

	if ($can_delete) {
		$output .= '<button title="' . esc_attr__('Delete chat', 'rapidchat') . '" class="delete-chat-btn" data-id="' . esc_attr($conversation->id) . '">'
			. rapidchat_icon('trash', 14) . '</button>';
	}
	$output .= '</div></div>';

	if ($can_delete) {
		$output .= '<div id="delete-confirm-modal" class="confirm-modal" hidden>
			<div class="confirm-box">
				<p class="confirm-message">' . esc_html__('Delete this conversation and all associated records?', 'rapidchat') . '</p>
				<div class="confirm-actions">
					<button id="confirm-delete-yes" class="confirm-btn confirm-yes">' . esc_html__('Delete', 'rapidchat') . '</button>
					<button id="confirm-delete-no" class="confirm-btn confirm-no">' . esc_html__('Cancel', 'rapidchat') . '</button>
				</div>
				<div id="response-message-delete" role="status"></div>
			</div>
		</div>';
	}

	$output .= '<div class="profile-details">';
	$output .= '<div class="rc-profile-section-title">' . esc_html__('Visitor Info', 'rapidchat') . '</div>';
	$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('Name', 'rapidchat') . '</span><span class="rc-kv-value">' . esc_html($name) . '</span></div>';
	$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('Email', 'rapidchat') . '</span><span class="rc-kv-value">' . ($email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '<span class="rc-muted">' . esc_html__('Guest', 'rapidchat') . '</span>') . '</span></div>';

	if ($wp_user_id > 0) {
		$user_edit_link = get_edit_user_link($wp_user_id);
		$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('WP Account', 'rapidchat') . '</span><span class="rc-kv-value">' . ($user_edit_link ? '<a href="' . esc_url($user_edit_link) . '" target="_blank" rel="noopener noreferrer">#' . esc_html($wp_user_id) . ' (' . esc_html__('View Profile', 'rapidchat') . ')</a>' : '#' . esc_html($wp_user_id)) . '</span></div>';
	}

	$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('IP Address', 'rapidchat') . '</span><span class="rc-kv-value">' . esc_html($ip ? $ip : '—') . '</span></div>';
	$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('Location', 'rapidchat') . '</span><span class="rc-kv-value">' . rapidchat_flag($country) . ' ' . esc_html($country ? $country : 'Unknown') . '</span></div>';

	if (!empty($conversation->submit_date)) {
		$started_formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($conversation->submit_date));
		$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('Started', 'rapidchat') . '</span><span class="rc-kv-value">' . esc_html($started_formatted) . '</span></div>';
	}

	/* translators: %d: number of conversations with this visitor. */
	$history = sprintf(_n('%d conversation', '%d conversations', $total_chats, 'rapidchat'), $total_chats);
	$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('History', 'rapidchat') . '</span><span class="rc-kv-value">' . esc_html($history) . '</span></div>';

	if (!empty($context)) {
		$output .= '<div class="rc-profile-section-title">' . esc_html__('Browsing Context', 'rapidchat') . '</div>';
		if (!empty($context['title']) || !empty($context['url'])) {
			$page_title = !empty($context['title']) ? $context['title'] : $context['url'];
			$page_url   = !empty($context['url']) ? $context['url'] : '#';
			$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('Current Page', 'rapidchat') . '</span><span class="rc-kv-value"><a href="' . esc_url($page_url) . '" target="_blank" rel="noopener noreferrer" class="rc-context-link">' . esc_html(rapidchat_preview($page_title, 35)) . '</a></span></div>';
		}
		if (!empty($context['referrer'])) {
			$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('Referrer', 'rapidchat') . '</span><span class="rc-kv-value"><a href="' . esc_url($context['referrer']) . '" target="_blank" rel="noopener noreferrer" class="rc-context-link">' . esc_html(rapidchat_preview($context['referrer'], 35)) . '</a></span></div>';
		}
		if (!empty($context['browser']) || !empty($context['os'])) {
			$device_str = trim(($context['browser'] ?? '') . ' on ' . ($context['os'] ?? '') . ' (' . ($context['device'] ?? '') . ')');
			$output .= '<div class="rc-kv"><span class="rc-kv-label">' . esc_html__('Device', 'rapidchat') . '</span><span class="rc-kv-value">' . esc_html($device_str) . '</span></div>';
		}
	}

	$output .= '<div class="rc-profile-section-title">' . esc_html__('Conversation Tags', 'rapidchat') . '</div>';
	$output .= '<div class="rc-tags-container" id="rc-tags-container" data-id="' . esc_attr($conversation->id) . '">';
	$output .= '<div class="rc-tags-list" id="rc-tags-list">';
	foreach ($tags as $tag) {
		$color = isset($standard_tags[$tag]) ? $standard_tags[$tag] : '#6b7280';
		$output .= '<span class="rc-tag-pill" style="border-left: 3px solid ' . esc_attr($color) . ';" data-tag="' . esc_attr($tag) . '">'
			. esc_html($tag)
			. ($can_manage ? '<button type="button" class="rc-tag-remove" aria-label="' . esc_attr__('Remove tag', 'rapidchat') . '">&times;</button>' : '')
			. '</span>';
	}
	$output .= '</div>';

	if ($can_manage) {
		$output .= '<div class="rc-standard-tags-quick">';
		foreach ($standard_tags as $stag => $scolor) {
			if (!in_array($stag, $tags, true)) {
				$output .= '<button type="button" class="rc-quick-tag-btn" data-tag="' . esc_attr($stag) . '" style="border-color:' . esc_attr($scolor) . '; color:' . esc_attr($scolor) . ';">+' . esc_html($stag) . '</button>';
			}
		}
		$output .= '</div>';

		$output .= '<div class="rc-tag-add-row">';
		$output .= '<input type="text" id="rc-new-tag-input" placeholder="' . esc_attr__('New tag...', 'rapidchat') . '" maxlength="30">';
		$output .= '<button type="button" id="rc-add-tag-btn" class="rc-btn-small">' . esc_html__('Add', 'rapidchat') . '</button>';
		$output .= '</div>';
	}
	$output .= '</div>';

	if ($rating > 0) {
		$stars = str_repeat('⭐', $rating);
		$output .= '<div class="rc-profile-section-title">' . esc_html__('Customer Feedback', 'rapidchat') . '</div>';
		$output .= '<div class="rc-profile-rating-box">' . $stars . ' (' . $rating . '/5)';
		if ($feedback !== '') {
			$output .= '<p class="rc-profile-feedback"><em>"' . esc_html($feedback) . '"</em></p>';
		}
		$output .= '</div>';
	}

	$output .= '<div class="rc-profile-actions">';
	$output .= apply_filters('rapidchat_profile_panel_actions', '', $view);
	if ($can_manage) {
		$output .= '<button type="button" class="rc-btn-secondary rc-email-transcript-btn" data-id="' . esc_attr($conversation->id) . '">' . esc_html__('📧 Send Transcript', 'rapidchat') . '</button>';
	}
	if (!empty($perms['close'])) {
		$output .= '<button type="button" class="rc-btn-danger rc-close-conversation-btn" data-id="' . esc_attr($conversation->id) . '">' . esc_html__('End Conversation', 'rapidchat') . '</button>';
	}
	$output .= '</div>';

	$output .= '</div>';
	$output .= '</div>';

	return $output;
}

add_action('wp_ajax_rapidchat_admin_post_message', 'rapidchat_admin_post_message');
function rapidchat_admin_post_message() {
	$account  = rapidchat_require_operator();
	$msg_type = isset($_POST['msg_type']) ? sanitize_text_field(wp_unslash($_POST['msg_type'])) : 'message';

	$result = rapidchat_message_create(array(
		'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
		'actor'           => $account,
		'is_operator'     => true,
		'msg'             => isset($_POST['msg']) ? sanitize_textarea_field(wp_unslash($_POST['msg'])) : '',
		'msg_type'        => $msg_type,
		'attachment_url'  => isset($_POST['attachment_url']) ? esc_url_raw(wp_unslash($_POST['attachment_url'])) : '',
		'attachment_type' => isset($_POST['attachment_type']) ? sanitize_key(wp_unslash($_POST['attachment_type'])) : '',
		'attachment_name' => isset($_POST['attachment_name']) ? sanitize_text_field(wp_unslash($_POST['attachment_name'])) : '',
		'attachment_size' => isset($_POST['attachment_size']) ? absint(wp_unslash($_POST['attachment_size'])) : 0,
	));

	if (!is_wp_error($result)) {
		$result['message'] = ($msg_type === 'note')
			? __('Note added successfully.', 'rapidchat')
			: __('Message submitted successfully!', 'rapidchat');
	}

	rapidchat_ajax_respond($result);
}

add_action('wp_ajax_rapidchat_update_tags', 'rapidchat_update_tags');
function rapidchat_update_tags() {
	$account         = rapidchat_require_operator();
	$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;
	$tags_raw        = isset($_POST['tags']) ? map_deep(wp_unslash($_POST['tags']), 'sanitize_text_field') : '';

	if (is_array($tags_raw)) {
		$tags = $tags_raw;
	} elseif (is_string($tags_raw)) {
		$tags = array_filter(array_map('trim', explode(',', $tags_raw)));
	} else {
		$tags = array();
	}

	$result = rapidchat_conversation_update_tags(array(
		'conversation_id' => $conversation_id,
		'actor'           => $account,
		'tags'            => $tags,
	));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	wp_send_json_success($result);
}

add_action('wp_ajax_rapidchat_get_smart_suggestions', 'rapidchat_get_smart_suggestions');
function rapidchat_get_smart_suggestions() {
	global $wpdb;

	$account         = rapidchat_require_operator();
	$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

	if ($conversation_id > 0) {
		rapidchat_require_operator_can('view', $conversation_id, $account);
	}

	$suggestions  = array();

	if ($wpdb->rapidchat_canned_responses && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->rapidchat_canned_responses))) === $wpdb->rapidchat_canned_responses) {
		$canned = $wpdb->get_results($wpdb->prepare('SELECT id, shortcut, title, content FROM %i LIMIT 8', $wpdb->rapidchat_canned_responses), ARRAY_A);
		foreach ($canned as $c) {
			$suggestions[] = array(
				'id'       => 'canned_' . $c['id'],
				'title'    => (!empty($c['shortcut']) ? '/' . $c['shortcut'] . ': ' : '') . $c['title'],
				'text'     => $c['content'],
				'category' => 'Canned',
			);
		}
	}

	$suggestions = (array) apply_filters('rapidchat_smart_suggestions', $suggestions, $conversation_id, $account);

	if (empty($suggestions)) {
		$suggestions = array(
			array('id' => 'def_1', 'title' => __('Hello & Welcome', 'rapidchat'), 'text' => __('Hello! Thanks for reaching out. How can I assist you today?', 'rapidchat'), 'category' => __('Quick', 'rapidchat')),
			array('id' => 'def_2', 'title' => __('Checking this now', 'rapidchat'), 'text' => __("I'm checking this for you right now, please give me just a moment.", 'rapidchat'), 'category' => __('Quick', 'rapidchat')),
			array('id' => 'def_3', 'title' => __('Anything else?', 'rapidchat'), 'text' => __('Is there anything else I can help you with today?', 'rapidchat'), 'category' => __('Quick', 'rapidchat')),
			array('id' => 'def_4', 'title' => __('Thank you & Goodbye', 'rapidchat'), 'text' => __('Thank you for chatting with us! Have a wonderful day.', 'rapidchat'), 'category' => __('Quick', 'rapidchat')),
		);
	}

	wp_send_json_success(array('suggestions' => $suggestions));
}

add_action('wp_ajax_rapidchat_close_conversation', 'rapidchat_close_conversation_handler');
function rapidchat_close_conversation_handler() {
	$account         = rapidchat_require_operator();
	$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

	$result = rapidchat_conversation_close($conversation_id, array('actor' => $account));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	wp_send_json_success(array('message' => __('Conversation ended.', 'rapidchat'), 'id' => $conversation_id));
}

/** "Accept & Chat": the signed-in operator claims a queued conversation. */
add_action('wp_ajax_rapidchat_claim_conversation', 'rapidchat_claim_conversation_handler');
function rapidchat_claim_conversation_handler() {
	$account         = rapidchat_require_operator('rapidchat_reply');
	$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

	$ctx = rapidchat_operator_context((int) $account['id'], $conversation_id);
	if ($ctx && $ctx['state'] === 'open' && $ctx['holder_id'] === (int) $account['id']) {
		wp_send_json_success(array('message' => __('Chat accepted.', 'rapidchat')));
	}

	rapidchat_require_operator_can('claim', $conversation_id, $account);

	if (!rapidchat_claim_if_unclaimed($conversation_id, (int) $account['id'])) {
		wp_send_json_error(__('Another agent has just accepted this chat.', 'rapidchat'), 409);
	}

	rapidchat_event('operator_assigned', $conversation_id, (int) $account['id']);

	wp_send_json_success(array('message' => __('Chat accepted.', 'rapidchat')));
}

/** Email the conversation's transcript to the visitor. */
add_action('wp_ajax_rapidchat_admin_send_transcript', 'rapidchat_admin_send_transcript');
function rapidchat_admin_send_transcript() {
	$account = rapidchat_require_operator();

	$result = rapidchat_conversation_transcript(array(
		'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
		'actor'           => $account,
		'is_operator'     => true,
		'recipient'       => 'visitor',
	));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	wp_send_json_success(__('Transcript emailed to the visitor.', 'rapidchat'));
}

add_action('wp_ajax_rapidchat_delete_chat', 'rapidchat_delete_chat');
function rapidchat_delete_chat() {
	$account = rapidchat_require_operator('rapidchat_manage_settings');

	$conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

	if (!$conversation_id) {
		wp_send_json_error(array('message' => __('Invalid conversation ID.', 'rapidchat')));
	}

	if (!rapidchat_operator_can('delete', $conversation_id, $account) || !rapidchat_can_access_conversation((int) $account['id'], $conversation_id, true)) {
		wp_send_json_error(array('message' => __('Conversation not found.', 'rapidchat')), 403);
	}

	if (!rapidchat_delete_conversations(array($conversation_id))) {
		wp_send_json_error(array('message' => __('Delete query failed.', 'rapidchat')));
	}

	wp_send_json_success(array('message' => __('Chat deleted successfully', 'rapidchat')));
}

