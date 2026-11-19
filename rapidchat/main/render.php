<?php
/** Shared presentation helpers: icons, formatting, and message markup. */

defined('ABSPATH') || exit;

/** Inline SVG icons. */
function rapidchat_icon($name, $size = 16) {
	static $paths = array(
		'comment-dots' => 'M12 2C6.5 2 2 5.9 2 10.7c0 2.7 1.4 5.1 3.6 6.7V22l3.3-1.8c1 .2 2 .4 3.1.4 5.5 0 10-3.9 10-8.7S17.5 2 12 2zm-4 10a1.3 1.3 0 1 1 0-2.6A1.3 1.3 0 0 1 8 12zm4 0a1.3 1.3 0 1 1 0-2.6A1.3 1.3 0 0 1 12 12zm4 0a1.3 1.3 0 1 1 0-2.6A1.3 1.3 0 0 1 16 12z',
		'comments'     => 'M20 2H8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h8l4 4V4a2 2 0 0 0 0-2zM4 8H3a1 1 0 0 0-1 1v12l4-4h8a1 1 0 0 0 1-1v-1H8a4 4 0 0 1-4-4V8z',
		'bars'         => 'M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z',
		'send'         => 'M2 21l21-9L2 3v7l15 2-15 2v7z',
		'inbox'        => 'M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 12h-4a3 3 0 0 1-6 0H5V5h14v10z',
		'tasks'        => 'M3 5h2v2H3V5zm4 0h14v2H7V5zM3 11h2v2H3v-2zm4 0h14v2H7v-2zm-4 6h2v2H3v-2zm4 0h14v2H7v-2z',
		'trash'        => 'M9 3h6l1 1h4v2H4V4h4l1-1zM6 8h12l-1 12a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 8z',
		'close'        => 'M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7l1.4-1.4L10.6 10.6l6.3-6.3z',
		'smile'        => 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm-3.5-9a1.5 1.5 0 1 0-1.5-1.5 1.5 1.5 0 0 0 1.5 1.5zm7 0a1.5 1.5 0 1 0-1.5-1.5 1.5 1.5 0 0 0 1.5 1.5zm-3.5 6a5.5 5.5 0 0 0 4.8-2.8.8.8 0 0 0-.3-1 .8.8 0 0 0-1 .3 4 4 0 0 1-7 0 .8.8 0 0 0-1-.3.8.8 0 0 0-.3 1 5.5 5.5 0 0 0 4.8 2.8z',
		'paperclip'    => 'M16.5 6v11.5a4 4 0 0 1-8 0V5a2.5 2.5 0 0 1 5 0v10.5a1 1 0 0 1-2 0V6H10v9.5a2.5 2.5 0 0 0 5 0V5a4 4 0 0 0-8 0v12.5a5.5 5.5 0 0 0 11 0V6z',
		'file'         => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm4 18H6V4h7v5h5z',
		'star'         => 'M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z',
		'home'         => 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
		'help'         => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 16h-2v-2h2v2zm1.07-7.75l-.9.92C12.45 11.9 12 12.5 12 14h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H7c0-2.76 2.24-5 5-5s5 2.24 5 5c0 .88-.36 1.68-.93 2.25z',
		'volume-up'    => 'M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z',
		'volume-mute'  => 'M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27l4.73 4.73H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z',
		'tag'          => 'M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z',
		'note'         => 'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10H7v-2h10v2zm0-4H7V7h10v2z',
		'lock'         => 'M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z',
		'sparkles'     => 'M9 4a1 1 0 0 1 1-1l.7 2.1a3 3 0 0 0 2.2 2.2L15 8l-2.1.7a3 3 0 0 0-2.2 2.2L10 13l-.7-2.1a3 3 0 0 0-2.2-2.2L5 8l2.1-.7a3 3 0 0 0 2.2-2.2L9 4zm10 8a.8.8 0 0 1 .7-.8l1.4-.5-1.4-.5a2.2 2.2 0 0 0-1.5-1.5L18 7.3l-.5 1.4a2.2 2.2 0 0 0-1.5 1.5l-1.4.5 1.4.5a2.2 2.2 0 0 0 1.5 1.5l.5 1.4.5-1.4a2.2 2.2 0 0 0 1.5-1.5z',
		'transfer'     => 'M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z',
		'eye'          => 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z',
		'clock'        => 'M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z',
		'globe'        => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z',
		'laptop'       => 'M20 18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zM4 6h16v10H4V6z',
		'building'     => 'M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z',
		'check'        => 'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z',
		'bell'         => 'M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z',
		'cog'          => 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 0 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z',
		'mail'         => 'M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
		'times'        => 'M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7l1.4-1.4L10.6 10.6l6.3-6.3z',
		'user'         => 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z',
		'user-plus'    => 'M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z',
		'exit'         => 'M10.09 15.59 11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5a2 2 0 0 0-2 2v4h2V5h14v14H5v-4H3v4a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z',
		'chart'        => 'M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z',
		'users'        => 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z',
		'hand'         => 'M21 6.5V18c0 2.2-1.8 4-4 4h-6.1c-1.1 0-2.1-.4-2.8-1.2L2 14.6l1.2-1.2c.3-.3.7-.4 1.1-.4.3 0 .5.1.8.2L8 15V5a1.5 1.5 0 0 1 3 0v6h1V3a1.5 1.5 0 0 1 3 0v8h1V4.5a1.5 1.5 0 0 1 3 0V11h1V6.5a1.5 1.5 0 0 1 3 0z',
		'check-double' => 'M0.5 12.5l4 4 10-10-1.4-1.4-8.6 8.6-2.6-2.6-1.4 1.4zm6.6 0l4 4 10-10-1.4-1.4-8.6 8.6-2.6-2.6-1.4 1.4z',
	);

	if (!isset($paths[$name])) {
		return '';
	}

	return sprintf(
		'<svg class="rapidchat-icon rapidchat-icon-%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24"'
			. ' fill="currentColor" aria-hidden="true" focusable="false"><path d="%3$s"/></svg>',
		esc_attr($name),
		(int) $size,
		esc_attr($paths[$name])
	);
}

