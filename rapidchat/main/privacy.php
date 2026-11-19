<?php

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** The visitor accounts that gave $email. */
function rapidchat_privacy_guest_accounts($email) {
	global $wpdb;

	$email = sanitize_email($email);
	if ($email === '') {
		return array();
	}

	return $wpdb->get_results($wpdb->prepare(
		'SELECT id, email, full_name, ip_address, country, last_seen FROM %i' . " WHERE email = %s AND role = 'Guest'",
		$wpdb->rapidchat_accounts,
		$email
	), ARRAY_A);
}

/** Conversations started by any of $account_ids. */
function rapidchat_privacy_conversation_ids(array $account_ids) {
	global $wpdb;

	$account_ids = array_values(array_filter(array_map('intval', $account_ids)));
	if (!$account_ids) {
		return array();
	}

	return array_map('intval', $wpdb->get_col($wpdb->prepare(
		'SELECT id FROM %i WHERE account_sender_id IN (' . implode(',', array_fill(0, count($account_ids), '%d')) . ') ORDER BY id ASC',
		array_merge(array($wpdb->rapidchat_conversations), $account_ids)
	)));
}

function rapidchat_privacy_export($email, $page = 1) {
	global $wpdb;

	if (function_exists('rapidchat_load')) {
		rapidchat_load('data');
	}

	$page     = max(1, (int) $page);
	$per_page = 200;
	$accounts = rapidchat_privacy_guest_accounts($email);
	$items    = array();

	if (!$accounts) {
		return array('data' => array(), 'done' => true);
	}

	$account_ids = wp_list_pluck($accounts, 'id');

	if ($page === 1) {
		foreach ($accounts as $account) {
			$items[] = array(
				'group_id'    => 'rapidchat-visitor',
				'group_label' => __('Live chat visitor', 'rapidchat'),
				'item_id'     => 'rapidchat-visitor-' . (int) $account['id'],
				'data'        => array(
					array('name' => __('Name', 'rapidchat'), 'value' => $account['full_name']),
					array('name' => __('Email', 'rapidchat'), 'value' => $account['email']),
					array('name' => __('IP address', 'rapidchat'), 'value' => $account['ip_address']),
					array('name' => __('Country', 'rapidchat'), 'value' => $account['country']),
					array('name' => __('Last seen', 'rapidchat'), 'value' => (string) $account['last_seen']),
				),
			);
		}

		$conversation_ids = rapidchat_privacy_conversation_ids($account_ids);
		if ($conversation_ids) {
			$conversations = $wpdb->get_results($wpdb->prepare(
				'SELECT id, submit_date, state, rating, feedback, custom_fields FROM %i WHERE id IN (' . implode(',', array_fill(0, count($conversation_ids), '%d')) . ')',
				array_merge(array($wpdb->rapidchat_conversations), $conversation_ids)
			), ARRAY_A);

			foreach ($conversations as $conversation) {
				$fields  = json_decode((string) $conversation['custom_fields'], true);
				$context = (is_array($fields) && isset($fields['context']) && is_array($fields['context'])) ? $fields['context'] : array();
				$data    = array(
					array('name' => __('Conversation', 'rapidchat'), 'value' => '#' . (int) $conversation['id']),
					array('name' => __('Started', 'rapidchat'), 'value' => (string) $conversation['submit_date']),
					array('name' => __('Status', 'rapidchat'), 'value' => (string) $conversation['state']),
				);
				foreach (array('url' => __('Started on page', 'rapidchat'), 'referrer' => __('Referrer', 'rapidchat'), 'browser' => __('Browser', 'rapidchat'), 'os' => __('Operating system', 'rapidchat')) as $key => $label) {
					if (!empty($context[$key])) {
						$data[] = array('name' => $label, 'value' => (string) $context[$key]);
					}
				}
				if ((int) $conversation['rating'] > 0) {
					$data[] = array('name' => __('Rating', 'rapidchat'), 'value' => (int) $conversation['rating'] . '/5');
				}
				if ((string) $conversation['feedback'] !== '') {
					$data[] = array('name' => __('Feedback', 'rapidchat'), 'value' => (string) $conversation['feedback']);
				}

				$items[] = array(
					'group_id'    => 'rapidchat-conversations',
					'group_label' => __('Live chat conversations', 'rapidchat'),
					'item_id'     => 'rapidchat-conversation-' . (int) $conversation['id'],
					'data'        => $data,
				);
			}
		}
	}

	$messages     = $wpdb->get_results($wpdb->prepare(
		'SELECT id, conversation_id, msg, submit_date, attachment_url FROM %i WHERE account_id IN (' . implode(',', array_fill(0, count($account_ids), '%d')) . ') ORDER BY id ASC LIMIT %d OFFSET %d',
		array_merge(array($wpdb->rapidchat_messages), $account_ids, array($per_page, ($page - 1) * $per_page))
	), ARRAY_A);

	foreach ($messages as $message) {
		$data = array(
			array('name' => __('Conversation', 'rapidchat'), 'value' => '#' . (int) $message['conversation_id']),
			array('name' => __('Sent', 'rapidchat'), 'value' => (string) $message['submit_date']),
			array('name' => __('Message', 'rapidchat'), 'value' => (string) $message['msg']),
		);
		if ((string) $message['attachment_url'] !== '') {
			$data[] = array('name' => __('Attachment', 'rapidchat'), 'value' => (string) $message['attachment_url']);
		}

		$items[] = array(
			'group_id'    => 'rapidchat-messages',
			'group_label' => __('Live chat messages', 'rapidchat'),
			'item_id'     => 'rapidchat-message-' . (int) $message['id'],
			'data'        => $data,
		);
	}

	return array(
		'data' => $items,
		'done' => count($messages) < $per_page,
	);
}

function rapidchat_privacy_erase($email, $page = 1) {
	global $wpdb;

	if (function_exists('rapidchat_load')) {
		rapidchat_load('data');
	}

	$accounts = rapidchat_privacy_guest_accounts($email);
	if (!$accounts) {
		return array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
	}

	$account_ids      = array_map('intval', wp_list_pluck($accounts, 'id'));
	$conversation_ids = rapidchat_privacy_conversation_ids($account_ids);

	if ($conversation_ids) {
		$files        = $wpdb->get_col($wpdb->prepare(
			'SELECT attachment_url FROM %i WHERE conversation_id IN (' . implode(',', array_fill(0, count($conversation_ids), '%d')) . ") AND attachment_url <> ''",
			array_merge(array($wpdb->rapidchat_messages), $conversation_ids)
		));
		foreach ((array) $files as $url) {
			rapidchat_privacy_delete_upload($url);
		}

		rapidchat_delete_conversations($conversation_ids);
	}

	foreach ($account_ids as $account_id) {
		$wpdb->delete($wpdb->rapidchat_accounts, array('id' => $account_id), array('%d'));
	}

	return array(
		'items_removed'  => true,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

function rapidchat_privacy_delete_upload($url) {
	$uploads = wp_get_upload_dir();
	if (empty($uploads['baseurl']) || strpos((string) $url, $uploads['baseurl'] . '/rapidchat/') !== 0) {
		return;
	}

	$path = realpath($uploads['basedir'] . substr((string) $url, strlen($uploads['baseurl'])));
	$base = realpath($uploads['basedir'] . '/rapidchat');

	if ($path && $base && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
		wp_delete_file($path);
	}
}
