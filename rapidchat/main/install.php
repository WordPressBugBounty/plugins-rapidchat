<?php
/** Schema, migration and capability registration. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

function rapidchat_install() {
	global $wpdb;

	$charset_collate  = $wpdb->get_charset_collate();

	$sql = array();

	$sql[] = "CREATE TABLE {$wpdb->rapidchat_accounts} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		email varchar(191) NOT NULL DEFAULT '',
		full_name varchar(191) NOT NULL DEFAULT '',
		role varchar(20) NOT NULL DEFAULT 'Guest',
		session_token_hash char(64) NOT NULL DEFAULT '',
		token_expires datetime DEFAULT NULL,
		ip_address varchar(45) NOT NULL DEFAULT '',
		country varchar(100) NOT NULL DEFAULT '',
		last_seen datetime DEFAULT NULL,
		status varchar(20) NOT NULL DEFAULT 'Idle',
		PRIMARY KEY  (id),
		KEY wp_user_id (wp_user_id),
		KEY email (email),
		KEY session_token_hash (session_token_hash),
		KEY role_status (role,status)
	) $charset_collate;";

	$sql[] = "CREATE TABLE {$wpdb->rapidchat_conversations} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		account_sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
		account_receiver_id bigint(20) unsigned NOT NULL DEFAULT 0,
		country varchar(100) NOT NULL DEFAULT '',
		state varchar(20) NOT NULL DEFAULT 'open',
		rating tinyint(2) unsigned DEFAULT NULL,
		feedback text DEFAULT NULL,
		closed_at datetime DEFAULT NULL,
		custom_fields text DEFAULT NULL,
		submit_date datetime DEFAULT NULL,
		last_activity datetime DEFAULT NULL,
		team_id bigint(20) unsigned NOT NULL DEFAULT 0,
		source varchar(20) NOT NULL DEFAULT 'chat',
		open_sender_id bigint(20) unsigned DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY account_sender_id (account_sender_id),
		KEY account_receiver_id (account_receiver_id),
		KEY state_activity (state,last_activity),
		KEY team_state (team_id,state),
		KEY rating (rating),
		UNIQUE KEY open_conversation_per_sender (open_sender_id)
	) $charset_collate;";

	$sql[] = "CREATE TABLE {$wpdb->rapidchat_messages} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
		account_id bigint(20) unsigned NOT NULL DEFAULT 0,
		msg text NOT NULL,
		attachment_url varchar(500) NOT NULL DEFAULT '',
		attachment_type varchar(50) NOT NULL DEFAULT '',
		attachment_name varchar(255) NOT NULL DEFAULT '',
		attachment_size int(10) unsigned NOT NULL DEFAULT 0,
		msg_type varchar(20) NOT NULL DEFAULT 'message',
		submit_date datetime DEFAULT NULL,
		is_read tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY conversation_seq (conversation_id,id),
		KEY conversation_unread (conversation_id,is_read),
		KEY conversation_type (conversation_id,msg_type),
		KEY account_id (account_id)
	) $charset_collate;";

	$sql[] = "CREATE TABLE {$wpdb->rapidchat_participants} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
		operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
		is_available tinyint(1) NOT NULL DEFAULT 1,
		PRIMARY KEY  (id),
		UNIQUE KEY conversation_operator (conversation_id,operator_id),
		KEY operator_available (operator_id,is_available)
	) $charset_collate;";

	$sql[] = "CREATE TABLE {$wpdb->rapidchat_canned_responses} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		shortcut varchar(50) NOT NULL DEFAULT '',
		title varchar(100) NOT NULL DEFAULT '',
		content text NOT NULL,
		created_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY shortcut (shortcut)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	foreach ($sql as $statement) {
		dbDelta($statement);
	}

	rapidchat_seed_canned_responses();
	rapidchat_register_capabilities();
	rapidchat_verify_schema();

	update_option('rapidchat_db_version', RAPIDCHAT_DB_VERSION);
	update_option('rapidchat_caps_version', RAPIDCHAT_CAPS_VERSION);
}

/** Confirm the tables and columns the code actually writes to are really present. */
function rapidchat_verify_schema() {
	global $wpdb;

	$expected = array(
		'conversations'  => array('team_id', 'source', 'rating', 'feedback', 'closed_at'),
		'messages'       => array('attachment_url', 'attachment_type', 'attachment_name', 'attachment_size', 'msg_type'),
	);

	$expected = (array) apply_filters('rapidchat_expected_tables', $expected);

	$missing = array();

	foreach ($expected as $name => $columns) {
		$table = rapidchat_table($name);

		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
			$missing[] = $name;
			continue;
		}

		$present = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
		foreach (array_diff($columns, (array) $present) as $column) {
			$missing[] = $name . '.' . $column;
		}
	}

	update_option('rapidchat_schema_missing', $missing);

	return $missing;
}

function rapidchat_seed_canned_responses() {
	global $wpdb;

	$count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->rapidchat_canned_responses));
	if ($count > 0) {
		return;
	}

	$now = current_time('mysql');
	$defaults = array(
		array('shortcut' => 'hello', 'title' => 'Greeting', 'content' => "Hello! 👋 How can I help you today?"),
		array('shortcut' => 'wait', 'title' => 'Hold on', 'content' => "Thanks for waiting! Let me look into this for you right now."),
		array('shortcut' => 'links', 'title' => 'Documentation', 'content' => "You can find comprehensive guides and FAQs in our help center."),
		array('shortcut' => 'bye', 'title' => 'Closing', 'content' => "Thank you for chatting with us! Let us know if you need anything else."),
	);

	foreach ($defaults as $row) {
		$wpdb->insert(
			$wpdb->rapidchat_canned_responses,
			array(
				'shortcut'   => $row['shortcut'],
				'title'      => $row['title'],
				'content'    => $row['content'],
				'created_at' => $now,
			),
			array('%s', '%s', '%s', '%s')
		);
	}
}

require_once RAPIDCHAT_DIR . '/main/core/capabilities.php';