/** Echo an icon, escaped through wp_kses. */
function rapidchat_icon_e($name, $size = 16) {
	echo wp_kses(rapidchat_icon($name, $size), rapidchat_kses_allowed());
}

/** Tags and attributes RapidChat markup uses: post HTML plus inline SVG and form controls. */
function rapidchat_kses_allowed() {
	static $allowed = null;

	if ($allowed !== null) {
		return $allowed;
	}

	$allowed = wp_kses_allowed_html('post');

	$common = array(
		'class'         => true,
		'id'            => true,
		'style'         => true,
		'title'         => true,
		'role'          => true,
		'hidden'        => true,
		'tabindex'      => true,
		'aria-label'    => true,
		'aria-hidden'   => true,
		'aria-expanded' => true,
		'aria-current'  => true,
		'aria-pressed'  => true,
		'aria-controls' => true,
		'aria-live'     => true,
		'data-*'        => true,
	);

	$extra = array(
		'svg'      => array('width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'focusable' => true, 'xmlns' => true),
		'path'     => array('d' => true, 'fill' => true),
		'form'     => array('method' => true, 'action' => true, 'autocomplete' => true, 'novalidate' => true, 'enctype' => true),
		'input'    => array('type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'maxlength' => true, 'required' => true, 'readonly' => true, 'disabled' => true, 'checked' => true, 'accept' => true, 'autocomplete' => true, 'pattern' => true, 'for' => true, 'multiple' => true),
		'textarea' => array('name' => true, 'rows' => true, 'cols' => true, 'placeholder' => true, 'maxlength' => true, 'required' => true, 'readonly' => true, 'disabled' => true, 'autocomplete' => true),
		'select'   => array('name' => true, 'required' => true, 'disabled' => true, 'multiple' => true),
		'option'   => array('value' => true, 'selected' => true, 'disabled' => true),
		'button'   => array('type' => true, 'name' => true, 'value' => true, 'disabled' => true, 'form' => true),
		'label'    => array('for' => true),
		'a'        => array('href' => true, 'target' => true, 'rel' => true, 'download' => true),
		'img'      => array('src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true, 'decoding' => true),
		'nav'      => array(),
		'aside'    => array(),
		'header'   => array(),
	);

	foreach ($extra as $tag => $attrs) {
		$allowed[$tag] = array_merge(isset($allowed[$tag]) ? $allowed[$tag] : array(), $attrs);
	}

	foreach ($allowed as $tag => $attrs) {
		$allowed[$tag] = array_merge(is_array($attrs) ? $attrs : array(), $common);
	}

	return (array) apply_filters('rapidchat_kses_allowed', $allowed);
}

/** Escape a block of RapidChat markup for output. */
function rapidchat_kses($html) {
	return wp_kses((string) $html, rapidchat_kses_allowed());
}

function rapidchat_flag_url($country_code) {
	$country_code = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $country_code));
	if (strlen($country_code) !== 2 || !file_exists(RAPIDCHAT_DIR . "/images/flags/$country_code.png")) {
		return '';
	}

	return RAPIDCHAT_URL . "/images/flags/$country_code.png";
}

/** Country flag. */
function rapidchat_flag($country_code) {
	$url = rapidchat_flag_url($country_code);

	if (!$url) {
		return '<span class="country-flag country-flag-unknown" aria-hidden="true">?</span>';
	}

	return sprintf(
		'<img src="%s" alt="%s" class="country-flag" width="24" height="18" loading="lazy" decoding="async">',
		esc_url($url),
		esc_attr(strtoupper($country_code))
	);
}

/** Stable avatar colour derived from a name. */
function rapidchat_avatar_color($string) {
	$colors = array(
		'#34568B', '#FF6F61', '#6B5B95', '#88B04B', '#F7CAC9', '#92A8D1', '#955251', '#B565A7', '#009B77',
		'#DD4124', '#D65076', '#45B8AC', '#EFC050', '#5B5EA6', '#9B2335', '#DFCFBE', '#BC243C', '#C3447A',
		'#363945', '#939597', '#E0B589', '#926AA6', '#0072B5', '#E9897E', '#B55A30', '#4B5335', '#798EA4',
		'#00758F', '#FA7A35', '#6B5876', '#B89B72', '#282D3C', '#C48A69', '#A2242F', '#006B54', '#6A2E2A',
		'#6C244C', '#755139', '#615550', '#5A3E36', '#264E36', '#577284', '#944743', '#00A591',
		'#6C4F3D', '#BD3D3A', '#7F4145', '#485167', '#5A7247', '#D2691E', '#F7786B', '#91A8D0', '#4C6A92',
		'#838487', '#AD5D5D', '#006E51', '#9E4624',
	);

	$index = hexdec(substr(sha1((string) $string), 0, 7)) % count($colors);

	return $colors[$index];
}

function rapidchat_preview($text, $length = 60) {
	$text = trim(preg_replace('/\s+/', ' ', (string) $text));

	return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
}

function rapidchat_message_body_html($text, $markdown = false) {
	$escaped = esc_html((string) $text);

	if ($markdown) {
		$escaped = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $escaped);
		$escaped = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $escaped);
	}

	$html = make_clickable(nl2br($escaped));

	return preg_replace(
		'/<a\s+(href="[^"]*")[^>]*>/i',
		'<a target="_blank" rel="noopener nofollow" $1>',
		$html
	);
}

