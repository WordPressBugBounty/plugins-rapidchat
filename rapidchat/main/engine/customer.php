<?php
/** Customer (visitor) identity engine. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

require_once RAPIDCHAT_DIR . '/main/engine/events.php';

/** Identify a visitor and bind a chat session to their browser. */
function rapidchat_customer_identify(array $args) {
	global $wpdb;

	$name  = isset($args['name']) ? sanitize_text_field((string) $args['name']) : '';
	$email = isset($args['email']) ? sanitize_email((string) $args['email']) : '';
	$ip    = isset($args['ip']) ? (string) $args['ip'] : '';
	$actor = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();

	$returning = !empty($actor['id']) && isset($actor['role']) && $actor['role'] === 'Guest';

	$limit_key = $returning ? 'auth_account_' . (int) $actor['id'] : 'auth_' . $ip;
	if (!rapidchat_rate_limit($limit_key, 10, 10 * MINUTE_IN_SECONDS)) {
		return new WP_Error(
			'rapidchat_rate_limited',
			__('Too many attempts. Please wait a few minutes and try again.', 'rapidchat'),
			array('status' => 429)
		);
	}

	if ($name === '' || $email === '') {
		return new WP_Error(
			'rapidchat_invalid_input',
			__('Please enter a valid name and email address!', 'rapidchat'),
			array('status' => 422)
		);
	}

	if (!is_email($email)) {
		return new WP_Error(
			'rapidchat_invalid_email',
			__('Please enter a valid email address!', 'rapidchat'),
			array('status' => 422)
		);
	}

	$name = mb_substr($name, 0, 100);

	rapidchat_event('before_guest_login', $email, $name);

	if ($returning) {
		$account_id = (int) $actor['id'];

		$wpdb->update(
			$wpdb->rapidchat_accounts,
			array('full_name' => $name, 'email' => $email, 'last_seen' => current_time('mysql')),
			array('id' => $account_id),
			array('%s', '%s', '%s'),
			array('%d')
		);
		rapidchat_issue_token($account_id);
		rapidchat_event('after_guest_login', $account_id, $email, $name);

		return array('id' => $account_id, 'created' => false);
	}

	$inserted = $wpdb->insert(
		$wpdb->rapidchat_accounts,
		array(
			'email'      => $email,
			'full_name'  => $name,
			'role'       => 'Guest',
			'last_seen'  => current_time('mysql'),
			'ip_address' => $ip,
			'country'    => '',
			'status'     => 'Idle',
		),
		array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
	);

	if (!$inserted) {
		return new WP_Error(
			'rapidchat_insert_failed',
			__('Could not start a chat session. Please try again.', 'rapidchat'),
			array('status' => 500)
		);
	}

	$account_id = (int) $wpdb->insert_id;

	rapidchat_issue_token($account_id);
	rapidchat_schedule_country_lookup($account_id, $ip);
	rapidchat_event('after_guest_login', $account_id, $email, $name);

	return array('id' => $account_id, 'created' => true);
}
