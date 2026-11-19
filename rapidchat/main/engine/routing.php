<?php
/** Routing engine: who a conversation is handed to. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Hand a conversation to one operator. */
function rapidchat_claim_conversation($conversation_id, $operator_id) {
	global $wpdb;

	$wpdb->query($wpdb->prepare(
		"INSERT IGNORE INTO %i (conversation_id, operator_id, is_available) VALUES (%d, %d, 1)",
		$wpdb->rapidchat_participants,
		$conversation_id,
		$operator_id
	));

	$wpdb->query($wpdb->prepare(
		"UPDATE %i SET is_available = 2 WHERE conversation_id = %d",
		$wpdb->rapidchat_participants,
		$conversation_id
	));

	$wpdb->query($wpdb->prepare(
		"UPDATE %i SET is_available = 0 WHERE conversation_id = %d AND operator_id = %d",
		$wpdb->rapidchat_participants,
		$conversation_id,
		$operator_id
	));
}

/** Claim a queued conversation only if nobody holds it yet. */
function rapidchat_claim_if_unclaimed($conversation_id, $operator_id) {
	global $wpdb;

	$conversation_id = (int) $conversation_id;
	$operator_id     = (int) $operator_id;
	$lock            = 'rapidchat_claim_' . $conversation_id;

	if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) {
		return false;
	}

	$holder = (int) $wpdb->get_var($wpdb->prepare(
		'SELECT operator_id FROM %i
		 WHERE conversation_id = %d AND is_available = 0 LIMIT 1',
		$wpdb->rapidchat_participants,
		$conversation_id
	));

	if ($holder === 0) {
		rapidchat_claim_conversation($conversation_id, $operator_id);
	}

	$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));

	return $holder === 0 || $holder === $operator_id;
}

/** Operators currently working a conversation: the holder first, then joined co-agents. */
function rapidchat_conversation_team($conversation_id) {
	global $wpdb;

	return $wpdb->get_results($wpdb->prepare(
		'SELECT a.id, a.full_name, p.is_available AS state
		 FROM %i p
		 JOIN %i a ON a.id = p.operator_id
		 WHERE p.conversation_id = %d AND p.is_available IN (0, 3)
		 ORDER BY p.is_available ASC, a.full_name ASC',
		$wpdb->rapidchat_participants,
		$wpdb->rapidchat_accounts,
		(int) $conversation_id
	), ARRAY_A);
}

/** Is $account_id an operator who can currently take chats? */
function rapidchat_is_operator_account($account_id) {
	global $wpdb;

	return (bool) $wpdb->get_var($wpdb->prepare(
		'SELECT id FROM %i' . "
		 WHERE id = %d AND role IN ('Manager','Operator','Agent')",
		$wpdb->rapidchat_accounts,
		(int) $account_id
	));
}

function rapidchat_pick_operator($team_id = 0) {
	$picked = (int) apply_filters('rapidchat_pick_operator', 0, (int) $team_id);
	if ($picked > 0) {
		return $picked;
	}

	return rapidchat_least_busy_operator();
}

/** The operator holding the fewest open chats, preferring those seen in the last three minutes; optionally only among $account_ids. */
function rapidchat_least_busy_operator($account_ids = null) {
	global $wpdb;

	if ($account_ids !== null) {
		$account_ids = array_values(array_filter(array_map('intval', (array) $account_ids)));
		if (!$account_ids) {
			return 0;
		}
	}

	foreach (array(rapidchat_time_ago(3 * MINUTE_IN_SECONDS), '1000-01-01 00:00:00') as $seen_since) {
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT a.id
				FROM %i a
				LEFT JOIN %i p ON p.operator_id = a.id AND p.is_available = 0
				WHERE a.role IN ('Operator','Agent','Manager') AND COALESCE(a.last_seen, '1000-01-01 00:00:00') >= %s
				GROUP BY a.id
				ORDER BY COUNT(p.id) ASC, a.last_seen DESC",
			$wpdb->rapidchat_accounts,
			$wpdb->rapidchat_participants,
			$seen_since
		));

		foreach (array_map('intval', (array) $ids) as $id) {
			if ($account_ids === null || in_array($id, $account_ids, true)) {
				return $id;
			}
		}
	}

	return 0;
}
