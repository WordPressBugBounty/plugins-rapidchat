<?php
/** Visitor-facing AJAX handlers. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// phpcs:disable WordPress.Security.NonceVerification.Missing

add_action('wp_ajax_rapidchat_authenticate_user', 'rapidchat_authenticate_user');
add_action('wp_ajax_nopriv_rapidchat_authenticate_user', 'rapidchat_authenticate_user');
function rapidchat_authenticate_user() {
	check_ajax_referer('rapidchat_user_nonce', 'rapidchat_nonce');

	$result = rapidchat_customer_identify(array(
		'name'  => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
		'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
		'ip'    => rapidchat_client_ip(),
		'actor' => rapidchat_current_account() ?: array(),
	));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	wp_send_json_success('success');
}

add_action('wp_ajax_rapidchat_conversations_user', 'rapidchat_conversations_user');
add_action('wp_ajax_nopriv_rapidchat_conversations_user', 'rapidchat_conversations_user');
function rapidchat_conversations_user() {
	$account = rapidchat_require_guest();

	echo rapidchat_kses(rapidchat_render_guest_conversations((int) $account['id']));
	wp_die();
}

/** One row per conversation, ordered and limited in SQL. */
function rapidchat_guest_conversations($account_id, $limit = RAPIDCHAT_LIST_LIMIT) {
	global $wpdb;

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.id, c.account_sender_id, c.account_receiver_id, c.submit_date, c.state,
					COALESCE(c.last_activity, c.submit_date) AS sort_date,
					a.full_name  AS sender_name,
					a2.full_name AS receiver_name,
					lm.msg       AS msg,
					lm.submit_date AS msg_date,
					(SELECT COUNT(*) FROM %i um
					  WHERE um.conversation_id = c.id AND um.is_read = 0 AND um.account_id <> %d) AS unread_count
			 FROM %i c
			 JOIN %i a  ON a.id  = c.account_sender_id
			 JOIN %i a2 ON a2.id = c.account_receiver_id
			 LEFT JOIN %i lm
					ON lm.id = (SELECT MAX(m2.id) FROM %i m2 WHERE m2.conversation_id = c.id)
			 WHERE c.account_sender_id = %d OR c.account_receiver_id = %d
			 ORDER BY sort_date DESC
			 LIMIT %d",
			$wpdb->rapidchat_messages,
			$account_id,
			$wpdb->rapidchat_conversations,
			$wpdb->rapidchat_accounts,
			$wpdb->rapidchat_accounts,
			$wpdb->rapidchat_messages,
			$wpdb->rapidchat_messages,
			$account_id,
			$account_id,
			(int) $limit
		),
		ARRAY_A
	);
}

function rapidchat_render_guest_conversations($account_id) {
	$conversations = rapidchat_guest_conversations($account_id);

	$output  = '<div class="chat-widget-conversations">';
	$output .= '<a href="#" class="chat-widget-new-conversation" data-id="new">+ ' . esc_html__('New Chat', 'rapidchat') . '</a>';

	$headed = array();

	foreach ($conversations as $conversation) {
		$is_mine      = ((int) $conversation['account_sender_id'] === (int) $account_id);
		$contact_name = $is_mine ? rapidchat_visitor_agent_name($conversation['receiver_name'], (int) $conversation['account_receiver_id']) : $conversation['sender_name'];
		$unread       = (int) $conversation['unread_count'];
		$heading      = ($conversation['state'] === 'open')
			? __('Recent Conversation', 'rapidchat')
			: __('Old Conversations', 'rapidchat');

		if (!isset($headed[$heading])) {
			$output .= '<div class="chat-widget-heading">' . esc_html($heading) . '</div>';
			$headed[$heading] = true;
		}

		$output .= '<a href="#" class="chat-widget-conversation' . ($unread ? ' chat-widget-unread' : '') . '"'
			. ' data-id="' . esc_attr($conversation['id']) . '">';
		$output .= '<div class="icon" style="background-color:' . esc_attr(rapidchat_avatar_color($contact_name)) . ';">'
			. esc_html(mb_substr((string) $contact_name, 0, 1)) . '</div>';
		$output .= '<div class="details">';
		$output .= '<div class="title">' . esc_html($contact_name) . '</div>';
		$output .= '<div class="msg">' . esc_html(rapidchat_preview($conversation['msg'])) . '</div>';
		$output .= '</div>';
		$output .= '<div class="date">' . esc_html(rapidchat_short_date($conversation['msg_date'] ?: $conversation['submit_date'])) . '</div>';

		if ($unread) {
			$output .= '<div class="unread-badge">' . esc_html($unread > 99 ? '99+' : $unread) . '</div>';
		}

		$output .= '</a>';
	}

	$output .= '</div>';

	return $output;
}

add_action('wp_ajax_rapidchat_conversation_user', 'rapidchat_conversation_user');
add_action('wp_ajax_nopriv_rapidchat_conversation_user', 'rapidchat_conversation_user');
function rapidchat_conversation_user() {
	$account = rapidchat_require_guest();

	$result = rapidchat_thread_view(array(
		'conversation_id' => isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0,
		'actor'           => $account,
	));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	echo rapidchat_kses($result['html']);
	wp_die();
}

