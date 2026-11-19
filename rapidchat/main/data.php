<?php
/** Table access and shared data operations. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** RapidChat's tables: short name => unprefixed table name. */
function rapidchat_table_names() {
	return (array) apply_filters('rapidchat_tables', array(
		'accounts'         => 'rapidchat_accounts',
		'conversations'    => 'rapidchat_conversations',
		'messages'         => 'rapidchat_messages',
		'participants'     => 'rapidchat_participants',
		'canned_responses' => 'rapidchat_canned_responses',
	));
}

/** The prefixed name of table $name ('' when there is no such table). */
function rapidchat_table($name) {
	global $wpdb;

	$map = rapidchat_table_names();

	return isset($map[$name]) ? $wpdb->prefix . $map[$name] : '';
}

function rapidchat_register_tables() {
	global $wpdb;

	foreach (rapidchat_table_names() as $table) {
		$wpdb->$table = $wpdb->prefix . $table;
	}
}

/** Bulk-fetch display names for a set of account ids (avoids a per-message query). */
function rapidchat_account_names(array $account_ids) {
	global $wpdb;

	$account_ids = array_values(array_unique(array_map('intval', $account_ids)));
	if (!$account_ids) {
		return array();
	}

	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, full_name FROM %i WHERE id IN (' . implode(',', array_fill(0, count($account_ids), '%d')) . ')',
			array_merge(array($wpdb->rapidchat_accounts), $account_ids)
		),
		OBJECT_K
	);
}

function rapidchat_touch_conversation($conversation_id) {
	global $wpdb;

	$wpdb->update(
		$wpdb->rapidchat_conversations,
		array('last_activity' => current_time('mysql')),
		array('id' => (int) $conversation_id),
		array('%s'),
		array('%d')
	);
}

function rapidchat_time_ago($seconds) {
	return wp_date('Y-m-d H:i:s', time() - (int) $seconds);
}

/** Return the number of operators seen within the last 3 minutes. */
function rapidchat_operators_online_count() {
	global $wpdb;

	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM %i
		 WHERE role IN ('Operator','Agent','Manager')
		   AND last_seen >= %s",
		$wpdb->rapidchat_accounts,
		rapidchat_time_ago(3 * MINUTE_IN_SECONDS)
	));
}

/** Record typing state for an account in a conversation. */
function rapidchat_set_typing($conversation_id, $account_id, $peek = '') {
	$key = 'rapidchat_type_' . (int) $conversation_id . '_' . (int) $account_id;
	set_transient($key, time(), 4);

	$peek_key = 'rapidchat_peek_' . (int) $conversation_id . '_' . (int) $account_id;
	if ($peek !== '') {
		set_transient($peek_key, mb_substr(sanitize_text_field($peek), 0, 200), 6);
	} else {
		delete_transient($peek_key);
	}
}

/** Check if someone else in the conversation is actively typing. */
function rapidchat_check_typing($conversation_id, $viewer_account_id) {
	global $wpdb;

	$conversation_id   = (int) $conversation_id;
	$viewer_account_id = (int) $viewer_account_id;

	if ($conversation_id <= 0) {
		return array('typing' => false, 'name' => '', 'peek' => '');
	}

	$participants = $wpdb->get_results($wpdb->prepare(
		'SELECT operator_id AS id FROM %i WHERE conversation_id = %d AND is_available = 0
		 UNION
		 SELECT account_sender_id AS id FROM %i WHERE id = %d',
		$wpdb->rapidchat_participants,
		$conversation_id,
		$wpdb->rapidchat_conversations,
		$conversation_id
	));

	foreach ($participants as $p) {
		$account_id = (int) $p->id;
		if ($account_id === $viewer_account_id || $account_id <= 0) {
			continue;
		}

		$key = 'rapidchat_type_' . $conversation_id . '_' . $account_id;
		if (get_transient($key) !== false) {
			$name = (string) $wpdb->get_var($wpdb->prepare(
				'SELECT full_name FROM %i WHERE id = %d',
				$wpdb->rapidchat_accounts,
				$account_id
			));
			$peek = (string) get_transient('rapidchat_peek_' . $conversation_id . '_' . $account_id);
			return array('typing' => true, 'name' => $name, 'peek' => $peek);
		}
	}

	return array('typing' => false, 'name' => '', 'peek' => '');
}