function rapidchat_short_date($mysql_date) {
	if (!$mysql_date) {
		return '';
	}

	return mysql2date('Y/m/d', $mysql_date) === current_time('Y/m/d')
		? mysql2date('H:i', $mysql_date)
		: mysql2date('d/m/y', $mysql_date);
}

/** "Today", "Yesterday", or a short date for a Y/m/d day stamp. */
function rapidchat_date_divider_label($ymd) {
	$ymd = (string) $ymd;

	if ($ymd === wp_date('Y/m/d')) {
		return __('Today', 'rapidchat');
	}
	if ($ymd === wp_date('Y/m/d', time() - DAY_IN_SECONDS)) {
		return __('Yesterday', 'rapidchat');
	}

	$ts = strtotime(str_replace('/', '-', $ymd));

	return $ts ? date_i18n(_x('M j, Y', 'chat date divider', 'rapidchat'), $ts) : $ymd;
}

function rapidchat_initial($name) {
	$name = trim((string) $name);

	return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
}

/** Shared message-list markup. */
/** Do two messages belong to the same visual run? */
function rapidchat_messages_grouped($previous, $current) {
	if (!$previous || !$current) {
		return false;
	}

	if ((int) $previous->account_id !== (int) $current->account_id) {
		return false;
	}

	$window = (int) apply_filters('rapidchat_message_group_window', 5 * MINUTE_IN_SECONDS);
	$gap    = abs((int) mysql2date('U', $current->submit_date) - (int) mysql2date('U', $previous->submit_date));

	return $gap <= $window;
}