add_action('wp_ajax_rapidchat_find_conversation', 'rapidchat_find_conversation');
add_action('wp_ajax_nopriv_rapidchat_find_conversation', 'rapidchat_find_conversation');
function rapidchat_find_conversation() {
	$account = rapidchat_require_guest();

	$team_id         = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
	$context         = isset($_POST['context']) && is_array($_POST['context']) ? rapidchat_sanitize_context(wp_unslash($_POST['context'])) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field by rapidchat_sanitize_context().
	$initial_message = isset($_POST['initial_message']) ? sanitize_textarea_field(wp_unslash($_POST['initial_message'])) : '';
	$force_new       = !empty($_POST['force_new']);
	$find_only       = !empty($_POST['find_only']);

	$result = rapidchat_conversation_find_or_create(array(
		'actor'     => $account,
		'team_id'   => $team_id,
		'context'   => $context,
		'force_new' => $force_new,
		'find_only' => $find_only,
	));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	if (!empty($result['created']) && $initial_message !== '') {
		rapidchat_message_create(array(
			'conversation_id' => (int) $result['id'],
			'actor'           => $account,
			'is_operator'     => false,
			'msg'             => $initial_message,
		));
	}

	wp_send_json_success(array('id' => $result['id']));
}

add_action('wp_ajax_rapidchat_post_message', 'rapidchat_post_message');
add_action('wp_ajax_nopriv_rapidchat_post_message', 'rapidchat_post_message');
function rapidchat_post_message() {
	$account = rapidchat_require_guest();

	$result = rapidchat_message_create(array(
		'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
		'actor'           => $account,
		'is_operator'     => false,
		'msg'             => isset($_POST['msg']) ? sanitize_textarea_field(wp_unslash($_POST['msg'])) : '',
		'attachment_url'  => isset($_POST['attachment_url']) ? esc_url_raw(wp_unslash($_POST['attachment_url'])) : '',
		'attachment_type' => isset($_POST['attachment_type']) ? sanitize_key(wp_unslash($_POST['attachment_type'])) : '',
		'attachment_name' => isset($_POST['attachment_name']) ? sanitize_text_field(wp_unslash($_POST['attachment_name'])) : '',
		'attachment_size' => isset($_POST['attachment_size']) ? absint(wp_unslash($_POST['attachment_size'])) : 0,
	));

	if (!is_wp_error($result)) {
		$result['message'] = __('Message submitted successfully!', 'rapidchat');
	}

	rapidchat_ajax_respond($result);
}

add_action('wp_ajax_rapidchat_upload_attachment', 'rapidchat_upload_attachment');
add_action('wp_ajax_nopriv_rapidchat_upload_attachment', 'rapidchat_upload_attachment');
function rapidchat_upload_attachment() {
	$account = check_ajax_referer('rapidchat_admin_nonce', 'rapidchat_nonce', false)
		? rapidchat_require_operator()
		: rapidchat_require_guest();

	rapidchat_ajax_respond(rapidchat_attachment_store(array(
		'actor' => $account,
		'file'  => isset($_FILES['file']) ? $_FILES['file'] : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an upload array; rapidchat_attachment_upload() validates it with wp_handle_upload() and an allowed-type list.
	)));
}

add_action('wp_ajax_rapidchat_rate_conversation', 'rapidchat_rate_conversation');
add_action('wp_ajax_nopriv_rapidchat_rate_conversation', 'rapidchat_rate_conversation');
function rapidchat_rate_conversation() {
	$account = rapidchat_require_guest();

	$result = rapidchat_conversation_rate(array(
		'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
		'actor'           => $account,
		'rating'          => isset($_POST['rating']) ? (int) $_POST['rating'] : 0,
		'feedback'        => isset($_POST['feedback']) ? sanitize_textarea_field(wp_unslash($_POST['feedback'])) : '',
	));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	wp_send_json_success(__('Thank you for your feedback!', 'rapidchat'));
}

add_action('wp_ajax_rapidchat_send_transcript', 'rapidchat_send_transcript');
add_action('wp_ajax_nopriv_rapidchat_send_transcript', 'rapidchat_send_transcript');
function rapidchat_send_transcript() {
	$account = rapidchat_require_guest();

	$result = rapidchat_conversation_transcript(array(
		'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
		'actor'           => $account,
		'is_operator'     => false,
		'recipient'       => 'self',
	));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	wp_send_json_success(__('Transcript sent to your email!', 'rapidchat'));
}

add_action('wp_ajax_rapidchat_destroy_chat_session', 'rapidchat_destroy_chat_session');
add_action('wp_ajax_nopriv_rapidchat_destroy_chat_session', 'rapidchat_destroy_chat_session');
function rapidchat_destroy_chat_session() {
	$account = rapidchat_require_guest();

	$result = rapidchat_conversation_end_session(array('actor' => $account));

	if (is_wp_error($result)) {
		rapidchat_ajax_respond($result);
	}

	wp_send_json_success(__('Chat ended successfully.', 'rapidchat'));
}