/** Return predefined standard conversation tags with colors. */
function rapidchat_get_standard_tags() {
	$defaults = array(
		'VIP'        => '#8b5cf6',
		'Sales'      => '#10b981',
		'Support'    => '#3b82f6',
		'Billing'    => '#f59e0b',
		'Bug'        => '#ef4444',
		'Feature'    => '#06b6d4',
		'Resolved'   => '#6b7280',
	);

	return apply_filters('rapidchat_conversation_tags_list', $defaults);
}

/** Remove conversations and everything hanging off them, atomically. */
function rapidchat_delete_conversations(array $conversation_ids) {
	global $wpdb;

	$conversation_ids = array_values(array_filter(array_map('intval', $conversation_ids)));
	if (!$conversation_ids) {
		return 0;
	}

	$wpdb->query('START TRANSACTION');

	$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE conversation_id IN (' . implode(',', array_fill(0, count($conversation_ids), '%d')) . ')', array_merge(array($wpdb->rapidchat_messages), $conversation_ids)));
	$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE conversation_id IN (' . implode(',', array_fill(0, count($conversation_ids), '%d')) . ')', array_merge(array($wpdb->rapidchat_participants), $conversation_ids)));
	$deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE id IN (' . implode(',', array_fill(0, count($conversation_ids), '%d')) . ')', array_merge(array($wpdb->rapidchat_conversations), $conversation_ids)));

	$wpdb->query('COMMIT');

	return (int) $deleted;
}

/** The name a visitor sees for a staff member: "Admin" in RapidChat. */
function rapidchat_visitor_agent_name($real_name, $account_id = 0) {
	return (string) apply_filters('rapidchat_visitor_agent_name', __('Admin', 'rapidchat'), (string) $real_name, (int) $account_id);
}

function rapidchat_names_for_visitor(array $names, $visitor_id) {
	foreach ($names as $id => $row) {
		if ((int) $id !== (int) $visitor_id && is_object($row)) {
			$row            = clone $row;
			$row->full_name = rapidchat_visitor_agent_name($row->full_name, (int) $id);
			$names[$id]     = $row;
		}
	}

	return $names;
}

/** Shared shape for a message row sent to either client. */
function rapidchat_format_message($message, array $names, $viewer_account_id, array $operator_ids = array()) {
	$account_id = (int) $message->account_id;
	$msg_type   = !empty($message->msg_type) ? sanitize_key($message->msg_type) : 'message';

	return array(
		'id'              => (int) $message->id,
		'mine'            => $account_id === (int) $viewer_account_id,
		'staff'           => in_array($account_id, $operator_ids, true),
		'author'          => isset($names[$account_id]) ? $names[$account_id]->full_name : '',
		'msg'             => $message->msg,
		'msg_type'        => $msg_type,
		'attachment_url'  => !empty($message->attachment_url) ? esc_url_raw($message->attachment_url) : '',
		'attachment_type' => !empty($message->attachment_type) ? sanitize_key($message->attachment_type) : '',
		'attachment_name' => !empty($message->attachment_name) ? esc_html($message->attachment_name) : '',
		'attachment_size' => !empty($message->attachment_size) ? (int) $message->attachment_size : 0,
		'date'            => mysql2date('Y/m/d', $message->submit_date),
		'time'            => mysql2date('H:i', $message->submit_date),
		'ts'              => (int) mysql2date('U', $message->submit_date),
	);
}

function rapidchat_conversation_read_through($conversation_id, $account_id) {
	global $wpdb;

	return (int) $wpdb->get_var($wpdb->prepare(
		'SELECT MAX(id) FROM %i
		 WHERE conversation_id = %d AND account_id = %d AND is_read = 1',
		$wpdb->rapidchat_messages,
		(int) $conversation_id,
		(int) $account_id
	));
}

