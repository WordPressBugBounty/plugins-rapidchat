<?php
/**
 * Plugin Name: RapidChat
 * Plugin URI: https://softaculous.com
 * Description: A simple, fast live chat plugin for WordPress.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.0
 * Author: Softaculous
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rapidchat
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('add_action')) {
	echo 'You are not allowed to access this page directly.';
	exit;
}

if (defined('RAPIDCHAT_VERSION')) {
	return;
}

define('RAPIDCHAT_FILE', __FILE__);
define('RAPIDCHAT_BASE', plugin_basename(RAPIDCHAT_FILE));
define('RAPIDCHAT_VERSION', '1.0.0');
define('RAPIDCHAT_DIR', dirname(RAPIDCHAT_FILE));
define('RAPIDCHAT_URL', plugins_url('', RAPIDCHAT_FILE));
define('RAPIDCHAT_SLUG', 'rapidchat');
define('RAPIDCHAT_CSS', RAPIDCHAT_URL . '/css');
define('RAPIDCHAT_JS', RAPIDCHAT_URL . '/js');

include_once RAPIDCHAT_DIR . '/init.php';