function rapidchat_render_messages($messages, array $names, $viewer_account_id, array $operator_ids = array(), $style = 'guest', $read_through = 0) {
	$output        = '<div class="chat-widget-messages" data-read-through="' . (int) $read_through . '">';
	$previous_date = '';
	$messages      = array_values($messages);
	$total         = count($messages);

	if ($style !== 'admin') {
		$names = rapidchat_names_for_visitor($names, $viewer_account_id);
	}

	foreach ($messages as $index => $message) {
		$row = rapidchat_format_message($message, $names, $viewer_account_id, $operator_ids);

		$previous    = $index > 0 ? $messages[$index - 1] : null;
		$next        = ($index + 1) < $total ? $messages[$index + 1] : null;
		$new_day     = ($row['date'] !== $previous_date);

		if ($new_day) {
			$output       .= '<p class="date-center" data-date="' . esc_attr($row['date']) . '">' . esc_html(rapidchat_date_divider_label($row['date'])) . '</p>';
			$previous_date = $row['date'];
		}

		$starts_group = $new_day || !rapidchat_messages_grouped($previous, $message);
		$ends_group   = !rapidchat_messages_grouped($message, $next)
			|| ($next && mysql2date('Y/m/d', $next->submit_date) !== $row['date']);

		$output .= rapidchat_render_message_row($row, $style, $starts_group, $ends_group);
	}

	$output .= '</div>';

	return $output;
}