function rapidchat_is_public_ip($ip) {
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
		return false;
	}

	$long = ip2long($ip);
	if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
		return false;
	}

	return true;
}

/** The client IP. */
function rapidchat_client_ip() {
	if (get_option('rapidchat_trust_proxy')) {
		foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP') as $header) {
			if (!empty($_SERVER[$header])) {
				$candidate = trim(sanitize_text_field(wp_unslash($_SERVER[$header])));
				if (filter_var($candidate, FILTER_VALIDATE_IP)) {
					return $candidate;
				}
			}
		}

		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$public = null;
			foreach (explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))) as $hop) {
				$hop = trim($hop);
				if ($public === null && rapidchat_is_public_ip($hop)) {
					$public = $hop;
				}
			}
			if ($public !== null) {
				return $public;
			}
		}
	}

	$remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

	return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
}

/** Country from the browser's timezone; the language tag is never used, it says nothing about location. */
function rapidchat_country_from_hint(array $hint) {
	$timezone = isset($hint['timezone']) ? (string) $hint['timezone'] : '';
	if ($timezone === '' || !preg_match('#^[A-Za-z]+/[A-Za-z0-9_+/-]+$#', $timezone)) {
		return '';
	}

	// Browsers report ICU's legacy IDs (Chrome: Asia/Calcutta), which PHP knows only as aliases with no country.
	$legacy = array(
		'Africa/Asmera'          => 'ER',
		'America/Buenos_Aires'   => 'AR',
		'America/Catamarca'      => 'AR',
		'America/Coral_Harbour'  => 'CA',
		'America/Cordoba'        => 'AR',
		'America/Godthab'        => 'GL',
		'America/Indianapolis'   => 'US',
		'America/Jujuy'          => 'AR',
		'America/Louisville'     => 'US',
		'America/Mendoza'        => 'AR',
		'America/Montreal'       => 'CA',
		'America/Nipigon'        => 'CA',
		'America/Pangnirtung'    => 'CA',
		'America/Rainy_River'    => 'CA',
		'America/Santa_Isabel'   => 'MX',
		'America/Thunder_Bay'    => 'CA',
		'America/Yellowknife'    => 'CA',
		'Asia/Calcutta'          => 'IN',
		'Asia/Choibalsan'        => 'MN',
		'Asia/Katmandu'          => 'NP',
		'Asia/Rangoon'           => 'MM',
		'Asia/Saigon'            => 'VN',
		'Atlantic/Faeroe'        => 'FO',
		'Australia/Currie'       => 'AU',
		'Europe/Kiev'            => 'UA',
		'Europe/Uzhgorod'        => 'UA',
		'Europe/Zaporozhye'      => 'UA',
		'Pacific/Enderbury'      => 'KI',
		'Pacific/Johnston'       => 'UM',
		'Pacific/Ponape'         => 'FM',
		'Pacific/Truk'           => 'FM',
	);
	if (isset($legacy[$timezone])) {
		return $legacy[$timezone];
	}

	try {
		$location = (new DateTimeZone($timezone))->getLocation();
	} catch (Exception $e) {
		return '';
	}

	return is_array($location) && !empty($location['country_code']) && $location['country_code'] !== '??' ? strtoupper($location['country_code']) : '';
}

/** Geolocation is resolved out of band. */
function rapidchat_schedule_country_lookup($account_id, $ip) {
	if (!$ip || !get_option('rapidchat_geolocate', 0)) {
		return;
	}

	wp_schedule_single_event(time() + 5, 'rapidchat_resolve_country', array((int) $account_id, $ip));
}

/** Cron worker. */
function rapidchat_resolve_country_event($account_id, $ip) {
	global $wpdb;

	$country = rapidchat_lookup_country($ip);
	if (!$country) {
		return;
	}

	$wpdb->update(
		$wpdb->rapidchat_accounts,
		array('country' => $country),
		array('id' => (int) $account_id),
		array('%s'),
		array('%d')
	);

	$wpdb->query($wpdb->prepare(
		'UPDATE %i
		 SET country = %s WHERE account_sender_id = %d AND country = %s',
		$wpdb->rapidchat_conversations,
		$country,
		(int) $account_id,
		''
	));
}

