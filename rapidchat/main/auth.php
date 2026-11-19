<?php
/** Identity, authorisation and rate limiting. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Issue a fresh opaque token for an account and hand it to the browser. */
function rapidchat_issue_token($account_id) {
	global $wpdb;

	$token   = bin2hex(random_bytes(32));
	$expires = time() + (30 * DAY_IN_SECONDS);

	$wpdb->update(
		$wpdb->rapidchat_accounts,
		array(
			'session_token_hash' => hash('sha256', $token),
			'token_expires'      => gmdate('Y-m-d H:i:s', $expires),
			'last_seen'          => current_time('mysql'),
		),
		array('id' => (int) $account_id),
		array('%s', '%s', '%s'),
		array('%d')
	);

	if (!headers_sent()) {
		// PHP 7.0 compatible setcookie() syntax (positional arguments)
		setcookie(
			RAPIDCHAT_COOKIE,
			(int) $account_id . ':' . $token,
			$expires,
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	$_COOKIE[RAPIDCHAT_COOKIE] = (int) $account_id . ':' . $token;
	rapidchat_current_account(true);

	return $token;
}

function rapidchat_clear_token() {
	global $wpdb;

	$account = rapidchat_current_account();
	if ($account) {
		$wpdb->update(
			$wpdb->rapidchat_accounts,
			array('session_token_hash' => '', 'token_expires' => null, 'status' => 'Idle'),
			array('id' => (int) $account['id']),
			array('%s', '%s', '%s'),
			array('%d')
		);
	}

	if (!headers_sent()) {
		// PHP 7.0 compatible setcookie() syntax for expiration / deletion
		setcookie(
			RAPIDCHAT_COOKIE,
			'',
			time() - 3600,
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	unset($_COOKIE[RAPIDCHAT_COOKIE]);
	rapidchat_current_account(true);
}

/** Resolve the chat account behind the current request's cookie, or null. */
function rapidchat_current_account($reset = false) {
	static $cached = false;

	if ($reset) {
		$cached = false;
		return null;
	}

	if ($cached !== false) {
		return $cached;
	}

	$cached = null;

	if (empty($_COOKIE[RAPIDCHAT_COOKIE])) {
		return null;
	}

	$raw = sanitize_text_field(wp_unslash($_COOKIE[RAPIDCHAT_COOKIE]));
	if (substr_count($raw, ':') !== 1) {
		return null;
	}

	list($account_id, $token) = explode(':', $raw, 2);
	$account_id = (int) $account_id;

	if ($account_id <= 0 || !ctype_xdigit($token) || strlen($token) !== 64) {
		return null;
	}

	global $wpdb;
	$account = $wpdb->get_row(
		$wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->rapidchat_accounts, $account_id),
		ARRAY_A
	);

	if (!$account || empty($account['session_token_hash'])) {
		return null;
	}

	if (!hash_equals($account['session_token_hash'], hash('sha256', $token))) {
		return null;
	}

	if (!empty($account['token_expires']) && strtotime($account['token_expires']) < time()) {
		return null;
	}

	$cached = $account;

	return $cached;
}

function rapidchat_operator_account() {
	static $cached = false;

	if ($cached !== false) {
		return $cached;
	}

	$cached = null;

	if (!is_user_logged_in()) {
		return null;
	}

	if (!current_user_can(RAPIDCHAT_CAP_OPERATE)) {
		return null;
	}

	global $wpdb;
	$user  = wp_get_current_user();

	$account = $wpdb->get_row(
		$wpdb->prepare("SELECT * FROM %i WHERE wp_user_id = %d", $wpdb->rapidchat_accounts, $user->ID),
		ARRAY_A
	);

	if ($account && $account['role'] !== 'Guest') {
		$role = rapidchat_role_for_user($user);
		if ($account['role'] !== $role) {
			$wpdb->update($wpdb->rapidchat_accounts, array('role' => $role), array('id' => (int) $account['id']), array('%s'), array('%d'));
			$account['role'] = $role;
		}
	}

	if (!$account) {
		$wpdb->insert(
			$wpdb->rapidchat_accounts,
			array(
				'wp_user_id' => $user->ID,
				'email'      => $user->user_email,
				'full_name'  => $user->display_name,
				'role'       => rapidchat_role_for_user($user),
				'last_seen'  => current_time('mysql'),
				'status'     => 'Idle',
			),
			array('%d', '%s', '%s', '%s', '%s', '%s')
		);

		if (!$wpdb->insert_id) {
			return null;
		}

		$account = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM %i WHERE id = %d", $wpdb->rapidchat_accounts, $wpdb->insert_id),
			ARRAY_A
		);
	}

	$cached = $account;

	return $cached;
}

/** The chat-account role for a WordPress user. */
function rapidchat_role_for_user($user) {
	if (!$user || empty($user->ID)) {
		return 'Inactive';
	}
	if (user_can($user, RAPIDCHAT_CAP_MANAGE) || user_can($user, 'rapidchat_assign')) {
		return 'Manager';
	}
	if (user_can($user, RAPIDCHAT_CAP_OPERATE)) {
		return 'Operator';
	}

	return 'Inactive';
}

/** Re-derive the stored role of the operator chat account linked to $user_id. */
function rapidchat_sync_account_role($user_id, $deleted = false) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ($user_id <= 0) {
		return;
	}

	$account = $wpdb->get_row($wpdb->prepare(
		"SELECT id, role FROM %i WHERE wp_user_id = %d AND role <> 'Guest'",
		$wpdb->rapidchat_accounts,
		$user_id
	), ARRAY_A);

	if (!$account) {
		return;
	}

	$role = $deleted ? 'Inactive' : rapidchat_role_for_user(get_userdata($user_id));
	if ($account['role'] !== $role) {
		$wpdb->update($wpdb->rapidchat_accounts, array('role' => $role), array('id' => (int) $account['id']), array('%s'), array('%d'));
	}
}

function rapidchat_sync_all_account_roles() {
	global $wpdb;

	$user_ids = $wpdb->get_col($wpdb->prepare(
		"SELECT wp_user_id FROM %i WHERE wp_user_id > 0 AND role <> 'Guest'",
		$wpdb->rapidchat_accounts
	));

	foreach ((array) $user_ids as $user_id) {
		rapidchat_sync_account_role((int) $user_id);
	}
}

function rapidchat_user_can($cap) {
	if (current_user_can($cap) || current_user_can(RAPIDCHAT_CAP_MANAGE)) {
		return true;
	}

	return ($cap === 'rapidchat_view' || $cap === 'rapidchat_reply') && current_user_can(RAPIDCHAT_CAP_OPERATE);
}

/** Team ids whose queue $account_id works, or null when every queue is theirs. */
function rapidchat_operator_team_ids($account_id) {
	$ids = apply_filters('rapidchat_operator_team_ids', null, (int) $account_id);

	return $ids === null ? null : array_values(array_unique(array_map('intval', (array) $ids)));
}

function rapidchat_operator_context($account_id, $conversation_id) {
	global $wpdb;

	$row = $wpdb->get_row($wpdb->prepare(
		'SELECT c.id, c.state, c.team_id,
				(SELECT p.is_available FROM %i p WHERE p.conversation_id = c.id AND p.operator_id = %d LIMIT 1) AS my_state,
				(SELECT h.operator_id FROM %i h WHERE h.conversation_id = c.id AND h.is_available = 0 LIMIT 1) AS holder_id
		 FROM %i c
		 WHERE c.id = %d',
		$wpdb->rapidchat_participants,
		(int) $account_id,
		$wpdb->rapidchat_participants,
		$wpdb->rapidchat_conversations,
		(int) $conversation_id
	), ARRAY_A);

	if (!$row) {
		return null;
	}

	$team_ids = rapidchat_operator_team_ids($account_id);

	return array(
		'state'     => (string) $row['state'],
		'team_id'   => (int) $row['team_id'],
		'my_state'  => ($row['my_state'] === null || $row['my_state'] === '') ? null : (int) $row['my_state'],
		'holder_id' => (int) $row['holder_id'],
		'in_team'   => $team_ids === null || in_array((int) $row['team_id'], $team_ids, true),
	);
}

function rapidchat_operator_can($action, $conversation_id, $account = null) {
	if ($account === null) {
		$account = rapidchat_operator_account();
	}

	$account_id      = is_array($account) && isset($account['id']) ? (int) $account['id'] : 0;
	$conversation_id = (int) $conversation_id;

	if ($account_id <= 0 || $conversation_id <= 0 || !rapidchat_user_can('rapidchat_view')) {
		return false;
	}

	if ($action === 'delete') {
		return rapidchat_user_can('rapidchat_manage_settings');
	}

	$ctx = rapidchat_operator_context($account_id, $conversation_id);
	if (!$ctx) {
		return false;
	}

	$can_assign = rapidchat_user_can('rapidchat_assign');
	$can_reply  = rapidchat_user_can('rapidchat_reply');
	$is_open    = ($ctx['state'] === 'open');
	$is_owner   = ($ctx['holder_id'] === $account_id);
	$is_joined  = ($ctx['my_state'] === 3);
	$involved   = $is_owner || $is_joined;
	$unclaimed  = ($ctx['holder_id'] === 0);
	$in_scope   = $can_assign || $ctx['team_id'] === 0 || $ctx['in_team'];

	switch ($action) {
		case 'view':
			return $can_assign || $involved || ($unclaimed && $in_scope);

		case 'claim':
			return $can_reply && $is_open && $unclaimed && $in_scope;

		case 'reply':
			return $can_reply && $is_open && ($involved || ($unclaimed && $in_scope));

		case 'note':
			return $can_reply && $is_open && ($involved || $can_assign || ($unclaimed && $in_scope));

		case 'manage':
			return $can_reply && ($involved || $can_assign || ($unclaimed && $in_scope));

		case 'close':
			return $is_open && (($can_reply && $is_owner) || $can_assign);
	}

	return (bool) apply_filters('rapidchat_operator_can', false, $action, array(
		'can_assign' => $can_assign,
		'can_reply'  => $can_reply,
		'is_open'    => $is_open,
		'is_owner'   => $is_owner,
		'is_joined'  => $is_joined,
		'involved'   => $involved,
		'unclaimed'  => $unclaimed,
		'in_scope'   => $in_scope,
	), $conversation_id, $account);
}

/**
 * Which conversations (aliased c) $operator_id may see, as SQL made only of
 * placeholders plus the values for them.
 */
function rapidchat_operator_visibility($operator_id) {
	global $wpdb;

	if (rapidchat_user_can('rapidchat_assign')) {
		return array('sql' => '', 'args' => array());
	}

	$team_ids = rapidchat_operator_team_ids($operator_id);
	$in_queue = '';
	if ($team_ids !== null) {
		$in_queue = ' AND (COALESCE(c.team_id, 0) = 0' . ($team_ids ? ' OR c.team_id IN (' . implode(',', array_fill(0, count($team_ids), '%d')) . ')' : '') . ')';
	}

	return array(
		'sql'  => ' AND (EXISTS (SELECT 1 FROM %i v_me WHERE v_me.conversation_id = c.id AND v_me.operator_id = %d AND v_me.is_available IN (0, 3))'
			. ' OR (NOT EXISTS (SELECT 1 FROM %i v_h WHERE v_h.conversation_id = c.id AND v_h.is_available = 0)' . $in_queue . '))',
		'args' => array_merge(array($wpdb->rapidchat_participants, (int) $operator_id, $wpdb->rapidchat_participants), (array) $team_ids),
	);
}

/** Is $account_id allowed to read $conversation_id? */
function rapidchat_can_access_conversation($account_id, $conversation_id, $is_operator = false) {
	static $cache = array();

	$account_id      = (int) $account_id;
	$conversation_id = (int) $conversation_id;

	if ($account_id <= 0 || $conversation_id <= 0) {
		return false;
	}

	$key = $account_id . '|' . $conversation_id . '|' . ($is_operator ? 1 : 0);
	if (isset($cache[$key])) {
		return $cache[$key];
	}

	global $wpdb;

	if ($is_operator) {
		return rapidchat_operator_can('view', $conversation_id, array('id' => $account_id));
	} else {
		$allowed = (bool) $wpdb->get_var($wpdb->prepare(
			'SELECT id FROM %i
			 WHERE id = %d AND (account_sender_id = %d OR account_receiver_id = %d)',
			$wpdb->rapidchat_conversations,
			$conversation_id,
			$account_id,
			$account_id
		));
	}

	$cache[$key] = $allowed;

	return $allowed;
}

/** Guest handler preamble: verify the nonce, then resolve the caller. */
function rapidchat_require_guest() {
	check_ajax_referer('rapidchat_user_nonce', 'rapidchat_nonce');

	$account = rapidchat_current_account();
	if (!$account) {
		wp_send_json_error(__('Your chat session has expired. Please start a new conversation.', 'rapidchat'), 401);
	}

	return $account;
}

/** Operator handler preamble: nonce, capability, then the operator's chat account. */
function rapidchat_require_operator($capability = 'rapidchat_view') {
	check_ajax_referer('rapidchat_admin_nonce', 'rapidchat_nonce');

	if (!rapidchat_user_can($capability)) {
		wp_send_json_error(__('You are not allowed to do that.', 'rapidchat'), 403);
	}

	$account = rapidchat_operator_account();
	if (!$account) {
		wp_send_json_error(__('No chat operator profile is available for your account.', 'rapidchat'), 403);
	}

	return $account;
}

/** Operator handler guard for one conversation: 403 unless the policy allows $action. */
function rapidchat_require_operator_can($action, $conversation_id, array $account) {
	if (!rapidchat_operator_can($action, $conversation_id, $account)) {
		wp_send_json_error(__('You are not allowed to do that for this conversation.', 'rapidchat'), 403);
	}
}

/** Fixed-window limiter backed by transients. */
function rapidchat_rate_limit($key, $max, $window) {
	global $wpdb;

	$transient = 'rapidchat_rl_' . md5($key);
	$lock_name = 'rapidchat_rl_' . md5($key);

	$acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lock_name));

	if ($acquired != 1) {
		return false;
	}

	$now    = time();
	$stored = get_transient($transient);
	$count  = is_array($stored) && isset($stored[0], $stored[1]) && (int) $stored[1] > $now ? (int) $stored[0] : 0;
	$ends   = $count > 0 ? (int) $stored[1] : $now + (int) $window;

	$allowed = $count < $max;

	if ($allowed) {
		set_transient($transient, array($count + 1, $ends), max(1, $ends - $now));
	}

	$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));

	return $allowed;
}

/** Send an engine result to an admin-ajax caller. */
function rapidchat_ajax_respond($result) {
	if (is_wp_error($result)) {
		$data   = $result->get_error_data();
		$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
		wp_send_json_error($result->get_error_message(), $status);
	}

	wp_send_json_success($result);
}