function rapidchat_render_message_row(array $row, $style = 'guest', $starts_group = true, $ends_group = true) {
	if (!empty($row['msg_type']) && $row['msg_type'] === 'system') {
		return '<div class="rc-message-system"><span class="rc-system-text">' . esc_html($row['msg']) . ' · ' . esc_html($row['time']) . '</span></div>';
	}

	if (!empty($row['msg_type']) && $row['msg_type'] === 'note') {
		if ($style !== 'admin') {
			return '';
		}
		return '<div class="chat-widget-message rc-message-note" data-message-id="' . esc_attr($row['id']) . '" data-author="' . esc_attr($row['author']) . '" data-ts="' . esc_attr($row['ts']) . '">'
			. '<div class="rc-note-header">' . rapidchat_icon('lock', 14) . ' <span>' . esc_html__('Internal Note (Staff only)', 'rapidchat') . '</span> · <span class="rc-note-author">' . esc_html($row['author']) . '</span> · <span class="rc-note-time">' . esc_html($row['time']) . '</span></div>'
			. '<div class="rc-note-body">' . rapidchat_message_body_html($row['msg'], true) . '</div>'
			. '</div>';
	}

	$own    = ($style === 'admin') ? $row['staff'] : $row['mine'];
	$alt    = $own ? '' : ' alt';
	$output = '';

	$group  = ($starts_group ? ' rc-group-start' : '') . ($ends_group ? ' rc-group-end' : '');

	$attachment_html = '';
	if (!empty($row['attachment_url'])) {
		if ($row['attachment_type'] === 'image') {
			$attachment_html = '<div class="rc-message-attachment rc-attachment-image">'
				. '<a href="' . esc_url($row['attachment_url']) . '" target="_blank" rel="noopener noreferrer" class="rc-img-preview">'
				. '<img src="' . esc_url($row['attachment_url']) . '" alt="' . esc_attr($row['attachment_name']) . '" loading="lazy">'
				. '</a>'
				. '</div>';
		} else {
			$size_html = !empty($row['attachment_size']) ? '<span class="rc-file-size">' . esc_html(size_format((int) $row['attachment_size'])) . '</span>' : '';
			$attachment_html = '<div class="rc-message-attachment rc-attachment-file">'
				. '<a href="' . esc_url($row['attachment_url']) . '" target="_blank" rel="noopener noreferrer" download class="rc-file-link">'
				. '<span class="rc-file-icon">' . rapidchat_icon('file', 18) . '</span>'
				. '<span class="rc-file-meta"><span class="rc-file-name">' . esc_html($row['attachment_name'] ? $row['attachment_name'] : 'Attachment') . '</span>' . $size_html . '</span>'
				. '</a>'
				. '</div>';
		}
	}

	$is_operator_authored = ($style === 'admin') ? !empty($row['staff']) : !$row['mine'];

	$body_html = '';
	if (!empty($row['msg'])) {
		$body_html = '<div class="message-text">' . rapidchat_message_body_html($row['msg'], $is_operator_authored) . '</div>';
	}

	if ($style === 'admin') {
		$output .= '<div class="chat-widget-message' . $alt . $group . '" data-message-id="' . esc_attr($row['id']) . '"'
			. ' data-author="' . esc_attr($row['author']) . '"'
			. ' data-ts="' . esc_attr($row['ts']) . '"'
			. ' data-mine="' . ($own ? '1' : '') . '"'
			. ' title="' . esc_attr($row['time']) . '">';
		$output .= $ends_group
			? '<span class="message-initial">' . esc_html(rapidchat_initial($row['author'])) . '</span>'
			: '<span class="message-initial rc-initial-spacer" aria-hidden="true"></span>';
		$output .= '<div class="message-bubble-content">' . $body_html . $attachment_html . '</div>';
		$output .= '</div>';
	} else {
		if (!$own) {
			$output .= '<div class="chat-message-wrapper">';
			$output .= $ends_group
				? '<span class="chat-icon" aria-hidden="true">' . esc_html(rapidchat_initial($row['author'])) . '</span>'
				: '<span class="chat-icon rc-icon-spacer" aria-hidden="true"></span>';
		}

		$output .= '<div class="chat-widget-message' . $alt . $group . '" data-message-id="' . esc_attr($row['id']) . '"'
			. ' data-author="' . esc_attr($row['author']) . '"'
			. ' data-ts="' . esc_attr($row['ts']) . '"'
			. ' data-mine="' . ($own ? '1' : '') . '"'
			. ' title="' . esc_attr($row['time']) . '">' . $body_html . $attachment_html . '</div>';

		if (!$own) {
			$output .= '</div>';
		}
	}

	if ($ends_group) {
		$output .= '<p class="msg-time' . $alt . '">' . esc_html($row['time']);
		if ($row['author'] !== '' && ($style === 'admin' || !$own)) {
			$output .= ' · ' . esc_html($row['author']);
		}
		$output .= '</p>';
	}

	return $output;
}

