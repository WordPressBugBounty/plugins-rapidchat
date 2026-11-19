<?php
/** Transcript engine. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

require_once RAPIDCHAT_DIR . '/main/engine/events.php';

/** Build the transcript HTML for one conversation. */
function rapidchat_transcript_html($conversation_id) {
	global $wpdb;

	$messages = $wpdb->get_results($wpdb->prepare(
		"SELECT id, account_id, msg, submit_date FROM %i
		 WHERE conversation_id = %d AND (msg_type IS NULL OR msg_type NOT IN ('note', 'system'))
		 ORDER BY id ASC",
		$wpdb->rapidchat_messages,
		$conversation_id
	));

	$visitor   = (int) $wpdb->get_var($wpdb->prepare('SELECT account_sender_id FROM %i WHERE id = %d', $wpdb->rapidchat_conversations, $conversation_id));
	$names     = rapidchat_names_for_visitor(rapidchat_account_names(wp_list_pluck($messages, 'account_id')), $visitor);
	$site_name = get_bloginfo('name');

	/* translators: %s: site name. */
	$html = '<h2>' . sprintf(esc_html__('Chat transcript with %s', 'rapidchat'), esc_html($site_name)) . '</h2><hr>';

	foreach ($messages as $m) {
		$author = isset($names[$m->account_id]) ? $names[$m->account_id]->full_name : __('User', 'rapidchat');
		$html  .= '<p><strong>' . esc_html($author) . '</strong> ('
			. esc_html($m->submit_date) . '):<br>'
			. make_clickable(nl2br(esc_html($m->msg))) . '</p>';
	}

	return $html;
}

/** Email a conversation transcript. */
function rapidchat_conversation_transcript(array $args) {
	global $wpdb;

	$conversation_id = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
	$actor           = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id        = isset($actor['id']) ? (int) $actor['id'] : 0;
	$is_operator     = !empty($args['is_operator']);
	$recipient       = ($args['recipient'] ?? 'self') === 'visitor' ? 'visitor' : 'self';

	if ($conversation_id <= 0 || $actor_id <= 0) {
		return new WP_Error('rapidchat_invalid_request', __('Invalid request.', 'rapidchat'), array('status' => 400));
	}

	$allowed = $is_operator
		? rapidchat_operator_can('manage', $conversation_id, $actor)
		: rapidchat_can_access_conversation($actor_id, $conversation_id);

	if (!$allowed) {
		return new WP_Error('rapidchat_forbidden', __('Access denied.', 'rapidchat'), array('status' => 403));
	}

	// Visitors choose their own email address, so cap how often the site will mail on their behalf.
	if (!$is_operator && (!rapidchat_rate_limit('transcript_' . $actor_id, 3, HOUR_IN_SECONDS) || !rapidchat_rate_limit('transcript_ip_' . rapidchat_client_ip(), 10, HOUR_IN_SECONDS))) {
		return new WP_Error(
			'rapidchat_rate_limited',
			__('Too many transcript requests. Please try again later.', 'rapidchat'),
			array('status' => 429)
		);
	}

	if ($recipient === 'visitor') {
		$sender_id = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT account_sender_id FROM %i WHERE id = %d',
			$wpdb->rapidchat_conversations,
			$conversation_id
		));

		if (!$sender_id) {
			return new WP_Error('rapidchat_not_found', __('Conversation not found.', 'rapidchat'), array('status' => 404));
		}

		$email = (string) $wpdb->get_var($wpdb->prepare(
			'SELECT email FROM %i WHERE id = %d',
			$wpdb->rapidchat_accounts,
			$sender_id
		));
		$no_email_message = __('No valid email on file for this visitor.', 'rapidchat');
	} else {
		$email = isset($actor['email']) ? (string) $actor['email'] : '';
		$no_email_message = __('No valid email on file.', 'rapidchat');
	}

	if (!is_email($email)) {
		return new WP_Error('rapidchat_invalid_email', $no_email_message, array('status' => 400));
	}

	$site_name = get_bloginfo('name');
	$sent = wp_mail(
		$email,
		/* translators: %s: site name. */
		sprintf(__('[%s] Your Chat Transcript', 'rapidchat'), $site_name),
		rapidchat_transcript_html($conversation_id),
		array('Content-Type: text/html; charset=UTF-8')
	);

	rapidchat_event('transcript_sent', $conversation_id, $email, $recipient, (bool) $sent);

	return array('sent_to' => $email);
}