function rapidchat_lookup_country($ip) {
	if (!rapidchat_is_public_ip($ip)) {
		return '';
	}

	$cache_key = 'rapidchat_geo_' . md5($ip);
	$cached    = get_transient($cache_key);
	if ($cached !== false) {
		return $cached;
	}

	$response = wp_remote_get(
		'https://api.ipbase.com/v1/json/' . rawurlencode($ip),
		array('timeout' => 3, 'redirection' => 1)
	);

	if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
		set_transient($cache_key, '', HOUR_IN_SECONDS);
		return '';
	}

	$data    = json_decode(wp_remote_retrieve_body($response), true);
	$country = '';
	if (is_array($data) && !empty($data['country_code'])) {
		$country = substr(preg_replace('/[^A-Za-z]/', '', $data['country_code']), 0, 2);
	}

	set_transient($cache_key, $country, $country === '' ? HOUR_IN_SECONDS : WEEK_IN_SECONDS);

	return $country;
}

/** Convert Hex color string to RGB integer array. */
function rapidchat_hex_to_rgb($hex) {
	$hex = ltrim((string) $hex, '#');
	if (strlen($hex) === 3) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
		return array(30, 60, 114);
	}
	return array(
		(int) hexdec(substr($hex, 0, 2)),
		(int) hexdec(substr($hex, 2, 2)),
		(int) hexdec(substr($hex, 4, 2)),
	);
}

/** Adjust Hex color brightness (positive steps = lighter, negative = darker). */
function rapidchat_adjust_brightness($hex, $steps) {
	$rgb = rapidchat_hex_to_rgb($hex);
	$out = array();
	foreach ($rgb as $c) {
		$out[] = str_pad(dechex(max(0, min(255, (int) ($c + $steps)))), 2, '0', STR_PAD_LEFT);
	}
	return '#' . implode('', $out);
}

/** Calculate color contrast luminance and return suitable text color (#ffffff or #0f172a). */
function rapidchat_color_contrast($hex) {
	$rgb = rapidchat_hex_to_rgb($hex);
	$r   = $rgb[0] / 255;
	$g   = $rgb[1] / 255;
	$b   = $rgb[2] / 255;
	$lum = 0.2126 * ($r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4))
		+ 0.7152 * ($g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4))
		+ 0.0722 * ($b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4));

	return ($lum > 0.45) ? '#0f172a' : '#ffffff';
}

/** Generate CSS custom properties string for dynamic widget theming. */
function rapidchat_generate_theme_styles($primary_hex) {
	$primary_hex = sanitize_hex_color($primary_hex) ?: '#1e3c72';
	$rgb         = rapidchat_hex_to_rgb($primary_hex);
	$rgb_str     = implode(', ', $rgb);
	$light_hex   = rapidchat_adjust_brightness($primary_hex, 28);
	$dark_hex    = rapidchat_adjust_brightness($primary_hex, -25);
	$text_color  = rapidchat_color_contrast($primary_hex);
	$is_dark_txt = ($text_color === '#0f172a');
	$icon_bg     = $is_dark_txt ? 'rgba(0, 0, 0, 0.08)' : 'rgba(255, 255, 255, 0.22)';

	return "--rc-primary: {$primary_hex}; "
		. "--rc-primary-light: {$light_hex}; "
		. "--rc-primary-dark: {$dark_hex}; "
		. "--rc-primary-rgb: {$rgb_str}; "
		. "--rc-primary-gradient: linear-gradient(135deg, {$primary_hex} 0%, {$light_hex} 100%); "
		. "--rc-primary-glow: rgba({$rgb_str}, 0.35); "
		. "--rc-header-text-color: {$text_color}; "
		. "--rc-header-icon-bg: {$icon_bg};";
}

function rapidchat_encode_emoji_option($value) {
	return wp_encode_emoji((string) $value);
}

function rapidchat_decode_emoji_option($value) {
	return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
}

function rapidchat_widget_flag($flag) {
	return (int) apply_filters('rapidchat_widget_' . $flag, 0);
}

