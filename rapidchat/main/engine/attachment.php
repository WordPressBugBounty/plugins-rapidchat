<?php
/** Attachment engine. */

defined('ABSPATH') || exit;

require_once RAPIDCHAT_DIR . '/main/engine/events.php';

/** Validate and store one uploaded chat attachment. */
function rapidchat_attachment_store(array $args) {
	$actor    = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
	$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;
	$file     = isset($args['file']) && is_array($args['file']) ? $args['file'] : array();

	if ($actor_id <= 0) {
		return new WP_Error('rapidchat_unauthorized', __('Unauthorized', 'rapidchat'), array('status' => 401));
	}

	if (!rapidchat_rate_limit('upload_' . $actor_id, 10, MINUTE_IN_SECONDS) || !rapidchat_rate_limit('upload_ip_' . rapidchat_client_ip(), 30, 10 * MINUTE_IN_SECONDS)) {
		return new WP_Error(
			'rapidchat_rate_limited',
			__('Too many uploads. Please wait a moment and try again.', 'rapidchat'),
			array('status' => 429)
		);
	}

	if (empty($file) || empty($file['name'])) {
		return new WP_Error('rapidchat_no_file', __('No file was provided.', 'rapidchat'), array('status' => 400));
	}

	$max_size = (int) rapidchat_filter('max_attachment_size', 5 * MB_IN_BYTES);
	if (!empty($file['size']) && (int) $file['size'] > $max_size) {
		return new WP_Error(
			'rapidchat_file_too_large',
			sprintf(
				/* translators: %s: human-readable file size limit */
				__('That file is too large. The limit is %s.', 'rapidchat'),
				size_format($max_size)
			),
			array('status' => 413)
		);
	}

	if (!function_exists('wp_handle_upload')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$allowed_mimes = (array) apply_filters('rapidchat_attachment_mimes', array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'pdf'          => 'application/pdf',
		'txt'          => 'text/plain',
		'doc'          => 'application/msword',
		'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	));

	add_filter('upload_dir', 'rapidchat_attachment_upload_dir');
	$movefile = wp_handle_upload($file, array(
		'test_form'                => false,
		'mimes'                    => $allowed_mimes,
		'unique_filename_callback' => 'rapidchat_attachment_filename',
	));
	remove_filter('upload_dir', 'rapidchat_attachment_upload_dir');

	// Keep the folder from being listed on hosts with directory indexes enabled.
	$guard = trailingslashit(wp_get_upload_dir()['basedir']) . 'rapidchat/index.php';
	if (!empty($movefile['file']) && !file_exists($guard) && WP_Filesystem()) {
		global $wp_filesystem;
		$wp_filesystem->put_contents($guard, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE);
	}

	if (!$movefile || isset($movefile['error'])) {
		return new WP_Error(
			'rapidchat_upload_failed',
			isset($movefile['error']) ? $movefile['error'] : __('File upload failed.', 'rapidchat'),
			array('status' => 500)
		);
	}

	$stored = array(
		'url'  => $movefile['url'],
		'type' => strpos((string) $movefile['type'], 'image') === 0 ? 'image' : 'file',
		'name' => sanitize_file_name($file['name']),
		'size' => (int) $file['size'],
	);

	rapidchat_event('attachment_stored', $actor_id, $stored['url'], $stored['type']);

	return $stored;
}

/** Chat attachments go to uploads/rapidchat/ instead of the media year/month folders. */
function rapidchat_attachment_upload_dir($dirs) {
	$dirs['subdir'] = '/rapidchat';
	$dirs['path']   = $dirs['basedir'] . '/rapidchat';
	$dirs['url']    = $dirs['baseurl'] . '/rapidchat';

	return $dirs;
}

/** A random prefix so attachment URLs cannot be guessed from the original file name. */
function rapidchat_attachment_filename($dir, $name, $ext) {
	return wp_unique_filename($dir, strtolower(wp_generate_password(16, false)) . '-' . $name);
}