function rapidchat_render_composer($conversation_id, $is_open) {
	if (!$is_open) {
		return '<div class="chat-session-ended"><p>' . esc_html__('Chat session has ended.', 'rapidchat') . '</p></div>';
	}

	return '<form method="post" class="chat-widget-input-message" autocomplete="off">'
		. '<div class="rc-composer-preview" style="display:none;"></div>'
		. '<div class="rc-composer-inner">'
		. '<button type="button" class="rc-composer-btn rc-emoji-btn" title="' . esc_attr__('Add Emoji', 'rapidchat') . '" aria-label="' . esc_attr__('Add Emoji', 'rapidchat') . '">' . rapidchat_icon('smile', 18) . '</button>'
		. '<label class="rc-composer-btn rc-attach-btn" title="' . esc_attr__('Attach File', 'rapidchat') . '" aria-label="' . esc_attr__('Attach File', 'rapidchat') . '">'
		. '<input type="file" class="rc-file-input" style="display:none;" accept="image/*,.pdf,.doc,.docx,.txt">'
		. rapidchat_icon('paperclip', 18)
		. '</label>'
		. '<textarea name="msg" class="rc-msg-input" rows="1" placeholder="' . esc_attr__('Type your message…', 'rapidchat') . '" maxlength="' . esc_attr(RAPIDCHAT_MAX_MESSAGE) . '"></textarea>'
		. '<input type="hidden" name="id" value="' . esc_attr($conversation_id) . '">'
		. '<input type="hidden" name="attachment_url" value="">'
		. '<input type="hidden" name="attachment_type" value="">'
		. '<input type="hidden" name="attachment_name" value="">'
		. '<input type="hidden" name="attachment_size" value="0">'
		. '<button type="submit" class="send-button" aria-label="' . esc_attr__('Send', 'rapidchat') . '">'
		. rapidchat_icon('send', 18)
		. '</button>'
		. '</div>'
		. '<div class="rc-emoji-picker-dropdown" style="display:none;"></div>'
		. '</form>';
}

function rapidchat_admin_shell_open(array $args) {
	$args = array_merge(array('title' => '', 'nav' => array(), 'nav_spy' => false, 'actions' => '', 'badge' => ''), $args);

	echo '<div class="wrap rc-wrap"><div id="rc-admin-wrap" class="rapidchat-content">';

	echo '<aside id="rc-sidebar">';
	echo '<div id="rc-sidebar-logo"><img class="rc-logo-mark" src="' . esc_url(RAPIDCHAT_URL . '/images/logo.png') . '" width="38" height="38" alt="" aria-hidden="true">'
		. '<div class="rc-logo-details"><span class="rc-logo-text">RapidChat</span>'
		. '<span class="rc-version">v' . esc_html(RAPIDCHAT_VERSION) . '</span></div></div>';

	if ($args['nav']) {
		echo '<nav id="rc-sidebar-nav" aria-label="' . esc_attr__('Sections', 'rapidchat') . '"' . ($args['nav_spy'] ? ' data-spy="1"' : '') . '>';
		foreach ($args['nav'] as $slug => $item) {
			$active = !empty($item['active']);
			echo '<a href="' . esc_url($item['url']) . '" class="rc-nav-item' . ($active ? ' is-active' : '') . '" data-section="' . esc_attr($slug) . '"' . ($active ? ' aria-current="page"' : '') . '>'
				. '<span class="rc-nav-icon dashicons ' . esc_attr($item['icon']) . '" aria-hidden="true"></span>'
				. '<span class="rc-nav-label">' . esc_html($item['label']) . '</span>'
				. (isset($item['count']) && $item['count'] !== null ? '<span class="rc-nav-count">' . esc_html(number_format_i18n((int) $item['count'])) . '</span>' : '')
				. '</a>';
		}
		echo '</nav>';
	}
	echo '</aside>';

	echo '<div id="rc-main">';
	echo '<header id="rc-top-header"><h1>' . esc_html($args['title'])
		. ($args['badge'] !== '' ? ' <span class="rc-badge">' . esc_html($args['badge']) . '</span>' : '')
		. '</h1><div class="rc-header-actions">' . rapidchat_kses($args['actions']) . '</div></header>';

	echo '<div id="rc-content"><hr class="wp-header-end">';
}

function rapidchat_admin_shell_close() {
	echo '</div></div></div></div>';
}

/** A header action link/button. */
function rapidchat_admin_action($label, $url, $variant = 'secondary', $icon = '') {
	return '<a class="rc-btn rc-btn-' . esc_attr($variant) . '" href="' . esc_url($url) . '">'
		. ($icon ? '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>' : '')
		. esc_html($label) . '</a>';
}
